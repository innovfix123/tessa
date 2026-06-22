<?php

namespace App\Http\Controllers\Api\Meetings;

use App\Http\Controllers\Controller;
use App\Models\ActionItem;
use App\Models\DiscussionPoint;
use App\Models\Meeting;
use App\Models\MeetingNote;
use App\Models\User;
use App\Services\MeetingSchedulerService;
use App\Services\SlackService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MeetingSchedulerController extends Controller
{
    /**
     * Analyze a natural language meeting request.
     * Returns parsed info + availability grid + suggested slots.
     */
    public function analyze(Request $request, MeetingSchedulerService $scheduler): JsonResponse
    {
        $user = $request->user();

        // Support both structured input and natural language
        if ($request->has('attendee_ids')) {
            // Structured form input
            $request->validate([
                'title'        => 'required|string|max:255',
                'attendee_ids' => 'required|array|min:1',
                'attendee_ids.*' => 'integer|exists:users,id',
                'date'         => 'required|date',
                'time'         => 'nullable|string',
                'time_mode'    => 'in:flexible,fixed',
                'duration'     => 'nullable|integer|min:15|max:120',
            ]);

            $attendeeIds = array_map('intval', $request->input('attendee_ids'));
            $attendeeNames = User::whereIn('id', $attendeeIds)->pluck('name')->toArray();
            $date = $request->input('date');
            $preferredTime = $request->input('time');
            $duration = (int) ($request->input('duration', 30));
            $title = $request->input('title');
            $timeMode = $request->input('time_mode', 'flexible');
            $unresolved = [];

        } else {
            // Natural language input (legacy)
            $request->validate(['message' => 'required|string|max:500']);
            $parsed = $scheduler->parseRequest($request->input('message'));

            if (isset($parsed['error'])) {
                return response()->json(['ok' => false, 'error' => $parsed['error']], 422);
            }

            $attendeeResult = $scheduler->resolveAttendees($parsed['attendees'] ?? []);
            if (empty($attendeeResult['resolved'])) {
                return response()->json(['ok' => false, 'error' => 'Could not find matching team members.'], 422);
            }

            $attendeeIds = array_column($attendeeResult['resolved'], 'id');
            $attendeeNames = array_column($attendeeResult['resolved'], 'name');
            $date = $parsed['date'] ?? Carbon::tomorrow('Asia/Kolkata')->toDateString();
            $preferredTime = $parsed['preferred_time'] ?? null;
            $duration = (int) ($parsed['duration_minutes'] ?? 30);
            $title = $parsed['title'] ?? 'Team Meeting';
            $timeMode = 'flexible';
            $unresolved = $attendeeResult['unresolved'];
        }

        // Reject dates that have already passed (covers both structured and natural-language input)
        $istToday = Carbon::today('Asia/Kolkata')->toDateString();
        if (Carbon::parse($date, 'Asia/Kolkata')->toDateString() < $istToday) {
            return response()->json(['ok' => false, 'error' => 'That date has already passed. Pick today or a later date.'], 422);
        }

        // Include requesting user
        $allUserIds = array_unique(array_merge([$user->id], $attendeeIds));

        // Check availability
        $availability = $scheduler->getAvailability($allUserIds, $date);

        // Find free slots
        $slots = $scheduler->findFreeSlots($availability, $allUserIds, $duration, $preferredTime);

        // Build user info for grid
        $userInfo = [];
        foreach ($allUserIds as $uid) {
            $u = User::find($uid);
            $userInfo[] = [
                'id'    => $uid,
                'name'  => $u?->name ?? "User {$uid}",
                'busy'  => $availability['user_busy'][$uid] ?? [],
                'is_me' => $uid === $user->id,
            ];
        }

        // For fixed mode: check if the exact time has clashes
        $fixedClashes = [];
        if ($timeMode === 'fixed' && $preferredTime) {
            $prefMin = null;
            if (preg_match('/^(\d{1,2}):(\d{2})$/', $preferredTime, $m)) {
                $prefMin = (int) $m[1] * 60 + (int) $m[2];
            }
            if ($prefMin !== null) {
                $prefEnd = $prefMin + $duration;
                foreach ($allUserIds as $uid) {
                    foreach ($availability['user_busy'][$uid] ?? [] as $block) {
                        if ($block['start'] < $prefEnd && $block['end'] > $prefMin) {
                            $userName = User::find($uid)?->name ?? "User {$uid}";
                            $fixedClashes[] = [
                                'user_id'     => $uid,
                                'user_name'   => $userName,
                                'meeting_key' => $block['meeting_key'] ?? '',
                                'meeting_id'  => $block['meeting_id'] ?? null,
                                'title'       => $block['title'],
                                'time'        => $block['time'],
                            ];
                        }
                    }
                }
            }
        }

        // Only suggest slots that haven't already elapsed today
        $futureSlots = array_values(array_filter($slots, fn ($s) => empty($s['passed'])));
        $suggested = array_slice($futureSlots, 0, 5);

        // Flag a preferred time that has already passed today (drives the fixed-mode notice)
        $timePassed = false;
        if ($preferredTime && Carbon::parse($date, 'Asia/Kolkata')->toDateString() === $istToday
            && preg_match('/^(\d{1,2}):(\d{2})$/', $preferredTime, $tp)) {
            $nowMin  = Carbon::now('Asia/Kolkata')->hour * 60 + Carbon::now('Asia/Kolkata')->minute;
            $prefMin = (int) $tp[1] * 60 + (int) $tp[2];
            $timePassed = $prefMin < $nowMin;
        }

        return response()->json([
            'ok'       => true,
            'parsed'   => [
                'attendees'      => $attendeeNames,
                'attendee_ids'   => array_values($attendeeIds),
                'date'           => $date,
                'day'            => $availability['day'],
                'preferred_time' => $preferredTime,
                'duration'       => $duration,
                'title'          => $title,
                'time_mode'      => $timeMode,
                'unresolved'     => $unresolved,
            ],
            'users'         => $userInfo,
            'slots'         => $slots,
            'suggested'     => $suggested,
            'fixed_clashes' => $fixedClashes,
            'time_passed'   => $timePassed,
            // Drives the "now" marker + past-shading on the availability grid (IST).
            'is_today'      => (Carbon::parse($date, 'Asia/Kolkata')->toDateString() === $istToday),
            'now_minutes'   => Carbon::now('Asia/Kolkata')->hour * 60 + Carbon::now('Asia/Kolkata')->minute,
        ]);
    }

    /**
     * Create a meeting from a selected time slot.
     */
    public function create(Request $request): JsonResponse
    {
        $request->validate([
            'title'     => 'required|string|max:255',
            'date'      => 'required|date',
            'time'      => 'required|string',
            'attendees' => 'required|array',
            'attendees.*' => 'integer|exists:users,id',
        ]);

        // Authoritative guard: never create a meeting in the past (covers flexible & fixed, any client state).
        // Carbon::parse handles both "HH:MM" (24h, fixed mode) and "HH:MM AM/PM" (flexible slot cards).
        if (Carbon::parse($request->input('date') . ' ' . $request->input('time'), 'Asia/Kolkata')
                ->lt(Carbon::now('Asia/Kolkata'))) {
            return response()->json(['ok' => false, 'error' => 'That time has already passed. Please pick an upcoming slot.'], 422);
        }

        $user    = $request->user();
        $date    = Carbon::parse($request->input('date'), 'Asia/Kolkata');
        $dayOfWeek = $date->format('l');

        // Normalize time to 12h format
        $time = $request->input('time');
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            $h = (int) $m[1];
            $min = $m[2];
            $period = $h >= 12 ? 'PM' : 'AM';
            $h12 = $h % 12;
            if ($h12 === 0) $h12 = 12;
            $time = sprintf('%02d:%s %s', $h12, $min, $period);
        }

        // Generate unique meeting key
        $portal = $user->role ?? 'ops';
        $baseKey = Str::slug($portal . '-' . $request->input('title'));
        $key = $baseKey;
        $counter = 1;
        while (Meeting::where('meeting_key', $key)->exists()) {
            $key = $baseKey . '-' . $counter++;
        }

        $attendeeIds = array_map('intval', $request->input('attendees'));
        // Ensure creator is in attendees
        if (! in_array($user->id, $attendeeIds)) {
            $attendeeIds[] = $user->id;
        }

        $meeting = Meeting::create([
            'meeting_key' => $key,
            'title'       => $request->input('title'),
            'owner'       => $user->name,
            'owner_id'    => $user->id,
            'day_of_week' => $dayOfWeek,
            'meeting_date' => $date->toDateString(),
            'time'        => $time,
            'recurrence'  => 'none',
            'portal'      => $portal,
            'attendees'   => array_values(array_unique($attendeeIds)),
            'created_by'  => $user->id,
        ]);

        // Resolve attendee names for response
        $attendees = User::whereIn('id', $attendeeIds)->get();
        $attendeeNames = $attendees->pluck('name')->toArray();

        // Notify attendees via Slack
        $slack = app(SlackService::class);
        $dateLabel = $date->format('D, j M');
        foreach ($attendees as $attendee) {
            if ($attendee->id === $user->id) {
                continue;
            }
            $slackUserId = $slack->getUserIdByName($attendee->name);
            if (! $slackUserId) {
                Log::warning('MeetingScheduler: attendee has no resolvable Slack account, invite not sent', [
                    'meeting_id'  => $meeting->id,
                    'attendee_id' => $attendee->id,
                    'attendee'    => $attendee->name,
                ]);
                continue;
            }
            $message = "*New Meeting Scheduled* 📅\n\n"
                . "*{$meeting->title}*\n"
                . "Date: {$dateLabel}\n"
                . "Time: {$time}\n"
                . "Scheduled by: {$user->name}\n"
                . "Attendees: " . implode(', ', $attendeeNames);
            // A meeting invite is a direct, user-initiated, time-sensitive ping, so it
            // bypasses the nightly Slack quiet window (which only exists to mute
            // automated fan-out reminders). Without this, evening/night invites were
            // silently dropped and the attendee was never notified.
            $sent = $slack->sendDirectMessage($slackUserId, $message, true);
            if (! $sent) {
                Log::warning('MeetingScheduler: Slack invite failed to send', [
                    'meeting_id'  => $meeting->id,
                    'attendee_id' => $attendee->id,
                    'slack_user'  => $slackUserId,
                ]);
            }
        }

        return response()->json([
            'ok'      => true,
            'meeting' => [
                'id'         => $meeting->id,
                'title'      => $meeting->title,
                'date'       => $date->toDateString(),
                'day'        => $dayOfWeek,
                'time'       => $time,
                'attendees'  => $attendeeNames,
                'meeting_key' => $meeting->meeting_key,
            ],
        ], 201);
    }

    /**
     * Suggest alternative times for a clashing meeting.
     */
    public function reschedule(Request $request, MeetingSchedulerService $scheduler): JsonResponse
    {
        $request->validate([
            'meeting_key' => 'required|string|max:50',
            'date'        => 'required|date',
        ]);

        $result = $scheduler->suggestReschedule(
            $request->input('meeting_key'),
            $request->input('date')
        );

        if (isset($result['error'])) {
            return response()->json(['ok' => false, 'error' => $result['error']], 422);
        }

        return response()->json(['ok' => true, 'data' => $result]);
    }

    /**
     * Skip a meeting for a specific date (adds to meeting_skips table).
     */
    public function skip(Request $request): JsonResponse
    {
        $request->validate([
            'meeting_key' => 'required|string|max:50',
            'date'        => 'required|date',
            'reason'      => 'nullable|string|max:255',
        ]);

        $meetingKey = $request->input('meeting_key');
        $date       = $request->input('date');

        // Verify meeting exists
        $meeting = Meeting::where('meeting_key', $meetingKey)->first();
        if (! $meeting) {
            return response()->json(['ok' => false, 'error' => 'Meeting not found'], 404);
        }

        // Insert or ignore (unique constraint on meeting_key + skip_date)
        DB::table('meeting_skips')->updateOrInsert(
            ['meeting_key' => $meetingKey, 'skip_date' => $date],
            [
                'reason'     => $request->input('reason', 'Skipped via scheduler'),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return response()->json(['ok' => true, 'skipped' => $meetingKey, 'date' => $date]);
    }

    /**
     * List meetings owned by the current user (for the Schedule view).
     */
    public function list(Request $request): JsonResponse
    {
        $user = $request->user();

        $meetings = Meeting::where('owner_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($m) {
                $attendeeNames = User::whereIn('id', $m->attendees ?? [])
                    ->pluck('name')
                    ->toArray();

                return [
                    'id'          => $m->id,
                    'meeting_key' => $m->meeting_key,
                    'title'       => $m->title,
                    'day_of_week' => $m->day_of_week,
                    'time'        => $m->time,
                    'recurrence'  => $m->recurrence,
                    'attendees'   => $attendeeNames,
                    'created_at'  => $m->created_at?->toIso8601String(),
                ];
            });

        return response()->json(['ok' => true, 'meetings' => $meetings]);
    }

    /**
     * Delete a meeting owned by the current user.
     */
    public function delete(Request $request): JsonResponse
    {
        $request->validate([
            'id' => 'required|integer',
        ]);

        $user = $request->user();
        $meeting = Meeting::find($request->input('id'));

        if (! $meeting) {
            return response()->json(['ok' => false, 'error' => 'Meeting not found'], 404);
        }

        if ((int) $meeting->owner_id !== $user->id) {
            return response()->json(['ok' => false, 'error' => 'You can only delete meetings you own'], 403);
        }

        $title = $meeting->title;
        $key = $meeting->meeting_key;

        DiscussionPoint::where('meeting_id', $key)->delete();
        ActionItem::where('meeting_id', $key)->delete();
        MeetingNote::where('meeting_id', $key)->delete();
        DB::table('meeting_skips')->where('meeting_key', $key)->delete();
        $meeting->delete();

        Log::info('MeetingSchedulerController::delete meeting deleted', [
            'meeting_id'  => $request->input('id'),
            'meeting_key' => $key,
            'title'       => $title,
            'deleted_by'  => $user->id,
        ]);

        return response()->json(['ok' => true]);
    }
}
