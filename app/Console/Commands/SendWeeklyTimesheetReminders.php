<?php

namespace App\Console\Commands;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WeeklyTimesheet;
use App\Services\SlackService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendWeeklyTimesheetReminders extends Command
{
    protected $signature = 'notify:weekly-timesheet {--dry-run : List who would be reminded without sending any Slack DMs}';

    protected $description = 'Friday Slack DM to employees who still owe their weekly timesheet for the current week';

    public function handle(SlackService $slack): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $ist = Carbon::now('Asia/Kolkata');
        $todayStr = $ist->format('Y-m-d');

        if (array_key_exists($todayStr, config('holidays', []))) {
            $this->line("Skipping — {$todayStr} is a holiday.");

            return self::SUCCESS;
        }

        // Live runs fire across the Fri–Sun window (the schedule enforces this
        // too). A --dry-run is allowed any day so the pending set can be inspected.
        $inWindow = in_array($ist->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY, Carbon::SUNDAY], true);
        if (! $inWindow && ! $dryRun) {
            $this->line("Outside the Fri–Sun window ({$ist->format('l')}) — nothing to do.");

            return self::SUCCESS;
        }

        $weekStart = $ist->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');

        $excluded = array_map('intval', config('weekly_timesheet.excluded_user_ids', []));

        // Everyone active who fills a weekly timesheet (i.e. not excluded).
        $candidates = User::where('is_active', true)
            ->when($excluded, fn ($q) => $q->whereNotIn('id', $excluded))
            ->orderBy('name')
            ->get();

        // Who's already submitted this week — one lookup, not per-user.
        $submittedIds = WeeklyTimesheet::where('week_start', $weekStart)
            ->whereIn('user_id', $candidates->pluck('id'))
            ->pluck('user_id')
            ->flip();

        $sent = 0;
        $skippedSubmitted = 0;
        $skippedLeave = 0;
        $skippedNoSlack = 0;

        foreach ($candidates as $user) {
            if ($submittedIds->has($user->id)) {
                $skippedSubmitted++;
                continue;
            }

            // Skip anyone on approved leave today — they can log it when back.
            $onLeave = LeaveRequest::where('user_id', $user->id)
                ->where('status', 'approved')
                ->where('start_date', '<=', $todayStr)
                ->where('end_date', '>=', $todayStr)
                ->exists();
            if ($onLeave) {
                $skippedLeave++;
                $this->line("Skipped (on leave): {$user->name}");
                continue;
            }

            $slackId = $user->slack_user_id ?: $slack->getUserIdByName($user->name);
            if (! $slackId) {
                $skippedNoSlack++;
                $this->line("Skipped (no Slack): {$user->name}");
                continue;
            }

            $text = "Hi {$user->name}! Your weekly timesheet is still pending — it's *mandatory* and blocks your sign-off. "
                . "Please log your regular hours + what you worked on, and any overtime (including weekend work).\n"
                . 'Open the *Weekly Timesheet* tab: https://tessa.innovfix.ai/portal#view=weeklyTimesheet';

            if ($dryRun) {
                $this->info("[dry-run] would remind: {$user->name}");
                $sent++;
                continue;
            }

            if ($slack->sendDirectMessage($slackId, $text)) {
                $sent++;
                $this->info("Reminded: {$user->name}");
            } else {
                $this->warn("Slack DM failed: {$user->name}");
            }

            usleep(150000); // 150ms — gentle pacing for a company-wide fanout
        }

        $verb = $dryRun ? 'Would remind' : 'Sent';
        $this->line("Done ({$weekStart}). {$verb}={$sent} · Submitted={$skippedSubmitted} · Leave={$skippedLeave} · NoSlack={$skippedNoSlack}");

        return self::SUCCESS;
    }
}
