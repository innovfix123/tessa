<?php

namespace App\Http\Controllers\Api\Rewards;

use App\Http\Controllers\Controller;
use App\Models\RewardWithdrawal;
use App\Services\RewardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RewardWithdrawalController extends Controller
{
    public function __construct(private RewardService $rewardService) {}

    public function mine(Request $request): JsonResponse
    {
        $rows = RewardWithdrawal::with(['paidBy:id,name', 'rewardTask:id,title'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json([
            'withdrawals' => $rows->map(fn ($w) => $this->format($w)),
        ]);
    }

    public function pending(Request $request): JsonResponse
    {
        $this->ensurePayer($request);

        $pending = RewardWithdrawal::with(['user:id,name', 'rewardTask:id,title'])
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->get();

        $recentPaid = RewardWithdrawal::with(['user:id,name', 'paidBy:id,name', 'rewardTask:id,title'])
            ->where('status', 'paid')
            ->where('paid_at', '>=', now()->subDays(30))
            ->orderByDesc('paid_at')
            ->limit(50)
            ->get();

        return response()->json([
            'pending' => $pending->map(fn ($w) => $this->format($w)),
            'recent_paid' => $recentPaid->map(fn ($w) => $this->format($w)),
        ]);
    }

    public function markPaid(RewardWithdrawal $withdrawal, Request $request): JsonResponse
    {
        $this->ensurePayer($request);

        $validated = $request->validate([
            'utr_number' => 'nullable|string|max:60',
            'note' => 'nullable|string|max:1000',
        ]);

        try {
            $withdrawal = $this->rewardService->markPaid(
                $request->user(),
                $withdrawal,
                $validated['utr_number'] ?? null,
                $validated['note'] ?? null
            );
            return response()->json([
                'message' => 'Withdrawal marked as paid. Employee notified.',
                'withdrawal' => $this->format($withdrawal),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    private function ensurePayer(Request $request): void
    {
        $payers = config('rewards.payers', []);
        if (! in_array($request->user()->id, $payers, true)) {
            abort(403, 'You are not authorized to mark withdrawals paid.');
        }
    }

    private function format(RewardWithdrawal $w): array
    {
        return [
            'id' => $w->id,
            'user' => $w->relationLoaded('user') && $w->user
                ? ['id' => $w->user->id, 'name' => $w->user->name]
                : null,
            'reward_task' => $w->rewardTask ? ['id' => $w->rewardTask->id, 'title' => $w->rewardTask->title] : null,
            'amount' => (float) $w->amount,
            'status' => $w->status,
            'requested_at' => $w->requested_at?->toIso8601String(),
            'paid_at' => $w->paid_at?->toIso8601String(),
            'paid_by' => $w->paidBy ? ['id' => $w->paidBy->id, 'name' => $w->paidBy->name] : null,
            'utr_number' => $w->utr_number,
            'employee_note' => $w->employee_note,
            'admin_note' => $w->admin_note,
            'cancel_reason' => $w->cancel_reason,
            'created_at' => $w->created_at->toIso8601String(),
        ];
    }
}
