<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\HimaRevenueEntry;
use App\Models\RevenuePayout;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HimaRevenueSheetController extends Controller
{
    /**
     * GET /api/hima-revenue-sheet?month=YYYY-MM
     * Returns one item per calendar day in the month: stored manual values
     * (or null) plus suggested_collection / suggested_payout from
     * revenue_payouts so Nandha/Anirudh can fill from the live API value
     * with one click.
     */
    public function index(Request $request): JsonResponse
    {
        $this->ensureAccess($request, allowViewer: true);

        $month = $this->parseMonth($request->query('month', Carbon::now('Asia/Kolkata')->format('Y-m')));
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $entries = HimaRevenueEntry::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn ($e) => $e->date->toDateString());

        $payouts = RevenuePayout::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn ($p) => Carbon::parse($p->date)->toDateString());

        $rows = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $entry = $entries->get($key);
            $payout = $payouts->get($key);

            $rows[] = [
                'date' => $key,
                'day' => $cursor->format('l'),
                'collection' => $entry?->collection,
                'zocket_meta_ads_without_gst' => $entry?->zocket_meta_ads_without_gst,
                'hima_creator' => $entry?->hima_creator,
                'g_ads_1_without_gst' => $entry?->g_ads_1_without_gst,
                'g_ads_2_without_gst' => $entry?->g_ads_2_without_gst,
                'payout' => $entry?->payout,
                'day0_revenue' => $entry?->day0_revenue,
                'notes' => $entry?->notes,
                'suggested_collection' => $payout ? (float) $payout->revenue : null,
                'suggested_payout' => $payout ? (float) $payout->payout_paid : null,
                'updated_by' => $entry?->updated_by,
                'updated_at' => optional($entry?->updated_at)->toIso8601String(),
            ];
            $cursor->addDay();
        }

        return response()->json([
            'ok' => true,
            'month' => $start->format('Y-m'),
            'can_edit' => $this->isEditor($request->user()->id),
            'rows' => $rows,
        ]);
    }

    /**
     * PUT /api/hima-revenue-sheet/{date}
     * Upserts the manual fields for the row.
     */
    public function update(Request $request, string $date): JsonResponse
    {
        $this->ensureAccess($request, allowViewer: false);

        try {
            $parsed = Carbon::parse($date)->toDateString();
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Invalid date'], 422);
        }

        $data = $request->validate([
            'collection' => 'nullable|numeric|min:0',
            'zocket_meta_ads_without_gst' => 'nullable|numeric|min:0',
            'hima_creator' => 'nullable|numeric|min:0',
            'g_ads_1_without_gst' => 'nullable|numeric|min:0',
            'g_ads_2_without_gst' => 'nullable|numeric|min:0',
            'payout' => 'nullable|numeric|min:0',
            'day0_revenue' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
        ]);

        $entry = HimaRevenueEntry::firstOrNew(['date' => $parsed]);
        foreach ($data as $field => $value) {
            $entry->$field = $value;
        }
        $entry->updated_by = $request->user()->id;
        $entry->save();

        return response()->json([
            'ok' => true,
            'date' => $parsed,
            'entry' => $entry->only(array_merge(HimaRevenueEntry::MANUAL_FIELDS, ['notes', 'updated_by', 'updated_at'])),
        ]);
    }

    /**
     * GET /api/hima-revenue-sheet/months
     * Returns YYYY-MM strings for every month with at least one entry,
     * plus the current month so the strip always has a default tab.
     */
    public function months(Request $request): JsonResponse
    {
        $this->ensureAccess($request, allowViewer: true);

        $months = HimaRevenueEntry::query()
            ->selectRaw("DATE_FORMAT(date, '%Y-%m') as ym")
            ->distinct()
            ->orderBy('ym')
            ->pluck('ym')
            ->all();

        $current = Carbon::now('Asia/Kolkata')->format('Y-m');
        if (! in_array($current, $months, true)) {
            $months[] = $current;
        }
        sort($months);

        return response()->json(['ok' => true, 'months' => $months]);
    }

    private function isEditor(int $userId): bool
    {
        return in_array($userId, config('hima_revenue.editors', []), true);
    }

    private function isViewer(int $userId): bool
    {
        return in_array($userId, config('hima_revenue.viewers', []), true);
    }

    private function ensureAccess(Request $request, bool $allowViewer): void
    {
        $userId = $request->user()->id;
        if ($this->isEditor($userId)) {
            return;
        }
        if ($allowViewer && $this->isViewer($userId)) {
            return;
        }
        abort(403, 'Forbidden');
    }

    private function parseMonth(string $value): Carbon
    {
        if (preg_match('/^\d{4}-\d{2}$/', $value)) {
            return Carbon::createFromFormat('Y-m', $value, 'Asia/Kolkata')->startOfMonth();
        }

        return Carbon::now('Asia/Kolkata')->startOfMonth();
    }
}
