<?php

namespace App\Http\Controllers\Api\Rewards;

use App\Http\Controllers\Controller;
use App\Models\RewardPool;
use App\Services\RewardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reward Pools — a manager (Krishnan, config 'pool_creators') logs a weekly team
 * performance reward (title + description + amount) and sends it straight to the
 * payer's (Ayush, config 'payers') queue. No assignee, no submit/approve loop.
 */
class RewardPoolController extends Controller
{
    public function __construct(private RewardService $rewardService) {}

    /** The creator's own pools (Krishnan sees what he submitted + its status). */
    public function mine(Request $request): JsonResponse
    {
        $this->ensureCreator($request);

        $pools = RewardPool::with('paidBy:id,name')
            ->where('created_by', $request->user()->id)
            ->orderByRaw("FIELD(status, 'pending','paid')")
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json([
            'pools' => $pools->map(fn ($p) => $this->format($p)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureCreator($request);

        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:5000',
            'amount' => 'required|numeric|min:1|max:9999999.99',
        ]);

        try {
            $pool = $this->rewardService->createRewardPool($request->user(), $validated);

            return response()->json([
                'message' => 'Reward pool sent to the Pay queue. Finance has been notified.',
                'pool' => $this->format($pool),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /** Payer queue (Ayush): pending pools + recently paid. */
    public function pending(Request $request): JsonResponse
    {
        $this->ensurePayer($request);

        $pending = RewardPool::with('creator:id,name')
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->get();

        $recentPaid = RewardPool::with(['creator:id,name', 'paidBy:id,name'])
            ->where('status', 'paid')
            ->where('paid_at', '>=', now()->subDays(30))
            ->orderByDesc('paid_at')
            ->limit(50)
            ->get();

        return response()->json([
            'pending' => $pending->map(fn ($p) => $this->format($p)),
            'recent_paid' => $recentPaid->map(fn ($p) => $this->format($p)),
        ]);
    }

    public function markPaid(RewardPool $pool, Request $request): JsonResponse
    {
        $this->ensurePayer($request);

        $validated = $request->validate([
            'utr_number' => 'nullable|string|max:60',
            'note' => 'nullable|string|max:1000',
        ]);

        try {
            $pool = $this->rewardService->markPoolPaid(
                $request->user(),
                $pool,
                $validated['utr_number'] ?? null,
                $validated['note'] ?? null
            );

            return response()->json([
                'message' => 'Reward pool marked as paid. Creator notified.',
                'pool' => $this->format($pool),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    private function ensureCreator(Request $request): void
    {
        if (! $this->rewardService->isPoolCreator($request->user())) {
            abort(403, 'You are not authorized to create reward pools.');
        }
    }

    private function ensurePayer(Request $request): void
    {
        if (! in_array($request->user()->id, config('rewards.payers', []), true)) {
            abort(403, 'You are not authorized to settle reward pools.');
        }
    }

    private function format(RewardPool $p): array
    {
        return [
            'id' => $p->id,
            'title' => $p->title,
            'description' => $p->description,
            'amount' => (float) $p->amount,
            'status' => $p->status,
            'created_by' => $p->relationLoaded('creator') && $p->creator
                ? ['id' => $p->creator->id, 'name' => $p->creator->name]
                : null,
            'paid_by' => $p->paidBy ? ['id' => $p->paidBy->id, 'name' => $p->paidBy->name] : null,
            'paid_at' => $p->paid_at?->toIso8601String(),
            'utr_number' => $p->utr_number,
            'admin_note' => $p->admin_note,
            'created_at' => $p->created_at->toIso8601String(),
        ];
    }
}
