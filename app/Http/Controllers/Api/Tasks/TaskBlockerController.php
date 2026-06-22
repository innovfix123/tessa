<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\TaskAuthorization;
use App\Models\TaskBlocker;
use App\Models\TessaTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskBlockerController extends Controller
{
    use TaskAuthorization;

    public function index(TessaTask $task, Request $request): JsonResponse
    {
        $this->authorizeTaskAccess($task, $request->user());

        return response()->json([
            'blockers' => $task->blockers()->with('creator:id,name')->get()->map(fn ($b) => $this->format($b)),
        ]);
    }

    /**
     * Reporter-facing dashboard inbox: every active blocker on a task this user reported.
     * The blocker card disappears as soon as the assignee removes the blocker.
     */
    public function inbox(Request $request): JsonResponse
    {
        $user = $request->user();

        $blockers = TaskBlocker::query()
            ->whereNull('dismissed_by_reporter_at')
            ->whereHas('task', function ($q) use ($user) {
                $q->where('assigned_by', $user->id)
                    ->whereNotIn('status', ['completed', 'closed', 'cancelled']);
            })
            ->with(['task:id,title,assigned_to,assigned_by,status', 'task.assignedTo:id,name', 'creator:id,name'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $items = $blockers->map(function (TaskBlocker $b) {
            return [
                'id' => $b->id,
                'note' => $b->note,
                'created_at' => $b->created_at?->toIso8601String(),
                'created_by' => $b->creator ? ['id' => $b->creator->id, 'name' => $b->creator->name] : null,
                'task' => [
                    'id' => $b->task->id,
                    'title' => $b->task->title,
                    'assignee' => $b->task->assignedTo ? ['id' => $b->task->assignedTo->id, 'name' => $b->task->assignedTo->name] : null,
                ],
            ];
        })->values();

        return response()->json(['items' => $items]);
    }

    public function store(TessaTask $task, Request $request): JsonResponse
    {
        // Only the assignee adds blockers (it's their own "what's blocking me?" list).
        // Reporters and participants can view but not add.
        if ($task->assigned_to !== $request->user()->id) {
            abort(403, 'Only the assignee can add blockers.');
        }

        $validated = $request->validate([
            'note' => 'required|string|max:500',
        ]);

        $blocker = TaskBlocker::create([
            'task_id' => $task->id,
            'note' => trim($validated['note']),
            'created_by' => $request->user()->id,
        ]);
        $blocker->load('creator:id,name');

        return response()->json([
            'ok' => true,
            'blocker' => $this->format($blocker),
        ], 201);
    }

    public function destroy(TessaTask $task, TaskBlocker $blocker, Request $request): JsonResponse
    {
        if ($task->assigned_to !== $request->user()->id) {
            abort(403, 'Only the assignee can remove blockers.');
        }

        if ($blocker->task_id !== $task->id) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $blocker->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Reporter dismisses ALL currently-visible blockers on their tasks from
     * the dashboard inbox in one shot. The blocker rows still exist on each
     * task — assignees remain blocked until they clear them themselves. This
     * is purely an inbox dismiss for the reporter.
     */
    public function dismissAll(Request $request): JsonResponse
    {
        $user = $request->user();

        $dismissed = TaskBlocker::query()
            ->whereNull('dismissed_by_reporter_at')
            ->whereHas('task', function ($q) use ($user) {
                $q->where('assigned_by', $user->id)
                    ->whereNotIn('status', ['completed', 'closed', 'cancelled']);
            })
            ->update(['dismissed_by_reporter_at' => now()]);

        return response()->json(['ok' => true, 'dismissed' => $dismissed]);
    }

    private function format(TaskBlocker $b): array
    {
        return [
            'id' => $b->id,
            'note' => $b->note,
            'created_at' => $b->created_at?->toIso8601String(),
            'created_by' => $b->creator
                ? ['id' => $b->creator->id, 'name' => $b->creator->name]
                : null,
        ];
    }
}
