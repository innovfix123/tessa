<?php

namespace App\Http\Controllers\Api\Meetings;

use App\Helpers\DateHelper;
use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\Role;
use App\Models\User;
use App\Services\ProjectRoleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeetingAttendanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $meetingId = trim((string) $request->query('meeting_id', ''));
        $date = trim((string) $request->query('date', ''));

        if ($meetingId === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['error' => 'meeting_id and valid date are required'], 422);
        }

        $meeting = $this->findMeeting($meetingId);
        if (! $meeting) {
            return response()->json(['error' => 'Meeting not found'], 404);
        }
        $this->requireMeetingAccess($meeting);

        $records = MeetingAttendance::where('meeting_id', $meetingId)
            ->where('occurrence_date', $date)
            ->with('user:id,name')
            ->get();

        $attendance = $records->map(fn ($r) => [
            'userId' => $r->user_id,
            'userName' => $r->user?->name ?? 'Unknown',
            'status' => $r->status,
            'source' => $r->source,
        ])->values();

        $present = $records->where('status', 'present')->count();
        $absent = $records->where('status', 'absent')->count();

        return response()->json([
            'ok' => true,
            'attendance' => $attendance,
            'summary' => [
                'present' => $present,
                'absent' => $absent,
                'total' => $present + $absent,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $meetingId = trim((string) $request->input('meetingId', ''));
        $date = trim((string) $request->input('date', ''));
        $userId = (int) $request->input('userId', 0);
        $status = trim((string) $request->input('status', ''));

        if ($meetingId === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $userId === 0 || ! in_array($status, ['present', 'absent'])) {
            return response()->json(['error' => 'meetingId, date, userId, and status (present/absent) are required'], 422);
        }

        $meeting = $this->findMeeting($meetingId);
        if (! $meeting) {
            return response()->json(['error' => 'Meeting not found'], 404);
        }

        if ($meeting->owner_id !== auth()->id()) {
            abort(403, 'Only the meeting owner can override attendance');
        }

        MeetingAttendance::updateOrCreate(
            ['meeting_id' => $meetingId, 'occurrence_date' => $date, 'user_id' => $userId],
            ['status' => $status, 'source' => 'manual', 'recorded_by' => auth()->id()]
        );

        return response()->json(['ok' => true]);
    }

    public function summary(Request $request): JsonResponse
    {
        $userId = (int) $request->query('user_id', 0);
        $from = trim((string) $request->query('from', ''));
        $to = trim((string) $request->query('to', ''));

        if ($userId === 0 || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return response()->json(['error' => 'user_id, from, and to dates are required'], 422);
        }

        $currentUser = auth()->user();
        $targetUser = User::find($userId);
        if (! $targetUser) {
            return response()->json(['error' => 'User not found'], 404);
        }

        if ($currentUser->id !== $userId && ($targetUser->reporting_manager_id ?? null) !== $currentUser->id) {
            if (! in_array($currentUser->role, [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_HR, Role::SLUG_HR_OPERATIONS])) {
                abort(403, 'Forbidden');
            }
        }

        $records = MeetingAttendance::where('user_id', $userId)
            ->whereBetween('occurrence_date', [$from, $to])
            ->selectRaw('meeting_id, COUNT(*) as total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as present_count', ['present'])
            ->groupBy('meeting_id')
            ->get();

        $summary = $records->map(fn ($r) => [
            'meetingId' => $r->meeting_id,
            'present' => (int) $r->present_count,
            'total' => (int) $r->total,
            'rate' => $r->total > 0 ? round($r->present_count / $r->total * 100) : 0,
        ])->values();

        $totalPresent = $records->sum('present_count');
        $totalAll = $records->sum('total');

        return response()->json([
            'ok' => true,
            'userId' => $userId,
            'userName' => $targetUser->name,
            'from' => $from,
            'to' => $to,
            'meetings' => $summary,
            'overall' => [
                'present' => (int) $totalPresent,
                'total' => (int) $totalAll,
                'rate' => $totalAll > 0 ? round($totalPresent / $totalAll * 100) : 0,
            ],
        ]);
    }

    public function overview(Request $request): JsonResponse
    {
        $user = auth()->user();
        $managerRoles = [
            Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_CMO, Role::SLUG_CFO,
            Role::SLUG_TECH_LEAD, Role::SLUG_OPS, Role::SLUG_CONTENT_LEAD, Role::SLUG_HR, Role::SLUG_HR_OPERATIONS,
        ];
        $isManager = in_array($user->role, $managerRoles, true);

        if (! $isManager && ! Meeting::where('owner_id', $user->id)->exists()) {
            abort(403, 'Forbidden');
        }

        $dateStr = trim((string) $request->query('date', ''));
        if ($dateStr === '') {
            $dateStr = Carbon::now('Asia/Kolkata')->format('Y-m-d');
        } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            return response()->json(['error' => 'date must be YYYY-MM-DD'], 422);
        }

        $selectedDate = DateHelper::parse($dateStr);
        $dayName = $selectedDate->format('l');

        $meetingsQuery = Meeting::where(function ($q) use ($dayName) {
            // monthly_first keys off day_of_week but only occurs the first such weekday
            // of the month; exclude it from this day's attendance view.
            $q->where(function ($w) use ($dayName) {
                $w->where('day_of_week', $dayName)->where('recurrence', '!=', 'monthly_first');
            });
            $multiDay = [
                'daily_weekdays' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
                'tue_to_fri'     => ['Tuesday', 'Wednesday', 'Thursday', 'Friday'],
                'mon_thu'        => ['Monday', 'Thursday'],
                'mon_wed_fri'    => ['Monday', 'Wednesday', 'Friday'],
            ];
            foreach ($multiDay as $recurrence => $days) {
                if (in_array($dayName, $days, true)) {
                    $q->orWhere('recurrence', $recurrence);
                }
            }
        })->with('ownerUser');

        if (! $isManager) {
            $meetingsQuery->where('owner_id', $user->id);
        }

        $meetings = $meetingsQuery->get();
        $items = [];

        foreach ($meetings as $meeting) {
            $effectiveKey = $this->effectiveMeetingKey($meeting->meeting_key, $meeting->recurrence, $dayName);

            $records = MeetingAttendance::where('meeting_id', $effectiveKey)
                ->where('occurrence_date', $dateStr)
                ->with('user:id,name')
                ->get();

            $attendeeIds = $meeting->attendees ?? [];
            if ($meeting->owner_id && ! in_array($meeting->owner_id, $attendeeIds)) {
                $attendeeIds[] = $meeting->owner_id;
            }
            $attendeeNames = collect($attendeeIds)
                ->map(fn ($id) => User::find($id)?->name)
                ->filter()
                ->values()
                ->toArray();

            $attendees = [];
            foreach ($attendeeIds as $uid) {
                $record = $records->firstWhere('user_id', $uid);
                $attendees[] = [
                    'userId' => $uid,
                    'userName' => User::find($uid)?->name ?? 'Unknown',
                    'status' => $record?->status ?? 'no_data',
                    'source' => $record?->source ?? null,
                ];
            }

            $presentCount = collect($attendees)->where('status', 'present')->count();
            $totalCount = count($attendees);

            $items[] = [
                'meetingKey' => $effectiveKey,
                'title' => $meeting->title,
                'owner' => $meeting->ownerUser?->name ?? $meeting->owner,
                'time' => $meeting->time,
                'portal' => $meeting->portal,
                'attendees' => $attendees,
                'present' => $presentCount,
                'total' => $totalCount,
                'rate' => $totalCount > 0 ? round($presentCount / $totalCount * 100) : 0,
                'hasData' => $records->isNotEmpty(),
            ];
        }

        usort($items, fn ($a, $b) => ($this->timeToMinutes($a['time']) ?? 0) <=> ($this->timeToMinutes($b['time']) ?? 0));

        return response()->json([
            'ok' => true,
            'date' => $dateStr,
            'dayName' => $dayName,
            'items' => $items,
        ]);
    }

    private function effectiveMeetingKey(string $meetingKey, ?string $recurrence, string $dayName): string
    {
        $multiDay = [
            'daily_weekdays' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            'tue_to_fri'     => ['Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            'mon_thu'        => ['Monday', 'Thursday'],
            'mon_wed_fri'    => ['Monday', 'Wednesday', 'Friday'],
        ];

        if (! isset($multiDay[$recurrence ?? ''])) {
            return $meetingKey;
        }

        if ($recurrence === 'daily_weekdays' && $dayName === 'Monday') {
            return $meetingKey;
        }

        return $meetingKey . '-' . strtolower(substr($dayName, 0, 3));
    }

    private function timeToMinutes(?string $time): ?int
    {
        if (! $time) return null;
        if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i', trim($time), $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            $period = strtoupper($m[3]);
            if ($period === 'PM' && $h !== 12) $h += 12;
            if ($period === 'AM' && $h === 12) $h = 0;
            return $h * 60 + $min;
        }
        return null;
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

    private function requireMeetingAccess(Meeting $meeting): void
    {
        $user = auth()->user();
        if (! ProjectRoleService::canAccessMeetings($user->role)) {
            abort(403, 'Forbidden');
        }

        if ($user->role === Role::SLUG_PRODUCT_MANAGER) {
            if ($meeting->owner_id === $user->id || in_array($user->id, $meeting->attendees ?? [], true)) {
                return;
            }
            abort(403, 'Forbidden');
        }

        if (
            $meeting->portal === $user->role
            || $meeting->owner_id === $user->id
            || in_array($user->id, $meeting->attendees ?? [], true)
        ) {
            return;
        }

        abort(403, 'Forbidden');
    }
}
