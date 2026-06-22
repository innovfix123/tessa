<?php

namespace App\Http\Controllers\Api\HR;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\TimesheetNudgeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimesheetTrackerController extends Controller
{
    private TimesheetNudgeService $nudge;

    public function __construct()
    {
        $this->nudge = app(TimesheetNudgeService::class);
    }

    public function daily(Request $request): JsonResponse
    {
        $date = $request->query('date')
            ? Carbon::parse($request->query('date'))->startOfDay()
            : Carbon::today('Asia/Kolkata');

        $users = $this->trackedUsers();

        $sheetMap = Timesheet::where('work_date', $date->format('Y-m-d'))
            ->whereIn('user_id', $users->pluck('id'))
            ->get()
            ->keyBy('user_id');

        $submitted = [];
        $pending = [];
        foreach ($users as $u) {
            $s = $sheetMap->get($u->id);
            if ($s) {
                $submitted[] = [
                    'user' => ['id' => $u->id, 'name' => $u->name],
                    'total_hours' => (float) $s->total_hours,
                    'overtime_hours' => (float) $s->overtime_hours,
                    'amount' => (float) $s->amount,
                ];
            } else {
                $pending[] = [
                    'user' => ['id' => $u->id, 'name' => $u->name],
                ];
            }
        }

        return response()->json([
            'view' => 'daily',
            'date' => $date->format('Y-m-d'),
            'stats' => [
                'total' => $users->count(),
                'submitted' => count($submitted),
                'pending' => count($pending),
                'total_hours' => array_sum(array_column($submitted, 'total_hours')),
                'overtime_hours' => array_sum(array_column($submitted, 'overtime_hours')),
            ],
            'submitted' => $submitted,
            'pending' => $pending,
        ]);
    }

    public function weekly(Request $request): JsonResponse
    {
        $weekStart = $request->query('week')
            ? Carbon::parse($request->query('week'))->startOfWeek(Carbon::MONDAY)
            : Carbon::now('Asia/Kolkata')->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);

        // Pull every timesheet in the week with its slots — one query per table.
        // We narrow `trackedUsers()` to allowlist ∪ active loggers in the period,
        // so the page no longer lists the 40+ employees who don't use timesheets.
        $sheets = Timesheet::with('timeSlots')
            ->whereBetween('work_date', [$weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d')])
            ->orderBy('work_date')
            ->get();

        // Strict scope: only the OT-eligible allowlist (currently Fida).
        // No fallback to "anyone who logged" — voluntary OT is opt-in by allowlist only.
        $users = $this->trackedUsers();

        $perUser = [];
        foreach ($sheets as $s) {
            if (! $users->contains('id', $s->user_id)) {
                continue;
            }
            $uid = $s->user_id;
            $perUser[$uid]['days'] ??= [];
            $perUser[$uid]['days'][$s->work_date->format('Y-m-d')] = [
                'work_date' => $s->work_date->format('Y-m-d'),
                'total_hours' => (float) $s->total_hours,
                'regular_hours' => (float) $s->regular_hours,
                'overtime_hours' => (float) $s->overtime_hours,
                'amount' => (float) $s->amount,
                'slot_count' => $s->timeSlots->count(),
            ];
        }

        $rows = $users->map(function ($u) use ($perUser) {
            $days = $perUser[$u->id]['days'] ?? [];
            return [
                'user' => ['id' => $u->id, 'name' => $u->name],
                'days_worked' => count($days),
                'total_hours' => array_sum(array_column($days, 'total_hours')),
                'regular_hours' => array_sum(array_column($days, 'regular_hours')),
                'overtime_hours' => array_sum(array_column($days, 'overtime_hours')),
                'amount' => array_sum(array_column($days, 'amount')),
                'days' => array_values($days),
            ];
        })
        ->sortByDesc(fn ($r) => $r['total_hours'])
        ->values();

        $totals = [
            'total_users' => $users->count(),
            'days_logged' => $rows->sum('days_worked'),
            'total_hours' => round($rows->sum('total_hours'), 2),
            'regular_hours' => round($rows->sum('regular_hours'), 2),
            'overtime_hours' => round($rows->sum('overtime_hours'), 2),
            'amount' => round($rows->sum('amount'), 2),
        ];

        return response()->json([
            'view' => 'weekly',
            'week_start' => $weekStart->format('Y-m-d'),
            'week_end' => $weekEnd->format('Y-m-d'),
            'totals' => $totals,
            'rows' => $rows,
        ]);
    }

    public function monthly(Request $request): JsonResponse
    {
        $month = $request->query('month')
            ? Carbon::parse($request->query('month') . '-01')
            : Carbon::now('Asia/Kolkata')->startOfMonth();
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        $users = $this->trackedUsers();

        $perUser = Timesheet::whereBetween('work_date', [$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')])
            ->whereIn('user_id', $users->pluck('id'))
            ->select('user_id',
                \DB::raw('COUNT(*) as days_worked'),
                \DB::raw('SUM(total_hours) as total_hours'),
                \DB::raw('SUM(regular_hours) as regular_hours'),
                \DB::raw('SUM(overtime_hours) as overtime_hours'),
                \DB::raw('SUM(amount) as amount'))
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        return response()->json([
            'view' => 'monthly',
            'month' => $monthStart->format('Y-m'),
            'rows' => $users->map(function ($u) use ($perUser) {
                $r = $perUser->get($u->id);
                return [
                    'user' => ['id' => $u->id, 'name' => $u->name],
                    'days_worked' => $r ? (int) $r->days_worked : 0,
                    'total_hours' => $r ? (float) $r->total_hours : 0.0,
                    'regular_hours' => $r ? (float) $r->regular_hours : 0.0,
                    'overtime_hours' => $r ? (float) $r->overtime_hours : 0.0,
                    'amount' => $r ? (float) $r->amount : 0.0,
                ];
            })->values(),
        ]);
    }

    public function nudge(int $userId, Request $request): JsonResponse
    {
        $user = User::findOrFail($userId);
        $refDate = $request->input('date')
            ? Carbon::parse($request->input('date'))
            : Carbon::today('Asia/Kolkata');

        $sent = $this->nudge->nudgeUser($user, $refDate, $request->user());

        return response()->json([
            'ok' => $sent,
            'message' => $sent
                ? "Reminder DM sent to {$user->name}."
                : "Could not send reminder to {$user->name}. (May be in quiet hours, or no Slack ID.)",
        ]);
    }

    public function nudgeBulk(Request $request): JsonResponse
    {
        $request->validate([
            'date' => 'nullable|date',
            'cap' => 'nullable|integer|min:1|max:200',
        ]);

        $refDate = $request->input('date')
            ? Carbon::parse($request->input('date'))
            : Carbon::today('Asia/Kolkata');

        $users = $this->trackedUsers();

        // Filter to those who have NOT submitted for the ref date.
        $submittedIds = Timesheet::where('work_date', $refDate->format('Y-m-d'))
            ->whereIn('user_id', $users->pluck('id'))
            ->pluck('user_id')
            ->all();

        $pending = $users->reject(fn ($u) => in_array($u->id, $submittedIds, true))->values();

        $cap = (int) $request->input('cap', 50);
        $result = $this->nudge->nudgeBulk($pending, $refDate, $request->user(), $cap);

        return response()->json([
            'ok' => true,
            'pending_count' => $pending->count(),
            'sent' => $result['sent'],
            'failed' => $result['failed'],
            'failed_names' => $result['failed_names'],
            'message' => "Sent {$result['sent']} reminder(s). Failed: {$result['failed']}.",
        ]);
    }

    /**
     * Tracker visibility = self-log allowlist only (currently Fida).
     * The Workforce Weekly Summary is the historical/financial view that
     * shows everyone with rows; the tracker is forward-looking and only
     * lists people who are OT-eligible and expected to self-log.
     */
    private function trackedUsers()
    {
        $ids = config('timesheet_access.self_log_user_ids', []);
        if (empty($ids)) {
            return collect();
        }

        return User::whereIn('id', $ids)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'role_id']);
    }
}
