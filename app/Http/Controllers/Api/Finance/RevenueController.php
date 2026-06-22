<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\RevenuePayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RevenueController extends Controller
{
    public function dailyRevenuePayout(Request $request): JsonResponse
    {
        $from = $request->query('from', '2026-03-31');
        $to = $request->query('to', now('Asia/Kolkata')->format('Y-m-d'));

        $rows = RevenuePayout::whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get()
            ->keyBy(fn ($r) => $r->date->format('Y-m-d'));

        // Google Ads spend per day (sum all campaigns for that date)
        $googleSpend = DB::table('google_ad_reports')
            ->select('reporting_date', DB::raw('SUM(cost) as total_cost'))
            ->whereBetween('reporting_date', [$from, $to])
            ->groupBy('reporting_date')
            ->pluck('total_cost', 'reporting_date');

        // Meta Ads spend per day (sum all ads for that date)
        $metaSpend = DB::table('meta_ad_reports')
            ->select('reporting_starts', DB::raw('SUM(amount_spent) as total_spent'))
            ->whereBetween('reporting_starts', [$from, $to])
            ->groupBy('reporting_starts')
            ->pluck('total_spent', 'reporting_starts');

        // Merge all dates
        $allDates = collect()
            ->merge($rows->keys())
            ->merge($googleSpend->keys())
            ->merge($metaSpend->keys())
            ->unique()
            ->sort()
            ->values();

        $result = $allDates->map(function ($date) use ($rows, $googleSpend, $metaSpend) {
            $rev = $rows->get($date);

            return [
                'date' => $date,
                'revenue' => $rev->revenue ?? 0,
                'paying_users' => $rev->paying_users ?? 0,
                'transactions' => $rev->transactions ?? 0,
                'by_language' => $rev->by_language ?? [],
                'payout_paid' => $rev ? (float) $rev->payout_paid : 0,
                'payout_paid_count' => $rev->payout_paid_count ?? 0,
                'payout_rejected' => $rev ? (float) $rev->payout_rejected : 0,
                'payout_rejected_count' => $rev->payout_rejected_count ?? 0,
                'payout_pending' => $rev ? (float) $rev->payout_pending : 0,
                'payout_pending_count' => $rev->payout_pending_count ?? 0,
                'google_spend' => (float) ($googleSpend->get($date) ?? 0),
                'meta_spend' => (float) ($metaSpend->get($date) ?? 0),
                'agora_cost_inr' => $rev ? (float) $rev->agora_total_cost_inr : 0,
                'agora_cost_usd' => $rev ? (float) $rev->agora_total_cost_usd : 0,
                'audio_minutes' => $rev->audio_minutes ?? 0,
                'video_minutes' => $rev->video_minutes ?? 0,
            ];
        });

        return response()->json(['rows' => $result]);
    }
}
