<?php

namespace App\Console\Commands;

use App\Models\KpiScorecardItem;
use App\Models\KpiWeeklyReport;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\SlackService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Fri–Mon Slack DM to managers who still have unfilled KPI weekly notes for
 * the current week. One bundled DM per manager listing the team members still
 * pending. Mirrors notify:overdue-review.
 */
class NotifyKpiReport extends Command
{
    protected $signature = 'notify:kpi-report {--dry-run : List who would be pinged without sending Slack DMs}';

    protected $description = 'Fri–Mon Slack nudge to managers with unfilled KPI weekly notes for the current week';

    public function handle(SlackService $slack): int
    {
        $dry = (bool) $this->option('dry-run');
        $ist = Carbon::now('Asia/Kolkata');
        $todayStr = $ist->format('Y-m-d');

        if (array_key_exists($todayStr, config('holidays', []))) {
            $this->line("Skipping — {$todayStr} is a holiday.");

            return self::SUCCESS;
        }

        // KPI notes are only fillable Fri–Mon, so only nudge in that window.
        if (! in_array($ist->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY, Carbon::SUNDAY, Carbon::MONDAY], true)) {
            $this->line("Not in the Fri–Mon KPI window ({$ist->format('l')}) — nothing to nudge.");

            return self::SUCCESS;
        }

        // Most recent Friday on/before today — so a Monday nudge chases the week
        // that just ended (its Fri→Mon window), not the upcoming Friday. Mirrors
        // KpiReportController::kpiFriday().
        $weekKey = $ist->copy()->subDays(($ist->dayOfWeek - Carbon::FRIDAY + 7) % 7)->format('Y-m-d');

        $sent = 0;
        $skippedAllFilled = 0;
        $skippedLeave = 0;
        $skippedNoSlack = 0;

        foreach (User::where('is_active', true)->get() as $manager) {
            $subs = KpiScorecardItem::fillableSubjectsFor($manager, $weekKey);
            if ($subs->isEmpty()) {
                continue;
            }

            $pending = [];
            foreach ($subs as $s) {
                $itemCount = KpiScorecardItem::where('user_id', $s->id)->where('is_active', true)->count();
                $filled = KpiWeeklyReport::where('user_id', $s->id)->where('week_key', $weekKey)
                    ->whereNotNull('report_text')->where('report_text', '!=', '')->count();
                if ($itemCount > 0 && $filled < $itemCount) {
                    $pending[] = $s->name;
                }
            }
            if (empty($pending)) {
                $skippedAllFilled++;
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

            $names = implode(', ', $pending);
            $count = count($pending);

            if ($dry) {
                $sent++;
                $this->info("[dry-run] Would DM: {$manager->name} → {$names}");
                continue;
            }

            // Resolve the bot recipient by stored id, else by email (cached).
            $slackId = $manager->slack_user_id ?: $slack->lookupByEmail($manager->email);
            if (! $slackId) {
                $skippedNoSlack++;
                continue;
            }

            $who = $count === 1 ? '1 team member' : "{$count} team members";
            // Monday is the final day of the Fri→Mon window — make the nudge say so.
            $lead = $ist->dayOfWeek === Carbon::MONDAY
                ? "today is the *last day* to fill this week's KPI reports — please complete them by end of day"
                : "it's KPI report day";
            $text = "Hi {$manager->name}, {$lead}. You still have this week's KPI updates to fill for {$who}: {$names}.\n"
                . "Add this week's notes here: https://tessa.innovfix.ai/portal#kpi_report";

            if ($slack->sendDirectMessage($slackId, $text)) {
                $sent++;
                $this->info("KPI nudge sent: {$manager->name} ({$count})");
            } else {
                $this->warn("Slack DM failed (or quiet window): {$manager->name}");
            }
        }

        $prefix = $dry ? '[dry-run] ' : '';
        $this->line("{$prefix}Done. Sent={$sent} · AllFilled={$skippedAllFilled} · Leave={$skippedLeave} · NoSlack={$skippedNoSlack}");

        return self::SUCCESS;
    }
}
