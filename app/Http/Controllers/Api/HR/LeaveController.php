<?php

namespace App\Http\Controllers\Api\HR;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\LeaveService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    // The global Team Leave overview is JP-only (CEO, user id 1).
    private const JP_USER_ID = 1;

    // Real leaves only for the JP Team Leave board — WFH and Permission are
    // deliberately excluded (they count as working days, not leave). This is
    // also the set the row-click detail modal lists.
    private const OVERVIEW_SLUGS = ['sick', 'casual', 'emergency', 'menstrual'];

    // The board collapses those into two display columns: every auto-approved
    // leave together, and Casual on its own (the only manager-approved real
    // leave). Order here = column order.
    private const COLUMN_GROUPS = [
        ['key' => 'auto', 'label' => 'Auto-approves', 'slugs' => ['sick', 'emergency', 'menstrual']],
        ['key' => 'casual', 'label' => 'Casual', 'slugs' => ['casual']],
    ];

    private LeaveService $leaveService;

    public function __construct()
    {
        $this->leaveService = app(LeaveService::class);
    }

    public function types(Request $request): JsonResponse
    {
        $user = $request->user();
        $types = LeaveType::active()
            ->forGender($user->gender)
            ->get(['id', 'name', 'slug', 'requires_approval', 'is_hourly']);

        return response()->json(['leave_types' => $types]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $status = $request->query('status');
        $year = (int) $request->query('year', date('Y'));

        $query = LeaveRequest::with(['leaveType:id,name,slug', 'reviewer:id,name'])
            ->where('user_id', $user->id)
            ->whereYear('start_date', $year);

        if ($status) {
            $query->where('status', $status);
        }

        $requests = $query->orderByDesc('created_at')->get();

        return response()->json([
            'leave_requests' => $requests->map(fn (LeaveRequest $r) => $this->formatRequest($r)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $istToday = Carbon::today('Asia/Kolkata')->toDateString();
        $request->validate([
            'leave_type' => 'required|string',
            'start_date' => 'required|date|after_or_equal:'.$istToday,
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'compensation_date' => 'nullable|date|after_or_equal:'.$istToday,
            'reason' => 'nullable|string|max:1000',
            'from_time' => 'nullable|string|max:10',
            'to_time' => 'nullable|string|max:10',
        ]);

        try {
            $user = $request->user();
            $leaveRequest = $this->leaveService->applyLeave(
                $user,
                $request->input('leave_type'),
                $request->input('start_date'),
                $request->input('end_date', $request->input('start_date')),
                $request->input('reason'),
                'web',
                null,
                $request->input('from_time'),
                $request->input('to_time'),
                $request->input('compensation_date')
            );
            $leaveRequest->load('leaveType:id,name,slug');

            $typeName = $leaveRequest->leaveType?->name ?? 'leave';
            $range = $leaveRequest->start_date->format('Y-m-d');
            if ($leaveRequest->end_date->format('Y-m-d') !== $range) {
                $range .= ' – '.$leaveRequest->end_date->format('Y-m-d');
            }
            ActivityLogService::log(
                $user->id,
                'leave_applied',
                "{$user->name} applied for {$typeName} ({$range})",
                'leave_request',
                $leaveRequest->id,
                ['status' => $leaveRequest->status, 'target_user_id' => $user->id],
            );

            return response()->json([
                'message' => $leaveRequest->status === 'approved'
                    ? 'Leave auto-approved! Your manager has been notified.'
                    : 'Leave submitted! Pending manager approval.',
                'leave_request' => $this->formatRequest($leaveRequest),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function review(LeaveRequest $leaveRequest, Request $request): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:approve,reject',
            'note' => 'nullable|string|max:500',
        ]);

        $reviewer = $request->user();
        $employee = $leaveRequest->user;

        // Only the reporting manager (or JP as fallback for a manager-less
        // senior) may review.
        if (! $this->authorizeReviewer($reviewer, $employee)) {
            return response()->json(['error' => 'You are not authorized to review this request.'], 403);
        }

        try {
            $action = $request->input('action');
            $leaveRequest = $this->leaveService->reviewLeave(
                $reviewer,
                $leaveRequest,
                $action,
                $request->input('note')
            );

            ActivityLogService::log(
                $reviewer->id,
                'leave_reviewed',
                "{$reviewer->name} {$action}d leave for {$employee->name}",
                'leave_request',
                $leaveRequest->id,
                ['action' => $action, 'status' => $leaveRequest->status, 'target_user_id' => $employee->id],
            );

            return response()->json([
                'message' => "Leave request {$leaveRequest->status}.",
                'leave_request' => $this->formatRequest($leaveRequest),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function cancel(LeaveRequest $leaveRequest, Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $leaveRequest = $this->leaveService->cancelLeave($user, $leaveRequest);

            ActivityLogService::log(
                $user->id,
                'leave_cancelled',
                "{$user->name} cancelled leave request",
                'leave_request',
                $leaveRequest->id,
                ['target_user_id' => $user->id],
            );

            return response()->json([
                'message' => 'Leave request cancelled.',
                'leave_request' => $this->formatRequest($leaveRequest),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // Employee requests cancellation of their own APPROVED leave. It stays
    // approved until the manager approves the cancellation via reviewCancellation.
    public function requestCancellation(LeaveRequest $leaveRequest, Request $request): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        try {
            $user = $request->user();
            $leaveRequest = $this->leaveService->requestCancellation($user, $leaveRequest, $request->input('reason'));

            ActivityLogService::log(
                $user->id,
                'leave_cancellation_requested',
                "{$user->name} requested to cancel an approved leave",
                'leave_request',
                $leaveRequest->id,
                ['target_user_id' => $user->id],
            );

            return response()->json([
                'message' => 'Cancellation request sent to your manager.',
                'leave_request' => $this->formatRequest($leaveRequest),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // Manager approves/rejects an employee's cancellation request. Approve =>
    // the leave is cancelled; reject => the leave stands.
    public function reviewCancellation(LeaveRequest $leaveRequest, Request $request): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:approve,reject',
            'note' => 'nullable|string|max:500',
        ]);

        $reviewer = $request->user();
        $employee = $leaveRequest->user;

        // Same approver as the original leave (reporting manager, or JP fallback).
        if (! $this->authorizeReviewer($reviewer, $employee)) {
            return response()->json(['error' => 'You are not authorized to review this request.'], 403);
        }

        try {
            $action = $request->input('action');
            $leaveRequest = $this->leaveService->reviewCancellation(
                $reviewer,
                $leaveRequest,
                $action,
                $request->input('note')
            );

            ActivityLogService::log(
                $reviewer->id,
                'leave_cancellation_reviewed',
                "{$reviewer->name} {$action}d cancellation of {$employee->name}'s leave",
                'leave_request',
                $leaveRequest->id,
                ['action' => $action, 'status' => $leaveRequest->status, 'target_user_id' => $employee->id],
            );

            return response()->json([
                'message' => $action === 'approve' ? 'Leave cancelled.' : 'Cancellation declined; leave stands.',
                'leave_request' => $this->formatRequest($leaveRequest),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function teamRequests(Request $request): JsonResponse
    {
        $manager = $request->user();
        $status = $request->query('status');

        $query = LeaveRequest::with(['user:id,name', 'leaveType:id,name,slug', 'reviewer:id,name'])
            ->whereHas('user', function ($q) use ($manager) {
                $q->where('is_active', true);
                $this->applyReviewableScope($q, $manager);
            })
            ->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        }

        $requests = $query->limit(100)->get();

        return response()->json([
            'team_requests' => $requests->map(fn (LeaveRequest $r) => $this->formatRequest($r)),
        ]);
    }

    public function teamPending(Request $request): JsonResponse
    {
        $manager = $request->user();

        // Needs-action = pending reviews PLUS cancellation requests on approved
        // leaves (those stay status='approved' with cancellation_requested_at set).
        $requests = LeaveRequest::with(['user:id,name', 'leaveType:id,name,slug'])
            ->whereHas('user', fn ($q) => $this->applyReviewableScope($q, $manager))
            ->where(function ($q) {
                $q->where('status', 'pending')
                    ->orWhere(fn ($w) => $w->where('status', 'approved')->whereNotNull('cancellation_requested_at'));
            })
            ->orderBy('start_date')
            ->get();

        return response()->json([
            'pending_requests' => $requests->map(fn (LeaveRequest $r) => $this->formatRequest($r)),
        ]);
    }

    public function teamOnLeaveToday(Request $request): JsonResponse
    {
        $manager = $request->user();
        $today = Carbon::today('Asia/Kolkata')->format('Y-m-d');

        // HR (see config/hr_leave_alerts.php) gets the company-wide view —
        // every active employee on approved leave today, not just their
        // direct reports. Lets Meghana/Akshara act on absences from the
        // dashboard without opening the attendance sheet.
        $hrUserIds = config('hr_leave_alerts.user_ids', []);
        $isHr = in_array((int) $manager->id, array_map('intval', $hrUserIds), true);

        // Non-HR managers see their direct reports PLUS their dotted-line
        // (secondary_manager_id) reports — so a secondary manager is kept aware
        // of their dotted-line report's approved leave even though approval
        // itself runs through the primary reporting_manager_id.
        $userScope = $isHr
            ? fn ($q) => $q->where('is_active', true)->where('id', '!=', 33)
            : fn ($q) => $q->where('is_active', true)
                ->where(fn ($w) => $w->where('reporting_manager_id', $manager->id)
                    ->orWhere('secondary_manager_id', $manager->id));

        // WFH and Permission are working days, not leave (project rule:
        // wfh_permission_not_leave). Keep them out of the "On Leave Today" card
        // so it agrees with the attendance sheet, which also excludes them.
        $requests = LeaveRequest::with(['user:id,name', 'leaveType:id,name,slug,is_hourly'])
            ->whereHas('user', $userScope)
            ->where('status', 'approved')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->orderBy('start_date')
            ->get()
            ->filter(fn (LeaveRequest $r) => ! ($r->leaveType?->is_hourly) && $r->leaveType?->slug !== 'wfh')
            ->values();

        return response()->json([
            'on_leave' => $requests->map(fn (LeaveRequest $r) => [
                'user' => ['id' => $r->user->id, 'name' => $r->user->name],
                'leave_type' => $r->leaveType ? ['name' => $r->leaveType->name, 'slug' => $r->leaveType->slug] : null,
                'start_date' => $r->start_date->format('Y-m-d'),
                'end_date' => $r->end_date->format('Y-m-d'),
                'total_days' => $r->total_days,
                'hours' => $r->hours ? (float) $r->hours : null,
            ]),
        ]);
    }

    /**
     * JP-only global Team Leave overview for a month: every active
     * employee's APPROVED leaves, grouped into Sick / Casual / WFH /
     * Permission columns, employees ranked by number of leaves (desc).
     * A leave is counted in the month of its start_date.
     */
    public function teamOverview(Request $request): JsonResponse
    {
        if ((int) $request->user()->id !== self::JP_USER_ID) {
            return response()->json(['error' => 'Forbidden.'], 403);
        }

        $month = $request->query('month');
        if (! is_string($month) || ! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = null;
        }
        $base = $month
            ? Carbon::createFromFormat('Y-m', $month, 'Asia/Kolkata')->startOfMonth()
            : Carbon::now('Asia/Kolkata')->startOfMonth();
        $start = $base->copy()->startOfMonth()->format('Y-m-d');
        $end = $base->copy()->endOfMonth()->format('Y-m-d');

        $slugs = self::OVERVIEW_SLUGS;

        $rows = LeaveRequest::with(['user:id,name', 'leaveType:id,name,slug,is_hourly'])
            ->where('status', 'approved')
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->whereHas('leaveType', fn ($q) => $q->whereIn('slug', $slugs))
            ->whereBetween('start_date', [$start, $end])
            ->orderBy('start_date')
            ->get();

        // One row per employee: per-type DAY counts (hours for hourly types
        // like Permission) + the full leave list (powers the detail modal).
        $people = [];
        foreach ($rows as $r) {
            $slug = $r->leaveType?->slug;
            if (! in_array($slug, $slugs, true) || ! $r->user) {
                continue;
            }
            $isHourly = (bool) $r->leaveType->is_hourly;
            $days = $isHourly ? 0 : (int) $r->total_days;
            $hours = $r->hours ? (float) $r->hours : 0.0;

            $uid = $r->user->id;
            if (! isset($people[$uid])) {
                $people[$uid] = [
                    'id' => $uid,
                    'name' => $r->user->name,
                    'metrics' => [],
                    'total_days' => 0,
                    'total_hours' => 0.0,
                    'leaves' => [],
                ];
                foreach (self::COLUMN_GROUPS as $g) {
                    $people[$uid]['metrics'][$g['key']] = ['days' => 0, 'hours' => 0.0];
                }
            }
            $gkey = self::groupKeyForSlug($slug);
            $people[$uid]['metrics'][$gkey]['days'] += $days;
            $people[$uid]['metrics'][$gkey]['hours'] += $hours;
            $people[$uid]['total_days'] += $days;
            $people[$uid]['total_hours'] += $hours;
            $people[$uid]['leaves'][] = [
                'slug' => $slug,
                'type' => $r->leaveType->name,
                'start_date' => $r->start_date->format('Y-m-d'),
                'end_date' => $r->end_date->format('Y-m-d'),
                'total_days' => (int) $r->total_days,
                'hours' => $r->hours ? (float) $r->hours : null,
                'from_time' => $r->from_time,
                'to_time' => $r->to_time,
                'reason' => $r->reason,
            ];
        }

        $people = array_values($people);
        // Most leave DAYS first; permission hours break ties so heavy
        // permission users still rank among people with equal day counts.
        usort($people, function ($a, $b) {
            return ($b['total_days'] <=> $a['total_days'])
                ?: ($b['total_hours'] <=> $a['total_hours'])
                ?: strcmp($a['name'], $b['name']);
        });

        $types = [];
        foreach (self::COLUMN_GROUPS as $g) {
            $types[] = [
                'slug' => $g['key'],
                'label' => $g['label'],
                'is_hourly' => false,
            ];
        }

        return response()->json([
            'month' => $base->format('Y-m'),
            'month_label' => $base->format('F Y'),
            'types' => $types,
            'people' => $people,
        ]);
    }

    private static function groupKeyForSlug(string $slug): string
    {
        foreach (self::COLUMN_GROUPS as $g) {
            if (in_array($slug, $g['slugs'], true)) {
                return $g['key'];
            }
        }

        return $slug;
    }

    // Restrict a users query to those whose leave $manager may review: their
    // direct reports, plus — for JP only — any top-of-org user with no
    // reporting manager (Bala/Nandha/Ayush), who would otherwise have no one
    // to approve them. JP's own request is excluded (no self-approval).
    // Whether $reviewer may approve/reject $employee's leave (or a cancellation
    // of it): the employee's reporting manager, or JP as fallback for a
    // manager-less senior (JP can't review his own request).
    private function authorizeReviewer(User $reviewer, User $employee): bool
    {
        $managerId = $employee->reporting_manager_id;
        $isManager = $managerId !== null && (int) $managerId === (int) $reviewer->id;
        $isJpFallback = $managerId === null
            && (int) $reviewer->id === self::JP_USER_ID
            && (int) $employee->id !== self::JP_USER_ID;

        return $isManager || $isJpFallback;
    }

    private function applyReviewableScope($query, User $manager): void
    {
        $query->where(function ($q) use ($manager) {
            $q->where('reporting_manager_id', $manager->id);
            if ((int) $manager->id === self::JP_USER_ID) {
                $q->orWhere(function ($qq) {
                    $qq->whereNull('reporting_manager_id')
                        ->where('id', '!=', self::JP_USER_ID);
                });
            }
        });
    }

    private function formatRequest(LeaveRequest $r): array
    {
        return [
            'id' => $r->id,
            'user' => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name] : null,
            'leave_type' => $r->leaveType ? ['name' => $r->leaveType->name, 'slug' => $r->leaveType->slug, 'is_hourly' => (bool) $r->leaveType->is_hourly] : null,
            'start_date' => $r->start_date->format('Y-m-d'),
            'end_date' => $r->end_date->format('Y-m-d'),
            'compensation_date' => $r->compensation_date?->format('Y-m-d'),
            'total_days' => $r->total_days,
            'hours' => $r->hours ? (float) $r->hours : null,
            'from_time' => $r->from_time,
            'to_time' => $r->to_time,
            'reason' => $r->reason,
            'status' => $r->status,
            'reviewer' => $r->reviewer ? ['id' => $r->reviewer->id, 'name' => $r->reviewer->name] : null,
            'reviewer_note' => $r->reviewer_note,
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'cancellation_requested_at' => $r->cancellation_requested_at?->toIso8601String(),
            'cancellation_reason' => $r->cancellation_reason,
            // Cancellation can only be requested while the leave isn't over yet
            // (up to and including its last day, IST).
            'can_request_cancellation' => $r->status === 'approved'
                && $r->cancellation_requested_at === null
                && $r->end_date->gte(Carbon::today('Asia/Kolkata')),
            'applied_via' => $r->applied_via,
            'created_at' => $r->created_at->toIso8601String(),
        ];
    }
}
