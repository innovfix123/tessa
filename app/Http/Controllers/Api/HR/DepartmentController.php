<?php

namespace App\Http\Controllers\Api\HR;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Role;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DepartmentController extends Controller
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

        $departments = Department::with(['head:id,name', 'parent:id,name'])
            ->withCount(['users' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'slug' => $d->slug,
                'head' => $d->head?->name,
                'head_user_id' => $d->head_user_id,
                'parent' => $d->parent?->name,
                'parent_department_id' => $d->parent_department_id,
                'member_count' => $d->users_count,
                'is_active' => $d->is_active,
            ]);

        return response()->json(['ok' => true, 'departments' => $departments]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, self::ALLOWED_ROLES, true)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $id = $request->input('id');
        $data = [
            'name' => $request->input('name'),
            'slug' => Str::slug($request->input('name')),
            'head_user_id' => $request->input('head_user_id'),
            'parent_department_id' => $request->input('parent_department_id'),
            'is_active' => $request->input('is_active', true),
        ];

        if ($id) {
            $dept = Department::findOrFail($id);
            $dept->update($data);
            $action = 'department_update';
            $msg = "{$user->name} updated department: {$dept->name}";
        } else {
            $dept = Department::create($data);
            $action = 'department_create';
            $msg = "{$user->name} created department: {$dept->name}";
        }

        ActivityLogService::log($user->id, $action, $msg);

        return response()->json(['ok' => true, 'id' => $dept->id]);
    }
}
