<?php

namespace App\Http\Controllers\Api\Meetings;

use App\Helpers\DateHelper;
use App\Http\Controllers\Controller;
use App\Models\ActionItem;
use App\Models\DiscussionPoint;
use App\Models\Meeting;
use App\Models\LeaveRequest;
use App\Models\MeetingNote;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\ProjectRoleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MeetingController extends Controller
{
    private const RECURRENCE = ['daily_weekdays', 'weekly', 'none', 'tue_to_fri', 'mon_thu', 'mon_wed_fri', 'monthly_first'];

    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

    /** Recurrences that span multiple fixed weekdays; day_of_week stores only the primary (first) day. */
    private const MULTI_DAY_RECURRENCES = [
        'daily_weekdays' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
        'tue_to_fri'     => ['Tuesday', 'Wednesday', 'Thursday', 'Friday'],
        'mon_thu'        => ['Monday', 'Thursday'],
        'mon_wed_fri'    => ['Monday', 'Wednesday', 'Friday'],
    ];

    /**
     * Users whose meeting page shows only meetings scheduled for today (no prior/upcoming week).
     * On weekends they see an empty list.
     */
    private const TODAY_ONLY_USER_IDS = [2]; // Bala

    /** True if a meeting's recurrence/day_of_week lands on the given weekday name (e.g. 'Thursday'). */
    private function meetingOccursOn(Meeting $m, string $dayName): bool
    {
        if (isset(self::MULTI_DAY_RECURRENCES[$m->recurrence])) {
            return in_array($dayName, self::MULTI_DAY_RECURRENCES[$m->recurrence], true);
        }
        // weekly and one-time ('none') both key off day_of_week.
        // monthly_first also stores day_of_week but only occurs on the first such
        // weekday of the month — it's handled by the reminder cron, not this
        // day-expansion view, so exclude it here to avoid showing it every week.
        return $m->day_of_week === $dayName && $m->recurrence !== 'monthly_first';
    }

    /**
     * Read-only list of meeting action items, scoped to the meetings the user
     * can already see (their role-portal's role-wide meetings + ones they own
     * or attend). Optional filters: meeting_id (meeting_key), status, week_key,
     * owner (name contains), limit. Backs the MCP list_action_items tool — the
     * portal renders these inline per meeting, so there is no other GET route.
     */
    public function actionItems(Request $request): JsonResponse
    {
        $user = auth()->user();
        $userId = $user->id;

        $visibleKeys = Meeting::where(function ($q) use ($user, $userId) {
            $q->where(function ($q2) use ($user) {
                $q2->where('portal', $user->role)->where('attendees_only', false);
            })
                ->orWhere('owner_id', $userId)
                ->orWhereJsonContains('attendees', $userId);
        })->pluck('meeting_key');

        $q = ActionItem::whereIn('meeting_id', $visibleKeys);

        if ($mid = trim((string) $request->query('meeting_id', ''))) {
            $q->where('meeting_id', $mid);
        }
        if ($status = trim((string) $request->query('status', ''))) {
            $q->where('status', $status);
        }
        if ($wk = trim((string) $request->query('week_key', ''))) {
            $q->whereDate('week_key', $wk);
        }
        if ($owner = trim((string) $request->query('owner', ''))) {
            $q->where('owner', 'like', '%'.$owner.'%');
        }
        $limit = min(200, max(1, (int) $request->query('limit', 50)));

        $items = $q->orderByDesc('week_key')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (ActionItem $a) => [
                'id' => $a->id,
                'meeting_id' => $a->meeting_id,
                'week_key' => $a->week_key?->format('Y-m-d'),
                'task' => $a->task,
                'owner' => $a->owner,
                'status' => $a->status,
                'priority' => $a->priority,
                'deadline' => $a->deadline?->format('Y-m-d'),
                'comment' => $a->comment,
            ])->values();

        return response()->json(['ok' => true, 'count' => $items->count(), 'items' => $items]);
    }

    public function index(Request $request): JsonResponse
    {
        $portal = $this->normalizePortal($request->query('portal', ''));
        $this->requirePortalAccess($portal);

        $user = auth()->user();
        $userId = $user->id;

        $meetings = Meeting::where(function ($q) use ($portal, $userId) {
            if ($portal === Role::SLUG_PRODUCT_MANAGER) {
                $q->where('owner_id', $userId)
                    ->orWhereJsonContains('attendees', $userId);

                return;
            }

            // Role-wide meetings reach everyone in the portal, EXCEPT those flagged
            // attendees_only (e.g. the AI Intern Standup), which fall through to the
            // owner/attendee clauses below so only their own people see them.
            $q->where(function ($q2) use ($portal) {
                $q2->where('portal', $portal)
                    ->where('attendees_only', false);
            })
                ->orWhere('owner_id', $userId)
                ->orWhereJsonContains('attendees', $userId);
        })
            ->orderByRaw("FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday')")
            ->orderBy('time')
            ->orderBy('id')
            ->with('ownerUser')
            ->get();

        // Capture original meeting_keys before any TODAY_ONLY rewrite so skip lookups
        // still match the unsuffixed keys actually stored in meeting_skips.
        $originalKeyByMeetingId = $meetings->mapWithKeys(fn (Meeting $m) => [$m->id => $m->meeting_key])->all();

        // Today-only users: restrict to meetings scheduled for today's weekday (empty on weekends).
        // Rewrite recurrence/day_of_week in-memory so the client renders each as a single row for today
        // rather than the multi-day expansion it does for recurring meetings.
        $todayOnlyDay = null;
        if (in_array($userId, self::TODAY_ONLY_USER_IDS, true)) {
            $today = Carbon::now('Asia/Kolkata');
            if ($today->isWeekend()) {
                $meetings = $meetings->take(0);
            } else {
                $todayOnlyDay = $today->format('l');
                $meetings = $meetings
                    ->filter(fn (Meeting $m) => $this->meetingOccursOn($m, $todayOnlyDay))
                    ->each(function (Meeting $m) use ($todayOnlyDay) {
                        // Multi-day recurrences (daily_weekdays, tue_to_fri, mon_thu, mon_wed_fri)
                        // pass through unchanged so the client renders them as a single multi-day
                        // card with all weekday badges + the correct "Daily (Mon-Fri)" label.
                        // expandMeetings already produces the per-day suffixed ids that match
                        // SlackHuddleSyncService::resolveDayMeetingId, so storage stays per-day.
                        if (isset(self::MULTI_DAY_RECURRENCES[$m->recurrence])) {
                            return;
                        }
                        $m->day_of_week = $todayOnlyDay;
                        $m->recurrence = 'weekly';
                    })
                    ->values();
            }
        }

        $skipLookupKeys = array_values(array_unique($originalKeyByMeetingId));
        $skipsByKey = DB::table('meeting_skips')
            ->whereIn('meeting_key', $skipLookupKeys)
            ->get()
            ->groupBy('meeting_key')
            ->map(fn ($rows) => $rows->pluck('skip_date')->map(fn ($d) => DateHelper::parse($d)->format('Y-m-d'))->toArray())
            ->toArray();

        $items = $meetings->map(function (Meeting $m) use ($portal, $userId, $skipsByKey, $originalKeyByMeetingId) {
            $skipKey = $originalKeyByMeetingId[$m->id] ?? $m->meeting_key;
            return $this->normalizeMeeting($m, $portal, $userId, $skipsByKey[$skipKey] ?? []);
        });

        return response()->json(['ok' => true, 'items' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->all();
        $action = $payload['action'] ?? '';

        if ($action === 'add') {
            return $this->add($request);
        }
        if ($action === 'update') {
            return $this->updateMeeting($request);
        }
        if ($action === 'delete') {
            return $this->deleteMeeting($request);
        }

        return response()->json(['error' => 'Unknown action'], 404);
    }

    private function add(Request $request): JsonResponse
    {
        $portal = $this->normalizePortal($request->input('portal', ''));
        $this->requirePortalAccess($portal);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'ownerId' => 'required|integer|exists:users,id',
            'time' => ['required', 'string', 'regex:/^(0?[1-9]|1[0-2]):[0-5][0-9]\s?(AM|PM)$/i'],
            'recurrence' => 'in:'.implode(',', self::RECURRENCE),
            'dayOfWeek' => 'nullable|in:'.implode(',', self::DAYS),
            'attendees' => 'nullable|array',
            'attendees.*' => 'integer|exists:users,id',
            'agendaTemplateId' => 'nullable|integer|exists:agenda_templates,id',
        ]);

        $recurrence = strtolower($validated['recurrence'] ?? 'weekly');
        $dayOfWeek = $validated['dayOfWeek'] ?? null;
        if ($recurrence === 'none' && ! $dayOfWeek) {
            return response()->json(['error' => 'dayOfWeek is required for one-time meetings'], 422);
        }
        if (isset(self::MULTI_DAY_RECURRENCES[$recurrence])) {
            $dayOfWeek = self::MULTI_DAY_RECURRENCES[$recurrence][0];
        }

        $attendees = $request->input('attendees', []);
        $attendees = is_array($attendees) ? array_values(array_unique(array_map('intval', array_filter($attendees)))) : [];

        $ownerUser = User::findOrFail($validated['ownerId']);
        $meetingKey = $this->generateMeetingKey($portal, $validated['title']);

        $agendaTemplateId = $request->input('agendaTemplateId');
        $meeting = Meeting::create([
            'meeting_key' => $meetingKey,
            'title' => $validated['title'],
            'owner' => $ownerUser->name,
            'owner_id' => $validated['ownerId'],
            'day_of_week' => $dayOfWeek,
            'time' => $this->normalizeTime($validated['time']),
            'recurrence' => $recurrence,
            'portal' => $portal,
            'attendees' => $attendees,
            'agenda_template_id' => $agendaTemplateId ? (int) $agendaTemplateId : null,
            'created_by' => $request->user()->id,
        ]);

        Log::info('MeetingController::add meeting created', [
            'meeting_id' => $meeting->id,
            'meeting_key' => $meetingKey,
            'title' => $validated['title'],
            'portal' => $portal,
            'owner_id' => $validated['ownerId'],
            'created_by' => $request->user()->id,
        ]);

        ActivityLogService::log($request->user()->id, 'meeting_created', "{$request->user()->name} created meeting: {$validated['title']}", 'meeting', $meeting->id, ['title' => $validated['title'], 'portal' => $portal]);

        return response()->json(['ok' => true, 'item' => $this->normalizeMeeting($meeting, $portal, $request->user()->id)], 201);
    }

    private function updateMeeting(Request $request): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        if ($id <= 0) {
            return response()->json(['error' => 'id is required'], 422);
        }

        $meeting = Meeting::find($id);
        if (! $meeting) {
            return response()->json(['error' => 'Meeting not found'], 404);
        }
        $this->requireMeetingEditAccess($meeting);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'ownerId' => 'sometimes|required|integer|exists:users,id',
            'time' => ['sometimes', 'required', 'string', 'regex:/^(0?[1-9]|1[0-2]):[0-5][0-9]\s?(AM|PM)$/i'],
            'recurrence' => 'sometimes|in:'.implode(',', self::RECURRENCE),
            'dayOfWeek' => 'nullable|in:'.implode(',', self::DAYS),
            'attendees' => 'nullable|array',
            'attendees.*' => 'integer|exists:users,id',
            'agendaTemplateId' => 'nullable|integer|exists:agenda_templates,id',
        ]);

        $recurrence = $validated['recurrence'] ?? $meeting->recurrence;
        $dayOfWeek = $validated['dayOfWeek'] ?? $meeting->day_of_week;
        if ($recurrence === 'none' && ! $dayOfWeek) {
            return response()->json(['error' => 'dayOfWeek is required for one-time meetings'], 422);
        }
        if (isset(self::MULTI_DAY_RECURRENCES[$recurrence])) {
            $dayOfWeek = self::MULTI_DAY_RECURRENCES[$recurrence][0];
        }

        $attendees = $request->has('attendees') ? $request->input('attendees', []) : $meeting->attendees;
        $attendees = is_array($attendees) ? array_values(array_unique(array_map('intval', array_filter($attendees)))) : [];

        $updateData = [
            'title' => $validated['title'] ?? $meeting->title,
            'day_of_week' => $dayOfWeek,
            'time' => isset($validated['time']) ? $this->normalizeTime($validated['time']) : $meeting->time,
            'recurrence' => $recurrence,
            'attendees' => $attendees,
        ];
        if (array_key_exists('ownerId', $validated)) {
            $ownerUser = User::findOrFail($validated['ownerId']);
            $updateData['owner_id'] = $ownerUser->id;
            $updateData['owner'] = $ownerUser->name;
        }
        if (array_key_exists('agendaTemplateId', $request->all())) {
            $updateData['agenda_template_id'] = $request->input('agendaTemplateId') ? (int) $request->input('agendaTemplateId') : null;
        }
        $meeting->update($updateData);

        Log::info('MeetingController::updateMeeting meeting updated', [
            'meeting_id' => $id,
            'changed_fields' => array_keys($updateData),
            'updated_by' => $request->user()->id,
        ]);

        ActivityLogService::log($request->user()->id, 'meeting_updated', "{$request->user()->name} updated meeting: {$meeting->title}", 'meeting', $id, ['title' => $meeting->title]);

        return response()->json(['ok' => true, 'item' => $this->normalizeMeeting($meeting->fresh(), $request->user()->role, $request->user()->id)]);
    }

    private function deleteMeeting(Request $request): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        if ($id <= 0) {
            return response()->json(['error' => 'id is required'], 422);
        }

        $meeting = Meeting::find($id);
        if (! $meeting) {
            Log::warning('MeetingController::deleteMeeting meeting not found', [
                'meeting_id' => $id,
                'request_user_id' => $request->user()->id,
            ]);

            return response()->json(['error' => 'Meeting not found'], 404);
        }
        $this->requireMeetingEditAccess($meeting);

        $meetingTitle = $meeting->title;
        DiscussionPoint::where('meeting_id', $meeting->meeting_key)->delete();
        ActionItem::where('meeting_id', $meeting->meeting_key)->delete();
        MeetingNote::where('meeting_id', $meeting->meeting_key)->delete();
        $meeting->delete();

        Log::info('MeetingController::deleteMeeting meeting deleted', [
            'meeting_id' => $id,
            'meeting_key' => $meeting->meeting_key,
            'title' => $meetingTitle,
            'deleted_by' => $request->user()->id,
        ]);

        ActivityLogService::log($request->user()->id, 'meeting_deleted', "{$request->user()->name} deleted meeting: {$meetingTitle}", 'meeting', $id, ['title' => $meetingTitle]);

        return response()->json(['ok' => true]);
    }

    public function pendingNotes(Request $request): JsonResponse
    {
        $user = auth()->user();
        $userId = $user->id;
        $now = DateHelper::now();
        $weekStart = $now->copy()->startOfWeek(Carbon::MONDAY);
        $weekKey = $weekStart->format('Y-m-d');

        // Only show pending notes for meetings the user owns/created
        $meetings = Meeting::where('owner_id', $userId)->get();

        $meetingKeys = $meetings->pluck('meeting_key')->toArray();
        $skippedDates = DB::table('meeting_skips')
            ->whereIn('meeting_key', $meetingKeys)
            ->get()
            ->groupBy('meeting_key')
            ->map(fn ($rows) => $rows->pluck('skip_date')->map(fn ($d) => DateHelper::parse($d)->format('Y-m-d'))->toArray())
            ->toArray();

        $dayMap = ['Monday' => 0, 'Tuesday' => 1, 'Wednesday' => 2, 'Thursday' => 3, 'Friday' => 4];
        $todayDayIndex = $now->dayOfWeekIso - 1; // 0=Mon, 4=Fri
        $currentTime = $now->format('H:i');

        // Collect dates this week where the user was on a non-working leave
        // (same rule as SignoffStatusService — WFH/permission still count as worked).
        $leaveDatesThisWeek = LeaveRequest::where('user_id', $userId)
            ->where('status', 'approved')
            ->where('start_date', '<=', $weekStart->copy()->addDays(4)->format('Y-m-d'))
            ->where('end_date', '>=', $weekStart->format('Y-m-d'))
            ->whereHas('leaveType', fn ($q) => $q->where('is_hourly', false)->where('slug', '!=', 'wfh'))
            ->get()
            ->flatMap(function ($lr) use ($weekStart) {
                $dates = [];
                $cursor = Carbon::parse($lr->start_date);
                $end    = Carbon::parse($lr->end_date);
                while ($cursor->lte($end)) {
                    $dates[] = $cursor->format('Y-m-d');
                    $cursor->addDay();
                }
                return $dates;
            })
            ->unique()
            ->flip() // flip to use isset() for O(1) lookup
            ->toArray();

        $pending = [];

        foreach ($meetings as $m) {
            $meetingTime24 = date('H:i', strtotime($m->time));
            $isMultiDay = isset(self::MULTI_DAY_RECURRENCES[$m->recurrence]);
            $days = $isMultiDay ? self::MULTI_DAY_RECURRENCES[$m->recurrence] : [$m->day_of_week];

            $meetingSkips = $skippedDates[$m->meeting_key] ?? [];

            foreach ($days as $day) {
                $dayIndex = $dayMap[$day] ?? null;
                if ($dayIndex === null) continue;

                $occurrenceDate = $weekStart->copy()->addDays($dayIndex)->format('Y-m-d');

                // monthly_first occurs only on the first <weekday> of the month (day 1–7).
                if ($m->recurrence === 'monthly_first' && (int) Carbon::parse($occurrenceDate, 'Asia/Kolkata')->day > 7) continue;

                // One-time meetings pinned to a specific date should not surface as
                // "missing notes" on every same-weekday in the current week — skip
                // unless the current week's occurrence date matches the pinned date.
                if ($m->recurrence === 'none' && $m->meeting_date) {
                    $pinned = $m->meeting_date instanceof Carbon
                        ? $m->meeting_date->format('Y-m-d')
                        : (string) $m->meeting_date;
                    if ($pinned !== $occurrenceDate) continue;
                }

                if (in_array($occurrenceDate, $meetingSkips, true)) continue;

                // Don't ask for meeting notes on days the user was on leave.
                if (isset($leaveDatesThisWeek[$occurrenceDate])) continue;

                // Only show if meeting time has passed
                if ($dayIndex > $todayDayIndex) continue; // future day
                if ($dayIndex === $todayDayIndex && $meetingTime24 > $currentTime) continue; // today but not yet

                $useSuffix = $isMultiDay && ! ($m->recurrence === 'daily_weekdays' && $day === 'Monday');
                $noteKey = $m->meeting_key . ($useSuffix ? '-' . strtolower(substr($day, 0, 3)) : '');

                $hasNote = MeetingNote::where('meeting_id', $noteKey)
                    ->where('week_key', $weekKey)
                    ->whereNotNull('content')
                    ->where('content', '!=', '')
                    ->exists();

                if (!$hasNote) {
                    $isOverdue = $dayIndex < $todayDayIndex;
                    $pending[] = [
                        'meetingKey' => $noteKey,
                        'title' => $m->title,
                        'dayOfWeek' => $day,
                        'time' => $m->time,
                        'isOverdue' => $isOverdue,
                    ];
                }
            }
        }

        return response()->json(['ok' => true, 'items' => $pending]);
    }

    private function normalizePortal(string $portal): string
    {
        $value = strtolower(trim($portal));
        $validPortals = Role::getSlugsWithPermission('meeting.access');
        if (! in_array($value, $validPortals, true)) {
            abort(422, 'Invalid portal');
        }

        return $value;
    }

    private function requirePortalAccess(string $portal): void
    {
        $user = auth()->user();
        if (! ProjectRoleService::canAccessMeetings($user->role)) {
            Log::warning('MeetingController::requirePortalAccess access denied', [
                'user_role' => $user->role,
                'user_id' => $user->id,
                'portal' => $portal,
                'reason' => 'cannot_access_meetings',
            ]);
            abort(403, 'Forbidden');
        }
        if ($user->role !== $portal) {
            Log::warning('MeetingController::requirePortalAccess access denied', [
                'user_role' => $user->role,
                'user_id' => $user->id,
                'portal' => $portal,
                'reason' => 'role_mismatch',
            ]);
            abort(403, 'Forbidden');
        }
    }

    private function requireMeetingEditAccess(Meeting $meeting): void
    {
        $user = auth()->user();
        if (! ProjectRoleService::canAccessMeetings($user->role)) {
            Log::warning('MeetingController::requireMeetingEditAccess access denied', [
                'user_role' => $user->role,
                'user_id' => $user->id,
                'meeting_id' => $meeting->id,
                'reason' => 'cannot_access_meetings',
            ]);
            abort(403, 'Forbidden');
        }
        if ($user->id === $meeting->owner_id) {
            return;
        }
        Log::warning('MeetingController::requireMeetingEditAccess access denied', [
            'user_role' => $user->role,
            'user_id' => $user->id,
            'meeting_id' => $meeting->id,
            'meeting_owner_id' => $meeting->owner_id,
            'reason' => 'not_owner',
        ]);
        abort(403, 'Forbidden');
    }

    private function normalizeTime(string $time): string
    {
        $value = strtoupper(trim($time));
        if (strlen($value) === 7) {
            $value = '0'.$value;
        }

        return str_replace('  ', ' ', $value);
    }

    private function generateMeetingKey(string $portal, string $title): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) ?? '', '-') ?: 'meeting';
        $base = substr($portal.'-'.$slug, 0, 45);
        $candidate = $base;
        $suffix = 1;
        while (Meeting::where('meeting_key', $candidate)->exists()) {
            Log::debug('MeetingController::generateMeetingKey collision detected', [
                'candidate' => $candidate,
                'suffix' => $suffix,
                'portal' => $portal,
                'title' => $title,
            ]);
            $candidate = substr($base, 0, 45 - strlen((string) $suffix) - 1).'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function normalizeMeeting(Meeting $m, ?string $userPortal = null, ?int $userId = null, array $skipDates = []): array
    {
        $isGuest = $userPortal !== null && $m->portal !== $userPortal;
        $canEdit = $userId !== null && $userId === $m->owner_id;

        $attendeeIds = $m->attendees ?? [];
        $attendeeNames = collect($attendeeIds)
            ->map(fn ($id) => User::find($id)?->name)
            ->filter()
            ->values()
            ->toArray();

        $ownerName = $m->ownerUser?->name ?? $m->owner;

        return [
            'id' => $m->id,
            'meetingKey' => $m->meeting_key,
            'title' => $m->title,
            'owner' => $ownerName,
            'ownerId' => $m->owner_id,
            'dayOfWeek' => $m->day_of_week,
            // meetingDate is set only for one-time meetings (recurrence='none') scheduled
            // for a specific calendar date. Frontend uses it to pin the meeting to that
            // single week instead of rendering it as if it recurs weekly.
            'meetingDate' => $m->meeting_date?->format('Y-m-d'),
            'time' => $m->time,
            'recurrence' => $m->recurrence,
            'portal' => $m->portal,
            'attendees' => $attendeeNames,
            'attendeeIds' => $attendeeIds,
            'agendaTemplateId' => $m->agenda_template_id,
            'isGuest' => $isGuest,
            'canEdit' => $canEdit,
            'skipDates' => $skipDates,
            'createdBy' => $m->created_by,
            'createdAt' => $m->created_at?->toIso8601String(),
            'updatedAt' => $m->updated_at?->toIso8601String(),
        ];
    }
}
