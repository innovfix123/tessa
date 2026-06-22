<?php

namespace App\Http\Controllers\Api\Agile;

use App\Http\Controllers\Controller;
use App\Models\Sprint;
use App\Models\Squad;
use App\Models\Story;
use App\Models\Bug;
use App\Services\AgileService;
use App\Services\ProjectRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgileDashboardController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! ProjectRoleService::canViewAgileDashboard($user->role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $squads = Squad::active()->with(['lead:id,name'])->get();

        $squadSummaries = $squads->map(function ($squad) {
            $activeSprint = Sprint::where('squad_id', $squad->id)
                ->where('status', Sprint::STATUS_ACTIVE)
                ->first();

            return [
                'id' => $squad->id,
                'name' => $squad->name,
                'leadName' => $squad->lead?->name ?? '',
                'memberCount' => $squad->members()->count(),
                'activeSprint' => $activeSprint ? [
                    'id' => $activeSprint->id,
                    'name' => $activeSprint->name,
                    'goal' => $activeSprint->goal ?? '',
                    'totalPoints' => $activeSprint->total_points,
                    'completedPoints' => $activeSprint->completed_points,
                    'daysRemaining' => $activeSprint->days_remaining,
                    'startDate' => $activeSprint->start_date->format('Y-m-d'),
                    'endDate' => $activeSprint->end_date->format('Y-m-d'),
                ] : null,
            ];
        });

        $totalOpenStories = Story::whereNotIn('status', [Story::STATUS_DONE])->count();
        $totalOpenBugs = Bug::whereNotIn('status', [Bug::STATUS_CLOSED, Bug::STATUS_WONT_FIX])->count();
        $totalBacklog = Story::whereNull('sprint_id')->count();

        return response()->json([
            'ok' => true,
            'squads' => $squadSummaries,
            'summary' => [
                'totalOpenStories' => $totalOpenStories,
                'totalOpenBugs' => $totalOpenBugs,
                'totalBacklog' => $totalBacklog,
            ],
        ]);
    }

    public function velocity(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! ProjectRoleService::canViewAgileDashboard($user->role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $squadId = $request->query('squad_id');
        $count = (int) ($request->query('count', 10));

        if (! $squadId) {
            // Return velocity for all squads
            $squads = Squad::active()->get();
            $data = $squads->map(fn ($squad) => [
                'squadId' => $squad->id,
                'squadName' => $squad->name,
                'velocity' => AgileService::getVelocityData($squad->id, $count),
            ]);

            return response()->json(['ok' => true, 'data' => $data]);
        }

        $data = AgileService::getVelocityData((int) $squadId, $count);

        return response()->json(['ok' => true, 'data' => $data]);
    }
}
