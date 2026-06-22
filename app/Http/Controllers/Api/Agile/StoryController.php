<?php

namespace App\Http\Controllers\Api\Agile;

use App\Http\Controllers\Controller;
use App\Models\Story;
use App\Services\ActivityLogService;
use App\Services\AgileService;
use App\Services\ProjectRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $query = Story::with(['assignee:id,name', 'reporter:id,name', 'epic:id,title', 'sprint:id,name', 'labels', 'dependencies:id,title,status', 'dependents:id,title,status'])
            ->orderByDesc('created_at');

        AgileService::scopeToAllowedProjects($query, $user);

        // See BugController::index — backlog/story listing is the triage surface
        // so every agile role sees the full list. Sprint Board (SprintController)
        // keeps its per-user scope for focused work.

        if ($request->query('project_id')) {
            $query->where('project_id', (int) $request->query('project_id'));
        }
        if ($request->query('sprint_id')) {
            $query->where('sprint_id', (int) $request->query('sprint_id'));
        }
        if ($request->query('epic_id')) {
            $query->where('epic_id', (int) $request->query('epic_id'));
        }
        if ($request->query('status') && in_array($request->query('status'), Story::STATUSES, true)) {
            $query->where('status', $request->query('status'));
        }
        if ($request->query('assignee_id')) {
            $query->where('assignee_id', (int) $request->query('assignee_id'));
        }
        if ($request->query('backlog')) {
            $query->whereNull('sprint_id');
        }

        $stories = $query->get()->map(fn ($s) => $this->normalizeStory($s));

        return response()->json(['ok' => true, 'stories' => $stories]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! ProjectRoleService::canCrudAgileStories($user->role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'acceptance_criteria' => 'nullable|string',
            'technical_notes' => 'nullable|string',
            'project_id' => 'nullable|exists:projects,id',
            'epic_id' => 'nullable|exists:epics,id',
            'sprint_id' => 'nullable|exists:sprints,id',
            'assignee_id' => 'nullable|exists:users,id',
            'priority' => 'sometimes|in:'.implode(',', Story::PRIORITIES),
            'moscow' => 'nullable|in:'.implode(',', Story::MOSCOW),
            'business_value' => 'nullable|in:'.implode(',', Story::BUSINESS_VALUES),
            'story_points' => 'nullable|integer|min:1|max:21',
            'label_ids' => 'nullable|array',
            'label_ids.*' => 'exists:labels,id',
            'dependency_ids' => 'nullable|array',
            'dependency_ids.*' => 'integer|exists:stories,id',
        ]);

        $labelIds = $validated['label_ids'] ?? [];
        unset($validated['label_ids']);
        $dependencyIds = $validated['dependency_ids'] ?? [];
        unset($validated['dependency_ids']);

        $story = Story::create([
            ...$validated,
            'reporter_id' => $user->id,
            'status' => !empty($validated['sprint_id']) ? Story::STATUS_TODO : Story::STATUS_BACKLOG,
            'created_by' => $user->id,
        ]);

        if ($labelIds) {
            $story->labels()->sync($labelIds);
        }

        if ($dependencyIds) {
            $result = AgileService::syncStoryDependencies($story, $dependencyIds);
            if (! $result['ok']) {
                return response()->json(['ok' => false, 'error' => $result['error']], 422);
            }
        }

        $story->load(['assignee:id,name', 'reporter:id,name', 'epic:id,title', 'sprint:id,name', 'labels', 'dependencies:id,title,status', 'dependents:id,title,status']);
        ActivityLogService::log($user->id, 'story_created', "{$user->name} created story: {$story->title}", 'story', $story->id);

        return response()->json(['ok' => true, 'story' => $this->normalizeStory($story)], 201);
    }

    public function show(Request $request, Story $story): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $story->load(['assignee:id,name', 'reporter:id,name', 'epic:id,title', 'sprint:id,name', 'labels', 'tasks.assignee:id,name', 'bugs.assignee:id,name', 'dependencies:id,title,status', 'dependents:id,title,status']);

        $data = $this->normalizeStory($story);
        $data['tasks'] = $story->tasks->map(fn ($t) => [
            'id' => $t->id,
            'title' => $t->title,
            'description' => $t->description ?? '',
            'status' => $t->status,
            'assigneeId' => $t->assignee_id,
            'assigneeName' => $t->assignee?->name ?? '',
            'estimatedHours' => $t->estimated_hours,
            'actualHours' => $t->actual_hours,
        ])->values();
        $data['relatedBugs'] = $story->bugs->map(fn ($b) => [
            'id' => $b->id,
            'title' => $b->title,
            'status' => $b->status,
            'severity' => $b->severity,
            'assigneeName' => $b->assignee?->name ?? '',
        ])->values();

        return response()->json(['ok' => true, 'story' => $data]);
    }

    public function update(Request $request, Story $story): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! AgileService::canUpdateItem($user, $story)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'acceptance_criteria' => 'nullable|string',
            'technical_notes' => 'nullable|string',
            'epic_id' => 'nullable|exists:epics,id',
            'sprint_id' => 'nullable|exists:sprints,id',
            'assignee_id' => 'nullable|exists:users,id',
            'status' => 'sometimes|in:'.implode(',', Story::STATUSES),
            'priority' => 'sometimes|in:'.implode(',', Story::PRIORITIES),
            'moscow' => 'nullable|in:'.implode(',', Story::MOSCOW),
            'business_value' => 'nullable|in:'.implode(',', Story::BUSINESS_VALUES),
            'story_points' => 'nullable|integer|min:1|max:21',
            'label_ids' => 'nullable|array',
            'label_ids.*' => 'exists:labels,id',
            'dependency_ids' => 'nullable|array',
            'dependency_ids.*' => 'integer|exists:stories,id',
        ]);

        if (isset($validated['label_ids'])) {
            $story->labels()->sync($validated['label_ids']);
            unset($validated['label_ids']);
        }

        if (array_key_exists('dependency_ids', $validated)) {
            $result = AgileService::syncStoryDependencies($story, $validated['dependency_ids'] ?? []);
            unset($validated['dependency_ids']);
            if (! $result['ok']) {
                return response()->json(['ok' => false, 'error' => $result['error']], 422);
            }
        }

        $oldStatus = $story->status;
        $story->update($validated);

        if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            ActivityLogService::log($user->id, 'story_status_changed', "{$user->name} moved story \"{$story->title}\" from {$oldStatus} to {$validated['status']}", 'story', $story->id, ['from' => $oldStatus, 'to' => $validated['status']]);
        } else {
            ActivityLogService::log($user->id, 'story_updated', "{$user->name} updated story: {$story->title}", 'story', $story->id);
        }

        $story->load(['assignee:id,name', 'reporter:id,name', 'epic:id,title', 'sprint:id,name', 'labels', 'dependencies:id,title,status', 'dependents:id,title,status']);

        return response()->json(['ok' => true, 'story' => $this->normalizeStory($story->fresh()->load(['assignee:id,name', 'reporter:id,name', 'epic:id,title', 'sprint:id,name', 'labels', 'dependencies:id,title,status', 'dependents:id,title,status']))]);
    }

    public function destroy(Request $request, Story $story): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! ProjectRoleService::canManageAgileSprints($user->role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        ActivityLogService::log($user->id, 'story_deleted', "{$user->name} deleted story: {$story->title}", 'story', $story->id);
        $story->delete();

        return response()->json(['ok' => true]);
    }

    public function move(Request $request, Story $story): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! AgileService::canUpdateItem($user, $story)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:'.implode(',', Story::STATUSES),
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        $oldStatus = $story->status;
        $story->update($validated);

        if ($validated['status'] !== $oldStatus) {
            ActivityLogService::log($user->id, 'story_status_changed', "{$user->name} moved story \"{$story->title}\" from {$oldStatus} to {$validated['status']}", 'story', $story->id, ['from' => $oldStatus, 'to' => $validated['status']]);
        }

        return response()->json(['ok' => true, 'story' => $this->normalizeStory($story->fresh()->load(['assignee:id,name', 'reporter:id,name', 'epic:id,title', 'sprint:id,name', 'labels']))]);
    }

    public function bulkMove(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! ProjectRoleService::canManageAgileSprints($user->role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'story_ids' => 'required|array|min:1',
            'story_ids.*' => 'exists:stories,id',
            'sprint_id' => 'required|exists:sprints,id',
        ]);

        Story::whereIn('id', $validated['story_ids'])->update([
            'sprint_id' => $validated['sprint_id'],
            'status' => Story::STATUS_TODO,
        ]);

        ActivityLogService::log($user->id, 'stories_bulk_moved', "{$user->name} moved ".count($validated['story_ids'])." stories to sprint", 'sprint', $validated['sprint_id']);

        return response()->json(['ok' => true]);
    }

    private function normalizeStory(Story $s): array
    {
        return [
            'id' => $s->id,
            'title' => $s->title,
            'description' => $s->description ?? '',
            'acceptanceCriteria' => $s->acceptance_criteria ?? '',
            'technicalNotes' => $s->technical_notes ?? '',
            'projectId' => $s->project_id,
            'epicId' => $s->epic_id,
            'epicTitle' => $s->epic?->title ?? '',
            'sprintId' => $s->sprint_id,
            'sprintName' => $s->sprint?->name ?? '',
            'assigneeId' => $s->assignee_id,
            'assigneeName' => $s->assignee?->name ?? '',
            'reporterId' => $s->reporter_id,
            'reporterName' => $s->reporter?->name ?? '',
            'status' => $s->status,
            'priority' => $s->priority,
            'moscow' => $s->moscow,
            'businessValue' => $s->business_value,
            'storyPoints' => $s->story_points,
            'sortOrder' => $s->sort_order,
            'labels' => $s->labels->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values(),
            'dependencies' => $s->relationLoaded('dependencies')
                ? $s->dependencies->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'status' => $d->status])->values()
                : [],
            'dependents' => $s->relationLoaded('dependents')
                ? $s->dependents->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'status' => $d->status])->values()
                : [],
            'createdBy' => $s->created_by,
            'createdAt' => $s->created_at?->toIso8601String(),
            'updatedAt' => $s->updated_at?->toIso8601String(),
        ];
    }
}
