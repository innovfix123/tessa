<?php

namespace App\Http\Controllers\Api\Agile;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\ActivityLogService;
use App\Services\AgileService;
use App\Services\ProjectRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $query = Project::withCount(['releases'])->orderBy('name');
        AgileService::scopeToAllowedProjects($query, $user, 'id');

        $projects = $query->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'releasesCount' => $p->releases_count,
                'createdAt' => $p->created_at?->toIso8601String(),
            ]);

        return response()->json(['ok' => true, 'projects' => $projects]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->canManage($user)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:projects,name',
        ]);

        $project = Project::create($validated);
        ActivityLogService::log($user->id, 'project_created', "{$user->name} created project: {$project->name}", 'project', $project->id);

        return response()->json(['ok' => true, 'project' => [
            'id' => $project->id,
            'name' => $project->name,
            'releasesCount' => 0,
            'createdAt' => $project->created_at?->toIso8601String(),
        ]], 201);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        if (! $this->canManage($user)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:projects,name,' . $project->id,
        ]);

        $project->update($validated);
        ActivityLogService::log($user->id, 'project_updated', "{$user->name} updated project: {$project->name}", 'project', $project->id);

        return response()->json(['ok' => true, 'project' => [
            'id' => $project->id,
            'name' => $project->name,
            'releasesCount' => $project->releases()->count(),
            'createdAt' => $project->created_at?->toIso8601String(),
        ]]);
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        if (! $this->canManage($user)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        ActivityLogService::log($user->id, 'project_deleted', "{$user->name} deleted project: {$project->name}", 'project', $project->id);
        $project->delete();

        return response()->json(['ok' => true]);
    }

    private function canManage($user): bool
    {
        return in_array($user->role, ['tech_lead', 'ceo']);
    }
}
