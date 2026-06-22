<?php

namespace App\Console\Commands;

use App\Models\LeaveRequest;
use App\Models\ManagerWorkReview;
use App\Models\User;
use App\Services\SlackService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendReviewFollowupReminders extends Command
{
    protected $signature = 'notify:review-followup';

    protected $description = 'Persistent Slack DM every 4 hours to managers who still have unsubmitted weekly reviews';

    public function handle(SlackService $slack): int
    {
        $ist = Carbon::now('Asia/Kolkata');
        $todayStr = $ist->format('Y-m-d');

        // Rating window is Fri/Sat/Sun only — skip every other day even if the
        // command is invoked manually.
        if (! in_array($ist->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY, Carbon::SUNDAY], true)) {
            $this->line("Outside rating window ({$ist->format('l')}) — skipping.");

            return self::SUCCESS;
        }

        if (array_key_exists($todayStr, config('holidays', []))) {
            $this->line("Skipping — {$todayStr} is a holiday.");

            return self::SUCCESS;
        }

        $weekFriday = $ist->copy()->startOfWeek(Carbon::MONDAY)->addDays(4)->format('Y-m-d');

        $candidates = User::where('is_active', true)
            ->isRatingManager()
            ->with('roleRelation')
            ->get();

        $sent = 0;
        $skipped = 0;

        foreach ($candidates as $manager) {
            $rateables = ManagerWorkReview::rateableSubordinatesFor($manager, $weekFriday);
            if ($rateables->isEmpty()) {
                $skipped++;
                continue;
            }

            $onLeave = LeaveRequest::where('user_id', $manager->id)
                ->where('status', 'approved')
                ->where('start_date', '<=', $todayStr)
                ->where('end_date', '>=', $todayStr)
                ->exists();
            if ($onLeave) {
                $skipped++;
                continue;
            }

            $ratedIds = ManagerWorkReview::where('manager_id', $manager->id)
                ->where('week_key', $weekFriday)
                ->pluck('subordinate_id')
                ->toArray();
            $missing = $rateables->count() - count(array_intersect($rateables->pluck('id')->toArray(), $ratedIds));
            if ($missing === 0) {
                $skipped++;
                continue;
            }

            if (! $manager->slack_user_id) {
                $skipped++;
                continue;
            }

            $plural = $missing === 1 ? 'member' : 'members';
            $text = "Reminder: You still have {$missing} team {$plural} pending work-quality review for this week. Please submit your ratings as soon as possible.\n"
                . 'Form: https://tessa.innovfix.ai/portal#friday-review';

            if ($slack->sendDirectMessage($manager->slack_user_id, $text)) {
                $sent++;
                $this->info("Follow-up sent: {$manager->name} ({$missing} unrated)");
            } else {
                $this->warn("Slack DM failed: {$manager->name}");
            }
        }

        $this->line("Done. Sent={$sent} · Skipped={$skipped}");

        return self::SUCCESS;
    }
}
