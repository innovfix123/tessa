<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiUsageController extends Controller
{
    /** USD -> INR display rate (approximate; OpenRouter bills in USD). */
    private const USD_TO_INR = 85;

    /**
     * Day-wise AI spend across all tracked features. CEO-only.
     */
    public function summary(Request $request): JsonResponse
    {
        if (($request->user()->role ?? null) !== Role::SLUG_CEO) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $days = (int) $request->integer('days', 30);
        $days = max(1, min($days, 180));
        $since = now()->subDays($days);

        // Group by IST calendar day (created_at is UTC; +330 min = IST) and feature.
        $rows = AiUsageLog::where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at + INTERVAL 330 MINUTE) as d')
            ->selectRaw('feature')
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw('SUM(cost_usd) as cost')
            ->groupBy('d', 'feature')
            ->orderByDesc('d')
            ->get();

        $byDay = [];
        $totalCost = 0.0;
        $totalCalls = 0;
        $featureTotals = [];

        foreach ($rows as $r) {
            $d = (string) $r->d;
            $calls = (int) $r->calls;
            $cost = (float) $r->cost;

            if (! isset($byDay[$d])) {
                $byDay[$d] = ['date' => $d, 'calls' => 0, 'cost_usd' => 0.0, 'by_feature' => []];
            }
            $byDay[$d]['calls'] += $calls;
            $byDay[$d]['cost_usd'] += $cost;
            $byDay[$d]['by_feature'][$r->feature] = [
                'calls' => $calls,
                'cost_usd' => round($cost, 6),
            ];

            $totalCost += $cost;
            $totalCalls += $calls;
            $featureTotals[$r->feature] = ($featureTotals[$r->feature] ?? 0) + $cost;
        }

        $daysOut = array_values(array_map(function ($day) {
            $day['cost_usd'] = round($day['cost_usd'], 6);
            $day['cost_inr'] = round($day['cost_usd'] * self::USD_TO_INR, 2);

            return $day;
        }, $byDay));

        $featureTotalsOut = [];
        foreach ($featureTotals as $f => $c) {
            $featureTotalsOut[$f] = round($c, 6);
        }
        arsort($featureTotalsOut);

        return response()->json([
            'usd_to_inr' => self::USD_TO_INR,
            'range_days' => $days,
            'total' => [
                'calls' => $totalCalls,
                'cost_usd' => round($totalCost, 6),
                'cost_inr' => round($totalCost * self::USD_TO_INR, 2),
            ],
            'by_feature' => $featureTotalsOut,
            'days' => $daysOut,
        ]);
    }
}
