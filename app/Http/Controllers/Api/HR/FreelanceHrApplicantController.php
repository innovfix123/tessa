<?php

namespace App\Http\Controllers\Api\HR;

use App\Http\Controllers\Controller;
use App\Models\FreelanceHrApplicant;
use App\Services\ActivityLogService;
use App\Services\ProjectRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FreelanceHrApplicantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $user->role;

        if (! ProjectRoleService::hasFeature($role, 'hr_resumes')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $items = FreelanceHrApplicant::orderBy('name')->get();

        return response()->json(['ok' => true, 'items' => $items]);
    }

    public function update(Request $request, FreelanceHrApplicant $hrApplicant): JsonResponse
    {
        $user = $request->user();
        $role = $user->role;

        if (! ProjectRoleService::hasFeature($role, 'hr_resumes')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if ($request->has('status')) {
            $status = $request->input('status');
            if (! in_array($status, ['pending', 'selected', 'not_selected'], true)) {
                return response()->json(['error' => 'Invalid status'], 422);
            }
            $hrApplicant->status = $status;
        }
        if ($request->has('charge')) {
            $hrApplicant->charge = $request->input('charge') ?: null;
        }
        if ($request->has('notes')) {
            $hrApplicant->notes = $request->input('notes') ?: null;
        }
        $hrApplicant->save();

        $changes = array_filter(['status' => $request->input('status'), 'charge' => $request->input('charge'), 'notes' => $request->has('notes') ? 'updated' : null]);
        $changeStr = implode(', ', array_keys($changes));
        ActivityLogService::log($user->id, 'hr_applicant_updated', "{$user->name} updated applicant {$hrApplicant->name}: {$changeStr}", 'hr_applicant', $hrApplicant->id, $changes);

        return response()->json(['ok' => true, 'item' => $hrApplicant->fresh()]);
    }
}
