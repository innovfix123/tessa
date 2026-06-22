<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\ManagerWorkReview;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManagerReviewController extends Controller
{
    /**
     * GET /api/manager-review
     * Returns a list of pending review weeks for the current manager:
     *   - Current week (only during the Fri/Sat/Sun rating window)
     *   - Overdue past weeks within the lookback window where any subordinate is unrated
     * Each week disappears from this list as soon as the manager submits its ratings.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (ManagerWorkReview::rateableSubordinatesFor($user)->isEmpty()) {
            return response()->json(['error' => 'No rateable team members'], 403);
        }

        $now = Carbon::now('Asia/Kolkata');
        $currentFriday = $now->copy()->startOfWeek(Carbon::MONDAY)->addDays(4)->format('Y-m-d');
        $isReviewWindow = in_array($now->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY, Carbon::SUNDAY], true);

        $lookback = max(0, (int) config('review.overdue_lookback_weeks', 4));

        $candidateWeeks = [];
        if ($isReviewWindow) {
            $candidateWeeks[] = $currentFriday;
        }
        for ($i = 1; $i <= $lookback; $i++) {
            $candidateWeeks[] = $now->copy()->startOfWeek(Carbon::MONDAY)->addDays(4)->subWeeks($i)->format('Y-m-d');
        }

        if (empty($candidateWeeks)) {
            return response()->json(['ok' => true, 'weeks' => []]);
        }

        $existing = ManagerWorkReview::where('manager_id', $user->id)
            ->whereIn('week_key', $candidateWeeks)
            ->get()
            ->groupBy(fn ($r) => Carbon::parse($r->week_key)->format('Y-m-d'));

        $weeksOut = [];
        foreach ($candidateWeeks as $weekKey) {
            // Administratively waived weeks (e.g. retired after a mid-week reorg)
            // surface no card at all.
            if (ManagerWorkReview::isSkippedWeek($weekKey)) {
                continue;
            }

            $weekRateables = ManagerWorkReview::rateableSubordinatesFor($user, $weekKey);
            if ($weekRateables->isEmpty()) {
                continue;
            }

            $records = $existing->get($weekKey, collect())->keyBy('subordinate_id');

            $allSubmitted = $weekRateables->every(fn (User $sub) => (bool) $records->get($sub->id));
            if ($allSubmitted) {
                continue;
            }

            $friday = Carbon::parse($weekKey);
            $weeksOut[] = [
                'weekKey'      => $weekKey,
                'weekLabel'    => $friday->format('M j, Y'),
                'weekRange'    => $friday->copy()->subDays(4)->format('M j') . ' – ' . $friday->format('M j'),
                'isOverdue'    => $weekKey !== $currentFriday,
                'subordinates' => $weekRateables->map(function (User $sub) use ($records) {
                    $row = $records->get($sub->id);

                    return [
                        'id'                 => $sub->id,
                        'name'               => $sub->name,
                        'role'               => $sub->roleRelation?->name ?? $sub->role,
                        'alreadyRated'       => (bool) $row,
                        'submittedAt'        => $row?->submitted_at?->toIso8601String(),
                        // Existing stars for already-rated reports so the UI can
                        // show them pre-filled + locked (immutable) instead of
                        // making the manager blindly re-rate them.
                        'ratingDeliverables' => $row?->rating_deliverables,
                        'ratingQuality'      => $row?->rating_quality,
                    ];
                })->values(),
            ];
        }

        // Newest pending week first so the most recent "this week" or just-missed
        // card is the one a manager sees on top.
        usort($weeksOut, fn ($a, $b) => strcmp($b['weekKey'], $a['weekKey']));

        return response()->json([
            'ok'    => true,
            'weeks' => $weeksOut,
        ]);
    }

    /**
     * POST /api/manager-review
     * Body: { week_key: 'YYYY-MM-DD', items: [{ subordinate_id, rating, feedback? }] }
     * Upserts per (manager_id, subordinate_id, week_key).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'week_key'                    => ['required', 'date_format:Y-m-d'],
            'items'                       => ['required', 'array', 'min:1'],
            'items.*.subordinate_id'      => ['required', 'integer'],
            'items.*.rating_deliverables' => ['required', 'integer', 'between:1,5'],
            'items.*.rating_quality'      => ['required', 'integer', 'between:1,5'],
        ]);

        $weekFriday = $this->resolveWeekFriday($validated['week_key']);
        $rateables = ManagerWorkReview::rateableSubordinatesFor($user, $weekFriday);

        if ($rateables->isEmpty()) {
            return response()->json(['error' => 'No rateable team members'], 403);
        }

        $allowedIds = $rateables->pluck('id')->all();

        // A subordinate already rated this week is immutable — we never edit an
        // existing rating. But the submission must stay ADDITIVE per subordinate:
        // if an earlier submission only covered part of the roster (e.g. the team
        // was reorganised after the manager first submitted, so a transferred-in
        // report now appears unrated), the manager must be able to rate the rest.
        // A blanket "any row exists ⇒ locked" check would dead-lock that week —
        // it can never be completed, yet stays perpetually pending/overdue and
        // blocks Friday sign-off. This matches index()/SignoffStatusService, which
        // both gate on per-subordinate completeness, not on row existence.
        $alreadyRatedIds = ManagerWorkReview::where('manager_id', $user->id)
            ->where('week_key', $weekFriday)
            ->pluck('subordinate_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Guard: every subordinate_id in the payload must actually be a rateable direct report.
        foreach ($validated['items'] as $it) {
            if (! in_array((int) $it['subordinate_id'], $allowedIds, true)) {
                return response()->json([
                    'error' => "User {$it['subordinate_id']} is not a rateable subordinate for you.",
                ], 403);
            }
        }

        // Insert only the not-yet-rated subordinates; silently skip any already
        // submitted this week (their stars can't be changed).
        $toInsert = array_values(array_filter(
            $validated['items'],
            fn ($it) => ! in_array((int) $it['subordinate_id'], $alreadyRatedIds, true)
        ));

        if (empty($toInsert)) {
            return response()->json(['error' => 'Reviews already submitted for this week and cannot be edited.'], 403);
        }

        DB::transaction(function () use ($user, $weekFriday, $toInsert) {
            foreach ($toInsert as $it) {
                ManagerWorkReview::create([
                    'manager_id'          => $user->id,
                    'subordinate_id'      => (int) $it['subordinate_id'],
                    'week_key'            => $weekFriday,
                    'rating_deliverables' => (int) $it['rating_deliverables'],
                    'rating_quality'      => (int) $it['rating_quality'],
                    'submitted_at'        => now(),
                ]);
            }
        });

        return response()->json(['ok' => true, 'weekKey' => $weekFriday]);
    }

    /**
     * GET /api/manager-ratings/overview
     * CEO-only. Lists every manager with their direct reports and the D/Q ratings
     * each subordinate received per submitted review week.
     */
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || $user->role !== Role::SLUG_CEO) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $weeksLimit = max(1, min(12, (int) $request->query('weeks', 6)));

        // Only show weeks that have fully closed. The rating window is Fri–Sun,
        // so a week becomes visible on the following Monday (Friday + 3 days).
        $visibleThrough = Carbon::now('Asia/Kolkata')->startOfDay()->subDays(3)->format('Y-m-d');

        $weeks = ManagerWorkReview::select('week_key')
            ->distinct()
            ->where('week_key', '<=', $visibleThrough)
            ->orderByDesc('week_key')
            ->limit($weeksLimit)
            ->pluck('week_key')
            ->map(fn ($w) => Carbon::parse($w)->format('Y-m-d'))
            ->values();

        $weekLabels = $weeks->map(function ($w) {
            $d = Carbon::parse($w);
            return [
                'key'   => $w,
                'label' => 'W/O ' . $d->copy()->subDays(4)->format('M j'),
                'short' => $d->format('M j'),
            ];
        })->values();

        $managers = User::where('is_active', true)
            ->isRatingManager()
            ->with('roleRelation:id,name,slug')
            ->orderBy('name')
            ->get();

        if ($weeks->isEmpty() || $managers->isEmpty()) {
            return response()->json([
                'ok'       => true,
                'weeks'    => $weekLabels,
                'managers' => [],
            ]);
        }

        $reviews = ManagerWorkReview::whereIn('week_key', $weeks)
            ->get()
            ->groupBy(fn ($r) => $r->manager_id . ':' . $r->subordinate_id . ':' . Carbon::parse($r->week_key)->format('Y-m-d'));

        $managersOut = [];
        foreach ($managers as $mgr) {
            $rateables = ManagerWorkReview::rateableSubordinatesFor($mgr)->load('roleRelation:id,name,slug');
            if ($rateables->isEmpty()) {
                continue;
            }

            $subs = [];
            foreach ($rateables as $sub) {
                $cells = [];
                foreach ($weeks as $wk) {
                    $key = $mgr->id . ':' . $sub->id . ':' . $wk;
                    $row = $reviews->get($key)?->first();
                    $cells[$wk] = $row ? [
                        'd' => (int) $row->rating_deliverables,
                        'q' => (int) $row->rating_quality,
                        'submittedAt' => $row->submitted_at?->toIso8601String(),
                    ] : null;
                }
                $subs[] = [
                    'id'   => $sub->id,
                    'name' => $sub->name,
                    'role' => $sub->roleRelation?->name ?? $sub->role,
                    'cells'=> $cells,
                ];
            }

            $managersOut[] = [
                'id'           => $mgr->id,
                'name'         => $mgr->name,
                'role'         => $mgr->roleRelation?->name ?? $mgr->role,
                'subordinates' => $subs,
            ];
        }

        return response()->json([
            'ok'       => true,
            'weeks'    => $weekLabels,
            'managers' => $managersOut,
        ]);
    }

    /**
     * Resolve the Friday of the target week in IST. If an input is given, use the
     * Friday of THAT week; if not, today's week's Friday.
     */
    private function resolveWeekFriday(?string $dateStr): string
    {
        $anchor = $dateStr
            ? Carbon::createFromFormat('Y-m-d', $dateStr, 'Asia/Kolkata')
            : Carbon::now('Asia/Kolkata');

        return $anchor->startOfWeek(Carbon::MONDAY)->addDays(4)->format('Y-m-d');
    }
}
