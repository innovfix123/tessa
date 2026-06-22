<?php

namespace App\Http\Controllers\Api\HR;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\IssuedLetter;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HRDashboardController extends Controller
{
    private const ALLOWED_ROLES = [
        Role::SLUG_CEO,
        Role::SLUG_COO,
        Role::SLUG_CFO,
        Role::SLUG_HR,
        Role::SLUG_HR_OPERATIONS,
        Role::SLUG_BUSINESS_ANALYST,
    ];

    private const SALARY_ROLES = [
        Role::SLUG_CEO,
        Role::SLUG_CFO,
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, self::ALLOWED_ROLES, true)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $now = Carbon::now('Asia/Kolkata');
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $monthEnd = $now->copy()->endOfMonth()->toDateString();
        $thirtyDaysFromNow = $now->copy()->addDays(30)->toDateString();
        $today = $now->toDateString();

        $allUsers = User::with(['roleRelation', 'department', 'reportingManager:id,name'])
            ->where('id', '!=', 33)
            ->get();

        $activeUsers = $allUsers->filter(fn ($u) => $u->is_active);

        // Headcount by status
        $statusCounts = [
            'active' => 0,
            'probation' => 0,
            'intern' => 0,
            'notice_period' => 0,
            'resigned' => 0,
            'terminated' => 0,
            'absconding' => 0,
            'exited' => 0,
        ];
        foreach ($allUsers as $u) {
            $status = $u->employee_status ?? 'active';
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }
        }

        // Headcount by employment type
        $typeCounts = [
            'full_time' => $activeUsers->where('employment_type', 'full_time')->count(),
            'internship' => $activeUsers->where('employment_type', 'internship')->count(),
            'freelancer' => $activeUsers->where('employment_type', 'freelancer')->count(),
        ];

        // Joiners this month
        $joiners = $allUsers->filter(fn ($u) => $u->joining_date && $u->joining_date >= $monthStart && $u->joining_date <= $monthEnd)
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->roleRelation?->name ?? '',
                'department' => $u->department?->name ?? '',
                'joining_date' => $u->joining_date?->format('Y-m-d'),
                'employment_type' => $u->employment_type,
            ])->values();

        // Leavers this month
        $leavers = $allUsers->filter(fn ($u) => $u->exit_date && $u->exit_date >= $monthStart && $u->exit_date <= $monthEnd)
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->roleRelation?->name ?? '',
                'exit_date' => $u->exit_date?->format('Y-m-d'),
                'exit_reason' => $u->exit_reason,
                'employee_status' => $u->employee_status,
            ])->values();

        // Probation ending soon (within 30 days)
        $probationAlerts = $activeUsers->filter(fn ($u) => $u->employee_status === 'probation' && $u->probation_end_date && $u->probation_end_date <= $thirtyDaysFromNow)
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->roleRelation?->name ?? '',
                'probation_end_date' => $u->probation_end_date?->format('Y-m-d'),
                'days_remaining' => max(0, (int) $now->diffInDays($u->probation_end_date, false)),
            ])->sortBy('days_remaining')->values();

        // Intern conversion due (internship ending within 30 days)
        $internAlerts = $activeUsers->filter(fn ($u) => $u->employee_status === 'intern' && $u->internship_end_date && $u->internship_end_date <= $thirtyDaysFromNow)
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->roleRelation?->name ?? '',
                'internship_end_date' => $u->internship_end_date?->format('Y-m-d'),
                'days_remaining' => max(0, (int) $now->diffInDays($u->internship_end_date, false)),
            ])->sortBy('days_remaining')->values();

        // ── Probation lifecycle tracker ─────────────────────────────────────
        // Richer than probation_alerts: every active-probation member (classified
        // overdue / ending soon / on probation) plus members confirmed in the last
        // 30 days, each with avatar, type, progress %, and probation-letter linkage.
        $todayStart = $now->copy()->startOfDay();
        $confirmedSince = $now->copy()->subDays(30)->toDateString();

        $typeLabel = function (User $u): string {
            if ($u->employment_type === 'internship') {
                return 'Intern';
            }
            if ($u->employment_type === 'freelancer') {
                return 'Freelancer';
            }

            return $u->joined_as === 'experienced' ? 'Experienced' : 'Full-time';
        };
        $durationLabel = function (?Carbon $start, ?Carbon $end): string {
            if (! $start || ! $end) {
                return '';
            }
            $d = $start->diffInDays($end);
            if ($d >= 28 && $d <= 31) {
                return '1 month';
            }
            if ($d > 0 && $d % 30 === 0) {
                return ($d / 30).' months';
            }

            return $d.' days';
        };
        $progressPct = function (?Carbon $start, Carbon $end) use ($now): int {
            if (! $start) {
                return 100;
            }
            $total = $start->diffInDays($end);
            if ($total <= 0) {
                return 100;
            }

            return min(100, max(0, (int) round($start->diffInDays($now) / $total * 100)));
        };

        $activeProbation = $activeUsers->filter(fn ($u) => $u->employee_status === 'probation' && $u->probation_end_date)
            ->map(function ($u) use ($todayStart, $typeLabel, $durationLabel, $progressPct) {
                $end = $u->probation_end_date;
                $start = $u->probation_start_date ?? $u->joining_date;
                $daysRemaining = (int) $todayStart->diffInDays($end, false);
                $state = $daysRemaining < 0 ? 'overdue' : ($daysRemaining <= 7 ? 'ending_soon' : 'on_probation');

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'role' => $u->roleRelation?->name ?? '',
                    'designation' => $u->designation ?? '',
                    'avatar_url' => $u->profile_photo_url,
                    'type' => $typeLabel($u),
                    'probation_start_date' => $start?->format('Y-m-d'),
                    'probation_end_date' => $end->format('Y-m-d'),
                    'duration_label' => $durationLabel($start, $end),
                    'days_remaining' => $daysRemaining,
                    'progress_pct' => $progressPct($start, $end),
                    'state' => $state,
                    'confirmed_date' => null,
                    'letter_id' => null,
                ];
            })->values();

        // Recently confirmed (probation → active within 30 days) + their probation letter.
        $confirmedMembers = $activeUsers->filter(fn ($u) => $u->employee_status === 'active' && $u->confirmed_date && $u->confirmed_date->toDateString() >= $confirmedSince);
        $letterIdByUser = [];
        if ($confirmedMembers->isNotEmpty()) {
            $letterIdByUser = IssuedLetter::whereIn('recipient_user_id', $confirmedMembers->pluck('id'))
                ->where('letter_type', 'probation')
                ->where('status', 'issued')
                ->orderByDesc('id')
                ->get(['id', 'recipient_user_id'])
                ->groupBy('recipient_user_id')
                ->map(fn ($g) => $g->first()->id)
                ->all();
        }
        $confirmedRows = $confirmedMembers->map(function ($u) use ($typeLabel, $durationLabel, $letterIdByUser) {
            $start = $u->probation_start_date ?? $u->joining_date;
            $end = $u->probation_end_date;

            return [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->roleRelation?->name ?? '',
                'designation' => $u->designation ?? '',
                'avatar_url' => $u->profile_photo_url,
                'type' => $typeLabel($u),
                'probation_start_date' => $start?->format('Y-m-d'),
                'probation_end_date' => $end?->format('Y-m-d'),
                'duration_label' => $durationLabel($start, $end),
                'days_remaining' => 0,
                'progress_pct' => 100,
                'state' => 'confirmed',
                'confirmed_date' => $u->confirmed_date?->format('Y-m-d'),
                'letter_id' => $letterIdByUser[$u->id] ?? null,
            ];
        })->values();

        $stateOrder = ['overdue' => 0, 'ending_soon' => 1, 'on_probation' => 2, 'confirmed' => 3];
        $probationTracker = $activeProbation->concat($confirmedRows)
            ->sortBy(fn ($r) => sprintf('%d_%08d', $stateOrder[$r['state']] ?? 9, ($r['days_remaining'] ?? 0) + 1000000))
            ->values();

        $trackerCounts = [
            'all' => $probationTracker->count(),
            'overdue' => $activeProbation->where('state', 'overdue')->count(),
            'ending_soon' => $activeProbation->where('state', 'ending_soon')->count(),
            'on_probation' => $activeProbation->where('state', 'on_probation')->count(),
            'confirmed' => $confirmedRows->count(),
        ];

        // Department breakdown
        $departments = Department::where('is_active', true)->orderBy('name')->get();
        $deptBreakdown = $departments->map(function ($dept) use ($activeUsers) {
            $members = $activeUsers->where('department_id', $dept->id);

            return [
                'id' => $dept->id,
                'name' => $dept->name,
                'head' => $dept->head?->name,
                'headcount' => $members->count(),
                'on_probation' => $members->where('employee_status', 'probation')->count(),
                'interns' => $members->where('employee_status', 'intern')->count(),
            ];
        });

        // Unassigned (no department)
        $unassigned = $activeUsers->whereNull('department_id')->count();

        $result = [
            'ok' => true,
            'total_active' => $activeUsers->count(),
            'total_all' => $allUsers->count(),
            'status_counts' => $statusCounts,
            'type_counts' => $typeCounts,
            'joiners_this_month' => $joiners,
            'leavers_this_month' => $leavers,
            'probation_alerts' => $probationAlerts,
            'intern_alerts' => $internAlerts,
            'probation_tracker' => $probationTracker,
            'tracker_counts' => $trackerCounts,
            'department_breakdown' => $deptBreakdown,
            'unassigned_department' => $unassigned,
        ];

        // Salary overview (CEO/CFO only)
        if (in_array($user->role, self::SALARY_ROLES, true)) {
            $totalPayroll = $activeUsers->sum('monthly_salary');
            $avgSalary = $activeUsers->where('monthly_salary', '>', 0)->avg('monthly_salary');

            $result['salary_overview'] = [
                'total_monthly_payroll' => round($totalPayroll, 2),
                'average_monthly_salary' => round($avgSalary ?? 0, 2),
                'total_annual_cost' => round($activeUsers->sum('annual_ctc'), 2),
            ];
        }

        return response()->json($result);
    }

    /** Confirm an employee: probation → active + stamp confirmed_date (HR action). */
    public function confirm(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, self::ALLOWED_ROLES, true)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);
        $emp = User::findOrFail($data['user_id']);
        if ($emp->employee_status !== 'probation') {
            return response()->json(['error' => 'Employee is not on probation.'], 422);
        }

        $confirmedDate = Carbon::now('Asia/Kolkata')->toDateString();
        $emp->employee_status = 'active';
        $emp->confirmed_date = $confirmedDate;
        $emp->syncIsActive();
        $emp->save();

        return response()->json(['ok' => true, 'name' => $emp->name, 'confirmed_date' => $confirmedDate]);
    }

    /** Extend a probation window by 15 or 30 days. */
    public function extend(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, self::ALLOWED_ROLES, true)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'days' => 'required|integer|in:15,30',
        ]);
        $emp = User::findOrFail($data['user_id']);
        if ($emp->employee_status !== 'probation') {
            return response()->json(['error' => 'Employee is not on probation.'], 422);
        }

        $base = $emp->probation_end_date ?? Carbon::now('Asia/Kolkata');
        $newEnd = $base->copy()->addDays((int) $data['days'])->toDateString();
        $emp->probation_end_date = $newEnd;
        $emp->save();

        return response()->json(['ok' => true, 'name' => $emp->name, 'probation_end_date' => $newEnd]);
    }
}
