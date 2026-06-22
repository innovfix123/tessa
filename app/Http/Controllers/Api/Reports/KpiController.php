<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\KpiCeoNote;
use App\Models\KpiEntry;
use App\Models\KpiTarget;
use App\Services\ActivityLogService;
use App\Services\ProjectRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KpiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $weekKey = $this->requireWeekKey($request->query('week_key', ''));
        $userId = $request->query('user_id');
        $userRole = $request->user()->role;

        if ($userId !== null && $userId !== '') {
            $userId = (int) $userId;
            $isSelf = $request->user()->id === $userId;
            if (! $isSelf && ! ProjectRoleService::canAccessUserByRole($userRole, $userId)) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
            $data = $this->loadUserWeek($userId, $weekKey);

            return response()->json([
                'ok' => true,
                'userId' => $userId,
                'weekKey' => $weekKey,
                'data' => $data,
            ]);
        }

        $allowedUserIds = ProjectRoleService::getAllowedUserIdsForRole($userRole);
        if (empty($allowedUserIds)) {
            return response()->json(['ok' => true, 'weekKey' => $weekKey, 'items' => []]);
        }

        $entries = KpiEntry::where('week_key', $weekKey)
            ->whereIn('user_id', $allowedUserIds)
            ->get();
        $targets = KpiTarget::where('week_key', $weekKey)
            ->whereIn('user_id', $allowedUserIds)
            ->get();
        $notes = KpiCeoNote::where('week_key', $weekKey)
            ->whereIn('user_id', $allowedUserIds)
            ->get();

        $items = [];
        foreach ($entries as $e) {
            $uid = $e->user_id;
            if (! isset($items[$uid])) {
                $items[$uid] = ['entries' => [], 'targets' => [], 'ceoNote' => ''];
            }
            $items[$uid]['entries'][$e->field_key] = $e->value ?? '';
        }
        foreach ($targets as $t) {
            $uid = $t->user_id;
            if (! isset($items[$uid])) {
                $items[$uid] = ['entries' => [], 'targets' => [], 'ceoNote' => ''];
            }
            $items[$uid]['targets'][$t->field_key] = $t->value ?? '';
        }
        foreach ($notes as $n) {
            $uid = $n->user_id;
            if (! isset($items[$uid])) {
                $items[$uid] = ['entries' => [], 'targets' => [], 'ceoNote' => ''];
            }
            $items[$uid]['ceoNote'] = $n->note ?? '';
        }

        return response()->json([
            'ok' => true,
            'weekKey' => $weekKey,
            'items' => $items,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $userId = (int) ($request->input('userId') ?? $request->input('personId') ?? 0);
        $weekKey = $this->requireWeekKey($request->input('weekKey', ''));
        $userRole = $request->user()->role;

        if ($userId <= 0) {
            return response()->json(['error' => 'userId is required'], 422);
        }

        $isSelf = $request->user()->id === $userId;
        if (! $isSelf && ! ProjectRoleService::canAccessUserByRole($userRole, $userId)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $action = $request->input('action', '');

        if ($action === 'save_entry') {
            if (! $isSelf && ! ProjectRoleService::canEditKpiEntry($userRole)) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
            $fieldKey = trim($request->input('fieldKey', ''));
            $value = $request->input('value', '');
            if ($fieldKey === '') {
                return response()->json(['error' => 'fieldKey is required'], 422);
            }
            $this->upsertField(KpiEntry::class, $userId, $weekKey, $fieldKey, $value, $request->user()->id);
            $actor = $request->user();
            ActivityLogService::log($actor->id, 'kpi_entry_saved', "{$actor->name} saved KPI entry: {$fieldKey}", 'kpi_entry', null, ['field_key' => $fieldKey, 'value' => $value, 'week_key' => $weekKey, 'target_user_id' => $userId]);

            return response()->json(['ok' => true]);
        }

        if ($action === 'save_target') {
            if (! ProjectRoleService::canSetKpiTarget($userRole)) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
            $fieldKey = trim($request->input('fieldKey', ''));
            $value = $request->input('value', '');
            if ($fieldKey === '') {
                return response()->json(['error' => 'fieldKey is required'], 422);
            }
            $this->upsertField(KpiTarget::class, $userId, $weekKey, $fieldKey, $value, $request->user()->id);
            $actor = $request->user();
            ActivityLogService::log($actor->id, 'kpi_target_saved', "{$actor->name} set KPI target: {$fieldKey} = {$value}", 'kpi_target', null, ['field_key' => $fieldKey, 'value' => $value, 'week_key' => $weekKey, 'target_user_id' => $userId]);

            return response()->json(['ok' => true]);
        }

        if ($action === 'save_ceo_note') {
            if (! ProjectRoleService::canSaveCeoNote($userRole)) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
            $note = $request->input('note', '');
            KpiCeoNote::updateOrCreate(
                ['user_id' => $userId, 'week_key' => $weekKey],
                ['note' => $note, 'updated_by' => $request->user()->id]
            );
            $actor = $request->user();
            ActivityLogService::log($actor->id, 'kpi_ceo_note_saved', "{$actor->name} saved CEO note for {$weekKey}", 'kpi_ceo_note', null, ['week_key' => $weekKey, 'target_user_id' => $userId]);

            return response()->json(['ok' => true]);
        }

        return response()->json(['error' => 'Unknown action'], 404);
    }

    private function loadUserWeek(int $userId, string $weekKey): array
    {
        $entries = KpiEntry::where('user_id', $userId)->where('week_key', $weekKey)->pluck('value', 'field_key')->toArray();
        $targets = KpiTarget::where('user_id', $userId)->where('week_key', $weekKey)->pluck('value', 'field_key')->toArray();
        $ceoNote = KpiCeoNote::where('user_id', $userId)->where('week_key', $weekKey)->value('note') ?? '';

        return ['entries' => $entries, 'targets' => $targets, 'ceoNote' => $ceoNote];
    }

    private function upsertField(string $model, int $userId, string $weekKey, string $fieldKey, string $value, int $updatedBy): void
    {
        $model::updateOrCreate(
            ['user_id' => $userId, 'week_key' => $weekKey, 'field_key' => $fieldKey],
            ['value' => $value, 'updated_by' => $updatedBy]
        );
    }

    private function requireWeekKey(string $value): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            abort(422, 'Invalid week_key format');
        }

        return trim($value);
    }
}
