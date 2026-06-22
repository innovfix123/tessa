<?php

namespace App\Http\Controllers\Api\Agile;

use App\Http\Controllers\Controller;
use App\Models\Squad;
use App\Models\SquadMember;
use App\Services\ActivityLogService;
use App\Services\ProjectRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SquadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $squads = Squad::with(['lead:id,name', 'members:id,name'])
            ->active()
            ->orderBy('name')
            ->get()
            ->map(fn ($s) => $this->normalizeSquad($s));

        return response()->json(['ok' => true, 'squads' => $squads]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! ProjectRoleService::canManageAgileSquads($user->role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'lead_user_id' => 'nullable|exists:users,id',
            'definition_of_ready' => 'nullable|string',
            'definition_of_done' => 'nullable|string',
            'wip_limit_per_user' => 'nullable|integer|min:0|max:50',
        ]);

        $validated['slug'] = Str::slug($validated['name']);

        $squad = Squad::create($validated);
        $squad->load(['lead:id,name', 'members:id,name']);
        ActivityLogService::log($user->id, 'squad_created', "{$user->name} created squad: {$squad->name}", 'squad', $squad->id);

        return response()->json(['ok' => true, 'squad' => $this->normalizeSquad($squad)], 201);
    }

    public function update(Request $request, Squad $squad): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! ProjectRoleService::canManageAgileSquads($user->role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'lead_user_id' => 'nullable|exists:users,id',
            'is_active' => 'sometimes|boolean',
            'definition_of_ready' => 'nullable|string',
            'definition_of_done' => 'nullable|string',
            'wip_limit_per_user' => 'nullable|integer|min:0|max:50',
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $squad->update($validated);
        $squad->load(['lead:id,name', 'members:id,name']);
        ActivityLogService::log($user->id, 'squad_updated', "{$user->name} updated squad: {$squad->name}", 'squad', $squad->id);

        return response()->json(['ok' => true, 'squad' => $this->normalizeSquad($squad->fresh())]);
    }

    public function addMember(Request $request, Squad $squad): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! ProjectRoleService::canManageAgileSquads($user->role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'role_in_squad' => 'sometimes|in:lead,member',
        ]);

        $exists = SquadMember::where('squad_id', $squad->id)
            ->where('user_id', $validated['user_id'])
            ->exists();

        if ($exists) {
            return response()->json(['error' => 'User is already a member of this squad'], 422);
        }

        SquadMember::create([
            'squad_id' => $squad->id,
            'user_id' => $validated['user_id'],
            'role_in_squad' => $validated['role_in_squad'] ?? 'member',
        ]);

        $squad->load(['lead:id,name', 'members:id,name']);
        ActivityLogService::log($user->id, 'squad_member_added', "{$user->name} added member to squad: {$squad->name}", 'squad', $squad->id);

        return response()->json(['ok' => true, 'squad' => $this->normalizeSquad($squad)]);
    }

    public function removeMember(Request $request, Squad $squad, int $userId): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! ProjectRoleService::canManageAgileSquads($user->role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        SquadMember::where('squad_id', $squad->id)->where('user_id', $userId)->delete();

        $squad->load(['lead:id,name', 'members:id,name']);
        ActivityLogService::log($user->id, 'squad_member_removed', "{$user->name} removed member from squad: {$squad->name}", 'squad', $squad->id);

        return response()->json(['ok' => true, 'squad' => $this->normalizeSquad($squad)]);
    }

    private function normalizeSquad(Squad $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'slug' => $s->slug,
            'description' => $s->description ?? '',
            'definitionOfReady' => $s->definition_of_ready ?? '',
            'definitionOfDone' => $s->definition_of_done ?? '',
            'wipLimitPerUser' => $s->wip_limit_per_user,
            'leadUserId' => $s->lead_user_id,
            'leadName' => $s->lead?->name ?? '',
            'isActive' => $s->is_active,
            'members' => $s->members->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'roleInSquad' => $m->pivot->role_in_squad,
            ])->values(),
            'createdAt' => $s->created_at?->toIso8601String(),
        ];
    }
}
