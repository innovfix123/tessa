<?php

namespace App\Http\Controllers\Api\Workforce;

use App\Http\Controllers\Controller;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\WorkforcePayment;
use App\Services\WorkforcePaymentService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkforcePaymentController extends Controller
{
    private WorkforcePaymentService $service;

    public function __construct()
    {
        $this->service = app(WorkforcePaymentService::class);
    }

    /**
     * Recent payments + filters.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $userId = $request->query('user_id');

        $query = WorkforcePayment::with(['user:id,name', 'paidBy:id,name'])
            ->orderByDesc('week_start');

        if ($status) {
            $query->where('status', $status);
        }
        if ($userId) {
            $query->where('user_id', (int) $userId);
        }

        $rows = $query->limit(200)->get();

        $totalPaid = (float) WorkforcePayment::paid()->sum('total_amount');
        $totalPending = (float) WorkforcePayment::pending()->sum('total_amount');

        return response()->json([
            'stats' => [
                'total_paid' => $totalPaid,
                'total_pending' => $totalPending,
                'pending_count' => WorkforcePayment::pending()->count(),
                'paid_count' => WorkforcePayment::paid()->count(),
            ],
            'payments' => $rows->map(fn ($p) => $this->formatPayment($p))->values(),
        ]);
    }

    public function weekSummary(Request $request): JsonResponse
    {
        $weekStart = $request->query('week')
            ? Carbon::parse($request->query('week'))->startOfWeek(Carbon::MONDAY)
            : Carbon::now('Asia/Kolkata')->startOfWeek(Carbon::MONDAY);

        return response()->json($this->service->weeklySummary($weekStart));
    }

    public function userWeek(int $userId, Request $request): JsonResponse
    {
        $user = User::findOrFail($userId);
        $weekStart = $request->query('week')
            ? Carbon::parse($request->query('week'))->startOfWeek(Carbon::MONDAY)
            : Carbon::now('Asia/Kolkata')->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);

        $sheets = Timesheet::with('timeSlots')
            ->where('user_id', $user->id)
            ->whereBetween('work_date', [$weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d')])
            ->orderBy('work_date')
            ->get();

        $payment = WorkforcePayment::where('user_id', $user->id)
            ->where('week_start', $weekStart->format('Y-m-d'))
            ->first();

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'hourly_rate' => (float) $user->hourly_rate],
            'week_start' => $weekStart->format('Y-m-d'),
            'week_end' => $weekEnd->format('Y-m-d'),
            'timesheets' => $sheets->map(fn ($s) => [
                'id' => $s->id,
                'work_date' => $s->work_date->format('Y-m-d'),
                'total_hours' => (float) $s->total_hours,
                'regular_hours' => (float) $s->regular_hours,
                'overtime_hours' => (float) $s->overtime_hours,
                'amount' => (float) $s->amount,
                'time_slots' => $s->timeSlots->map(fn ($t) => [
                    'start_time' => substr($t->start_time, 0, 5),
                    'end_time' => substr($t->end_time, 0, 5),
                    'duration_hours' => (float) $t->duration_hours,
                    'type' => $t->type,
                    'description' => $t->description,
                ])->values(),
            ])->values(),
            'totals' => [
                'total_hours' => (float) $sheets->sum('total_hours'),
                'overtime_hours' => (float) $sheets->sum('overtime_hours'),
                'amount' => (float) $sheets->sum('amount'),
            ],
            'payment' => $payment ? $this->formatPayment($payment) : null,
        ]);
    }

    public function markPaid(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'week_start' => 'required|date',
            'utr_number' => 'nullable|string|max:60',
            'admin_note' => 'nullable|string|max:500',
            'payment_screenshot' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
        ]);

        $weekStart = Carbon::parse($request->input('week_start'))->startOfWeek(Carbon::MONDAY);

        $payment = $this->service->markPaid(
            $request->user(),
            (int) $request->input('user_id'),
            $weekStart,
            (string) $request->input('utr_number', ''),
            $request->file('payment_screenshot'),
            $request->input('admin_note')
        );

        return response()->json([
            'message' => 'Payment marked as paid.',
            'payment' => $this->formatPayment($payment),
        ]);
    }

    public function bulkMarkPaid(Request $request): JsonResponse
    {
        $request->validate([
            'week_start' => 'required|date',
            'utr_number' => 'nullable|string|max:60',
            'admin_note' => 'nullable|string|max:500',
        ]);

        $weekStart = Carbon::parse($request->input('week_start'))->startOfWeek(Carbon::MONDAY);

        $result = $this->service->bulkMarkPaid(
            $request->user(),
            $weekStart,
            (string) $request->input('utr_number', ''),
            $request->input('admin_note')
        );

        return response()->json([
            'message' => "Marked {$result['marked']} user(s) as paid.",
            'marked' => $result['marked'],
        ]);
    }

    public function screenshot(WorkforcePayment $payment): StreamedResponse|JsonResponse
    {
        if (! $payment->payment_screenshot_path) {
            return response()->json(['error' => 'No screenshot on this payment.'], 404);
        }
        if (! Storage::disk('public')->exists($payment->payment_screenshot_path)) {
            return response()->json(['error' => 'Screenshot file missing on disk.'], 404);
        }
        return Storage::disk('public')->response($payment->payment_screenshot_path);
    }

    private function formatPayment(WorkforcePayment $p): array
    {
        return [
            'id' => $p->id,
            'user' => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name] : null,
            'week_start' => $p->week_start->format('Y-m-d'),
            'week_end' => $p->week_end->format('Y-m-d'),
            'total_overtime_hours' => (float) $p->total_overtime_hours,
            'total_amount' => (float) $p->total_amount,
            'status' => $p->status,
            'utr_number' => $p->utr_number,
            'admin_note' => $p->admin_note,
            'paid_by' => $p->paidBy ? ['id' => $p->paidBy->id, 'name' => $p->paidBy->name] : null,
            'paid_at' => $p->paid_at?->toIso8601String(),
            'has_screenshot' => ! empty($p->payment_screenshot_path),
        ];
    }
}
