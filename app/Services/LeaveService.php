<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\ManagerNotification;
use App\Models\User;
use App\Helpers\DateHelper;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Log;

class LeaveService
{
    // Compensate is not a real leave. It's a swap: original weekday off,
    // weekend day worked instead. Both attendance + summary surfaces treat
    // the day-off as "compensated" (info column, not a leave) and the weekend
    // sign-in as a regular working day for that user.
    public const SLUG_COMPENSATE = 'compensate';

    // WFH is a working day, not leave (project rule: wfh_permission_not_leave).
    // HR still wants to be kept in the loop on approved WFH days, so an approval
    // pings them on Slack + drops a card on their dashboard (mirrors compensate).
    public const SLUG_WFH = 'wfh';

    // Top-of-org users (Bala/Nandha/Ayush) have no reporting manager, so their
    // approval-required leave routes to JP — otherwise it can never be approved.
    public const JP_USER_ID = 1;

    public function __construct(
        private SlackService $slackService
    ) {}

    public function applyLeave(
        User $user,
        string $leaveTypeSlug,
        string $startDate,
        string $endDate,
        ?string $reason = null,
        string $appliedVia = 'web',
        ?float $hours = null,
        ?string $fromTime = null,
        ?string $toTime = null,
        ?string $compensationDate = null
    ): LeaveRequest {
        $leaveType = LeaveType::where('slug', $leaveTypeSlug)->where('is_active', true)->firstOrFail();

        // Gender restriction check
        if ($leaveType->gender_restricted && $leaveType->gender_restricted !== $user->gender) {
            throw new \InvalidArgumentException("{$leaveType->name} is not available for your profile.");
        }

        $start = DateHelper::parse($startDate);

        // Reject past start dates (anchored to IST today). Catches the common
        // wrong-year mistake — e.g. an MCP client filing 2025-06-13 instead of
        // 2026 — before it silently lands a past-dated leave the employee never
        // sees on their (current-year) My-Leaves tab. The after_or_equal rule in
        // LeaveController::store guards the API path first; this covers the
        // /logs/request-leave and any direct callers too.
        if ($start->lt(Carbon::today('Asia/Kolkata'))) {
            throw new \InvalidArgumentException(
                "Leave start date can't be in the past — today is ".Carbon::today('Asia/Kolkata')->toDateString().' (IST). Use the current year.'
            );
        }

        if ($leaveType->slug === self::SLUG_COMPENSATE) {
            if (! $compensationDate) {
                throw new \InvalidArgumentException('Please pick the weekend day you will work to compensate.');
            }
            $compDate = DateHelper::parse($compensationDate);
            // Single weekday off + single weekend day worked. Multi-day swaps
            // can stack as multiple separate Compensate requests so the
            // approval flow keeps a clean 1:1 mapping.
            if ($start->isWeekend()) {
                throw new \InvalidArgumentException('The day you want off must be a weekday (Mon–Fri).');
            }
            if (! $compDate->isWeekend()) {
                throw new \InvalidArgumentException('The compensation day must be a weekend (Sat or Sun).');
            }
            $today = Carbon::today('Asia/Kolkata');
            if ($start->lt($today)) {
                throw new \InvalidArgumentException('The day off must be today or in the future.');
            }
            if ($compDate->lt($today)) {
                throw new \InvalidArgumentException('The compensation day must be today or in the future.');
            }

            $leaveRequest = LeaveRequest::create([
                'user_id' => $user->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $start->format('Y-m-d'),
                'compensation_date' => $compDate->format('Y-m-d'),
                'total_days' => 1,
                'reason' => $reason,
                'status' => 'pending',
                'applied_via' => $appliedVia,
            ]);
        } elseif ($leaveType->is_hourly) {
            if (!$fromTime || !$toTime) {
                throw new \InvalidArgumentException('Please select from and to time.');
            }

            $from = Carbon::parse($fromTime);
            $to = Carbon::parse($toTime);
            $diffMinutes = $from->diffInMinutes($to, false);

            if ($diffMinutes < 30) {
                throw new \InvalidArgumentException('Permission must be at least 30 minutes.');
            }
            if ($diffMinutes > 480) {
                throw new \InvalidArgumentException('Permission cannot exceed 8 hours.');
            }

            $calculatedHours = round($diffMinutes / 60, 1);

            $leaveRequest = LeaveRequest::create([
                'user_id' => $user->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $start->format('Y-m-d'),
                'total_days' => 0,
                'hours' => $calculatedHours,
                'from_time' => $fromTime,
                'to_time' => $toTime,
                'reason' => $reason,
                'status' => 'pending',
                'applied_via' => $appliedVia,
            ]);
        } else {
            $end = DateHelper::parse($endDate);
            $totalDays = $this->computeBusinessDays($start, $end);

            if ($totalDays < 1) {
                throw new \InvalidArgumentException('Leave must be at least 1 business day.');
            }

            $leaveRequest = LeaveRequest::create([
                'user_id' => $user->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
                'total_days' => $totalDays,
                'reason' => $reason,
                'status' => 'pending',
                'applied_via' => $appliedVia,
            ]);
        }

        $leaveRequest->load('leaveType', 'user');

        if (!$leaveType->requires_approval) {
            $this->autoApprove($leaveRequest);
        } else {
            $this->notifyManagerForApproval($leaveRequest);
            $this->notifyEmployee($leaveRequest, 'submitted');
        }

        return $leaveRequest->fresh(['leaveType', 'user']);
    }

    public function autoApprove(LeaveRequest $request): void
    {
        $request->update([
            'status' => 'approved',
            'reviewed_at' => now(),
        ]);

        $this->notifyManagerOfAutoApproval($request);
        $this->notifyEmployee($request, 'auto_approved');
        $this->notifyLeaveCc($request);
        $this->upsertLeaveCcDashboardNotice($request);

        Log::info('LeaveService: Leave auto-approved', [
            'leave_request_id' => $request->id,
            'user' => $request->user->name,
        ]);
    }

    public function reviewLeave(User $reviewer, LeaveRequest $request, string $action, ?string $note = null): LeaveRequest
    {
        if (!in_array($action, ['approve', 'reject'])) {
            throw new \InvalidArgumentException('Action must be approve or reject.');
        }

        if (!$request->isPending()) {
            throw new \InvalidArgumentException('Only pending leave requests can be reviewed.');
        }

        $status = $action === 'approve' ? 'approved' : 'rejected';

        $request->update([
            'status' => $status,
            'approved_by' => $reviewer->id,
            'reviewed_at' => now(),
            'reviewer_note' => $note,
        ]);

        $this->notifyEmployee($request, $status);

        if ($status === 'approved') {
            $this->notifyLeaveCc($request);
            $this->upsertLeaveCcDashboardNotice($request);
        }

        // HR sees compensate swaps separately from regular leaves: they need
        // a Slack ping + a dashboard card the moment a manager approves, and
        // a second ping on the working weekend (handled by NotifyCompensationDay).
        // On rejection, clear any stale dashboard card from a prior approval.
        if ($request->leaveType?->slug === self::SLUG_COMPENSATE) {
            if ($status === 'approved') {
                $this->notifyHrOfCompensateApproval($request);
                $this->upsertHrDashboardNotice($request);
            } else {
                $this->clearHrDashboardNotice($request);
            }
        }

        // WFH: keep HR in the loop with a Slack ping + dashboard card on
        // approval; clear the card if it's later rejected.
        if ($request->leaveType?->slug === self::SLUG_WFH) {
            if ($status === 'approved') {
                $this->notifyHrOfWfhApproval($request);
                $this->upsertHrWfhDashboardNotice($request);
            } else {
                $this->clearHrWfhDashboardNotice($request);
            }
        }

        Log::info("LeaveService: Leave {$status}", [
            'leave_request_id' => $request->id,
            'reviewer' => $reviewer->name,
        ]);

        return $request->fresh(['leaveType', 'user', 'reviewer']);
    }

    public function cancelLeave(User $user, LeaveRequest $request): LeaveRequest
    {
        if ($request->user_id !== $user->id) {
            throw new \InvalidArgumentException('You can only cancel your own leave requests.');
        }

        // Approved leave can no longer be self-cancelled — it must go through a
        // manager-approved cancellation request (see requestCancellation()).
        if ($request->status !== 'pending') {
            throw new \InvalidArgumentException('Only pending leave requests can be cancelled directly. Approved leave needs a cancellation request.');
        }

        $request->update(['status' => 'cancelled']);

        $this->notifyManagerOfCancellation($request);

        if ($request->leaveType?->slug === self::SLUG_COMPENSATE) {
            $this->clearHrDashboardNotice($request);
        }

        if ($request->leaveType?->slug === self::SLUG_WFH) {
            $this->clearHrWfhDashboardNotice($request);
        }

        return $request->fresh(['leaveType', 'user']);
    }

    /**
     * Employee asks to cancel an already-APPROVED leave. The leave deliberately
     * stays 'approved' (still in effect — counts in On-Leave-Today / overviews)
     * until the manager approves the cancellation; we just flag the request and
     * ping the approving manager.
     */
    public function requestCancellation(User $user, LeaveRequest $request, ?string $reason = null): LeaveRequest
    {
        if ($request->user_id !== $user->id) {
            throw new \InvalidArgumentException('You can only cancel your own leave requests.');
        }

        if ($request->status !== 'approved') {
            throw new \InvalidArgumentException('Only approved leave can be requested for cancellation.');
        }

        if ($request->cancellation_requested_at !== null) {
            throw new \InvalidArgumentException('A cancellation request is already pending manager approval.');
        }

        // Cancellation only applies up to the leave's last day — once it's over
        // there's nothing left to cancel.
        if ($request->end_date->lt(Carbon::today('Asia/Kolkata'))) {
            throw new \InvalidArgumentException('This leave has already passed and can no longer be cancelled.');
        }

        $request->update([
            'cancellation_requested_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        $request->load(['leaveType', 'user']);
        $this->notifyManagerOfCancellationRequest($request);
        $this->notifyEmployee($request, 'cancellation_requested');

        return $request->fresh(['leaveType', 'user']);
    }

    /**
     * Manager approves/rejects a cancellation request on an approved leave.
     * Approve -> the leave is cancelled. Reject -> the leave stands (the flag is
     * cleared so the employee may request again).
     */
    public function reviewCancellation(User $reviewer, LeaveRequest $request, string $action, ?string $note = null): LeaveRequest
    {
        if (!in_array($action, ['approve', 'reject'])) {
            throw new \InvalidArgumentException('Action must be approve or reject.');
        }

        if (!$request->hasPendingCancellation()) {
            throw new \InvalidArgumentException('This leave has no pending cancellation request.');
        }

        if ($action === 'approve') {
            $request->update([
                'status' => 'cancelled',
                'reviewer_note' => $note,
            ]);

            // Drop any HR dashboard cards tied to the now-cancelled leave.
            if ($request->leaveType?->slug === self::SLUG_COMPENSATE) {
                $this->clearHrDashboardNotice($request);
            }
            if ($request->leaveType?->slug === self::SLUG_WFH) {
                $this->clearHrWfhDashboardNotice($request);
            }
            // Drop the dotted-line manager's leave-FYI card too.
            $this->clearLeaveCcDashboardNotice($request);

            $this->notifyEmployee($request->fresh(['leaveType', 'user']), 'cancellation_approved');
        } else {
            // Reject: leave stays approved; clear the request so it can be re-raised.
            $request->update([
                'cancellation_requested_at' => null,
                'reviewer_note' => $note,
            ]);

            $this->notifyEmployee($request->fresh(['leaveType', 'user']), 'cancellation_rejected');
        }

        Log::info("LeaveService: Cancellation {$action}", [
            'leave_request_id' => $request->id,
            'reviewer' => $reviewer->name,
        ]);

        return $request->fresh(['leaveType', 'user', 'reviewer']);
    }

    public function computeBusinessDays(Carbon $start, Carbon $end): int
    {
        $holidays = config('holidays', []);
        $days = 0;
        $period = CarbonPeriod::create($start, $end);

        foreach ($period as $date) {
            if ($date->isWeekend()) {
                continue;
            }
            if (array_key_exists($date->format('Y-m-d'), $holidays)) {
                continue;
            }
            $days++;
        }

        return $days;
    }

    private function sendSlackDm(string $userName, string $message): void
    {
        try {
            $slackUserId = $this->slackService->getUserIdByName($userName);
            if ($slackUserId) {
                $this->slackService->sendDirectMessage($slackUserId, $message, bypassQuietWindow: true);
            } else {
                Log::warning('LeaveService: Could not resolve Slack user', ['name' => $userName]);
            }
        } catch (\Throwable $e) {
            Log::error('LeaveService: Slack DM failed', ['name' => $userName, 'error' => $e->getMessage()]);
        }
    }

    private function notifyManagerForApproval(LeaveRequest $request): void
    {
        $manager = $request->user->reportingManager;
        // Manager-less seniors (Bala/Nandha/Ayush) route to JP so the request
        // is actually seen and actionable. JP's own request has no approver.
        if (!$manager) {
            if ((int) $request->user_id === self::JP_USER_ID) {
                return;
            }
            $manager = User::find(self::JP_USER_ID);
        }
        if (!$manager) {
            Log::warning('LeaveService: No reporting manager found', ['user_id' => $request->user_id]);
            return;
        }

        $portalUrl = config('app.url') . '/#view=leave';

        $isHourly = $request->hours > 0;
        $isCompensate = $request->leaveType?->slug === self::SLUG_COMPENSATE;
        if ($isCompensate) {
            $durationLine = "Day off: {$request->start_date->format('D, j M Y')}\n"
                . "Working instead on: " . ($request->compensation_date?->format('D, j M Y') ?? '—');
        } elseif ($isHourly) {
            $durationLine = "Date: {$request->start_date->format('D, j M Y')}\nTime: {$request->from_time} to {$request->to_time} ({$request->hours}h)";
        } else {
            $durationLine = "Dates: {$request->start_date->format('D, j M Y')} to {$request->end_date->format('D, j M Y')}\nDays: {$request->total_days}";
        }

        $message = "*Leave Request from {$request->user->name}*\n\n"
            . "Type: {$request->leaveType->name}\n"
            . "{$durationLine}\n"
            . "Reason: " . ($request->reason ?: 'Not specified') . "\n\n"
            . "<{$portalUrl}|Approve or Reject on Tessa Portal>";

        $this->sendSlackDm($manager->name, $message);
    }

    private function notifyManagerOfAutoApproval(LeaveRequest $request): void
    {
        $manager = $request->user->reportingManager;
        if (!$manager) {
            return;
        }

        $portalUrl = config('app.url') . '/#view=leave';

        $message = "*{$request->leaveType->name} Auto-Approved*\n\n"
            . "{$request->user->name} has taken {$request->leaveType->name}.\n"
            . "Dates: {$request->start_date->format('D, j M Y')} to {$request->end_date->format('D, j M Y')}\n"
            . "Days: {$request->total_days}\n"
            . "Reason: " . ($request->reason ?: 'Not specified') . "\n\n"
            . "Auto-approved by Tessa. <{$portalUrl}|View on Portal>";

        $this->sendSlackDm($manager->name, $message);
    }

    /**
     * FYI dotted-line managers when an employee's leave is approved. Recipients
     * come from config/leave_notify_cc.php ([employee_id => [cc_user_id, ...]]),
     * NOT secondary_manager_id (which several ops staff already share).
     */
    private function notifyLeaveCc(LeaveRequest $request): void
    {
        $ccIds = config('leave_notify_cc.' . $request->user_id, []);
        if (empty($ccIds)) {
            return;
        }

        $portalUrl = config('app.url') . '/#view=leave';

        $dates = $request->start_date->format('D, j M Y');
        if ($request->end_date) {
            $dates .= ' to ' . $request->end_date->format('D, j M Y');
        }

        $message = "*Leave FYI — {$request->user->name}*\n\n"
            . "{$request->user->name}'s {$request->leaveType->name} has been approved.\n"
            . "Dates: {$dates}\n"
            . "Days: {$request->total_days}\n"
            . "Reason: " . ($request->reason ?: 'Not specified') . "\n\n"
            . "You're notified as a dotted-line manager. <{$portalUrl}|View on Portal>";

        foreach (User::whereIn('id', (array) $ccIds)->get() as $cc) {
            $this->sendSlackDm($cc->name, $message);
        }
    }

    /**
     * Resolve the FYI managers who get a "Team updates" dashboard card for a
     * leaver. Union of two configs:
     *   - per-employee: config/leave_dashboard_cc.php            [employee_id => [mgr, ...]]
     *   - per-manager:  config/leave_dashboard_cc_by_manager.php [reporting_manager_id => [mgr, ...]]
     * The per-manager map is dynamic — "anyone reporting to manager X also FYIs
     * Y" — so a skip-level manager keeps visibility as the team changes without
     * per-person edits. The leaver and their own approving reporting manager are
     * never FYI'd (the reporting manager is already in the approval loop).
     */
    private function leaveDashboardCcManagerIds(LeaveRequest $request): array
    {
        $reportingManagerId = (int) ($request->user?->reporting_manager_id ?? 0);

        $merged = array_merge(
            (array) config('leave_dashboard_cc.' . $request->user_id, []),
            $reportingManagerId
                ? (array) config('leave_dashboard_cc_by_manager.' . $reportingManagerId, [])
                : []
        );

        $ids = array_values(array_unique(array_filter(array_map('intval', $merged))));

        return array_values(array_diff($ids, [(int) $request->user_id, $reportingManagerId]));
    }

    /**
     * Dotted-line dashboard FYI: on leave approval (manual or auto, ANY type),
     * drop a "Team updates" card for each FYI manager (see
     * leaveDashboardCcManagerIds(): per-employee config/leave_dashboard_cc.php
     * unioned with per-manager config/leave_dashboard_cc_by_manager.php). Used
     * for people whose approval sits with one manager but whose former/skip-level
     * manager should still SEE their time off on the dashboard.
     * Idempotent (re-approval updates the same row); cleared on cancellation.
     * Distinct from notifyLeaveCc() (Slack-only, different config).
     */
    private function upsertLeaveCcDashboardNotice(LeaveRequest $request): void
    {
        $managerIds = $this->leaveDashboardCcManagerIds($request);
        if (empty($managerIds)) {
            return;
        }

        $name = $request->user?->name ?? 'Employee';
        $type = $request->leaveType?->name ?? 'Leave';

        if ($request->leaveType?->slug === self::SLUG_COMPENSATE) {
            $when = 'off ' . $request->start_date->format('D, j M')
                . ' → working ' . ($request->compensation_date?->format('D, j M') ?? '—');
        } elseif ($request->hours > 0) {
            $when = $request->start_date->format('D, j M') . ', '
                . $request->from_time . '–' . $request->to_time;
        } else {
            $when = $request->start_date->format('D, j M');
            if ($request->end_date && $request->end_date->ne($request->start_date)) {
                $when .= ' → ' . $request->end_date->format('D, j M');
            }
        }

        $message = "Leave FYI — {$name}: {$type} approved ({$when})";
        // ManagerNotification.message is varchar(255).
        if (strlen($message) > 255) {
            $message = substr($message, 0, 252) . '...';
        }

        foreach ($managerIds as $managerId) {
            ManagerNotification::updateOrCreate(
                [
                    'manager_id' => $managerId,
                    'team_member_id' => $request->user_id,
                    'source' => 'leave_approved_fyi',
                    'source_ref' => (string) $request->id,
                ],
                [
                    'message' => $message,
                    'dismissed_at' => null,
                ]
            );
        }
    }

    private function clearLeaveCcDashboardNotice(LeaveRequest $request): void
    {
        ManagerNotification::where('source', 'leave_approved_fyi')
            ->where('source_ref', (string) $request->id)
            ->delete();
    }

    private function notifyManagerOfCancellation(LeaveRequest $request): void
    {
        $manager = $request->user->reportingManager;
        if (!$manager) {
            return;
        }

        $portalUrl = config('app.url') . '/#view=leave';

        $message = "*Leave Cancelled*\n\n"
            . "{$request->user->name} has cancelled their {$request->leaveType->name}.\n"
            . "Dates: {$request->start_date->format('D, j M Y')} to {$request->end_date->format('D, j M Y')}\n\n"
            . "<{$portalUrl}|View on Portal>";

        $this->sendSlackDm($manager->name, $message);
    }

    /**
     * Ping the approving manager that an employee wants to cancel an APPROVED
     * leave. Same manager resolution as notifyManagerForApproval (reportingManager
     * -> JP fallback for manager-less seniors; JP's own request has no approver).
     */
    private function notifyManagerOfCancellationRequest(LeaveRequest $request): void
    {
        $manager = $request->user->reportingManager;
        if (!$manager) {
            if ((int) $request->user_id === self::JP_USER_ID) {
                return;
            }
            $manager = User::find(self::JP_USER_ID);
        }
        if (!$manager) {
            Log::warning('LeaveService: No reporting manager for cancellation request', ['user_id' => $request->user_id]);
            return;
        }

        $portalUrl = config('app.url') . '/#view=leave';

        $isHourly = $request->hours > 0;
        $isCompensate = $request->leaveType?->slug === self::SLUG_COMPENSATE;
        if ($isCompensate) {
            $durationLine = "Day off: {$request->start_date->format('D, j M Y')}\n"
                . "Working instead on: " . ($request->compensation_date?->format('D, j M Y') ?? '—');
        } elseif ($isHourly) {
            $durationLine = "Date: {$request->start_date->format('D, j M Y')}\nTime: {$request->from_time} to {$request->to_time} ({$request->hours}h)";
        } else {
            $durationLine = "Dates: {$request->start_date->format('D, j M Y')} to {$request->end_date->format('D, j M Y')}\nDays: {$request->total_days}";
        }

        $message = "*Leave Cancellation Request from {$request->user->name}*\n\n"
            . "{$request->user->name} wants to cancel an approved {$request->leaveType->name}.\n"
            . "{$durationLine}\n"
            . "Reason: " . ($request->cancellation_reason ?: 'Not specified') . "\n\n"
            . "<{$portalUrl}|Approve or Reject the cancellation on Tessa Portal>";

        $this->sendSlackDm($manager->name, $message);
    }

    private function notifyEmployee(LeaveRequest $request, string $event): void
    {
        $isCompensate = $request->leaveType?->slug === self::SLUG_COMPENSATE;
        if ($isCompensate) {
            $offLabel = $request->start_date->format('D, j M');
            $workLabel = $request->compensation_date?->format('D, j M') ?? '—';
            $window = "off on {$offLabel}, working on {$workLabel}";
        } else {
            $window = $request->start_date->format('D, j M') . ' to ' . $request->end_date->format('D, j M');
            $window = "({$window})";
        }
        $reviewer = $request->reviewer ? " by {$request->reviewer->name}" : '';
        $note = $request->reviewer_note ? "\nNote: {$request->reviewer_note}" : '';
        $typeName = $request->leaveType->name;

        $messages = $isCompensate ? [
            'submitted' => "Your Compensate request — {$window} — has been submitted and is pending manager approval.",
            'auto_approved' => "Your Compensate request — {$window} — has been auto-approved. Your manager has been notified.",
            'approved' => "Your Compensate request — {$window} — has been approved{$reviewer}. You'll get a reminder on the compensation day.",
            'rejected' => "Your Compensate request — {$window} — has been rejected{$reviewer}.{$note}",
            'cancellation_requested' => "Your request to cancel your Compensate — {$window} — has been sent to your manager for approval.",
            'cancellation_approved' => "Your Compensate — {$window} — has been cancelled; your manager approved the cancellation.",
            'cancellation_rejected' => "Your request to cancel your Compensate — {$window} — was declined by your manager; it stands.{$note}",
        ] : [
            'submitted' => "Your {$typeName} request {$window} has been submitted and is pending manager approval.",
            'auto_approved' => "Your {$typeName} {$window} has been auto-approved. Your manager has been notified.",
            'approved' => "Your {$typeName} {$window} has been approved{$reviewer}.",
            'rejected' => "Your {$typeName} {$window} has been rejected{$reviewer}.{$note}",
            'cancellation_requested' => "Your request to cancel your {$typeName} {$window} has been sent to your manager for approval.",
            'cancellation_approved' => "Your {$typeName} {$window} has been cancelled; your manager approved the cancellation.",
            'cancellation_rejected' => "Your request to cancel your {$typeName} {$window} was declined by your manager; the leave stands.{$note}",
        ];

        if (isset($messages[$event])) {
            $this->sendSlackDm($request->user->name, $messages[$event]);
        }
    }

    /* ── HR notifications (compensate) ─────────────────────────────── */

    /**
     * Slack DM each HR user from config/hr_leave_alerts.php with the approved
     * Compensate swap so they know to expect a weekend sign-in + a weekday
     * absence. Quiet on regular leaves — those have separate channels.
     */
    private function notifyHrOfCompensateApproval(LeaveRequest $request): void
    {
        $hrIds = $this->hrUserIds();
        if (empty($hrIds)) {
            return;
        }

        $offLabel = $request->start_date->format('D, j M Y');
        $workLabel = $request->compensation_date?->format('D, j M Y') ?? '—';
        $reviewer = $request->reviewer?->name ?? 'manager';
        $portalUrl = config('app.url') . '/#view=leave';

        $message = "*Compensate Approved*\n\n"
            . "{$request->user->name} — off {$offLabel}, working {$workLabel}\n"
            . "Approved by {$reviewer}.\n\n"
            . "<{$portalUrl}|View on Portal>";

        foreach (User::whereIn('id', $hrIds)->where('is_active', true)->get(['name']) as $hr) {
            $this->sendSlackDm($hr->name, $message);
        }
    }

    /**
     * Surface the approved Compensate as a dashboard notification card on each
     * HR user's portal. Idempotent: re-approval (after a revert) updates the
     * same row; cancellation/rejection deletes it. Uses ManagerNotification
     * with `source=compensate_approved` and source_ref=leave_request_id.
     */
    private function upsertHrDashboardNotice(LeaveRequest $request): void
    {
        $hrIds = $this->hrUserIds();
        if (empty($hrIds)) {
            return;
        }

        $offLabel = $request->start_date->format('D, j M');
        $workLabel = $request->compensation_date?->format('D, j M') ?? '—';
        $employeeName = $request->user?->name ?? 'Employee';
        $message = "Compensate approved — {$employeeName}: off {$offLabel} → working {$workLabel}";
        // ManagerNotification.message is varchar(255); guard just in case a
        // very long name pushes it past the limit.
        if (strlen($message) > 255) {
            $message = substr($message, 0, 252) . '...';
        }

        foreach ($hrIds as $hrId) {
            ManagerNotification::updateOrCreate(
                [
                    'manager_id' => $hrId,
                    'team_member_id' => $request->user_id,
                    'source' => 'compensate_approved',
                    'source_ref' => (string) $request->id,
                ],
                [
                    'message' => $message,
                    'dismissed_at' => null,
                ]
            );
        }
    }

    private function clearHrDashboardNotice(LeaveRequest $request): void
    {
        ManagerNotification::where('source', 'compensate_approved')
            ->where('source_ref', (string) $request->id)
            ->delete();
    }

    /* ── HR notifications (WFH) ────────────────────────────────────── */

    /**
     * Build a "Mon, 3 Jun" or "Mon, 3 Jun → Wed, 5 Jun" range for the WFH dates.
     */
    private function wfhDateRange(LeaveRequest $request, string $arrow = ' to '): string
    {
        $range = $request->start_date->format('D, j M Y');
        if ($request->end_date && $request->end_date->ne($request->start_date)) {
            $range .= $arrow . $request->end_date->format('D, j M Y');
        }

        return $range;
    }

    /**
     * Slack DM each HR user (config/hr_leave_alerts.php) when a WFH is approved
     * so they know who's remote and on which days. Mirrors the compensate ping.
     */
    private function notifyHrOfWfhApproval(LeaveRequest $request): void
    {
        $hrIds = $this->hrUserIds();
        if (empty($hrIds)) {
            return;
        }

        $reviewer = $request->reviewer?->name ?? 'manager';
        $portalUrl = config('app.url') . '/#view=attendance';

        $message = ":house_with_garden: *Work From Home Approved*\n\n"
            . "{$request->user->name} will be working from home.\n"
            . "Dates: {$this->wfhDateRange($request)}\n"
            . "Reason: " . ($request->reason ?: 'Not specified') . "\n"
            . "Approved by {$reviewer}.\n\n"
            . "<{$portalUrl}|View attendance on Tessa>";

        foreach (User::whereIn('id', $hrIds)->where('is_active', true)->get(['name']) as $hr) {
            $this->sendSlackDm($hr->name, $message);
        }
    }

    /**
     * Surface the approved WFH as a dashboard card on each HR user's portal.
     * Idempotent (re-approval updates the same row); rejection/cancellation
     * deletes it. ManagerNotification source=wfh_approved, source_ref=request id.
     */
    private function upsertHrWfhDashboardNotice(LeaveRequest $request): void
    {
        $hrIds = $this->hrUserIds();
        if (empty($hrIds)) {
            return;
        }

        $employeeName = $request->user?->name ?? 'Employee';
        $message = "WFH approved — {$employeeName}: {$this->wfhDateRange($request, ' → ')}";
        if (strlen($message) > 255) {
            $message = substr($message, 0, 252) . '...';
        }

        foreach ($hrIds as $hrId) {
            ManagerNotification::updateOrCreate(
                [
                    'manager_id' => $hrId,
                    'team_member_id' => $request->user_id,
                    'source' => 'wfh_approved',
                    'source_ref' => (string) $request->id,
                ],
                [
                    'message' => $message,
                    'dismissed_at' => null,
                ]
            );
        }
    }

    private function clearHrWfhDashboardNotice(LeaveRequest $request): void
    {
        ManagerNotification::where('source', 'wfh_approved')
            ->where('source_ref', (string) $request->id)
            ->delete();
    }

    /**
     * @return int[]
     */
    private function hrUserIds(): array
    {
        return array_values(array_filter(array_map(
            'intval',
            (array) config('hr_leave_alerts.user_ids', [])
        )));
    }
}
