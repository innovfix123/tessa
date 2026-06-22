<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\KpiMonthlySummary;
use App\Models\KpiScorecardItem;
use App\Models\KpiWeeklyReport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * KPI Report — managers add a short weekly tracking note per KPI (Fri–Mon,
 * never locked); employees view their own KPIs/notes/AI summary read-only;
 * JP (admin) views everyone and manages KPI definitions.
 *
 * Filler of a subject's notes = the subject's reporting_manager_id, unless
 * config('kpi_report.filler_overrides') reroutes it (JP fills Bala/Nandha/Ayush).
 */
class KpiReportController extends Controller
{
    private const RECENT_WEEKS = 8;
    private const RECENT_MONTHS = 6;

    /* ───────────────────────── helpers ───────────────────────── */

    private function isAdmin(User $u): bool
    {
        return in_array((int) $u->id, array_map('intval', (array) config('kpi_report.admin_user_ids', [])), true);
    }

    /**
     * The "report Friday" for a given day — the most recent Friday on or before
     * it. The fill window is Fri→Mon (4 days), so every day in that span anchors
     * to the SAME Friday: Fri→itself, Sat→−1, Sun→−2, Mon→−3. (On Tue–Thu, when
     * the window is shut, this returns the just-closed week's Friday.) Anchoring
     * this way is what lets Monday fill the week that just ended, not the next.
     */
    private function kpiFriday(Carbon $d): Carbon
    {
        return $d->copy()->subDays(($d->dayOfWeek - Carbon::FRIDAY + 7) % 7)->startOfDay();
    }

    private function currentFriday(): string
    {
        return $this->kpiFriday(Carbon::now('Asia/Kolkata'))->format('Y-m-d');
    }

    /**
     * Is the KPI edit window open right now? Normally Fri→Mon for everyone. A
     * per-user one-off grace in config('kpi_report.window_extensions') can let a
     * specific filler keep editing through the END of a given date (e.g. Krishnan
     * gets an extra day). Self-expiring once that date passes.
     */
    private function isWindowOpen(?User $u = null): bool
    {
        $now = Carbon::now('Asia/Kolkata');
        if (in_array($now->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY, Carbon::SUNDAY, Carbon::MONDAY], true)) {
            return true;
        }
        if ($u) {
            $ext = (array) config('kpi_report.window_extensions', []);
            if (isset($ext[$u->id])) {
                return $now->lte(Carbon::parse($ext[$u->id], 'Asia/Kolkata')->endOfDay());
            }
        }
        return false;
    }

    /** Fridays a manager may currently edit: this week + the overdue lookback. */
    private function editableWeekKeys(): array
    {
        $friday = $this->kpiFriday(Carbon::now('Asia/Kolkata'));
        $weeks = [$friday->format('Y-m-d')];
        $lookback = max(0, (int) config('review.overdue_lookback_weeks', 1));
        for ($i = 1; $i <= $lookback; $i++) {
            $weeks[] = $friday->copy()->subWeeks($i)->format('Y-m-d');
        }
        return $weeks;
    }

    private function weekFridayOf(string $dateStr): string
    {
        return $this->kpiFriday(Carbon::createFromFormat('Y-m-d', $dateStr, 'Asia/Kolkata'))->format('Y-m-d');
    }

    private function canView(User $viewer, User $subject): bool
    {
        if (! KpiScorecardItem::isEligibleSubject($subject)) {
            return false;
        }
        return $viewer->id === $subject->id
            || KpiScorecardItem::fillerIdFor($subject) === (int) $viewer->id
            || $this->isAdmin($viewer);
    }

    private function canEdit(User $viewer, User $subject): bool
    {
        return KpiScorecardItem::isEligibleSubject($subject)
            && KpiScorecardItem::fillerIdFor($subject) === (int) $viewer->id;
    }

    private function fmtWeek(string $weekKey): string
    {
        $f = Carbon::parse($weekKey);
        return $f->copy()->subDays(4)->format('M j') . ' – ' . $f->format('M j');
    }

    /* ───────────────────────── endpoints ───────────────────────── */

    /**
     * GET /api/kpi-report/people
     * Viewer-scoped: me (always), team (subjects I fill), all (admin only).
     */
    public function people(Request $request): JsonResponse
    {
        $user = $request->user();
        $weekKey = $this->currentFriday();

        $me = [
            'id'      => $user->id,
            'name'    => $user->name,
            'hasKpis' => KpiScorecardItem::where('user_id', $user->id)->where('is_active', true)->exists(),
        ];

        // Team: subjects whose KPI notes this user fills (+ this week's progress).
        $teamSubjects = KpiScorecardItem::fillableSubjectsFor($user);
        $team = $teamSubjects->map(function (User $s) use ($weekKey) {
            $itemCount = KpiScorecardItem::where('user_id', $s->id)->where('is_active', true)->count();
            $filled = KpiWeeklyReport::where('user_id', $s->id)
                ->where('week_key', $weekKey)
                ->whereNotNull('report_text')
                ->where('report_text', '!=', '')
                ->count();
            return [
                'id'          => $s->id,
                'name'        => $s->name,
                'role'        => $s->roleRelation?->name ?? $s->role,
                'itemCount'   => $itemCount,
                'weekFilled'  => $itemCount > 0 && $filled >= $itemCount,
                'weekPartial' => $filled > 0 && $filled < $itemCount,
            ];
        })->values();

        // All eligible subjects with KPIs (admin only).
        $all = [];
        if ($this->isAdmin($user)) {
            $ids = KpiScorecardItem::where('is_active', true)->distinct()->pluck('user_id');
            $all = User::whereIn('id', $ids)->with('roleRelation:id,slug,name')->orderBy('name')->get()
                ->filter(fn (User $u) => KpiScorecardItem::isEligibleSubject($u))
                ->map(fn (User $u) => [
                    'id'   => $u->id,
                    'name' => $u->name,
                    'role' => $u->roleRelation?->name ?? $u->role,
                ])->values();
        }

        return response()->json([
            'ok'           => true,
            'weekKey'      => $weekKey,
            'weekLabel'    => $this->fmtWeek($weekKey),
            'isWindowOpen' => $this->isWindowOpen($user),
            'isAdmin'      => $this->isAdmin($user),
            'me'           => $me,
            'team'         => $team,
            'all'          => $all,
        ]);
    }

    /**
     * GET /api/kpi-report/pending
     * Dashboard-card data: the team members whose KPI weekly notes THIS manager
     * still has to fill for the current week. Returns rows ONLY inside the
     * Fri–Mon editing window (notes aren't fillable otherwise), so the card
     * appears on the same days the Slack nudge fires. The pending computation
     * mirrors notify:kpi-report exactly so the card and the DM never disagree.
     */
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();
        $weekKey = $this->currentFriday();

        if (! $this->isWindowOpen($user)) {
            return response()->json(['ok' => true, 'items' => [], 'weekKey' => $weekKey, 'weekLabel' => $this->fmtWeek($weekKey)]);
        }

        $items = [];
        foreach (KpiScorecardItem::fillableSubjectsFor($user, $weekKey) as $s) {
            $itemCount = KpiScorecardItem::where('user_id', $s->id)->where('is_active', true)->count();
            if ($itemCount === 0) {
                continue;
            }
            $filled = KpiWeeklyReport::where('user_id', $s->id)
                ->where('week_key', $weekKey)
                ->whereNotNull('report_text')
                ->where('report_text', '!=', '')
                ->count();
            if ($filled >= $itemCount) {
                continue; // fully filled — nothing to nag about
            }
            $items[] = [
                'id'        => $s->id,
                'name'      => $s->name,
                'itemCount' => $itemCount,
                'filled'    => $filled,
                'partial'   => $filled > 0,
            ];
        }

        return response()->json([
            'ok'        => true,
            'weekKey'   => $weekKey,
            'weekLabel' => $this->fmtWeek($weekKey),
            'items'     => $items,
        ]);
    }

    /**
     * GET /api/kpi-report/user/{id}
     * Full scorecard for one subject: KPI items, recent weekly notes, AI summaries.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $viewer = $request->user();
        $subject = User::find($id);
        if (! $subject) {
            return response()->json(['error' => 'Not found'], 404);
        }
        if (! $this->canView($viewer, $subject)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $items = KpiScorecardItem::where('user_id', $subject->id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name', 'description', 'target', 'weight', 'sort_order']);

        // Recent weekly notes grouped by week.
        $weekKeys = KpiWeeklyReport::where('user_id', $subject->id)
            ->select('week_key')->distinct()
            ->orderByDesc('week_key')->limit(self::RECENT_WEEKS)
            ->pluck('week_key')->map(fn ($w) => Carbon::parse($w)->format('Y-m-d'))->values();

        $reports = $weekKeys->isEmpty() ? collect() : KpiWeeklyReport::where('user_id', $subject->id)
            ->whereIn('week_key', $weekKeys->all())->get();

        $weeks = $weekKeys->map(function ($wk) use ($reports) {
            $entries = [];
            foreach ($reports->where('week_key', $wk) as $r) {
                $entries[$r->kpi_item_id] = [
                    'text'        => $r->report_text,
                    'submittedAt' => $r->submitted_at?->toIso8601String(),
                ];
            }
            return ['weekKey' => $wk, 'label' => $this->fmtWeek($wk), 'entries' => $entries];
        })->values();

        // Recent monthly AI summaries grouped by month.
        $summaries = KpiMonthlySummary::where('user_id', $subject->id)
            ->orderByDesc('month_key')->get();
        // KPI weight map (id => weight) so the overall % is weighted by each KPI's
        // scorecard weight, not a flat average — a 35-weight KPI moves the overall
        // attainment more than a 20-weight one.
        $weightMap = $items->pluck('weight', 'id');

        $months = $summaries->groupBy('month_key')->take(self::RECENT_MONTHS)->map(function ($rows, $mk) use ($weightMap) {
            $perItem = [];
            $overall = null;
            foreach ($rows as $r) {
                $payload = [
                    'summary'       => $r->summary_text,
                    'percentageMet' => $r->percentage_met,
                    'status'        => $r->status,
                ];
                if ($r->kpi_item_id === null) {
                    $overall = $payload;
                } else {
                    $perItem[$r->kpi_item_id] = $payload;
                }
            }

            // Weighted average of per-KPI attainment (KPIs with no weight count as 1).
            $acc = 0;
            $wsum = 0;
            foreach ($perItem as $itemId => $pi) {
                if ($pi['percentageMet'] === null) {
                    continue;
                }
                $w = (int) ($weightMap[$itemId] ?? 0);
                if ($w <= 0) {
                    $w = 1;
                }
                $acc += $w * $pi['percentageMet'];
                $wsum += $w;
            }

            return [
                'monthKey'   => $mk,
                'label'      => Carbon::parse($mk . '-01')->format('M Y'),
                'overall'    => $overall,
                'overallPct' => $wsum > 0 ? (int) round($acc / $wsum) : null,
                'perItem'    => $perItem,
            ];
        })->values();

        return response()->json([
            'ok'           => true,
            'subject'      => ['id' => $subject->id, 'name' => $subject->name, 'role' => $subject->roleRelation?->name ?? $subject->role],
            'weekKey'      => $this->currentFriday(),
            'isWindowOpen' => $this->isWindowOpen($viewer),
            'editableWeeks'=> $this->editableWeekKeys(),
            'canEdit'      => $this->canEdit($viewer, $subject),
            'items'        => $items,
            'weeks'        => $weeks,
            'months'       => $months,
        ]);
    }

    /**
     * POST /api/kpi-report/user/{id}/week
     * Body: { week_key, items:[{ kpi_item_id, report_text }] }
     * Manager (the resolved filler) upserts weekly notes. Editable Fri–Mon only,
     * never locked (re-saveable). week_key must be current or within lookback.
     */
    public function saveWeek(Request $request, int $id): JsonResponse
    {
        $viewer = $request->user();
        $subject = User::find($id);
        if (! $subject) {
            return response()->json(['error' => 'Not found'], 404);
        }
        if (! $this->canEdit($viewer, $subject)) {
            return response()->json(['error' => 'You do not fill this person\'s KPI report.'], 403);
        }
        if (! $this->isWindowOpen($viewer)) {
            return response()->json(['error' => 'KPI reports can only be edited on Fri–Mon.'], 403);
        }

        $validated = $request->validate([
            'week_key'             => ['required', 'date_format:Y-m-d'],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.kpi_item_id'  => ['required', 'integer'],
            'items.*.report_text'  => ['nullable', 'string', 'max:5000'],
        ]);

        $weekFriday = $this->weekFridayOf($validated['week_key']);
        if (! in_array($weekFriday, $this->editableWeekKeys(), true)) {
            return response()->json(['error' => 'That week is no longer editable.'], 403);
        }

        // Every kpi_item_id must be an ACTIVE KPI of this subject.
        $allowedItemIds = KpiScorecardItem::where('user_id', $subject->id)
            ->where('is_active', true)->pluck('id')->map(fn ($i) => (int) $i)->all();
        foreach ($validated['items'] as $it) {
            if (! in_array((int) $it['kpi_item_id'], $allowedItemIds, true)) {
                return response()->json(['error' => "KPI {$it['kpi_item_id']} is not a KPI of this person."], 422);
            }
        }

        DB::transaction(function () use ($viewer, $subject, $weekFriday, $validated) {
            foreach ($validated['items'] as $it) {
                $row = KpiWeeklyReport::firstOrNew([
                    'kpi_item_id' => (int) $it['kpi_item_id'],
                    'week_key'    => $weekFriday,
                ]);
                $row->user_id     = $subject->id;
                $row->manager_id  = $viewer->id;
                $row->report_text = $it['report_text'] ?? null;
                if (! $row->exists) {
                    $row->submitted_at = now();
                }
                $row->save();
            }
        });

        return response()->json(['ok' => true, 'weekKey' => $weekFriday]);
    }

    /* ──────────────── JP-only KPI definition management ──────────────── */

    public function storeItem(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->isAdmin($user)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $v = $request->validate([
            'user_id'     => ['required', 'integer', 'exists:users,id'],
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'target'      => ['nullable', 'string', 'max:255'],
            'weight'      => ['nullable', 'integer', 'between:0,100'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
        ]);

        $sort = $v['sort_order'] ?? ((int) KpiScorecardItem::where('user_id', $v['user_id'])->max('sort_order') + 1);
        $item = KpiScorecardItem::create([
            'user_id'     => $v['user_id'],
            'name'        => $v['name'],
            'description' => $v['description'] ?? null,
            'target'      => $v['target'] ?? null,
            'weight'      => $v['weight'] ?? null,
            'sort_order'  => $sort,
            'is_active'   => true,
            'created_by'  => $user->id,
        ]);

        return response()->json(['ok' => true, 'item' => $item]);
    }

    public function updateItem(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $this->isAdmin($user)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $item = KpiScorecardItem::find($id);
        if (! $item) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $v = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'target'      => ['nullable', 'string', 'max:255'],
            'weight'      => ['nullable', 'integer', 'between:0,100'],
            'sort_order'  => ['sometimes', 'integer', 'min:0'],
            'is_active'   => ['sometimes', 'boolean'],
        ]);
        $item->fill($v)->save();

        return response()->json(['ok' => true, 'item' => $item]);
    }

    /** Soft-deactivate (keeps weekly history + AI summaries intact). */
    public function destroyItem(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $this->isAdmin($user)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $item = KpiScorecardItem::find($id);
        if (! $item) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $item->is_active = false;
        $item->save();

        return response()->json(['ok' => true]);
    }
}
