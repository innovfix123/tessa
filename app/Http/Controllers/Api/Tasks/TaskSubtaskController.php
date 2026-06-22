<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\TaskAuthorization;
use App\Models\TaskSubtask;
use App\Models\TessaTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskSubtaskController extends Controller
{
    use TaskAuthorization;
    public function index(TessaTask $task, Request $request): JsonResponse
    {
        $this->authorizeTaskOwner($task, $request->user());

        $subtasks = $task->subtasks()->get()->map(fn ($s) => [
            'id' => $s->id,
            'title' => $s->title,
            'is_completed' => $s->is_completed,
            'sort_order' => $s->sort_order,
        ]);

        return response()->json(['subtasks' => $subtasks]);
    }

    public function store(TessaTask $task, Request $request): JsonResponse
    {
        $this->authorizeTaskOwner($task, $request->user());

        $request->validate([
            'title' => 'required|string|max:255',
        ]);

        $maxOrder = $task->subtasks()->max('sort_order') ?? 0;

        $subtask = TaskSubtask::create([
            'task_id' => $task->id,
            'title' => $request->input('title'),
            'sort_order' => $maxOrder + 1,
        ]);

        return response()->json([
            'ok' => true,
            'subtask' => [
                'id' => $subtask->id,
                'title' => $subtask->title,
                'is_completed' => $subtask->is_completed,
                'sort_order' => $subtask->sort_order,
            ],
        ], 201);
    }

    public function toggle(TessaTask $task, TaskSubtask $subtask, Request $request): JsonResponse
    {
        $this->authorizeTaskOwner($task, $request->user());

        if ($subtask->task_id !== $task->id) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $subtask->update(['is_completed' => ! $subtask->is_completed]);

        return response()->json([
            'ok' => true,
            'subtask' => [
                'id' => $subtask->id,
                'title' => $subtask->title,
                'is_completed' => $subtask->is_completed,
            ],
        ]);
    }

    public function destroy(TessaTask $task, TaskSubtask $subtask, Request $request): JsonResponse
    {
        $this->authorizeTaskOwner($task, $request->user());

        if ($subtask->task_id !== $task->id) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $subtask->delete();

        return response()->json(['ok' => true]);
    }

}
