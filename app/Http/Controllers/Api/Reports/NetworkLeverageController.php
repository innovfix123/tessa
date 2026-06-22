<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\NetworkLeverageEvent;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NetworkLeverageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->query('user_id', 0);
        $weekKey = $request->query('week_key', '');

        $user = $request->user();
        if ($user->id !== $userId && $user->role !== Role::SLUG_CEO) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $events = NetworkLeverageEvent::where('user_id', $userId)
            ->where('week_key', $weekKey)
            ->orderBy('event_date')
            ->get();

        // Full history, newest first — surfaced below the weekly view so events
        // from any past week or month stay visible without navigating week by
        // week. Tie-break on id so same-day events show most-recently-added first.
        $allEvents = NetworkLeverageEvent::where('user_id', $userId)
            ->orderByDesc('event_date')
            ->orderByDesc('id')
            ->get();

        return response()->json(['ok' => true, 'events' => $events, 'allEvents' => $allEvents]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = (int) $request->input('user_id', $user->id);

        if ($user->id !== $userId) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'week_key' => 'required|string|max:10',
            'event_date' => 'required|date',
            'event_name' => 'required|string|max:255',
            'co_attendees' => 'nullable|string|max:255',
            'attendee_count' => 'nullable|integer|min:0',
            'contacts' => 'nullable|string',
            'linkedin_urls' => 'required|string',
        ]);

        $validated['user_id'] = $userId;

        $event = NetworkLeverageEvent::create($validated);

        return response()->json(['ok' => true, 'event' => $event]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $event = NetworkLeverageEvent::findOrFail($id);

        if ($request->user()->id !== $event->user_id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $event->delete();

        return response()->json(['ok' => true]);
    }
}
