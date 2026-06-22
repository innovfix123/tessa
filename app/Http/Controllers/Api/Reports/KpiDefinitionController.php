<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\KpiDefinition;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\ProjectRoleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KpiDefinitionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        $userIdsRaw = $request->query('user_ids');
        $weekKey = $request->query('week_key');

        $userIds = null;
        if ($userId !== null && $userId !== '') {
            $userIds = [(int) $userId];
        } elseif ($userIdsRaw !== null) {
            $userIds = is_array($userIdsRaw)
                ? array_map('intval', $userIdsRaw)
                : array_map('intval', array_filter(explode(',', (string) $userIdsRaw)));
        }

        $baseQuery = $weekKey
            ? KpiDefinition::withTrashed()->visibleForWeek($weekKey)
            : KpiDefinition::query();

        if (empty($userIds)) {
            $teamUserIds = ProjectRoleService::getAllowedUserIdsForRole(Role::SLUG_CEO);
            $definitions = (clone $baseQuery)->whereIn('user_id', $teamUserIds)
                ->orderBy('user_id')->orderBy('group_name')->orderBy('sort_order')->get();

            return response()->json([
                'ok' => true,
                'definitions' => [
                    'kpiGroups' => $this->buildKpiGroups($definitions),
                    'aggregation' => $this->buildAggregation($definitions),
                    'marketingKpiPeople' => $this->buildPeople($definitions),
                ],
            ]);
        }

        $definitions = (clone $baseQuery)->whereIn('user_id', $userIds)
            ->orderBy('user_id')->orderBy('group_name')->orderBy('sort_order')->get();

        $result = [
            'kpiGroups' => $this->buildKpiGroups($definitions),
            'aggregation' => $this->buildAggregation($definitions),
        ];

        if (count($userIds) >= 1) {
            $result['marketingKpiPeople'] = $this->buildPeople($definitions);

            $byPerson = [];
            $metadata = ProjectRoleService::getAllUserMetadata();
            foreach ($definitions->groupBy('user_id') as $uid => $personDefs) {
                $meta = $metadata[$uid] ?? null;
                $byPerson[$uid] = [
                    'name' => $meta['name'] ?? $personDefs->first()?->user?->name ?? (string) $uid,
                    'role' => $meta['role'] ?? $personDefs->first()?->user?->roleRelation?->name ?? '',
                    'projectName' => $meta['project'] ?? null,
                    'groups' => $this->buildKpiGroups($personDefs),
                ];
            }
            $result['kpiGroupsByPerson'] = $byPerson;
        }

        return response()->json(['ok' => true, 'definitions' => $result]);
    }

    public function store(Request $request): JsonResponse
    {
        $action = $request->input('action', '');

        if ($action === 'create') {
            return $this->create($request);
        }
        if ($action === 'update') {
            return $this->update($request);
        }
        if ($action === 'delete') {
            return $this->delete($request);
        }
        if ($action === 'create_group') {
            return $this->createGroup($request);
        }
        if ($action === 'create_person') {
            return $this->createPerson($request);
        }

        return response()->json(['error' => 'Unknown action'], 404);
    }

    private function create(Request $request): JsonResponse
    {
        $userId = (int) ($request->input('userId') ?? $request->input('personId') ?? 0);
        if ($userId <= 0) {
            return response()->json(['error' => 'userId is required'], 422);
        }

        if (! $this->canManageUser($request, $userId)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $groupName = trim($request->input('groupName', ''));
        $fieldKey = trim($request->input('fieldKey', ''));
        $fieldLabel = trim($request->input('fieldLabel', ''));
        $aggregation = $request->input('aggregation');

        if ($fieldKey === '' || $fieldLabel === '') {
            return response()->json(['error' => 'fieldKey and fieldLabel are required'], 422);
        }

        $fieldKey = $this->slugifyKey($fieldKey);

        $maxSort = KpiDefinition::where('user_id', $userId)
            ->where('group_name', $groupName ?: 'Metrics')
            ->max('sort_order') ?? -1;

        $currentWeekStart = Carbon::now('Asia/Kolkata')->startOfWeek(Carbon::MONDAY)->format('Y-m-d');

        $def = KpiDefinition::create([
            'user_id' => $userId,
            'group_name' => $groupName ?: 'Metrics',
            'field_key' => $fieldKey,
            'field_label' => $fieldLabel,
            'aggregation' => in_array($aggregation, ['sum', 'avg', 'latest']) ? $aggregation : null,
            'sort_order' => $maxSort + 1,
            'created_by' => $request->user()->id,
            'effective_from' => $currentWeekStart,
        ]);

        $actor = $request->user();
        ActivityLogService::log($actor->id, 'kpi_definition_created', "{$actor->name} created KPI field: {$fieldLabel}", 'kpi_definition', $def->id, ['field_label' => $fieldLabel, 'field_key' => $fieldKey, 'group_name' => $def->group_name, 'target_user_id' => $userId]);

        return response()->json(['ok' => true, 'id' => $def->id]);
    }

    private function createGroup(Request $request): JsonResponse
    {
        $userId = (int) ($request->input('userId') ?? $request->input('personId') ?? 0);
        if ($userId <= 0) {
            return response()->json(['error' => 'userId is required'], 422);
        }

        if (! $this->canManageUser($request, $userId)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $groupName = trim($request->input('groupName', ''));
        if ($groupName === '') {
            return response()->json(['error' => 'groupName is required'], 422);
        }

        if (KpiDefinition::where('user_id', $userId)->where('group_name', $groupName)->exists()) {
            return response()->json(['error' => 'Group already exists'], 422);
        }

        KpiDefinition::create([
            'user_id' => $userId,
            'group_name' => $groupName,
            'field_key' => '_group_init',
            'field_label' => '',
            'aggregation' => null,
            'sort_order' => 0,
            'created_by' => $request->user()->id,
        ]);

        $actor = $request->user();
        ActivityLogService::log($actor->id, 'kpi_group_created', "{$actor->name} created KPI group: {$groupName}", 'kpi_definition', null, ['group_name' => $groupName, 'target_user_id' => $userId]);

        return response()->json(['ok' => true, 'groupName' => $groupName]);
    }

    private function createPerson(Request $request): JsonResponse
    {
        $userId = (int) ($request->input('userId') ?? $request->input('personId') ?? 0);
        if ($userId <= 0) {
            return response()->json(['error' => 'userId is required and must be a valid user id'], 422);
        }

        if (! $this->canManageUser($request, $userId)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if (! User::find($userId)) {
            return response()->json(['error' => 'User not found'], 422);
        }

        if (KpiDefinition::where('user_id', $userId)->exists()) {
            return response()->json(['error' => 'User already has KPI definitions'], 422);
        }

        KpiDefinition::create([
            'user_id' => $userId,
            'group_name' => 'Metrics',
            'field_key' => '_person_init',
            'field_label' => '',
            'aggregation' => null,
            'sort_order' => 0,
            'created_by' => $request->user()->id,
        ]);

        $actor = $request->user();
        ActivityLogService::log($actor->id, 'kpi_person_created', "{$actor->name} added person to KPI tracking", 'kpi_definition', null, ['target_user_id' => $userId]);

        return response()->json(['ok' => true, 'userId' => $userId]);
    }

    private function update(Request $request): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        $def = KpiDefinition::find($id);
        if (! $def) {
            return response()->json(['error' => 'Definition not found'], 404);
        }
        if (! $this->canManageUser($request, $def->user_id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $updates = [];
        if ($request->has('fieldLabel')) {
            $updates['field_label'] = trim($request->input('fieldLabel'));
        }
        if ($request->has('groupName')) {
            $updates['group_name'] = trim($request->input('groupName')) ?: 'Metrics';
        }
        if ($request->has('aggregation')) {
            $agg = $request->input('aggregation');
            $updates['aggregation'] = in_array($agg, ['sum', 'avg', 'latest']) ? $agg : null;
        }
        if ($request->has('fieldKey')) {
            $updates['field_key'] = $this->slugifyKey(trim($request->input('fieldKey')));
        }

        if (! empty($updates)) {
            $def->update($updates);
        }

        $actor = $request->user();
        ActivityLogService::log($actor->id, 'kpi_definition_updated', "{$actor->name} updated KPI definition", 'kpi_definition', $def->id, $updates);

        return response()->json(['ok' => true]);
    }

    private function delete(Request $request): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        $def = KpiDefinition::find($id);
        if (! $def) {
            return response()->json(['error' => 'Definition not found'], 404);
        }
        if (! $this->canManageUser($request, $def->user_id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $actor = $request->user();
        $label = $def->field_label ?: $def->field_key;
        ActivityLogService::log($actor->id, 'kpi_definition_deleted', "{$actor->name} deleted KPI definition: {$label}", 'kpi_definition', $def->id);
        $def->delete();

        return response()->json(['ok' => true]);
    }

    private function canManageUser(Request $request, int $targetUserId): bool
    {
        return ProjectRoleService::canAccessUser($request->user(), $targetUserId)
            || ProjectRoleService::canManageKpiDefinitions($request->user()->role);
    }

    private function slugifyKey(string $key): string
    {
        return strtolower(preg_replace('/[^a-z0-9_]/i', '_', $key));
    }

    private function buildKpiGroups($definitions): array
    {
        $groups = [];
        foreach ($definitions as $d) {
            if (! isset($groups[$d->group_name])) {
                $groups[$d->group_name] = ['name' => $d->group_name, 'fields' => []];
            }
            if ($d->field_key !== '_group_init') {
                $groups[$d->group_name]['fields'][] = [
                    'key' => $d->field_key,
                    'label' => $d->field_label,
                    'id' => $d->id,
                ];
            }
        }

        return array_values($groups);
    }

    private function buildAggregation($definitions): array
    {
        $agg = [];
        foreach ($definitions as $d) {
            if ($d->aggregation) {
                $agg[$d->field_key] = $d->aggregation;
            }
        }

        return $agg;
    }

    private function buildPeople($definitions): array
    {
        $people = [];
        $metadata = ProjectRoleService::getAllUserMetadata();

        foreach ($definitions as $d) {
            $uid = $d->user_id;
            if (! isset($people[$uid])) {
                $meta = $metadata[$uid] ?? null;
                $people[$uid] = [
                    'id' => $uid,
                    'name' => $meta['name'] ?? $d->user?->name ?? '',
                    'role' => $meta['role'] ?? $d->user?->roleRelation?->name ?? '',
                    'project' => $meta['project'] ?? null,
                    'reportingManager' => $meta['reporting_manager'] ?? null,
                    'fields' => [],
                ];
            }
            if (! in_array($d->field_key, ['_placeholder', '_person_init'], true)) {
                $people[$uid]['fields'][] = [
                    'key' => $d->field_key,
                    'label' => $d->field_label,
                    'id' => $d->id,
                    'group' => $d->group_name,
                ];
            }
        }

        return array_values($people);
    }
}
