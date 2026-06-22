<?php

namespace App\Console\Commands;

use App\Models\LeaveRequest;
use App\Models\ManagerWorkReview;
use App\Models\User;
use App\Services\SlackService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendOverdueReviewReminders extends Command
{
    protected $signature = 'notify:overdue-review {--dry-run : List who would be pinged without sending Slack DMs}';

    protected $description = 'Weekday Slack DM to managers who still owe ratings for past weeks (outside the live Fri/Sat/Sun rating window)';

    public function handle(SlackService $slack): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $ist = Carbon::now('Asia/Kolkata');
        $todayStr = $ist->format('Y-m-d');

        if (array_key_exists($todayStr, config('holidays', []))) {
            $this->line("Skipping — {$todayStr} is a holiday.");

            return self::SUCCESS;
        }

        // Within Fri/Sat/Sun the existing notify:review-followup nag handles the
        // current week. This command only covers carry-over from prior weeks.
        if (in_array($ist->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY, Carbon::SUNDAY], true)) {
            $this->line("Inside live rating window ({$ist->format('l')}) — overdue nag deferred to followup command.");

            return self::SUCCESS;
        }

        $lookback = max(0, (int) config('review.overdue_lookback_weeks', 4));
        if ($lookback === 0) {
            $this->line('Overdue lookback disabled (review.overdue_lookback_weeks=0) — nothing to do.');

            return self::SUCCESS;
        }

        $pastFridays = [];
        for ($i = 1; $i <= $lookback; $i++) {
            $pastFridays[] = $ist->copy()->startOfWeek(Carbon::MONDAY)->addDays(4)->subWeeks($i)->format('Y-m-d');
        }
        // Drop administratively waived weeks (config('review.skip_weeks')) so a
        // retired week is never nagged about.
        $pastFridays = array_values(array_filter(
            $pastFridays,
            fn ($weekKey) => ! ManagerWorkReview::isSkippedWeek($weekKey)
        ));

        $managers = User::where('is_active', true)
            ->isRatingManager()
            ->with('roleRelation')
            ->get();

        $sent = 0;
        $skippedNothingDue = 0;
        $skippedLeave = 0;
        $skippedNoSlack = 0;
        $skippedNothingToRate = 0;

        foreach ($managers as $manager) {
            if (ManagerWorkReview::rateableSubordinatesFor($manager)->isEmpty()) {
                $skippedNothingToRate++;
                continue;
            }

            $onLeave = LeaveRequest::where('user_id', $manager->id)
                ->where('status', 'approved')
                ->where('start_date', '<=', $todayStr)
                ->where('end_date', '>=', $todayStr)
                ->exists();
            if ($onLeave) {
                $skippedLeave++;
                continue;
            }

            $records = ManagerWorkReview::where('manager_id', $manager->id)
                ->whereIn('week_key', $pastFridays)
                ->get()
                ->groupBy(fn ($r) => Carbon::parse($r->week_key)->format('Y-m-d'));

            $overdueWeeks = [];
            foreach ($pastFridays as $weekKey) {
                $weekRateables = ManagerWorkReview::rateableSubordinatesFor($manager, $weekKey);
                if ($weekRateables->isEmpty()) {
                    continue;
                }
                $rateableIds = $weekRateables->pluck('id')->all();
                $rated = $records->get($weekKey, collect())->pluck('subordinate_id')->all();
                if (count(array_intersect($rateableIds, $rated)) < count($rateableIds)) {
                    $overdueWeeks[] = $weekKey;
                }
            }

            if (empty($overdueWeeks)) {
                $skippedNothingDue++;
                continue;
            }

            if (! $manager->slack_user_id) {
                $skippedNoSlack++;
                continue;
            }

            sort($overdueWeeks);
            $weekList = implode(', ', array_map(
                fn ($w) => Carbon::parse($w)->format('M j'),
                $overdueWeeks
            ));
            $count = count($overdueWeeks);
            $plural = $count === 1 ? 'week' : 'weeks';

            $text = "Hi {$manager->name}, you have {$count} pending work-quality review {$plural} for your team (week of {$weekList}). "
                . "Please submit your ratings as soon as possible — the form stays open until you do.\n"
                . 'Form: https://tessa.innovfix.ai/portal#friday-review';

            if ($dryRun) {
                $sent++;
                $this->info("[dry-run] Would DM: {$manager->name} ({$count} {$plural}: {$weekList})");
                continue;
            }

            if ($slack->sendDirectMessage($manager->slack_user_id, $text)) {
                $sent++;
                $this->info("Overdue reminder sent: {$manager->name} ({$count} {$plural})");
            } else {
                $this->warn("Slack DM failed: {$manager->name}");
            }
        }

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->line("{$prefix}Done. Sent={$sent} · NothingDue={$skippedNothingDue} · Leave={$skippedLeave} · NoSlack={$skippedNoSlack} · NothingToRate={$skippedNothingToRate}");

        return self::SUCCESS;
    }
}
