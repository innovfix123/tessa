<?php

namespace App\Http\Controllers\Api\HR;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\WeeklyTimesheet;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WeeklyTimesheetController extends Controller
{
    /** Roles that see the company-wide review (everyone's timesheet). */
    private const REVIEWER_ROLE_SLUGS = [
        Role::SLUG_CEO,
        Role::SLUG_COO,
        Role::SLUG_CFO,
        Role::SLUG_HR,
        Role::SLUG_HR_OPERATIONS,
        Role::SLUG_BUSINESS_ANALYST,
    ];

    private const MIN_SUMMARY_CHARS = 10;

    /** GET /weekly-timesheet/mine?week=YYYY-MM-DD — the caller's own week. */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        $weekStart = WeeklyTimesheet::mondayOf($request->query('week'));
        $weekEnd = $weekStart->copy()->addDays(6);
        $currentMonday = WeeklyTimesheet::mondayOf(null);

        $entry = WeeklyTimesheet::forUser($user->id)
            ->forWeek($weekStart->toDateString())
            ->first();

        $canFill = ! $this->isExcluded($user);
        // Can't log a week that hasn't started yet.
        $editable = $canFill && $weekStart->lte($currentMonday);

        return response()->json([
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'label' => $this->weekLabel($weekStart, $weekEnd),
            'is_current' => $weekStart->equalTo($currentMonday),
            'can_fill' => $canFill,
            'editable' => $editable,
            'entry' => $entry ? $this->formatEntry($entry) : null,
        ]);
    }

    /** POST /weekly-timesheet — upsert the caller's own week. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($this->isExcluded($user)) {
            return response()->json(['message' => 'You are not required to fill a weekly timesheet.'], 403);
        }

        $data = $request->validate([
            'week_start' => ['nullable', 'date'],
            'regular_hours' => ['required', 'numeric', 'min:0', 'max:168'],
            'overtime_hours' => ['required', 'numeric', 'min:0', 'max:168'],
            'regular_summary' => ['nullable', 'string', 'max:5000'],
            'overtime_summary' => ['nullable', 'string', 'max:5000'],
            'overtime_saturday' => ['nullable', 'boolean'],
            'overtime_sunday' => ['nullable', 'boolean'],
        ]);

        $weekStart = WeeklyTimesheet::mondayOf($data['week_start'] ?? null);
        $weekEnd = $weekStart->copy()->addDays(6);
        $currentMonday = WeeklyTimesheet::mondayOf(null);

        if ($weekStart->gt($currentMonday)) {
            throw ValidationException::withMessages(['week_start' => 'You cannot log a future week.']);
        }

        $regular = (float) $data['regular_hours'];
        $overtime = (float) $data['overtime_hours'];
        $regularSummary = trim((string) ($data['regular_summary'] ?? ''));
        $overtimeSummary = trim((string) ($data['overtime_summary'] ?? ''));

        if ($regular + $overtime <= 0) {
            throw ValidationException::withMessages(['regular_hours' => 'Enter the hours you worked this week.']);
        }
        if ($regular > 0 && mb_strlen($regularSummary) < self::MIN_SUMMARY_CHARS) {
            throw ValidationException::withMessages(['regular_summary' => 'Briefly describe your regular work (at least 10 characters).']);
        }
        if ($overtime > 0 && mb_strlen($overtimeSummary) < self::MIN_SUMMARY_CHARS) {
            throw ValidationException::withMessages(['overtime_summary' => 'Briefly describe your overtime work (at least 10 characters).']);
        }

        $sheet = WeeklyTimesheet::updateOrCreate(
            ['user_id' => $user->id, 'week_start' => $weekStart->toDateString()],
            [
                'week_end' => $weekEnd->toDateString(),
                'regular_hours' => $regular,
                'regular_summary' => $regularSummary !== '' ? $regularSummary : null,
                'overtime_hours' => $overtime,
                'overtime_summary' => $overtimeSummary !== '' ? $overtimeSummary : null,
                // Weekend-day flags are only meaningful with overtime hours — zero
                // them out otherwise so a stray tick can't orphan onto a 0h row.
                'overtime_saturday' => $overtime > 0 && $request->boolean('overtime_saturday'),
                'overtime_sunday' => $overtime > 0 && $request->boolean('overtime_sunday'),
                'total_hours' => $regular + $overtime,
                'status' => 'submitted',
                'submitted_at' => now(),
                'updated_by' => $user->id,
            ]
        );

        return response()->json([
            'ok' => true,
            'entry' => $this->formatEntry($sheet),
        ], 201);
    }

    /**
     * GET /weekly-timesheet/team?week=YYYY-MM-DD — manager/HR review + tracker.
     * Managers see their direct reports; company-wide reviewers see everyone.
     */
    public function team(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->canReview($user)) {
            return response()->json(['message' => 'You do not have access to the timesheet review.'], 403);
        }

        $weekStart = WeeklyTimesheet::mondayOf($request->query('week'));
        $weekEnd = $weekStart->copy()->addDays(6);
        $weekFriday = $weekStart->copy()->addDays(4);

        $excluded = array_map('intval', config('weekly_timesheet.excluded_user_ids', []));

        // Scope: company-wide reviewers see all active fillers; a plain manager
        // sees only their direct reports. (Indirect/secondary reports are out of
        // scope for now — keep it predictable.)
        $query = User::where('is_active', true)->whereNotIn('id', $excluded);
        if (! $this->isCompanyWideReviewer($user)) {
            $query->where('reporting_manager_id', $user->id);
        }
        $users = $query->orderBy('name')->get();

        $entries = WeeklyTimesheet::forWeek($weekStart->toDateString())
            ->whereIn('user_id', $users->pluck('id'))
            ->get()
            ->keyBy('user_id');

        // Users on approved leave for the WHOLE work week (Mon–Fri) genuinely
        // have nothing to log — flag them rather than counting them "pending".
        $onLeaveFull = LeaveRequest::where('status', 'approved')
            ->whereIn('user_id', $users->pluck('id'))
            ->where('start_date', '<=', $weekStart->toDateString())
            ->where('end_date', '>=', $weekFriday->toDateString())
            ->pluck('user_id')
            ->flip();

        $rows = $users->map(function (User $u) use ($entries, $onLeaveFull) {
            $entry = $entries->get($u->id);
            $status = $entry ? 'submitted' : ($onLeaveFull->has($u->id) ? 'on_leave' : 'pending');

            return [
                'user' => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'photo' => $u->profile_photo_url,
                ],
                'status' => $status,
                'regular_hours' => $entry ? (float) $entry->regular_hours : null,
                'overtime_hours' => $entry ? (float) $entry->overtime_hours : null,
                'total_hours' => $entry ? (float) $entry->total_hours : null,
                'regular_summary' => $entry?->regular_summary,
                'overtime_summary' => $entry?->overtime_summary,
                'overtime_saturday' => $entry ? (bool) $entry->overtime_saturday : false,
                'overtime_sunday' => $entry ? (bool) $entry->overtime_sunday : false,
                'submitted_at' => $entry?->submitted_at?->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'label' => $this->weekLabel($weekStart, $weekEnd),
            'is_current' => $weekStart->equalTo(WeeklyTimesheet::mondayOf(null)),
            'scope' => $this->isCompanyWideReviewer($user) ? 'company' : 'team',
            'summary' => [
                'total' => $rows->count(),
                'submitted' => $rows->where('status', 'submitted')->count(),
                'pending' => $rows->where('status', 'pending')->count(),
                'on_leave' => $rows->where('status', 'on_leave')->count(),
            ],
            'rows' => $rows,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function isExcluded(User $user): bool
    {
        return in_array((int) $user->id, array_map('intval', config('weekly_timesheet.excluded_user_ids', [])), true);
    }

    private function isCompanyWideReviewer(User $user): bool
    {
        if (in_array($user->role, self::REVIEWER_ROLE_SLUGS, true)) {
            return true;
        }

        return in_array((int) $user->id, array_map('intval', config('weekly_timesheet.reviewer_user_ids', [])), true);
    }

    private function canReview(User $user): bool
    {
        if ($this->isCompanyWideReviewer($user)) {
            return true;
        }

        // Any manager with at least one active direct report.
        return User::where('reporting_manager_id', $user->id)->where('is_active', true)->exists();
    }

    private function formatEntry(WeeklyTimesheet $sheet): array
    {
        return [
            'id' => $sheet->id,
            'week_start' => $sheet->week_start->toDateString(),
            'week_end' => $sheet->week_end->toDateString(),
            'regular_hours' => (float) $sheet->regular_hours,
            'regular_summary' => $sheet->regular_summary,
            'overtime_hours' => (float) $sheet->overtime_hours,
            'overtime_summary' => $sheet->overtime_summary,
            'overtime_saturday' => (bool) $sheet->overtime_saturday,
            'overtime_sunday' => (bool) $sheet->overtime_sunday,
            'total_hours' => (float) $sheet->total_hours,
            'status' => $sheet->status,
            'submitted_at' => $sheet->submitted_at?->toIso8601String(),
        ];
    }

    private function weekLabel(Carbon $start, Carbon $end): string
    {
        // e.g. "9 – 15 Jun 2026" or "30 Jun – 6 Jul 2026"
        if ($start->month === $end->month) {
            return $start->format('j') . ' – ' . $end->format('j M Y');
        }

        return $start->format('j M') . ' – ' . $end->format('j M Y');
    }
}
