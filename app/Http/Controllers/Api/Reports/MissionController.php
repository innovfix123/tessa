<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\OnlyCareRevenueSnapshot;
use App\Services\HimaAnalyticsService;
use App\Services\OnlyCareAnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MissionController extends Controller
{
    private const FY_START = '2026-04-01';
    private const FY_END   = '2027-03-31';
    private const MISSION_TARGET_CR = 200;

    public function __construct(
        private OnlyCareAnalyticsService $onlyCare,
        private HimaAnalyticsService $hima,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $now = Carbon::now('Asia/Kolkata');
        $deadline = Carbon::parse(self::FY_END)->endOfDay();
        $daysRemaining = max(0, (int) $now->copy()->startOfDay()->diffInDays($deadline->copy()->startOfDay(), false));

        $hima = $this->buildHima();
        $onlyCare = $this->buildOnlyCare();

        $projects = [$hima, $onlyCare];

        // Combined live daily run-rate (in Cr/day) across all projects with `source=live`.
        $combinedDailyRunRateCr = 0.0;
        $combinedCurrentCr = 0.0;
        foreach ($projects as $p) {
            $combinedCurrentCr += (float) ($p['current'] ?? 0);
            if (($p['source'] ?? '') === 'live') {
                $combinedDailyRunRateCr += (float) ($p['daily_run_rate_cr'] ?? 0);
            }
        }
        $paceProjectedCr = round($combinedCurrentCr + ($combinedDailyRunRateCr * $daysRemaining), 1);

        // Combined precise rupee total (no rounding for display).
        $combinedCurrentInr = 0;
        $latestDataDate = null;
        foreach ($projects as $p) {
            $combinedCurrentInr += (int) ($p['current_inr'] ?? 0);
            if (! empty($p['as_of']) && ($latestDataDate === null || $p['as_of'] > $latestDataDate)) {
                $latestDataDate = $p['as_of'];
            }
        }

        return response()->json([
            'ok' => true,
            'mission' => [
                'target_cr' => self::MISSION_TARGET_CR,
                'deadline' => self::FY_END,
                'days_remaining' => $daysRemaining,
                'daily_run_rate_cr' => round($combinedDailyRunRateCr, 3),
                'pace_projected_cr' => round($paceProjectedCr, 2),
                'current_cr' => round($combinedCurrentCr, 2),
                'current_inr' => $combinedCurrentInr,
                'as_of' => $latestDataDate,
            ],
            'projects' => $projects,
        ]);
    }

    /**
     * Hima slice: cumulative FY revenue, daily run-rate, project-level pace, last-30-day series.
     *
     * Reads from `revenue_payouts` which is kept fresh by the `revenue:sync-mission`
     * cron (every 5 min, populated from himaapp.in/api/internal/daily-revenue).
     * No upstream HTTP on the request path — flaky-upstream blast radius is the cron.
     */
    private function buildHima(): array
    {
        $now = Carbon::now('Asia/Kolkata');
        $today = $now->copy()->format('Y-m-d');

        $base = [
            'name' => 'Hima',
            'color' => '#ec4899',
            'icon' => 'H',
            'target' => 80,
        ];

        $fyTotalInr = (int) DB::table('revenue_payouts')
            ->whereBetween('date', [self::FY_START, $today])
            ->sum('revenue');

        $dailyRows = DB::table('revenue_payouts')
            ->select('date', 'revenue')
            ->whereBetween('date', [self::FY_START, $today])
            ->orderBy('date')
            ->get();
        $dailyData = $dailyRows->map(fn ($r) => [
            'date' => $r->date,
            'revenue_inr' => (int) $r->revenue,
        ])->all();

        $asOf = $dailyData !== [] ? end($dailyData)['date'] : null;
        $source = $asOf === $today ? 'live' : 'snapshot';

        return array_merge($base, $this->summariseDailyData($dailyData, $now, $source), [
            'current_inr' => $fyTotalInr,
            'as_of' => $asOf,
        ]);
    }

    /**
     * Compute current_cr, daily run-rate, next-month forecast, FY pace, and
     * week-over-week growth from a `[{date, revenue_inr}, ...]` series.
     *
     * @param array<int,array{date:string,revenue_inr:int}> $dailyData
     * @return array{current:float,next:float,pace_cr:float,daily_run_rate_cr:float,daily_data:array<int,array{date:string,revenue_inr:int}>,today_inr:int,week_growth_pct:?float,source:string}
     */
    private function summariseDailyData(array $dailyData, Carbon $now, string $source): array
    {
        // Cumulative across the whole supplied series (caller decides the window).
        $fyTotalInr = array_sum(array_column($dailyData, 'revenue_inr'));
        $currentCr = round($fyTotalInr / 10000000, 2);

        // Sparkline window = trailing 30 days.
        $thirtyAgo = $now->copy()->subDays(29)->format('Y-m-d');
        $sparkData = array_values(array_filter(
            $dailyData,
            fn ($d) => ($d['date'] ?? '') >= $thirtyAgo,
        ));

        // Daily run-rate from the trailing window (min 7 days for stability).
        $dailyRunRateInr = 0.0;
        $count = count($sparkData);
        if ($count >= 7) {
            $sum = array_sum(array_column($sparkData, 'revenue_inr'));
            $dailyRunRateInr = $sum / $count;
        }
        $dailyRunRateCr = $dailyRunRateInr / 10000000;

        // Next calendar-month forecast.
        $daysInNextMonth = $now->copy()->addMonthNoOverflow()->daysInMonth;
        $nextCr = $dailyRunRateInr > 0
            ? round(($dailyRunRateInr * $daysInNextMonth) / 10000000, 1)
            : 0.0;

        // FY-end pace projection for THIS project alone.
        $deadline = Carbon::parse(self::FY_END)->endOfDay();
        $daysRemaining = max(0, (int) $now->copy()->startOfDay()->diffInDays($deadline->copy()->startOfDay(), false));
        $paceCr = round($currentCr + ($dailyRunRateCr * $daysRemaining), 1);

        // Today + week-over-week growth.
        $todayInr = $count > 0 ? (int) ($sparkData[$count - 1]['revenue_inr'] ?? 0) : 0;
        $last7 = array_slice($sparkData, -7);
        $prev7 = array_slice($sparkData, -14, 7);
        $sum7 = array_sum(array_column($last7, 'revenue_inr'));
        $sumPrev7 = array_sum(array_column($prev7, 'revenue_inr'));
        $weekGrowthPct = $sumPrev7 > 0 ? round((($sum7 - $sumPrev7) / $sumPrev7) * 100, 1) : null;

        return [
            'current' => $currentCr,
            'next' => $nextCr,
            'pace_cr' => $paceCr,
            'daily_run_rate_cr' => round($dailyRunRateCr, 3),
            'daily_data' => $sparkData,
            'today_inr' => $todayInr,
            'week_growth_pct' => $weekGrowthPct,
            'source' => $source,
        ];
    }

    /**
     * Only Care: cumulative FY revenue, daily run-rate, project-level pace,
     * last-30-day series — mirrors Hima. Pulls per-day revenue from
     * onlycare.in/api/internal/daily-revenue. Falls back to the weekly
     * lifetime snapshot if the upstream is unreachable.
     */
    private function buildOnlyCare(): array
    {
        $now = Carbon::now('Asia/Kolkata');
        $today = $now->copy()->format('Y-m-d');

        $base = [
            'name' => 'Only Care',
            'color' => '#a1a1aa',
            'icon' => 'O',
            'target' => 25,
            'current' => 0,
            'next' => 0,
            'pace_cr' => 0,
            'daily_run_rate_cr' => 0,
            'daily_data' => [],
            'source' => 'awaiting_data',
        ];

        $daily = $this->onlyCare->getDailyRevenue(self::FY_START, $today);

        if ($daily !== null) {
            $currentCr = round(($daily['total_revenue'] ?? 0) / 10000000, 2);

            // Last-30-day daily series for the sparkline.
            $thirtyAgo = $now->copy()->subDays(29)->format('Y-m-d');
            $dailyData = [];
            foreach ($daily['days'] ?? [] as $row) {
                if (($row['date'] ?? '') >= $thirtyAgo) {
                    $dailyData[] = [
                        'date' => $row['date'],
                        'revenue_inr' => (int) ($row['revenue'] ?? 0),
                    ];
                }
            }

            // Daily run-rate from the trailing window (min 7 days for stability).
            $dailyRunRateInr = 0.0;
            $count = count($dailyData);
            if ($count >= 7) {
                $sum = array_sum(array_column($dailyData, 'revenue_inr'));
                $dailyRunRateInr = $sum / $count;
            }
            $dailyRunRateCr = $dailyRunRateInr / 10000000;

            // Next calendar-month forecast.
            $daysInNextMonth = $now->copy()->addMonthNoOverflow()->daysInMonth;
            $nextCr = $dailyRunRateInr > 0
                ? round(($dailyRunRateInr * $daysInNextMonth) / 10000000, 1)
                : 0.0;

            // FY-end pace projection for THIS project alone.
            $deadline = Carbon::parse(self::FY_END)->endOfDay();
            $daysRemaining = max(0, (int) $now->copy()->startOfDay()->diffInDays($deadline->copy()->startOfDay(), false));
            $paceCr = round($currentCr + ($dailyRunRateCr * $daysRemaining), 1);

            // Today + week-over-week growth.
            $todayInr = 0;
            if ($count > 0) {
                $todayInr = (int) ($dailyData[$count - 1]['revenue_inr'] ?? 0);
            }
            $last7 = array_slice($dailyData, -7);
            $prev7 = array_slice($dailyData, -14, 7);
            $sum7 = array_sum(array_column($last7, 'revenue_inr'));
            $sumPrev7 = array_sum(array_column($prev7, 'revenue_inr'));
            $weekGrowthPct = $sumPrev7 > 0 ? round((($sum7 - $sumPrev7) / $sumPrev7) * 100, 1) : null;

            return array_merge($base, [
                'current' => $currentCr,
                'current_inr' => (int) ($daily['total_revenue'] ?? 0),
                'next' => $nextCr,
                'pace_cr' => $paceCr,
                'daily_run_rate_cr' => round($dailyRunRateCr, 3),
                'daily_data' => $dailyData,
                'today_inr' => $todayInr,
                'week_growth_pct' => $weekGrowthPct,
                'source' => 'live',
                'as_of' => $daily['as_of'] ?? null,
            ]);
        }

        // Upstream unreachable — fall back to the weekly snapshot of lifetime revenue.
        $snapshot = OnlyCareRevenueSnapshot::orderByDesc('snapshot_date')->first();
        if (! $snapshot) {
            return $base;
        }

        $currentCr = round($snapshot->total_revenue / 10000000, 2);

        return array_merge($base, [
            'current' => $currentCr,
            'pace_cr' => $currentCr,
            'source' => 'snapshot',
            'last_transaction_at' => optional($snapshot->last_transaction_at)->toIso8601String(),
            'as_of' => optional($snapshot->source_as_of)->toIso8601String(),
            'snapshot_date' => optional($snapshot->snapshot_date)->toDateString(),
        ]);
    }
}
