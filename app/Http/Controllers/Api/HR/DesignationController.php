<?php

namespace App\Http\Controllers\Api\HR;

use App\Http\Controllers\Controller;
use App\Models\Designation;
use App\Models\Role;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DesignationController extends Controller
{
    private const ALLOWED_ROLES = [
        Role::SLUG_CEO,
        Role::SLUG_COO,
        Role::SLUG_CFO,
        Role::SLUG_HR,
        Role::SLUG_HR_OPERATIONS,
        Role::SLUG_BUSINESS_ANALYST,
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, self::ALLOWED_ROLES, true)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $designations = Designation::with('department:id,name')
            ->withCount(['users' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('department_id')
            ->orderBy('level')
            ->orderBy('title')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'title' => $d->title,
                'level' => $d->level,
                'department' => $d->department?->name,
                'department_id' => $d->department_id,
                'member_count' => $d->users_count,
                'is_active' => $d->is_active,
            ]);

        return response()->json(['ok' => true, 'designations' => $designations]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, self::ALLOWED_ROLES, true)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'title' => 'required|string|max:150',
        ]);

        $id = $request->input('id');
        $data = [
            'title' => $request->input('title'),
            'level' => $request->input('level', 1),
            'department_id' => $request->input('department_id'),
            'is_active' => $request->input('is_active', true),
        ];

        if ($id) {
            $desg = Designation::findOrFail($id);
            $desg->update($data);
            $action = 'designation_update';
            $msg = "{$user->name} updated designation: {$desg->title}";
        } else {
            $desg = Designation::create($data);
            $action = 'designation_create';
            $msg = "{$user->name} created designation: {$desg->title}";
        }

        ActivityLogService::log($user->id, $action, $msg);

        return response()->json(['ok' => true, 'id' => $desg->id]);
    }
}
