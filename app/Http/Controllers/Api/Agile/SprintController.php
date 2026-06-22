<?php

namespace App\Http\Controllers\Api\Agile;

use App\Http\Controllers\Controller;
use App\Models\Sprint;
use App\Services\ActivityLogService;
use App\Services\AgileService;
use App\Services\ProjectRoleService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SprintController extends Controller
{
    /**
     * Users allowed to close any active/in-review sprint regardless of who created it.
     * Why: Yuvanesh (Tech Lead) needs to wrap up sprints across all projects, not just
     * the ones he opened himself.
     */
    private const SPRINT_CLOSE_OVERRIDE_USER_IDS = [34];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $query = Sprint::with(['squad:id,name', 'creator:id,name', 'project:id,name'])
            ->orderByDesc('start_date');

        AgileService::scopeToAllowedProjects($query, $user);

        if ($request->query('project_id')) {
            $query->where('project_id', (int) $request->query('project_id'));
        }
        if ($request->query('squad_id')) {
            $query->where('squad_id', (int) $request->query('squad_id'));
        }
        if ($request->query('status') && in_array($request->query('status'), Sprint::STATUSES, true)) {
            $query->where('status', $request->query('status'));
        }

        $sprints = $query->get()->map(fn ($s) => $this->normalizeSprint($s));

        return response()->json(['ok' => true, 'sprints' => $sprints]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! ProjectRoleService::canManageAgileSprints($user->role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'goal' => 'nullable|string',
            'squad_id' => 'nullable|exists:squads,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'project_id' => 'nullable|exists:projects,id',
            'capacity_hours' => 'nullable|integer|min:0|max:9999',
        ]);

        // Auto-resolve squad_id from project if not provided
        if (empty($validated['squad_id']) && !empty($validated['project_id'])) {
            $project = \App\Models\Project::find($validated['project_id']);
            if ($project) {
                $squad = \App\Models\Squad::where('name', $project->name)->first();
                if (!$squad) {
                    $squad = \App\Models\Squad::create(['name' => $project->name, 'slug' => \Illuminate\Support\Str::slug($project->name)]);
                }
                $validated['squad_id'] = $squad->id;
            }
        }
        if (empty($validated['squad_id'])) {
            return response()->json(['error' => 'A project must be selected'], 422);
        }

        $sprint = Sprint::create([
            ...$validated,
            'status' => Sprint::STATUS_PLANNING,
            'created_by' => $user->id,
        ]);

        $sprint->load(['squad:id,name', 'creator:id,name']);
        ActivityLogService::log($user->id, 'sprint_created', "{$user->name} created sprint: {$sprint->name}", 'sprint', $sprint->id);

        return response()->json(['ok' => true, 'sprint' => $this->normalizeSprint($sprint)], 201);
    }

    public function update(Request $request, Sprint $sprint): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if ($denied = $this->requireSprintCreator($sprint, $user)) {
            return $denied;
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'goal' => 'nullable|string',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date',
            'project_id' => 'sometimes|nullable|exists:projects,id',
            'capacity_hours' => 'nullable|integer|min:0|max:9999',
            'review_notes' => 'nullable|string',
            'retrospective_notes' => 'nullable|array',
            'retrospective_notes.wentWell' => 'nullable|array',
            'retrospective_notes.wentWell.*' => 'string',
            'retrospective_notes.wentPoorly' => 'nullable|array',
            'retrospective_notes.wentPoorly.*' => 'string',
            'retrospective_notes.actionItems' => 'nullable|array',
            'retrospective_notes.actionItems.*' => 'string',
        ]);

        $sprint->update($validated);
        $sprint->load(['squad:id,name', 'creator:id,name']);
        ActivityLogService::log($user->id, 'sprint_updated', "{$user->name} updated sprint: {$sprint->name}", 'sprint', $sprint->id);

        return response()->json(['ok' => true, 'sprint' => $this->normalizeSprint($sprint->fresh())]);
    }

    public function activate(Request $request, Sprint $sprint): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if ($denied = $this->requireSprintCreator($sprint, $user)) {
            return $denied;
        }

        $result = AgileService::activateSprint($sprint);
        if (! $result['ok']) {
            return response()->json(['error' => $result['error']], 422);
        }

        $sprint->load(['squad:id,name', 'creator:id,name']);
        ActivityLogService::log($user->id, 'sprint_activated', "{$user->name} activated sprint: {$sprint->name}", 'sprint', $sprint->id);

        return response()->json(['ok' => true, 'sprint' => $this->normalizeSprint($sprint->fresh())]);
    }

    public function review(Request $request, Sprint $sprint): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if ($denied = $this->requireSprintCreator($sprint, $user)) {
            return $denied;
        }

        $result = AgileService::reviewSprint($sprint);
        if (! $result['ok']) {
            return response()->json(['error' => $result['error']], 422);
        }

        $sprint->load(['squad:id,name', 'creator:id,name']);
        ActivityLogService::log($user->id, 'sprint_review', "{$user->name} moved sprint to review: {$sprint->name}", 'sprint', $sprint->id);

        return response()->json(['ok' => true, 'sprint' => $this->normalizeSprint($sprint->fresh())]);
    }

    public function close(Request $request, Sprint $sprint): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! in_array((int) $user->id, self::SPRINT_CLOSE_OVERRIDE_USER_IDS, true)) {
            if ($denied = $this->requireSprintCreator($sprint, $user)) {
                return $denied;
            }
        }

        $result = AgileService::closeSprint($sprint);
        if (! $result['ok']) {
            return response()->json(['error' => $result['error']], 422);
        }

        $sprint->load(['squad:id,name', 'creator:id,name']);
        ActivityLogService::log($user->id, 'sprint_closed', "{$user->name} closed sprint: {$sprint->name} (velocity: {$result['velocity']})", 'sprint', $sprint->id, ['velocity' => $result['velocity']]);

        return response()->json(['ok' => true, 'sprint' => $this->normalizeSprint($sprint->fresh())]);
    }

    public function reopen(Request $request, Sprint $sprint): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if ($denied = $this->requireSprintCreator($sprint, $user)) {
            return $denied;
        }

        if ($sprint->status !== Sprint::STATUS_CLOSED && $sprint->status !== 'review') {
            return response()->json(['error' => 'Only closed or review sprints can be reopened'], 422);
        }

        $sprint->update(['status' => Sprint::STATUS_ACTIVE]);
        $sprint->load(['squad:id,name', 'creator:id,name']);
        ActivityLogService::log($user->id, 'sprint_reopened', "{$user->name} reopened sprint: {$sprint->name}", 'sprint', $sprint->id);

        return response()->json(['ok' => true, 'sprint' => $this->normalizeSprint($sprint->fresh())]);
    }

    public function board(Request $request, Sprint $sprint): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        // Users without 'assign_items' permission see only their own tasks
        $filterByUserId = null;
        if (! \App\Models\Role::roleHasPermission($user->role, 'agile.assign_items')) {
            $filterByUserId = $user->id;
        }

        $columns = AgileService::getBoardData($sprint, $filterByUserId);

        return response()->json([
            'ok' => true,
            'sprint' => $this->normalizeSprint($sprint->load(['squad:id,name', 'creator:id,name'])),
            'columns' => $columns,
        ]);
    }

    public function burndown(Request $request, Sprint $sprint): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! ProjectRoleService::canViewAgileDashboard($user->role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $data = AgileService::getBurndownData($sprint);

        return response()->json(['ok' => true, 'burndown' => $data]);
    }

    public function capacity(Request $request, Sprint $sprint): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json([
            'ok' => true,
            'capacity' => AgileService::getSprintCapacityStatus($sprint),
        ]);
    }

    public function wipStatus(Request $request, Sprint $sprint): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json([
            'ok' => true,
            'wip' => AgileService::getWipBreaches($sprint),
        ]);
    }

    public function export(Request $request, Sprint $sprint): Response
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            abort(403, 'Forbidden');
        }

        $sprint->load(['squad:id,name', 'creator:id,name', 'project:id,name']);

        // Always scope the PDF export to the current user's own stories/bugs —
        // this is the "My sprint" download, not a full-team snapshot. Capacity +
        // burndown stay sprint-wide so the user still sees their slice in context.
        $columns = AgileService::getBoardData($sprint, $user->id);
        $capacity = AgileService::getSprintCapacityStatus($sprint);
        $burndown = AgileService::getBurndownData($sprint);

        $sprintMeta = $this->normalizeSprint($sprint);

        $html = view('agile.sprint-export', [
            'sprint' => $sprint,
            'columns' => $columns,
            'capacity' => $capacity,
            'burndown' => $burndown,
            'sprintMeta' => $sprintMeta,
            'generatedAt' => \App\Helpers\DateHelper::now()->format('M j, Y g:i A'),
            'generatedBy' => $user->name,
        ])->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4');

        $slug = \Illuminate\Support\Str::slug($sprint->name) ?: 'sprint-' . $sprint->id;
        $filename = "sprint-{$slug}-mine-" . now()->format('Ymd-His') . '.pdf';

        ActivityLogService::log($user->id, 'sprint_exported', "{$user->name} exported sprint: {$sprint->name}", 'sprint', $sprint->id);

        return $pdf->download($filename);
    }

    private function requireSprintCreator(Sprint $sprint, $user): ?JsonResponse
    {
        if ((int) $sprint->created_by !== (int) $user->id) {
            return response()->json(['error' => 'Only the sprint creator can perform this action'], 403);
        }
        return null;
    }

    private function normalizeSprint(Sprint $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'goal' => $s->goal ?? '',
            'projectId' => $s->project_id,
            'projectName' => $s->project?->name ?? '',
            'squadId' => $s->squad_id,
            'squadName' => $s->squad?->name ?? '',
            'status' => $s->status,
            'startDate' => $s->start_date?->format('Y-m-d'),
            'endDate' => $s->end_date?->format('Y-m-d'),
            'velocity' => $s->velocity,
            'capacityHours' => $s->capacity_hours,
            'reviewNotes' => $s->review_notes ?? '',
            'retrospectiveNotes' => $s->retrospective_notes ?? null,
            'totalPoints' => $s->total_points,
            'completedPoints' => $s->completed_points,
            'daysRemaining' => $s->days_remaining,
            'createdBy' => $s->created_by,
            'createdByName' => $s->creator?->name ?? '',
            'createdAt' => $s->created_at?->toIso8601String(),
        ];
    }
}
