<?php

namespace App\Http\Controllers\Api\Rewards;

use App\Http\Controllers\Controller;
use App\Models\RewardTask;
use App\Services\RewardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RewardTaskController extends Controller
{
    public function __construct(private RewardService $rewardService) {}

    public function mine(Request $request): JsonResponse
    {
        $rows = RewardTask::with(['assigner:id,name', 'reviewer:id,name', 'updates.user:id,name', 'withdrawal'])
            ->forAssignee($request->user()->id)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json([
            'tasks' => $rows->map(fn ($t) => $this->format($t, withUpdates: true)),
        ]);
    }

    public function show(RewardTask $task, Request $request): JsonResponse
    {
        $user = $request->user();
        $isReviewer = in_array($user->id, config('rewards.reviewers', []), true);
        $isAssignee = $task->assigned_to_id === $user->id;
        if (! $isReviewer && ! $isAssignee) {
            abort(403, 'Not authorized to view this task.');
        }

        $task->load(['assignee:id,name', 'assigner:id,name', 'reviewer:id,name', 'updates.user:id,name', 'withdrawal']);

        return response()->json([
            'task' => $this->format($task, withUpdates: true, withAssignee: true),
        ]);
    }

    public function manage(Request $request): JsonResponse
    {
        $this->ensureReviewer($request);

        $tasks = RewardTask::with(['assignee:id,name', 'assigner:id,name', 'reviewer:id,name', 'updates.user:id,name', 'withdrawal'])
            ->orderByRaw("FIELD(status, 'submitted','assigned','approved','rejected')")
            ->orderByDesc('created_at')
            ->limit(300)
            ->get();

        return response()->json([
            'tasks' => $tasks->map(fn ($t) => $this->format($t, withUpdates: true, withAssignee: true)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureReviewer($request);

        $validated = $request->validate([
            'assigned_to_id' => 'required|integer|exists:users,id',
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:5000',
            'amount' => 'required|numeric|min:1|max:9999999.99',
            'deadline' => 'nullable|date',
        ]);

        try {
            $task = $this->rewardService->assignTask($request->user(), $validated);
            return response()->json([
                'message' => 'Reward task assigned. Assignee has been notified.',
                'task' => $this->format($task, withAssignee: true),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function update(RewardTask $task, Request $request): JsonResponse
    {
        $this->ensureReviewer($request);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:200',
            'description' => 'sometimes|nullable|string|max:5000',
            'amount' => 'sometimes|numeric|min:1|max:9999999.99',
            'deadline' => 'sometimes|nullable|date',
        ]);

        try {
            $task = $this->rewardService->updateTask($request->user(), $task, $validated);
            return response()->json([
                'message' => 'Task updated.',
                'task' => $this->format($task, withAssignee: true),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function postUpdate(RewardTask $task, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'note' => 'required|string|max:2000',
            'evidence_url' => 'nullable|string|max:500',
        ]);

        try {
            $update = $this->rewardService->addUpdate($request->user(), $task, $validated);
            $update->load('user:id,name');
            return response()->json([
                'message' => 'Update posted.',
                'update' => $this->formatUpdate($update),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function submit(RewardTask $task, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'note' => 'nullable|string|max:2000',
            'evidence_url' => 'nullable|string|max:500',
        ]);

        try {
            $task = $this->rewardService->submitTask($request->user(), $task, $validated);
            return response()->json([
                'message' => 'Submitted for review.',
                'task' => $this->format($task, withAssignee: true),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function approve(RewardTask $task, Request $request): JsonResponse
    {
        $this->ensureReviewer($request);

        $validated = $request->validate([
            'final_amount' => 'nullable|numeric|min:0|max:9999999.99',
            'note' => 'nullable|string|max:2000',
        ]);

        try {
            $task = $this->rewardService->approveTask(
                $request->user(),
                $task,
                $validated['final_amount'] ?? null,
                $validated['note'] ?? null
            );
            return response()->json([
                'message' => 'Task approved.',
                'task' => $this->format($task, withAssignee: true),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function reject(RewardTask $task, Request $request): JsonResponse
    {
        $this->ensureReviewer($request);

        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        try {
            $task = $this->rewardService->rejectTask($request->user(), $task, $validated['reason']);
            return response()->json([
                'message' => 'Task rejected.',
                'task' => $this->format($task, withAssignee: true),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    private function ensureReviewer(Request $request): void
    {
        $reviewers = config('rewards.reviewers', []);
        if (! in_array($request->user()->id, $reviewers, true)) {
            abort(403, 'You are not authorized to manage reward tasks.');
        }
    }

    private function format(RewardTask $t, bool $withUpdates = false, bool $withAssignee = false): array
    {
        $out = [
            'id' => $t->id,
            'title' => $t->title,
            'description' => $t->description,
            'amount' => (float) $t->amount,
            'final_amount' => $t->final_amount !== null ? (float) $t->final_amount : null,
            'deadline' => $t->deadline?->format('Y-m-d'),
            'status' => $t->status,
            'is_overdue' => $t->isOverdue(),
            'is_locked' => $t->isLocked(),
            'submission_note' => $t->submission_note,
            'submission_evidence_url' => $t->submission_evidence_url,
            'submitted_at' => $t->submitted_at?->toIso8601String(),
            'reviewed_at' => $t->reviewed_at?->toIso8601String(),
            'review_note' => $t->review_note,
            'created_at' => $t->created_at->toIso8601String(),
            'assigner' => $t->assigner ? ['id' => $t->assigner->id, 'name' => $t->assigner->name] : null,
            'reviewer' => $t->reviewer ? ['id' => $t->reviewer->id, 'name' => $t->reviewer->name] : null,
            'withdrawal' => $t->withdrawal ? [
                'id' => $t->withdrawal->id,
                'status' => $t->withdrawal->status,
                'amount' => (float) $t->withdrawal->amount,
                'paid_at' => $t->withdrawal->paid_at?->toIso8601String(),
                'utr_number' => $t->withdrawal->utr_number,
            ] : null,
        ];

        if ($withAssignee) {
            $out['assignee'] = $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name] : null;
        }
        if ($withUpdates) {
            $out['updates'] = $t->updates->map(fn ($u) => $this->formatUpdate($u))->values();
        }

        return $out;
    }

    private function formatUpdate($u): array
    {
        return [
            'id' => $u->id,
            'note' => $u->note,
            'evidence_url' => $u->evidence_url,
            'user' => $u->user ? ['id' => $u->user->id, 'name' => $u->user->name] : null,
            'created_at' => $u->created_at->toIso8601String(),
        ];
    }
}
