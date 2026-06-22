<?php

namespace App\Console\Commands;

use App\Models\KpiMonthlySummary;
use App\Models\KpiScorecardItem;
use App\Models\KpiWeeklyReport;
use App\Models\User;
use App\Services\TessaAIService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Month-end AI summary of each person's KPI report. For every eligible subject
 * with KPI items, reads the month's weekly tracking notes per KPI and asks the
 * AI (gemini-2.5-flash) whether the monthly target was met and to what %, plus
 * one person-level overall narrative. Idempotent per (user, month).
 *
 *   php artisan kpi:generate-monthly-summaries --month=2026-05 --dry-run
 *   php artisan kpi:generate-monthly-summaries            # previous month, live
 */
class GenerateKpiMonthlySummaries extends Command
{
    protected $signature = 'kpi:generate-monthly-summaries {--month= : YYYY-MM (default: previous month, IST)} {--user= : limit to one user id} {--dry-run : count work without calling AI or writing}';

    protected $description = 'Month-end AI verdict on each person\'s KPI weekly notes — was the target met, and to what %';

    public function handle(TessaAIService $ai): int
    {
        $dry = (bool) $this->option('dry-run');
        $month = $this->option('month')
            ?: Carbon::now('Asia/Kolkata')->startOfMonth()->subMonth()->format('Y-m');

        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->error('Invalid --month; expected YYYY-MM.');

            return self::FAILURE;
        }

        $start = Carbon::createFromFormat('Y-m', $month, 'Asia/Kolkata')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $userFilter = $this->option('user');
        $subjectIds = KpiScorecardItem::where('is_active', true)
            ->when($userFilter, fn ($q) => $q->where('user_id', (int) $userFilter))
            ->distinct()->pluck('user_id');

        $processed = 0;
        $skipped = 0;
        $aiCalls = 0;

        foreach ($subjectIds as $uid) {
            $user = User::find($uid);
            if (! $user || ! KpiScorecardItem::isEligibleSubject($user)) {
                $skipped++;
                continue;
            }

            $items = KpiScorecardItem::where('user_id', $uid)->where('is_active', true)
                ->orderBy('sort_order')->orderBy('id')->get();

            $perItem = [];          // kpi_item_id => result
            foreach ($items as $it) {
                $notes = KpiWeeklyReport::where('kpi_item_id', $it->id)
                    ->whereBetween('week_key', [$start->toDateString(), $end->toDateString()])
                    ->whereNotNull('report_text')->where('report_text', '!=', '')
                    ->orderBy('week_key')->pluck('report_text')->all();
                if (empty($notes)) {
                    continue;
                }
                if ($dry) {
                    $perItem[$it->id] = ['name' => $it->name, 'count' => count($notes)];
                    continue;
                }
                $res = $ai->summarizeKpiMonth($it->name, $it->target, $it->weight, $notes);
                $aiCalls++;
                $perItem[$it->id] = array_merge(['name' => $it->name], $res);
            }

            if (empty($perItem)) {
                $skipped++;
                if ($dry) {
                    $this->line("skip {$user->name} (#{$uid}) — no notes in {$month}");
                }
                continue;
            }

            if ($dry) {
                $this->info("[dry-run] {$user->name} (#{$uid}): would summarize " . count($perItem) . " KPI(s) for {$month}");
                $processed++;
                continue;
            }

            $overall = $ai->summarizeKpiMonthOverall($user->name, array_values($perItem));
            $aiCalls++;

            DB::transaction(function () use ($uid, $month, $perItem, $overall) {
                KpiMonthlySummary::where('user_id', $uid)->where('month_key', $month)->delete();
                foreach ($perItem as $itemId => $r) {
                    KpiMonthlySummary::create([
                        'user_id'        => $uid,
                        'kpi_item_id'    => $itemId,
                        'month_key'      => $month,
                        'summary_text'   => $r['summary'] ?? null,
                        'percentage_met' => $r['percentage_met'] ?? null,
                        'status'         => $r['status'] ?? 'unknown',
                        'generated_at'   => now(),
                        'model'          => 'google/gemini-2.5-flash',
                    ]);
                }
                KpiMonthlySummary::create([
                    'user_id'        => $uid,
                    'kpi_item_id'    => null,
                    'month_key'      => $month,
                    'summary_text'   => $overall ?: null,
                    'percentage_met' => null,
                    'status'         => null,
                    'generated_at'   => now(),
                    'model'          => 'google/gemini-2.5-flash',
                ]);
            });

            $processed++;
            $this->info("Summarized {$user->name} (#{$uid}) — " . count($perItem) . " KPI(s), {$month}");
        }

        $prefix = $dry ? '[dry-run] ' : '';
        $this->line("{$prefix}Done {$month}. processed={$processed} skipped={$skipped} aiCalls={$aiCalls}");

        return self::SUCCESS;
    }
}
