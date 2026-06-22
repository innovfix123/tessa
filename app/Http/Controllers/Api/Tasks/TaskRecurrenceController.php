<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Http\Controllers\Controller;
use App\Models\TaskRecurrence;
use App\Models\TessaTask;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskRecurrenceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $recurrences = TaskRecurrence::where('assigned_by', $user->id)
            ->orWhere('assigned_to', $user->id)
            ->with(['assignedBy:id,name', 'assignedTo:id,name'])
            ->orderByDesc('is_active')
            ->orderBy('next_run_at')
            ->get()
            ->map(function ($r) {
                $latestTask = TessaTask::where('title', $r->title)
                    ->where('assigned_to', $r->assigned_to)
                    ->orderByDesc('created_at')
                    ->first(['id', 'status', 'created_at']);

                return [
                    'id' => $r->id,
                    'title' => $r->title,
                    'description' => $r->description,
                    'priority' => $r->priority,
                    'recurrence_type' => $r->recurrence_type,
                    'recurrence_day' => $r->recurrence_day,
                    'next_run_at' => $r->next_run_at?->toIso8601String(),
                    'is_active' => $r->is_active,
                    'deadline_offset_hours' => $r->deadline_offset_hours,
                    'assigned_by' => $r->assignedBy ? ['id' => $r->assignedBy->id, 'name' => $r->assignedBy->name] : null,
                    'assigned_to' => $r->assignedTo ? ['id' => $r->assignedTo->id, 'name' => $r->assignedTo->name] : null,
                    'created_at' => $r->created_at->toIso8601String(),
                    'latest_task' => $latestTask ? [
                        'id' => $latestTask->id,
                        'status' => $latestTask->status,
                    ] : null,
                ];
            });

        return response()->json(['recurrences' => $recurrences]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'assigned_to' => 'required|exists:users,id',
            'priority' => 'in:low,medium,high,urgent',
            'recurrence_type' => 'required|in:daily,weekly,monthly',
            'recurrence_day' => 'nullable|integer|min:0|max:28',
            'deadline_offset_hours' => 'nullable|integer|min:1|max:720',
        ]);

        $nextRun = $this->calculateFirstRun(
            $request->input('recurrence_type'),
            $request->input('recurrence_day')
        );

        $recurrence = TaskRecurrence::create([
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'assigned_to' => (int) $request->input('assigned_to'),
            'assigned_by' => $user->id,
            'priority' => $request->input('priority', 'medium'),
            'recurrence_type' => $request->input('recurrence_type'),
            'recurrence_day' => $request->input('recurrence_day'),
            'next_run_at' => $nextRun,
            'deadline_offset_hours' => $request->input('deadline_offset_hours', 24),
        ]);

        return response()->json([
            'ok' => true,
            'recurrence' => $recurrence->load(['assignedBy:id,name', 'assignedTo:id,name']),
        ], 201);
    }

    public function update(TaskRecurrence $recurrence, Request $request): JsonResponse
    {
        $user = $request->user();
        if ($recurrence->assigned_by !== $user->id) {
            return response()->json(['error' => 'Only the creator can edit recurrences'], 403);
        }

        $request->validate([
            'is_active' => 'boolean',
            'title' => 'string|max:255',
            'description' => 'nullable|string',
            'assigned_to' => 'exists:users,id',
            'priority' => 'in:low,medium,high,urgent',
            'recurrence_type' => 'in:daily,weekly,monthly',
            'recurrence_day' => 'nullable|integer|min:0|max:28',
            'deadline_offset_hours' => 'nullable|integer|min:1|max:720',
        ]);

        $recurrence->update($request->only([
            'is_active', 'title', 'description', 'assigned_to',
            'priority', 'recurrence_type', 'recurrence_day', 'deadline_offset_hours',
        ]));

        return response()->json(['ok' => true, 'recurrence' => $recurrence->fresh()->load(['assignedBy:id,name', 'assignedTo:id,name'])]);
    }

    public function destroy(TaskRecurrence $recurrence, Request $request): JsonResponse
    {
        $user = $request->user();
        if ($recurrence->assigned_by !== $user->id) {
            return response()->json(['error' => 'Only the creator can delete recurrences'], 403);
        }

        $recurrence->delete();

        return response()->json(['ok' => true]);
    }

    private function calculateFirstRun(string $type, ?int $day): Carbon
    {
        $now = now('Asia/Kolkata');
        $runTime = $now->copy()->startOfDay()->addHours(8);

        if ($type === 'daily') {
            return ($runTime->gt($now) ? $runTime : $runTime->addDay())->utc();
        }

        if ($type === 'weekly') {
            $target = $day ?? 1;
            if ($now->dayOfWeekIso === $target) {
                return $runTime->utc();
            }

            return $now->copy()->next($target)->startOfDay()->addHours(8)->utc();
        }

        if ($type === 'monthly') {
            $target = $day ?? 1;
            if ($now->day === $target) {
                return $runTime->utc();
            }
            $candidate = $now->copy()->startOfMonth()->addDays($target - 1)->startOfDay()->addHours(8);

            return ($candidate->gt($now) ? $candidate : $candidate->addMonth())->utc();
        }

        return $runTime->addDay()->utc();
    }
}
