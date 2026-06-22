<?php

namespace App\Http\Controllers\Api\HR;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Timesheet;
use App\Models\WorkforcePayment;
use App\Services\TimesheetService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimesheetController extends Controller
{
    private TimesheetService $service;

    public function __construct()
    {
        $this->service = app(TimesheetService::class);
    }

    /**
     * Return the user's timesheets for a given week (Mon–Sun) plus lock status.
     */
    public function week(Request $request): JsonResponse
    {
        $user = $request->user();
        $weekStartParam = $request->query('start');
        $weekStart = $weekStartParam
            ? Carbon::parse($weekStartParam)->startOfWeek(Carbon::MONDAY)
            : Carbon::now('Asia/Kolkata')->startOfWeek(Carbon::MONDAY);

        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);

        $sheets = Timesheet::with('timeSlots')
            ->where('user_id', $user->id)
            ->whereBetween('work_date', [$weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d')])
            ->orderBy('work_date')
            ->get();

        $isLocked = WorkforcePayment::where('user_id', $user->id)
            ->where('week_start', $weekStart->format('Y-m-d'))
            ->where('status', 'paid')
            ->exists();

        $payment = WorkforcePayment::where('user_id', $user->id)
            ->where('week_start', $weekStart->format('Y-m-d'))
            ->first();

        return response()->json([
            'week_start' => $weekStart->format('Y-m-d'),
            'week_end' => $weekEnd->format('Y-m-d'),
            'locked' => $isLocked,
            'hourly_rate' => (float) $user->hourly_rate,
            'timesheets' => $sheets->map(fn ($s) => $this->formatSheet($s))->values(),
            'totals' => [
                'total_hours' => (float) $sheets->sum('total_hours'),
                'regular_hours' => (float) $sheets->sum('regular_hours'),
                'overtime_hours' => (float) $sheets->sum('overtime_hours'),
                'amount' => (float) $sheets->sum('amount'),
            ],
            'payment' => $payment ? [
                'status' => $payment->status,
                'utr_number' => $payment->utr_number,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'total_amount' => (float) $payment->total_amount,
            ] : null,
        ]);
    }

    /**
     * Quick stats (current month) for the dashboard card.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $now = Carbon::now('Asia/Kolkata');
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();

        $sheets = Timesheet::where('user_id', $user->id)
            ->whereBetween('work_date', [$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')])
            ->get();

        $weekStart = $now->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $now->copy()->endOfWeek(Carbon::SUNDAY);

        $weekSheets = $sheets->filter(function ($s) use ($weekStart, $weekEnd) {
            $d = Carbon::parse($s->work_date);
            return $d->between($weekStart, $weekEnd);
        });

        return response()->json([
            'month_start' => $monthStart->format('Y-m-d'),
            'month_end' => $monthEnd->format('Y-m-d'),
            'month' => [
                'days_worked' => $sheets->count(),
                'total_hours' => (float) $sheets->sum('total_hours'),
                'regular_hours' => (float) $sheets->sum('regular_hours'),
                'overtime_hours' => (float) $sheets->sum('overtime_hours'),
                'amount' => (float) $sheets->sum('amount'),
            ],
            'this_week' => [
                'days_worked' => $weekSheets->count(),
                'overtime_hours' => (float) $weekSheets->sum('overtime_hours'),
                'amount' => (float) $weekSheets->sum('amount'),
            ],
            'hourly_rate' => (float) $user->hourly_rate,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            // "today" in IST — the app runs in UTC, but the timesheet UI sends
            // the user's local (IST) date, so a plain before_or_equal:today would
            // reject same-day entries logged after 18:30 UTC (evening IST).
            'work_date' => ['required', 'date', 'before_or_equal:' . \Carbon\Carbon::today('Asia/Kolkata')->toDateString()],
            'slots' => 'required|array|min:1',
            'slots.*.start_time' => 'required|string',
            'slots.*.end_time' => 'required|string',
            'slots.*.type' => 'nullable|in:regular,overtime',
            'slots.*.description' => 'required|string',
        ]);

        $user = $request->user();
        $isAdmin = $user->role === Role::SLUG_ADMIN;

        try {
            $sheet = $this->service->createOrUpdate(
                $user,
                $request->input('work_date'),
                $request->input('slots'),
                $isAdmin,
                'web'
            );

            return response()->json([
                'message' => 'Timesheet saved.',
                'timesheet' => $this->formatSheet($sheet),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function destroy(Timesheet $timesheet, Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->role === Role::SLUG_ADMIN;

        try {
            $this->service->delete($user, $timesheet, $isAdmin);
            return response()->json(['message' => 'Timesheet deleted.']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    private function formatSheet(Timesheet $s): array
    {
        return [
            'id' => $s->id,
            'work_date' => $s->work_date->format('Y-m-d'),
            'week_start' => $s->week_start->format('Y-m-d'),
            'total_hours' => (float) $s->total_hours,
            'regular_hours' => (float) $s->regular_hours,
            'overtime_hours' => (float) $s->overtime_hours,
            'amount' => (float) $s->amount,
            'hourly_rate_snapshot' => (float) $s->hourly_rate_snapshot,
            'locked' => $s->isLocked(),
            'time_slots' => $s->timeSlots->map(fn ($t) => [
                'id' => $t->id,
                'start_time' => substr($t->start_time, 0, 5),
                'end_time' => substr($t->end_time, 0, 5),
                'duration_hours' => (float) $t->duration_hours,
                'type' => $t->type,
                'description' => $t->description,
            ])->values(),
            'created_at' => $s->created_at->toIso8601String(),
            'updated_at' => $s->updated_at->toIso8601String(),
        ];
    }
}
