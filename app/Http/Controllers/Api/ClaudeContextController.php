<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClaudeContext;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\UserFeatureService;
use App\Support\DailyReportsAccess;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Claude Context" — one immutable end-of-day summary per employee per day,
 * pushed in by Claude over MCP (App\Mcp\Tools\LogClaudeContextTool). The store
 * action is the ONLY writer; there is no UI form, so employees cannot create or
 * edit a context. Employees read their own; the JP overview allowlist
 * (config/claude_context.php) reads everyone's and can reset a bad day.
 */
class ClaudeContextController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'summary' => 'required|string|min:10|max:20000',
            'categories' => 'nullable|array',
            'categories.*' => 'string|max:50',
        ]);

        $user = $request->user();
        $date = Carbon::today('Asia/Kolkata')->toDateString();

        // Write-once lock: a second push the same day is rejected, not merged.
        $existing = ClaudeContext::where('user_id', $user->id)
            ->where('context_date', $date)
            ->first();
        if ($existing) {
            return response()->json([
                'already_logged' => true,
                'message' => "Today's Claude context is already recorded and locked — it can't be changed.",
                'context' => $this->format($existing),
            ]);
        }

        $context = ClaudeContext::create([
            'user_id' => $user->id,
            'context_date' => $date,
            'summary' => trim($data['summary']),
            'categories' => $data['categories'] ?? null,
            'source' => 'mcp',
        ]);

        return response()->json([
            'message' => "Saved your Claude context for {$date}. It is now locked for the day.",
            'context' => $this->format($context),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $canSeeAll = $this->canSeeAll($user);

        $query = ClaudeContext::query()
            ->with('user:id,name')
            ->orderByDesc('context_date')
            ->orderByDesc('id');

        if ($canSeeAll) {
            if ($request->filled('user_id')) {
                $query->where('user_id', (int) $request->query('user_id'));
            }
            if ($request->filled('date')) {
                $query->where('context_date', $request->query('date'));
            }
        } else {
            $query->where('user_id', $user->id);
        }

        $entries = $query->limit(1500)->get()
            ->map(fn (ClaudeContext $c) => $this->format($c, $canSeeAll));

        $payload = [
            'can_see_all' => $canSeeAll,
            'today' => Carbon::today('Asia/Kolkata')->toDateString(),
            'entries' => $entries->values(),
        ];

        // Overview gets the full active roster so non-adopters (people who have
        // never logged a context) are visible, not silently dropped. id 33 is the
        // non-human "Admin" system account — exclude it so the count is the real
        // headcount. logged_user_ids = everyone who has EVER logged, so the UI can
        // tell "lapsed" (has old entries beyond the row cap) from "never used".
        if ($canSeeAll && ! $request->filled('user_id') && ! $request->filled('date')) {
            $payload['employees'] = User::where('is_active', 1)
                ->where('id', '!=', 33)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
                ->values();
            $payload['logged_user_ids'] = ClaudeContext::distinct()
                ->pluck('user_id')
                ->map(fn ($v) => (int) $v)
                ->values();
        }

        return response()->json($payload);
    }

    /**
     * Past working days THIS WEEK (up to yesterday) on which the employee has no
     * Claude Context logged — drives the dashboard "Claude Context pending" card.
     * Only for employees whose end-of-day obligation is the Claude Context
     * summary (i.e. NOT the Daily Reports allow-list) and who have the feature.
     * Mirrors DailyReportController::pendingDays (same leave/holiday waivers and
     * { date, dayLabel, isOverdue } shape) so the dashboard renders it the same.
     */
    public function pendingDays(Request $request): JsonResponse
    {
        $user = $request->user();

        // Allow-list users still fill Daily Reports — their pending card is the
        // daily-report one, not this. Users without the feature can't log context.
        if (DailyReportsAccess::enabledFor($user)
            || ! in_array('claude_context', UserFeatureService::featuresFor($user), true)) {
            return response()->json(['ok' => true, 'items' => []]);
        }

        $userId = $user->id;
        $now = Carbon::today('Asia/Kolkata');
        $weekStart = $now->copy()->startOfWeek(Carbon::MONDAY);
        $yesterdayIndex = min($now->dayOfWeekIso - 2, 4); // up to yesterday only, cap at Fri
        if ($yesterdayIndex < 0) {
            return response()->json(['ok' => true, 'items' => []]); // Monday — nothing past yet
        }
        $rangeEnd = $weekStart->copy()->addDays($yesterdayIndex)->toDateString();

        // Don't flag days before the rollback date — Claude Context only became
        // the tracked obligation then (Daily Reports were the rule before it).
        $rolloutFrom = (string) config('daily_reports_access.claude_context_kra_from', '');
        $holidays = config('holidays', []);

        // Approved full-day leave (not WFH/Permission, which are working days)
        // means no context was expected, so that day isn't pending.
        $leaveRows = LeaveRequest::where('user_id', $userId)
            ->where('status', 'approved')
            ->whereHas('leaveType', fn ($q) => $q->whereNotIn('slug', ['wfh', 'permission']))
            ->where('start_date', '<=', $rangeEnd)
            ->where('end_date', '>=', $weekStart->toDateString())
            ->get(['start_date', 'end_date']);
        $leaveBounds = $leaveRows->map(function ($lr) {
            $s = $lr->start_date instanceof \Carbon\CarbonInterface ? $lr->start_date->toDateString() : (string) $lr->start_date;
            $e = $lr->end_date instanceof \Carbon\CarbonInterface ? $lr->end_date->toDateString() : (string) $lr->end_date;
            return [substr($s, 0, 10), substr($e, 0, 10)];
        })->all();

        $loggedDates = ClaudeContext::where('user_id', $userId)
            ->whereBetween('context_date', [$weekStart->toDateString(), $rangeEnd])
            ->pluck('context_date')
            ->map(fn ($d) => $d instanceof \Carbon\CarbonInterface ? $d->toDateString() : substr((string) $d, 0, 10))
            ->all();

        $pending = [];
        for ($i = 0; $i <= $yesterdayIndex; $i++) {
            $date = $weekStart->copy()->addDays($i);
            $dateStr = $date->format('Y-m-d');

            if ($rolloutFrom !== '' && $dateStr < $rolloutFrom) {
                continue;
            }
            if (array_key_exists($dateStr, $holidays)) {
                continue;
            }
            $onLeave = false;
            foreach ($leaveBounds as [$s, $e]) {
                if ($dateStr >= $s && $dateStr <= $e) {
                    $onLeave = true;
                    break;
                }
            }
            if ($onLeave) {
                continue;
            }
            if (in_array($dateStr, $loggedDates, true)) {
                continue;
            }
            $pending[] = [
                'date' => $dateStr,
                'dayLabel' => $date->format('l, d M'),
                'isOverdue' => true,
            ];
        }

        return response()->json(['ok' => true, 'items' => $pending]);
    }

    /**
     * Reset a day's context (JP only) so a genuinely bad summary can be
     * re-pushed. This is the only mutation besides the MCP write — employees
     * have no edit/delete path anywhere.
     */
    public function destroy(Request $request, ClaudeContext $claudeContext): JsonResponse
    {
        if (! $this->canSeeAll($request->user())) {
            abort(403);
        }
        $claudeContext->delete();

        return response()->json(['deleted' => true]);
    }

    private function canSeeAll(User $user): bool
    {
        return in_array(
            (int) $user->id,
            array_map('intval', (array) config('claude_context.overview_user_ids', [1])),
            true
        );
    }

    private function format(ClaudeContext $c, bool $withUser = false): array
    {
        $out = [
            'id' => $c->id,
            'date' => $c->context_date->toDateString(),
            'summary' => $c->summary,
            'categories' => $c->categories ?? [],
            'created_at' => $c->created_at?->utc()->toIso8601String(),
        ];
        if ($withUser) {
            $out['user_id'] = $c->user_id;
            $out['user_name'] = $c->user?->name;
        }

        return $out;
    }
}
