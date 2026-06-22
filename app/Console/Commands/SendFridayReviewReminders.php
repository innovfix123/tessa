<?php

namespace App\Console\Commands;

use App\Models\LeaveRequest;
use App\Models\ManagerWorkReview;
use App\Models\User;
use App\Services\SlackService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendFridayReviewReminders extends Command
{
    protected $signature = 'notify:friday-review';

    protected $description = 'Friday afternoon Slack DM to managers/leads who still have unsubmitted weekly work-quality reviews';

    public function handle(SlackService $slack): int
    {
        $ist = Carbon::now('Asia/Kolkata');
        $todayStr = $ist->format('Y-m-d');

        if (array_key_exists($todayStr, config('holidays', []))) {
            $this->line("Skipping — {$todayStr} is a holiday.");

            return self::SUCCESS;
        }

        if (! $ist->isFriday()) {
            $this->line("Not a Friday ({$ist->format('l')}) — nothing to do.");

            return self::SUCCESS;
        }

        $weekFriday = $ist->copy()->startOfWeek(Carbon::MONDAY)->addDays(4)->format('Y-m-d');

        // Candidate managers: every active user whose rateable list is non-empty.
        $candidates = User::where('is_active', true)
            ->isRatingManager()
            ->with('roleRelation')
            ->get();

        $sent = 0;
        $skippedSubmitted = 0;
        $skippedLeave = 0;
        $skippedNoSlack = 0;
        $skippedNothingToRate = 0;

        foreach ($candidates as $manager) {
            $rateables = ManagerWorkReview::rateableSubordinatesFor($manager, $weekFriday);
            if ($rateables->isEmpty()) {
                $skippedNothingToRate++;
                continue;
            }

            // Skip managers on approved leave today.
            $onLeave = LeaveRequest::where('user_id', $manager->id)
                ->where('status', 'approved')
                ->where('start_date', '<=', $todayStr)
                ->where('end_date', '>=', $todayStr)
                ->exists();
            if ($onLeave) {
                $skippedLeave++;
                $this->line("Skipped (on leave): {$manager->name}");
                continue;
            }

            // Skip managers who've already rated all subordinates for this week.
            $ratedIds = ManagerWorkReview::where('manager_id', $manager->id)
                ->where('week_key', $weekFriday)
                ->pluck('subordinate_id')
                ->toArray();
            $missing = $rateables->count() - count(array_intersect($rateables->pluck('id')->toArray(), $ratedIds));
            if ($missing === 0) {
                $skippedSubmitted++;
                $this->line("Skipped (submitted): {$manager->name}");
                continue;
            }

            if (! $manager->slack_user_id) {
                $skippedNoSlack++;
                $this->line("Skipped (no slack_user_id): {$manager->name}");
                continue;
            }

            $plural = $missing === 1 ? 'member' : 'members';
            $text = "Hi {$manager->name}! It's Friday — please rate this week's performance for your {$missing} team {$plural} before signing off.\n"
                . 'Form: https://tessa.innovfix.ai/portal#friday-review';

            if ($slack->sendDirectMessage($manager->slack_user_id, $text)) {
                $sent++;
                $this->info("Sent reminder: {$manager->name} ({$missing} unrated)");
            } else {
                $this->warn("Slack DM failed: {$manager->name}");
            }
        }

        $this->line("Done. Sent={$sent} · Submitted={$skippedSubmitted} · Leave={$skippedLeave} · NoSlack={$skippedNoSlack} · NothingToRate={$skippedNothingToRate}");

        return self::SUCCESS;
    }
}
