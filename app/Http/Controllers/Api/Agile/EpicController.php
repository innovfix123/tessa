<?php

namespace App\Http\Controllers\Api\Agile;

use App\Http\Controllers\Controller;
use App\Models\Epic;
use App\Services\ActivityLogService;
use App\Services\AgileService;
use App\Services\ProjectRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EpicController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $query = Epic::with(['squad:id,name', 'owner:id,name', 'creator:id,name', 'labels', 'project:id,name'])
            ->orderByDesc('created_at');

        AgileService::scopeToAllowedProjects($query, $user);

        if ($request->query('project_id')) {
            $query->where('project_id', (int) $request->query('project_id'));
        }
        if ($request->query('squad_id')) {
            $query->where('squad_id', (int) $request->query('squad_id'));
        }
        if ($request->query('status') && in_array($request->query('status'), Epic::STATUSES, true)) {
            $query->where('status', $request->query('status'));
        }

        $epics = $query->get()->map(fn ($e) => $this->normalizeEpic($e));

        return response()->json(['ok' => true, 'epics' => $epics]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! ProjectRoleService::canManageAgileEpics($user->role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project_id' => 'nullable|exists:projects,id',
            'squad_id' => 'nullable|exists:squads,id',
            'priority' => 'sometimes|in:'.implode(',', Epic::PRIORITIES),
            'owner_id' => 'nullable|exists:users,id',
            'target_date' => 'nullable|date',
            'label_ids' => 'nullable|array',
            'label_ids.*' => 'exists:labels,id',
        ]);

        $labelIds = $validated['label_ids'] ?? [];
        unset($validated['label_ids']);

        $epic = Epic::create([
            ...$validated,
            'status' => Epic::STATUS_OPEN,
            'created_by' => $user->id,
        ]);

        if ($labelIds) {
            $epic->labels()->sync($labelIds);
        }

        $epic->load(['squad:id,name', 'owner:id,name', 'creator:id,name', 'labels']);
        ActivityLogService::log($user->id, 'epic_created', "{$user->name} created epic: {$epic->title}", 'epic', $epic->id);

        return response()->json(['ok' => true, 'epic' => $this->normalizeEpic($epic)], 201);
    }

    public function show(Request $request, Epic $epic): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $epic->load(['squad:id,name', 'owner:id,name', 'creator:id,name', 'labels', 'stories.assignee:id,name', 'bugs.assignee:id,name']);

        $data = $this->normalizeEpic($epic);
        $data['stories'] = $epic->stories->map(fn ($s) => [
            'id' => $s->id,
            'title' => $s->title,
            'status' => $s->status,
            'priority' => $s->priority,
            'storyPoints' => $s->story_points,
            'assigneeName' => $s->assignee?->name ?? '',
        ])->values();
        $data['bugs'] = $epic->bugs->map(fn ($b) => [
            'id' => $b->id,
            'title' => $b->title,
            'status' => $b->status,
            'severity' => $b->severity,
            'assigneeName' => $b->assignee?->name ?? '',
        ])->values();

        return response()->json(['ok' => true, 'epic' => $data]);
    }

    public function update(Request $request, Epic $epic): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! ProjectRoleService::canManageAgileEpics($user->role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'squad_id' => 'nullable|exists:squads,id',
            'status' => 'sometimes|in:'.implode(',', Epic::STATUSES),
            'priority' => 'sometimes|in:'.implode(',', Epic::PRIORITIES),
            'owner_id' => 'nullable|exists:users,id',
            'target_date' => 'nullable|date',
            'label_ids' => 'nullable|array',
            'label_ids.*' => 'exists:labels,id',
        ]);

        if (isset($validated['label_ids'])) {
            $epic->labels()->sync($validated['label_ids']);
            unset($validated['label_ids']);
        }

        $epic->update($validated);
        $epic->load(['squad:id,name', 'owner:id,name', 'creator:id,name', 'labels']);
        ActivityLogService::log($user->id, 'epic_updated', "{$user->name} updated epic: {$epic->title}", 'epic', $epic->id);

        return response()->json(['ok' => true, 'epic' => $this->normalizeEpic($epic->fresh())]);
    }

    public function destroy(Request $request, Epic $epic): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! ProjectRoleService::canManageAgileEpics($user->role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        ActivityLogService::log($user->id, 'epic_deleted', "{$user->name} deleted epic: {$epic->title}", 'epic', $epic->id);
        $epic->delete();

        return response()->json(['ok' => true]);
    }

    private function normalizeEpic(Epic $e): array
    {
        return [
            'id' => $e->id,
            'title' => $e->title,
            'description' => $e->description ?? '',
            'projectId' => $e->project_id,
            'projectName' => $e->project?->name ?? '',
            'squadId' => $e->squad_id,
            'squadName' => $e->squad?->name ?? '',
            'status' => $e->status,
            'priority' => $e->priority,
            'ownerId' => $e->owner_id,
            'ownerName' => $e->owner?->name ?? '',
            'targetDate' => $e->target_date?->format('Y-m-d'),
            'progress' => $e->progress,
            'labels' => $e->labels->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values(),
            'createdBy' => $e->created_by,
            'createdByName' => $e->creator?->name ?? '',
            'createdAt' => $e->created_at?->toIso8601String(),
        ];
    }
}
