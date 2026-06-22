<?php

namespace App\Http\Controllers\Api\Meetings;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetingNote;
use App\Models\Role;
use App\Services\ActivityLogService;
use App\Services\ProjectRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MeetingNoteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $meetingId = trim((string) $request->query('meeting_id', ''));
        $weekKey = trim((string) $request->query('week_key', ''));

        Log::debug('MeetingNoteController::index', [
            'meeting_id' => $meetingId,
            'week_key' => $weekKey,
            'request_user_id' => $request->user()->id,
        ]);

        if ($meetingId === '' || ! $this->isValidWeekKey($weekKey)) {
            Log::debug('MeetingNoteController::index validation failed', [
                'meeting_id' => $meetingId,
                'week_key' => $weekKey,
            ]);

            return response()->json(['error' => 'meeting_id and valid week_key are required'], 422);
        }

        $meeting = $this->findMeeting($meetingId);
        if (! $meeting) {
            Log::warning('MeetingNoteController::index meeting not found', [
                'meeting_id' => $meetingId,
                'request_user_id' => $request->user()->id,
            ]);

            return response()->json(['error' => 'Meeting not found'], 404);
        }
        $this->requireMeetingAccess($meeting);

        $note = MeetingNote::where('meeting_id', $meetingId)
            ->where('week_key', $weekKey)
            ->first();

        $response = [
            'ok' => true,
            'note' => $note?->content ?? '',
            'updatedAt' => $note?->updated_at?->toIso8601String(),
            'updatedBy' => $note?->updated_by,
        ];

        if ($request->boolean('include_previous')) {
            $previousMeetingId = $meetingId;
            $previousWeekKey = $this->previousWeekKey($weekKey);

            $recurrence = trim((string) $request->query('recurrence', ''));
            $day = trim((string) $request->query('day', ''));
            if ($recurrence === 'daily_weekdays') {
                $occurrence = $this->previousDailyOccurrence($meetingId, $weekKey, $day);
                $previousMeetingId = $occurrence['meeting_id'];
                $previousWeekKey = $occurrence['week_key'];
            }

            $previous = MeetingNote::where('meeting_id', $previousMeetingId)
                ->where('week_key', $previousWeekKey)
                ->first();
            $response['previousWeekKey'] = $previousWeekKey;
            $response['previousNote'] = $previous?->content ?? '';
        }

        return response()->json($response);
    }

    public function store(Request $request): JsonResponse
    {
        if (($request->input('action') ?? '') !== 'save') {
            return response()->json(['error' => 'Unknown action'], 404);
        }

        $meetingId = trim((string) $request->input('meetingId', ''));
        $weekKey = trim((string) $request->input('weekKey', ''));
        $content = (string) $request->input('content', '');

        if ($meetingId === '' || ! $this->isValidWeekKey($weekKey)) {
            return response()->json(['error' => 'meetingId and valid weekKey are required'], 422);
        }

        $meeting = $this->findMeeting($meetingId);
        if (! $meeting) {
            Log::warning('MeetingNoteController::store meeting not found', [
                'meeting_id' => $meetingId,
                'request_user_id' => $request->user()->id,
            ]);

            return response()->json(['error' => 'Meeting not found'], 404);
        }
        $this->requireMeetingAccess($meeting);

        $note = MeetingNote::updateOrCreate(
            ['meeting_id' => $meetingId, 'week_key' => $weekKey],
            ['content' => $content, 'updated_by' => $request->user()->id]
        );

        Log::info('MeetingNoteController::store note saved', [
            'meeting_id' => $meetingId,
            'week_key' => $weekKey,
            'updated_by' => $request->user()->id,
        ]);

        ActivityLogService::log($request->user()->id, 'meeting_notes_saved', "{$request->user()->name} saved notes for {$meetingId}", 'meeting_note', null, ['meeting_id' => $meetingId, 'week_key' => $weekKey]);

        return response()->json([
            'ok' => true,
            'note' => [
                'meetingId' => $note->meeting_id,
                'weekKey' => $note->week_key,
                'content' => $note->content,
                'updatedAt' => $note->updated_at?->toIso8601String(),
                'updatedBy' => $note->updated_by,
            ],
        ]);
    }

    private function isValidWeekKey(string $weekKey): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekKey);
    }

    private function previousWeekKey(string $weekKey): string
    {
        return date('Y-m-d', strtotime($weekKey.' -7 days'));
    }

    private function findMeeting(string $meetingId): ?Meeting
    {
        $meeting = Meeting::where('meeting_key', $meetingId)->first();
        if ($meeting) {
            return $meeting;
        }

        $baseMeetingKey = $this->resolveBaseMeetingKey($meetingId);
        if ($baseMeetingKey !== $meetingId) {
            return Meeting::where('meeting_key', $baseMeetingKey)->first();
        }

        return null;
    }

    private function resolveBaseMeetingKey(string $meetingId): string
    {
        $suffixes = ['-mon', '-tue', '-wed', '-thu', '-fri'];
        foreach ($suffixes as $suffix) {
            if (str_ends_with($meetingId, $suffix)) {
                return substr($meetingId, 0, -strlen($suffix));
            }
        }

        return $meetingId;
    }

    private function previousDailyOccurrence(string $meetingId, string $weekKey, string $day): array
    {
        $baseMeetingKey = $this->resolveBaseMeetingKey($meetingId);
        $normalizedDay = ucfirst(strtolower(trim($day)));

        if ($normalizedDay === 'Monday') {
            return [
                'meeting_id' => $baseMeetingKey.'-fri',
                'week_key' => $this->previousWeekKey($weekKey),
            ];
        }
        if ($normalizedDay === 'Tuesday') {
            return ['meeting_id' => $baseMeetingKey, 'week_key' => $weekKey];
        }
        if ($normalizedDay === 'Wednesday') {
            return ['meeting_id' => $baseMeetingKey.'-tue', 'week_key' => $weekKey];
        }
        if ($normalizedDay === 'Thursday') {
            return ['meeting_id' => $baseMeetingKey.'-wed', 'week_key' => $weekKey];
        }
        if ($normalizedDay === 'Friday') {
            return ['meeting_id' => $baseMeetingKey.'-thu', 'week_key' => $weekKey];
        }

        return ['meeting_id' => $meetingId, 'week_key' => $this->previousWeekKey($weekKey)];
    }

    private function requireMeetingAccess(Meeting $meeting): void
    {
        $user = auth()->user();
        if (! ProjectRoleService::canAccessMeetings($user->role)) {
            Log::warning('MeetingNoteController::requireMeetingAccess access denied', [
                'user_role' => $user->role,
                'user_id' => $user->id,
                'meeting_id' => $meeting->id,
                'reason' => 'cannot_access_meetings',
            ]);
            abort(403, 'Forbidden');
        }

        if ($user->role === Role::SLUG_PRODUCT_MANAGER) {
            if ($meeting->owner_id === $user->id || in_array($user->id, $meeting->attendees ?? [], true)) {
                return;
            }
            Log::warning('MeetingNoteController::requireMeetingAccess access denied', [
                'user_role' => $user->role,
                'user_id' => $user->id,
                'meeting_id' => $meeting->id,
                'meeting_owner_id' => $meeting->owner_id,
                'reason' => 'product_manager_not_attendee',
            ]);
            abort(403, 'Forbidden');
        }

        if (
            $meeting->portal === $user->role
            || $meeting->owner_id === $user->id
            || in_array($user->id, $meeting->attendees ?? [], true)
        ) {
            return;
        }

        Log::warning('MeetingNoteController::requireMeetingAccess access denied', [
            'user_role' => $user->role,
            'user_id' => $user->id,
            'meeting_id' => $meeting->id,
            'meeting_portal' => $meeting->portal,
            'meeting_owner_id' => $meeting->owner_id,
            'reason' => 'not_authorized',
        ]);
        abort(403, 'Forbidden');
    }
}
