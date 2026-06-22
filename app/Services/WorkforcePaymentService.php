<?php

namespace App\Services;

use App\Models\Timesheet;
use App\Models\User;
use App\Models\WorkforcePayment;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkforcePaymentService
{
    public function __construct(private SlackService $slackService) {}

    /**
     * Sync the workforce_payments aggregate row for each user with overtime in the given week.
     * Never overwrites the status of a paid row, but does refresh OT/amount snapshots.
     */
    public function syncWeekAggregates(Carbon $weekStart): Collection
    {
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
        $rows = Timesheet::forWeek($weekStart->format('Y-m-d'))
            ->select('user_id', DB::raw('SUM(overtime_hours) as ot'), DB::raw('SUM(amount) as amt'))
            ->groupBy('user_id')
            ->get();

        return $rows->map(function ($r) use ($weekStart, $weekEnd) {
            $payment = WorkforcePayment::firstOrNew([
                'user_id' => $r->user_id,
                'week_start' => $weekStart->format('Y-m-d'),
            ]);
            $payment->week_end = $weekEnd->format('Y-m-d');
            $payment->total_overtime_hours = $r->ot;
            $payment->total_amount = $r->amt;
            if (! $payment->exists) {
                $payment->status = 'pending';
            }
            $payment->save();
            return $payment;
        });
    }

    public function markPaid(
        User $admin,
        int $userId,
        Carbon $weekStart,
        string $utr,
        ?UploadedFile $screenshot = null,
        ?string $note = null
    ): WorkforcePayment {
        // Capture any late-OT before snapshotting amount.
        $this->syncWeekAggregates($weekStart);

        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);

        $attrs = [
            'status' => 'paid',
            'utr_number' => $utr,
            'admin_note' => $note,
            'paid_by' => $admin->id,
            'paid_at' => now(),
            'week_end' => $weekEnd->format('Y-m-d'),
        ];

        if ($screenshot) {
            $year = $weekStart->format('Y');
            $month = $weekStart->format('m');
            $attrs['payment_screenshot_path'] = $screenshot->store(
                "workforce/payment-screenshots/{$year}/{$month}",
                'public'
            );
        }

        $payment = WorkforcePayment::updateOrCreate(
            ['user_id' => $userId, 'week_start' => $weekStart->format('Y-m-d')],
            $attrs
        );

        // Re-sync to capture the actual OT/amount snapshot from timesheets (in case the
        // syncWeekAggregates above ran before any pending TS were created in this window).
        $weekRow = Timesheet::forWeek($weekStart->format('Y-m-d'))
            ->where('user_id', $userId)
            ->select(DB::raw('COALESCE(SUM(overtime_hours), 0) as ot'), DB::raw('COALESCE(SUM(amount), 0) as amt'))
            ->first();
        $payment->update([
            'total_overtime_hours' => $weekRow->ot ?? 0,
            'total_amount' => $weekRow->amt ?? 0,
        ]);

        $this->notifyEmployeePaid($payment);

        Log::info('WorkforcePaymentService: marked paid', [
            'payment_id' => $payment->id,
            'user_id' => $userId,
            'week_start' => $weekStart->format('Y-m-d'),
            'amount' => $payment->total_amount,
            'admin_id' => $admin->id,
        ]);

        return $payment->fresh(['user', 'paidBy']);
    }

    public function bulkMarkPaid(User $admin, Carbon $weekStart, string $utrTemplate, ?string $note = null): array
    {
        $this->syncWeekAggregates($weekStart);

        $rows = WorkforcePayment::where('week_start', $weekStart->format('Y-m-d'))
            ->where('status', 'pending')
            ->where('total_amount', '>', 0)
            ->get();

        $marked = 0;
        foreach ($rows as $r) {
            $this->markPaid($admin, $r->user_id, $weekStart, $utrTemplate, null, $note);
            $marked++;
        }

        return ['marked' => $marked];
    }

    public function weeklySummary(Carbon $weekStart): array
    {
        $this->syncWeekAggregates($weekStart);

        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
        $payments = WorkforcePayment::with('user:id,name,email')
            ->where('week_start', $weekStart->format('Y-m-d'))
            ->orderByDesc('total_amount')
            ->get();

        // Per-user timesheet rollup for the week — gives us days/regular/total
        // alongside the OT-only data we already track in workforce_payments.
        $timesheetRollup = Timesheet::forWeek($weekStart->format('Y-m-d'))
            ->select('user_id',
                DB::raw('COUNT(*) as days_worked'),
                DB::raw('SUM(total_hours) as total_hours'),
                DB::raw('SUM(regular_hours) as regular_hours'),
                DB::raw('SUM(overtime_hours) as overtime_hours'))
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $totalAmount = (float) $payments->sum('total_amount');
        $paidAmount = (float) $payments->where('status', 'paid')->sum('total_amount');
        $pendingAmount = $totalAmount - $paidAmount;
        $totalHours = (float) $timesheetRollup->sum('total_hours');
        $totalRegularHours = (float) $timesheetRollup->sum('regular_hours');
        $totalOvertimeHours = (float) $timesheetRollup->sum('overtime_hours');

        return [
            'week_start' => $weekStart->format('Y-m-d'),
            'week_end' => $weekEnd->format('Y-m-d'),
            'total_amount' => round($totalAmount, 2),
            'paid_amount' => round($paidAmount, 2),
            'pending_amount' => round($pendingAmount, 2),
            'total_hours' => round($totalHours, 2),
            'total_regular_hours' => round($totalRegularHours, 2),
            'total_overtime_hours' => round($totalOvertimeHours, 2),
            'paid_count' => $payments->where('status', 'paid')->count(),
            'pending_count' => $payments->where('status', 'pending')->count(),
            'rows' => $payments->map(function ($p) use ($timesheetRollup) {
                $ts = $timesheetRollup->get($p->user_id);
                return [
                    'id' => $p->id,
                    'user_id' => $p->user_id,
                    'user_name' => $p->user?->name,
                    'days_worked' => $ts ? (int) $ts->days_worked : 0,
                    'regular_hours' => $ts ? (float) $ts->regular_hours : 0.0,
                    'total_hours' => $ts ? (float) $ts->total_hours : 0.0,
                    'total_overtime_hours' => (float) $p->total_overtime_hours,
                    'total_amount' => (float) $p->total_amount,
                    'status' => $p->status,
                    'utr_number' => $p->utr_number,
                    'paid_at' => $p->paid_at?->toIso8601String(),
                    'has_screenshot' => ! empty($p->payment_screenshot_path),
                ];
            })->values(),
        ];
    }

    private function notifyEmployeePaid(WorkforcePayment $payment): void
    {
        if (! $payment->relationLoaded('user')) {
            $payment->load('user');
        }
        $user = $payment->user;
        if (! $user) {
            return;
        }

        $portalUrl = config('app.url') . '/#view=timesheets';
        $weekRange = Carbon::parse($payment->week_start)->format('D, j M')
            . ' to ' . Carbon::parse($payment->week_end)->format('D, j M Y');

        $msg = "*Overtime Payment Processed*\n\n"
            . "Hi {$user->name}, your overtime payment for the week {$weekRange} has been processed.\n"
            . "Amount: ₹" . number_format((float) $payment->total_amount, 2) . "\n"
            . "Hours: " . (float) $payment->total_overtime_hours . "h\n"
            . ($payment->utr_number ? "UTR: {$payment->utr_number}\n" : '')
            . "\n<{$portalUrl}|View on Tessa Portal>";

        try {
            $sid = $this->slackService->getUserIdByName($user->name);
            if ($sid) {
                // bypassQuietWindow=true: payment confirmation is time-sensitive (matches LeaveService posture).
                $this->slackService->sendDirectMessage($sid, $msg, bypassQuietWindow: true);
            }
        } catch (\Throwable $e) {
            Log::error('WorkforcePaymentService: slack notify failed', [
                'user' => $user->name,
                'err' => $e->getMessage(),
            ]);
        }
    }
}
