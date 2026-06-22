<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\TaskAuthorization;
use App\Models\TaskCheckin;
use App\Models\TessaTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskCheckinController extends Controller
{
    use TaskAuthorization;

    public function index(TessaTask $task, Request $request): JsonResponse
    {
        $this->authorizeTaskOwner($task, $request->user());

        $checkins = $task->checkins()
            ->with('user:id,name')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'user_name' => $c->user?->name ?? 'Unknown',
                'health_status' => $c->health_status,
                'progress' => $c->progress,
                'note' => $c->note,
                'created_at' => $c->created_at?->toIso8601String(),
            ]);

        return response()->json(['checkins' => $checkins]);
    }

    public function store(TessaTask $task, Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeTaskOwner($task, $user);

        $request->validate([
            'health_status' => 'required|in:on_track,at_risk,blocked',
            'progress' => 'required|integer|min:0|max:100',
            'note' => 'nullable|string|max:1000',
            // "today" must be evaluated in IST, not the app's UTC timezone.
            // The app stores dates as UTC, but users pick check-in dates in IST,
            // so after 18:30 UTC the IST date is already "tomorrow" and a plain
            // before_or_equal:today (UTC) would reject every same-day check-in.
            'checkin_date' => ['nullable', 'date', 'before_or_equal:' . \Carbon\Carbon::today('Asia/Kolkata')->toDateString()],
        ]);

        $checkinDate = $request->input('checkin_date')
            ? \Carbon\Carbon::parse($request->input('checkin_date'), 'Asia/Kolkata')->startOfDay()
            : \Carbon\Carbon::today('Asia/Kolkata');

        $checkinDateStr = $checkinDate->format('Y-m-d');

        // One update per user per day
        $existing = TaskCheckin::where('task_id', $task->id)
            ->where('user_id', $user->id)
            ->whereDate('created_at', $checkinDateStr)
            ->first();

        if ($existing) {
            return response()->json(['error' => 'You already submitted an update for ' . $checkinDate->format('j M Y') . '. One update per day.'], 422);
        }

        $checkin = DB::transaction(function () use ($task, $user, $request, $checkinDateStr) {
            // Use noon UTC on the selected date so the date survives UTC↔IST
            // roundtrips (the app stores/reads timestamps as UTC, but the user
            // picks dates in IST — any IST time after 18:30 would shift to the
            // next UTC date when read back).
            $checkin = TaskCheckin::create([
                'task_id' => $task->id,
                'user_id' => $user->id,
                'health_status' => $request->input('health_status'),
                'progress' => $request->input('progress'),
                'note' => $request->input('note'),
                'created_at' => \Carbon\Carbon::parse($checkinDateStr . ' 12:00:00', 'UTC'),
            ]);

            // Sync health and progress back to the task
            $taskUpdates = [
                'blocker_status' => $request->input('health_status'),
                'progress' => $request->input('progress'),
                'last_checkin_at' => now(),
            ];

            if ($request->input('health_status') === 'blocked' && $request->input('note')) {
                $taskUpdates['blocker_note'] = $request->input('note');
            } elseif ($request->input('health_status') !== 'blocked') {
                $taskUpdates['blocker_note'] = null;
            }

            $task->update($taskUpdates);

            return $checkin;
        });

        return response()->json([
            'ok' => true,
            'checkin' => [
                'id' => $checkin->id,
                'user_name' => $user->name,
                'health_status' => $checkin->health_status,
                'progress' => $checkin->progress,
                'note' => $checkin->note,
                'created_at' => $checkin->created_at->toIso8601String(),
            ],
        ], 201);
    }

    public function destroy(TessaTask $task, TaskCheckin $checkin, Request $request): JsonResponse
    {
        $user = $request->user();

        abort_if($checkin->task_id !== $task->id, 404);

        // Allow task owner (assigned_to / assigned_by) or the checkin creator
        $isTaskOwner = $task->assigned_to === $user->id || $task->assigned_by === $user->id;
        $isCheckinCreator = $checkin->user_id === $user->id;
        abort_unless($isTaskOwner || $isCheckinCreator, 403, 'Not authorized to delete this update.');

        DB::transaction(function () use ($task, $checkin) {
            $checkin->delete();

            $latest = $task->checkins()->latest('created_at')->first();

            if ($latest) {
                $taskUpdates = [
                    'blocker_status' => $latest->health_status,
                    'progress' => $latest->progress,
                    'last_checkin_at' => $latest->created_at,
                ];
                $taskUpdates['blocker_note'] = ($latest->health_status === 'blocked' && $latest->note)
                    ? $latest->note
                    : null;
            } else {
                $taskUpdates = [
                    'blocker_status' => 'on_track',
                    'progress' => 0,
                    'blocker_note' => null,
                    'last_checkin_at' => null,
                ];
            }

            $task->update($taskUpdates);
        });

        return response()->json(['ok' => true]);
    }
}
