<?php

namespace App\Http\Controllers\Api\Agile;

use App\Http\Controllers\Controller;
use App\Models\Label;
use App\Services\ActivityLogService;
use App\Services\ProjectRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LabelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $labels = Label::orderBy('name')->get()->map(fn ($l) => [
            'id' => $l->id,
            'name' => $l->name,
            'color' => $l->color,
        ]);

        return response()->json(['ok' => true, 'labels' => $labels]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! ProjectRoleService::canManageAgileLabels($user->role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:50|unique:labels,name',
            'color' => 'sometimes|string|max:7',
        ]);

        $label = Label::create($validated);
        ActivityLogService::log($user->id, 'label_created', "{$user->name} created label: {$label->name}", 'label', $label->id);

        return response()->json(['ok' => true, 'label' => [
            'id' => $label->id,
            'name' => $label->name,
            'color' => $label->color,
        ]], 201);
    }

    public function destroy(Request $request, Label $label): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! ProjectRoleService::canManageAgileLabels($user->role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        ActivityLogService::log($user->id, 'label_deleted', "{$user->name} deleted label: {$label->name}", 'label', $label->id);
        $label->delete();

        return response()->json(['ok' => true]);
    }
}
