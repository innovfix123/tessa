<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bug;
use App\Models\DailyReport;
use App\Models\DailySignin;
use App\Models\DailySignoff;
use App\Models\DiscussionPoint;
use App\Models\KpiDefinition;
use App\Models\LeaveRequest;
use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\MeetingNote;
use App\Models\Role;
use App\Models\TessaTask;
use App\Models\Ticket;
use App\Models\User;
use App\Helpers\DateHelper;
use App\Services\KraScorecardService;
use App\Services\ProjectRoleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminApiController extends Controller
{
    private const DAILY_FIELD_KEYS = [
        'applications_received',
        'applications_verified',
        'avg_verification_time_hrs',
        'active_creators',
        'avg_resolution_time_hrs',
        'calls_tried',
        'calls_connected_pct',
    ];

    public function meetingsOverview(Request $request): JsonResponse
    {
        $dateStr = $request->query('date', '');
        if ($dateStr === '') {
            $dateStr = Carbon::now('Asia/Kolkata')->format('Y-m-d');
        } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($dateStr))) {
            return response()->json(['error' => 'date must be YYYY-MM-DD'], 422);
        } else {
            $dateStr = trim($dateStr);
        }

        $selectedDate = DateHelper::parse($dateStr);
        $dayName = $selectedDate->format('l'); // Monday, Tuesday, etc.
        $weekKey = $selectedDate->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        $now = Carbon::now('Asia/Kolkata');
        $isToday = $selectedDate->isSameDay($now);

        $meetings = Meeting::where(function ($q) use ($dayName, $dateStr) {
            // Weekly meetings on this weekday + one-time meetings; one-time rows that
            // are pinned to a specific calendar date must only match when $dateStr
            // equals that date, otherwise the one-time row leaks into every same-weekday
            // overview (e.g. an HR eval on Tue 30 Jun showing up every Tuesday).
            $q->where(function ($w) use ($dayName, $dateStr) {
                $w->where('day_of_week', $dayName)
                    ->where('recurrence', '!=', 'monthly_first')
                    ->where(function ($d) use ($dateStr) {
                        $d->where('recurrence', '!=', 'none')
                            ->orWhereNull('meeting_date')
                            ->orWhereDate('meeting_date', $dateStr);
                    });
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
        })
            ->with('ownerUser')
            ->get();

        $items = [];
        foreach ($meetings as $meeting) {
            $effectiveKey = $this->effectiveMeetingKey($meeting->meeting_key, $meeting->recurrence, $dayName);

            $points = DiscussionPoint::where('meeting_id', $effectiveKey)
                ->where('week_key', $weekKey)
                ->get();

            $total = $points->count();
            $filled = $points->filter(fn ($p) => trim((string) ($p->answer ?? '')) !== '')->count();

            if ($total === 0) {
                $agendaStatus = 'empty';
            } elseif ($filled >= $total) {
                $agendaStatus = 'filled';
            } else {
                $agendaStatus = 'partial';
            }

            $timePassed = $this->meetingTimeHasPassed($meeting->time, $selectedDate, $isToday, $now);

            $note = MeetingNote::where('meeting_id', $effectiveKey)
                ->where('week_key', $weekKey)
                ->first();
            $notesStatus = ($note && trim((string) ($note->content ?? '')) !== '') ? 'written' : 'empty';

            if ($agendaStatus === 'filled') {
                $rowColor = 'green';
            } elseif ($timePassed && $agendaStatus !== 'filled') {
                $rowColor = 'red';
            } else {
                $rowColor = 'yellow';
            }

            $attendeeIds = $meeting->attendees ?? [];
            $attendeeNames = collect($attendeeIds)
                ->map(fn ($id) => User::find($id)?->name)
                ->filter()
                ->values()
                ->toArray();

            $parsed = $this->parseMeetingTime($meeting->time);
            $sortMinutes = $parsed ? (int) substr($parsed, 0, 2) * 60 + (int) substr($parsed, 3, 2) : 0;

            $items[] = [
                'id' => $meeting->id,
                'meetingKey' => $meeting->meeting_key,
                'title' => $meeting->title,
                'owner' => $meeting->ownerUser?->name ?? $meeting->owner,
                'ownerId' => $meeting->owner_id,
                'dayOfWeek' => $meeting->day_of_week,
                'time' => $meeting->time,
                'recurrence' => $meeting->recurrence,
                'portal' => $meeting->portal,
                'attendees' => $attendeeNames,
                'attendeeCount' => count($attendeeIds),
                'agendaStatus' => $agendaStatus,
                'agendaFilled' => $filled,
                'agendaTotal' => $total,
                'notesStatus' => $notesStatus,
                'timePassed' => $timePassed,
                'rowColor' => $rowColor,
                'sortMinutes' => $sortMinutes,
            ];
        }

        usort($items, fn ($a, $b) => $a['sortMinutes'] <=> $b['sortMinutes']);
        foreach ($items as &$item) {
            unset($item['sortMinutes']);
        }

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

        $recurrence = $recurrence ?? '';
        if (! isset($multiDay[$recurrence])) {
            return $meetingKey;
        }

        if ($recurrence === 'daily_weekdays' && $dayName === 'Monday') {
            return $meetingKey;
        }

        return $meetingKey.'-'.strtolower(substr($dayName, 0, 3));
    }

    private function meetingTimeHasPassed(string $timeStr, Carbon $selectedDate, bool $isToday, Carbon $now): bool
    {
        if (! $isToday) {
            if ($selectedDate->isPast()) {
                return true;
            }

            return false;
        }

        $parsed = $this->parseMeetingTime($timeStr);
        if ($parsed === null) {
            return false;
        }

        $meetingDateTime = $selectedDate->copy()->shiftTimezone('Asia/Kolkata')->setTimeFromTimeString($parsed);

        return $now->greaterThan($meetingDateTime);
    }

    private function parseMeetingTime(string $timeStr): ?string
    {
        $timeStr = trim($timeStr);
        if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i', $timeStr, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            $ampm = strtoupper($m[3]);
            if ($ampm === 'PM' && $h < 12) {
                $h += 12;
            } elseif ($ampm === 'AM' && $h === 12) {
                $h = 0;
            }

            return sprintf('%02d:%02d:00', $h, $min);
        }

        return null;
    }

    public function dailyReportsOverview(Request $request): JsonResponse
    {
        $reportDate = $request->query('report_date', '');
        if ($reportDate === '') {
            // Daily reports are filled for the previous day's performance (matches
            // DashboardController's reportDateOffset=-1 for non-CEO roles), so default
            // the overview to yesterday rather than today — today's row always shows
            // zeros until end-of-day submissions begin.
            $reportDate = Carbon::now('Asia/Kolkata')->subDay()->format('Y-m-d');
        } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($reportDate))) {
            return response()->json(['error' => 'report_date must be YYYY-MM-DD'], 422);
        } else {
            $reportDate = trim($reportDate);
        }

        $roleSlugs = Role::getSlugsWithPermission('daily_report.edit');
        if (empty($roleSlugs)) {
            return response()->json([
                'ok' => true,
                'reportDate' => $reportDate,
                'items' => [],
            ]);
        }

        $users = User::where('is_active', true)
            ->whereHas('roleRelation', fn ($q) => $q->whereIn('slug', $roleSlugs))
            ->with('roleRelation')
            ->orderBy('name')
            ->get();

        $items = [];
        foreach ($users as $user) {
            $fieldsMeta = $this->getFieldsForUser($user->id);
            $totalFields = count($fieldsMeta['fields']);

            $entries = DailyReport::where('user_id', $user->id)
                ->where('report_date', $reportDate)
                ->get();

            $filledCount = $entries->filter(fn ($e) => trim((string) ($e->value ?? '')) !== '')->count();

            if ($totalFields === 0) {
                $status = 'n/a';
            } elseif ($filledCount >= $totalFields) {
                $status = 'submitted';
            } elseif ($filledCount > 0) {
                $status = 'partial';
            } else {
                $status = 'missing';
            }

            $items[] = [
                'userId' => $user->id,
                'userName' => $user->name,
                'role' => $user->roleRelation?->name ?? '',
                'filledCount' => $filledCount,
                'totalFields' => $totalFields,
                'status' => $status,
            ];
        }

        return response()->json([
            'ok' => true,
            'reportDate' => $reportDate,
            'items' => $items,
        ]);
    }

    public function signInOverview(Request $request): JsonResponse
    {
        $dateStr = $request->query('date', '');
        if ($dateStr === '') {
            $dateStr = Carbon::now('Asia/Kolkata')->format('Y-m-d');
        } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($dateStr))) {
            return response()->json(['error' => 'date must be YYYY-MM-DD'], 422);
        } else {
            $dateStr = trim($dateStr);
        }

        $users = User::where('is_active', true)
            ->whereNotNull('reporting_manager_id')
            ->with('roleRelation')
            ->orderBy('name')
            ->get();

        if ($users->isEmpty()) {
            return response()->json([
                'ok' => true,
                'date' => $dateStr,
                'summary' => [
                    'totalCount' => 0,
                    'signedInCount' => 0,
                    'signedOffCount' => 0,
                ],
                'items' => [],
            ]);
        }

        $userIds = $users->pluck('id')->all();

        $signinByUser = DailySignin::where('signin_date', $dateStr)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        $signoffByUser = DailySignoff::where('signoff_date', $dateStr)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        $items = [];
        $signedInCount = 0;
        $signedOffCount = 0;

        foreach ($users as $user) {
            $signin = $signinByUser->get($user->id);
            $signoff = $signoffByUser->get($user->id);

            $signedIn = (bool) $signin;
            $signedOff = (bool) $signoff;
            if ($signedIn) {
                $signedInCount++;
            }
            if ($signedOff) {
                $signedOffCount++;
            }

            // Status priority: signed_off > signed_in > missing
            if ($signedOff) {
                $status = 'signed_off';
            } elseif ($signedIn) {
                $status = 'signed_in';
            } else {
                $status = 'missing';
            }

            $items[] = [
                'userId' => $user->id,
                'userName' => $user->name,
                'role' => $user->roleRelation?->name ?? '',
                'signedIn' => $signedIn,
                'signedInAt' => $signin?->signed_in_at?->toIso8601String(),
                'signedOff' => $signedOff,
                'signedOffAt' => $signoff?->signed_off_at?->toIso8601String(),
                'status' => $status,
            ];
        }

        return response()->json([
            'ok' => true,
            'date' => $dateStr,
            'summary' => [
                'totalCount' => count($items),
                'signedInCount' => $signedInCount,
                'signedOffCount' => $signedOffCount,
            ],
            'items' => $items,
        ]);
    }

    /**
     * Lightweight sign-in punctuality grid for HR/CEO Sign-In Status sidebar tab.
     */
    public function signinStatus(Request $request): JsonResponse
    {
        $dateStr = $request->query('date', '');
        if ($dateStr === '') {
            $dateStr = Carbon::now('Asia/Kolkata')->format('Y-m-d');
        } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($dateStr))) {
            return response()->json(['error' => 'date must be YYYY-MM-DD'], 422);
        } else {
            $dateStr = trim($dateStr);
        }

        $now = Carbon::now('Asia/Kolkata');
        $isHoliday = array_key_exists($dateStr, config('holidays', []));

        $users = User::where('is_active', true)
            ->where('id', '!=', 33)
            ->onSigninRoster()
            ->orderBy('name')
            ->get(['id', 'name', 'designation']);

        if ($users->isEmpty()) {
            return response()->json([
                'ok' => true,
                'date' => $dateStr,
                'updatedAt' => $now->toIso8601String(),
                'items' => [],
            ]);
        }

        $userIds = $users->pluck('id')->all();

        $signinByUser = DailySignin::where('signin_date', $dateStr)
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'signed_in_at'])
            ->keyBy('user_id');

        $leavesByUser = LeaveRequest::with('leaveType:id,slug,is_hourly')
            ->whereIn('user_id', $userIds)
            ->where('status', 'approved')
            ->where('start_date', '<=', $dateStr)
            ->where('end_date', '>=', $dateStr)
            ->get()
            ->groupBy('user_id');

        // ── Per-meeting status circles (attendance + notes) for the day ──
        // For each meeting the user owns/attends today: gray = notes not
        // updated yet; red = notes updated but the user wasn't present;
        // green = notes updated and the user was present.
        $meetingsByUser = $this->signinMeetingDots($dateStr, $userIds);

        $items = [];
        foreach ($users as $user) {
            $signin = $signinByUser->get($user->id);
            $signedIn = (bool) $signin;
            $leaves = $leavesByUser->get($user->id, collect());
            $blockingLeave = $leaves->first(fn ($l) => ! ($l->leaveType?->is_hourly) && $l->leaveType?->slug !== 'wfh');
            $isOnLeave = (bool) $blockingLeave;

            if ($isOnLeave) {
                $dayStatus = 'on_leave';
            } elseif ($signedIn) {
                $dayStatus = 'signed_in';
            } elseif ($isHoliday) {
                $dayStatus = 'holiday';
            } else {
                $dayStatus = 'missing';
            }

            $items[] = [
                'id' => $user->id,
                'name' => $user->name,
                'designation' => $user->designation ?? '',
                'indicator' => DailySignin::signinIndicator(
                    $signin?->signed_in_at,
                    $dayStatus,
                    $now,
                    $dateStr,
                ),
                'delayed' => DailySignin::signinDelayed($signin?->signed_in_at, $dateStr),
                'signedInAt' => $signin?->signed_in_at
                    ? Carbon::parse($signin->signed_in_at)->setTimezone('Asia/Kolkata')->format('h:i A')
                    : null,
                'meetings' => $meetingsByUser[$user->id] ?? [],
            ];
        }

        return response()->json([
            'ok' => true,
            'date' => $dateStr,
            'updatedAt' => $now->toIso8601String(),
            'items' => $items,
        ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    /**
     * Build per-user meeting status dots for the Sign-In Status grid on a given
     * day. Returns [userId => [['title','time','status'], ...]] where status is
     * 'gray' (notes not updated), 'red' (notes updated, user absent), or
     * 'green' (notes updated, user present).
     *
     * @param  array<int>  $userIds
     * @return array<int, array<int, array{title: string, time: ?string, status: string}>>
     */
    private function signinMeetingDots(string $dateStr, array $userIds): array
    {
        $selectedDate = Carbon::parse($dateStr, 'Asia/Kolkata');
        $dayName = $selectedDate->format('l');
        $weekKey = $selectedDate->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');

        $multiDay = [
            'daily_weekdays' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            'tue_to_fri'     => ['Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            'mon_thu'        => ['Monday', 'Thursday'],
            'mon_wed_fri'    => ['Monday', 'Wednesday', 'Friday'],
        ];
        $meetingOccursOn = function ($m, $dayName) use ($multiDay) {
            if (isset($multiDay[$m->recurrence])) {
                return in_array($dayName, $multiDay[$m->recurrence], true);
            }

            return $m->day_of_week === $dayName && $m->recurrence !== 'monthly_first';
        };

        $meetingsToday = Meeting::get(['id', 'meeting_key', 'title', 'time', 'owner_id', 'attendees', 'day_of_week', 'recurrence'])
            ->filter(fn ($m) => $meetingOccursOn($m, $dayName))
            ->sortBy('time')
            ->values();

        if ($meetingsToday->isEmpty()) {
            return [];
        }

        // Map each occurrence to its effective key + collect the participants
        // (owner + attendees) who are on the roster.
        $effectiveKeys = [];
        $perUser = []; // userId => list of ['effKey','title','time']
        foreach ($meetingsToday as $m) {
            $effKey = $this->effectiveMeetingKey($m->meeting_key, $m->recurrence, $dayName);
            $effectiveKeys[] = $effKey;
            $atts = is_array($m->attendees) ? $m->attendees : [];
            $participantIds = array_unique(array_merge([$m->owner_id], $atts));
            foreach ($participantIds as $uid) {
                $uid = (int) $uid;
                if (! in_array($uid, $userIds, true)) {
                    continue;
                }
                $perUser[$uid][] = [
                    'effKey' => $effKey,
                    'title' => (string) ($m->title ?? ''),
                    'time' => $m->time,
                ];
            }
        }
        $effectiveKeys = array_values(array_unique($effectiveKeys));

        // Notes updated? (non-empty MeetingNote for this occurrence's week)
        $notesFilled = [];
        $notesRows = MeetingNote::whereIn('meeting_id', $effectiveKeys)
            ->where('week_key', $weekKey)
            ->get(['meeting_id', 'content']);
        foreach ($notesRows as $n) {
            if (trim((string) ($n->content ?? '')) !== '') {
                $notesFilled[$n->meeting_id] = true;
            }
        }

        // Who was marked present for each occurrence on this date.
        $presentByKeyUser = [];
        $attRows = MeetingAttendance::whereIn('meeting_id', $effectiveKeys)
            ->where('occurrence_date', $dateStr)
            ->where('status', 'present')
            ->get(['meeting_id', 'user_id']);
        foreach ($attRows as $a) {
            $presentByKeyUser[$a->meeting_id . '|' . $a->user_id] = true;
        }

        $result = [];
        foreach ($perUser as $uid => $mtgs) {
            foreach ($mtgs as $mtg) {
                if (empty($notesFilled[$mtg['effKey']])) {
                    $status = 'gray';
                } elseif (! empty($presentByKeyUser[$mtg['effKey'] . '|' . $uid])) {
                    $status = 'green';
                } else {
                    $status = 'red';
                }
                $result[$uid][] = [
                    'title' => $mtg['title'],
                    'time' => $mtg['time'],
                    'status' => $status,
                ];
            }
        }

        return $result;
    }

    /**
     * Unified per-employee overview combining sign-in, daily reports, tasks, tickets, bugs, meetings.
     * Powers the redesigned admin Dashboard landing page.
     */
    public function employeeOverview(Request $request): JsonResponse
    {
        $dateStr = $request->query('date', '');
        if ($dateStr === '') {
            $dateStr = Carbon::now('Asia/Kolkata')->format('Y-m-d');
        } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($dateStr))) {
            return response()->json(['error' => 'date must be YYYY-MM-DD'], 422);
        } else {
            $dateStr = trim($dateStr);
        }

        $mode = $request->query('mode', 'day');
        if (! in_array($mode, ['day', 'week'], true)) {
            $mode = 'day';
        }

        $selectedDate = DateHelper::parse($dateStr);
        $dayName = $selectedDate->format('l');
        $isWeekday = ! $selectedDate->isWeekend();
        $now = Carbon::now('Asia/Kolkata');
        $todayStr = $now->format('Y-m-d');

        // Week window — Monday … min(Sunday, today) for current week; full Mon–Sun for past weeks.
        $weekStart = $selectedDate->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->addDays(4); // Friday — we're weekday-only
        $now7 = $now->copy()->startOfDay();
        if ($weekEnd->gt($now7)) {
            $weekEnd = $now7;
        }
        $weekdayDates = []; // Y-m-d strings for each weekday in the window
        if (! $weekEnd->lt($weekStart)) {
            $cursor = $weekStart->copy();
            while ($cursor->lte($weekEnd)) {
                if (! $cursor->isWeekend()) {
                    $weekdayDates[] = $cursor->format('Y-m-d');
                }
                $cursor->addDay();
            }
        }
        $periodStartStr = $weekStart->format('Y-m-d');
        $periodEndStr = $weekEnd->format('Y-m-d');

        $users = User::where('is_active', true)
            ->whereNotNull('reporting_manager_id')
            ->onSigninRoster()
            ->with(['roleRelation', 'projects'])
            ->orderBy('name')
            ->get();

        if ($users->isEmpty()) {
            return response()->json([
                'ok' => true,
                'date' => $dateStr,
                'dayName' => $dayName,
                'summary' => (object) [],
                'employees' => [],
            ]);
        }

        $userIds = $users->pluck('id')->all();
        $kraService = $mode === 'week' ? new KraScorecardService() : null;
        $kraExcludedIds = array_map('intval', (array) config('kra_exclusions.excluded_user_ids', []));
        // KRA always reflects the most recently completed full week — i.e. last
        // Mon–Sun. The current week's data is incomplete and would update
        // throughout the week, which makes scores feel volatile. Holding the
        // KRA stable until next Monday matches what the team saw on Monday's
        // sync and avoids re-displaying half-week partials.
        $lastWeekAnchor = Carbon::now('Asia/Kolkata')
            ->startOfWeek(Carbon::MONDAY)
            ->subWeek()
            ->format('Y-m-d');

        // Sign-ins / sign-offs for the selected day
        $signinByUser = DailySignin::where('signin_date', $dateStr)
            ->whereIn('user_id', $userIds)
            ->get()->keyBy('user_id');
        $signoffByUser = DailySignoff::where('signoff_date', $dateStr)
            ->whereIn('user_id', $userIds)
            ->get()->keyBy('user_id');

        $isHoliday = array_key_exists($dateStr, config('holidays', []));
        $leavesByUser = LeaveRequest::with('leaveType:id,slug,is_hourly')
            ->whereIn('user_id', $userIds)
            ->where('status', 'approved')
            ->where('start_date', '<=', $dateStr)
            ->where('end_date', '>=', $dateStr)
            ->get()
            ->groupBy('user_id');

        // Daily report KPI defs per user — total expected fields.
        // We pull *all* non-system definitions (required + optional) and
        // group them per user. The status logic below picks the required
        // count as the denominator when there is one, otherwise falls back
        // to the optional count — so users whose KPIs are *all* optional
        // still get tracked instead of collapsing to "n/a".
        $kpiDefsByUser = KpiDefinition::whereIn('user_id', $userIds)
            ->whereNull('deleted_at')
            ->whereNotIn('field_key', ['_group_init', '_person_init', '_placeholder'])
            ->get(['user_id', 'optional'])
            ->groupBy('user_id');
        // Daily report entries filled for the day
        $dailyEntries = DailyReport::whereIn('user_id', $userIds)
            ->where('report_date', $dateStr)
            ->get()
            ->filter(fn ($e) => trim((string) ($e->value ?? '')) !== '')
            ->groupBy('user_id');

        // WEEK-mode lookups — only run when requested, keeps day-mode fast.
        $weekSigninsByUser = collect();      // user_id => ['YYYY-MM-DD' => signed_in_at]
        $weekSignoffsByUser = collect();     // user_id => ['YYYY-MM-DD' => signed_off_at]
        $weekReportByDayByUser = collect();  // user_id => ['YYYY-MM-DD' => filled_count]
        $weekTicketsResolvedByUser = collect();
        $weekBugsResolvedByUser = collect();

        if ($mode === 'week' && ! empty($weekdayDates)) {
            $weekStartIso = $weekStart->copy()->startOfDay()->toDateTimeString();
            $weekEndIso = $weekEnd->copy()->endOfDay()->toDateTimeString();

            $weekSigninsByUser = DailySignin::whereIn('user_id', $userIds)
                ->whereIn('signin_date', $weekdayDates)
                ->get(['user_id', 'signin_date', 'signed_in_at'])
                ->groupBy('user_id')
                ->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [
                    Carbon::parse($r->signin_date)->format('Y-m-d') => $r->signed_in_at?->toIso8601String(),
                ]));

            $weekSignoffsByUser = DailySignoff::whereIn('user_id', $userIds)
                ->whereIn('signoff_date', $weekdayDates)
                ->get(['user_id', 'signoff_date', 'signed_off_at'])
                ->groupBy('user_id')
                ->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [
                    Carbon::parse($r->signoff_date)->format('Y-m-d') => $r->signed_off_at?->toIso8601String(),
                ]));

            $weekReportByDayByUser = DailyReport::whereIn('user_id', $userIds)
                ->whereIn('report_date', $weekdayDates)
                ->get(['user_id', 'report_date', 'value'])
                ->filter(fn ($r) => trim((string) ($r->value ?? '')) !== '')
                ->groupBy('user_id')
                ->map(function ($rows) {
                    $byDay = [];
                    foreach ($rows as $r) {
                        $d = Carbon::parse($r->report_date)->format('Y-m-d');
                        $byDay[$d] = ($byDay[$d] ?? 0) + 1;
                    }
                    return $byDay;
                });

            $weekTicketsResolvedByUser = Ticket::whereIn('assignee_id', $userIds)
                ->whereIn('status', ['resolved', 'closed'])
                ->whereNotNull('resolved_at')
                ->whereBetween('resolved_at', [$weekStartIso, $weekEndIso])
                ->selectRaw('assignee_id as uid, COUNT(*) as c')
                ->groupBy('assignee_id')
                ->pluck('c', 'uid');

            $weekBugsResolvedByUser = Bug::whereIn('assignee_id', $userIds)
                ->whereIn('status', ['fixed', 'verified', 'closed'])
                ->whereNotNull('resolved_at')
                ->whereBetween('resolved_at', [$weekStartIso, $weekEndIso])
                ->selectRaw('assignee_id as uid, COUNT(*) as c')
                ->groupBy('assignee_id')
                ->pluck('c', 'uid');
        }

        // Tasks — pull full rows so we can show titles + progress + latest check-in inline.
        // Include on_hold so admins can see stalled work too.
        $openTasks = TessaTask::whereIn('assigned_to', $userIds)
            ->whereIn('status', array_merge(TessaTask::ACTIVE_STATUSES, ['on_hold']))
            ->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END, deadline ASC')
            ->get(['id', 'assigned_to', 'title', 'priority', 'status', 'deadline',
                   'progress', 'status_note', 'last_checkin_at', 'blocker_status', 'blocker_note'])
            ->groupBy('assigned_to');

        // Tickets — pull open rows for titles; resolved as count only
        $openTicketsByUser = Ticket::whereIn('assignee_id', $userIds)
            ->whereIn('status', ['open', 'in_progress'])
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->orderByDesc('created_at')
            ->get(['id', 'assignee_id', 'title', 'priority', 'status'])
            ->groupBy('assignee_id');
        $ticketResolvedByUser = Ticket::whereIn('assignee_id', $userIds)
            ->whereIn('status', ['resolved', 'closed'])
            ->selectRaw('assignee_id as uid, COUNT(*) as c')
            ->groupBy('assignee_id')
            ->pluck('c', 'uid');

        // Bugs — pull open rows for titles; resolved as count only
        $openBugsByUser = Bug::whereIn('assignee_id', $userIds)
            ->whereIn('status', ['open', 'in_progress'])
            ->orderByRaw("FIELD(priority, 'critical', 'high', 'medium', 'low')")
            ->orderByDesc('created_at')
            ->get(['id', 'assignee_id', 'title', 'priority', 'status'])
            ->groupBy('assignee_id');
        $bugResolvedByUser = Bug::whereIn('assignee_id', $userIds)
            ->whereIn('status', ['fixed', 'verified', 'closed'])
            ->selectRaw('assignee_id as uid, COUNT(*) as c')
            ->groupBy('assignee_id')
            ->pluck('c', 'uid');

        // Meeting occurrences — per-user counts for selected day and, in week mode, for the whole window.
        $multiDay = [
            'daily_weekdays' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            'tue_to_fri' => ['Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            'mon_thu' => ['Monday', 'Thursday'],
            'mon_wed_fri' => ['Monday', 'Wednesday', 'Friday'],
        ];

        // Pull all meetings once so we can compute per-day occurrences in memory.
        $allMeetings = Meeting::get(['id', 'meeting_key', 'owner_id', 'attendees', 'day_of_week', 'recurrence']);

        // True if the given meeting occurs on $dayName (e.g. 'Tuesday').
        $meetingOccursOn = function ($m, $dayName) use ($multiDay) {
            if (isset($multiDay[$m->recurrence])) {
                return in_array($dayName, $multiDay[$m->recurrence], true);
            }
            // weekly & one-time ('none') — both key off day_of_week. monthly_first also
            // stores day_of_week but only occurs the first such weekday of the month, so
            // it is excluded from this per-day occurrence check.
            return $m->day_of_week === $dayName && $m->recurrence !== 'monthly_first';
        };

        // Per-user count for the selected day (used in day mode & as day-of-the-week value).
        $meetingsByUser = [];
        $todayDayName = $selectedDate->format('l');
        foreach ($allMeetings as $m) {
            if (! $meetingOccursOn($m, $todayDayName)) continue;
            $atts = is_array($m->attendees) ? $m->attendees : [];
            foreach (array_unique(array_merge([$m->owner_id], $atts)) as $uid) {
                $meetingsByUser[$uid] = ($meetingsByUser[$uid] ?? 0) + 1;
            }
        }

        // Per-user count across the whole week window (only computed in week mode).
        $meetingsByUserWeek = [];
        $meetingsAttendedByUserWeek = [];
        $meetingsTrackedByUserWeek = [];
        if ($mode === 'week') {
            foreach ($weekdayDates as $day) {
                $name = Carbon::parse($day)->format('l');
                foreach ($allMeetings as $m) {
                    if (! $meetingOccursOn($m, $name)) continue;
                    $atts = is_array($m->attendees) ? $m->attendees : [];
                    foreach (array_unique(array_merge([$m->owner_id], $atts)) as $uid) {
                        $meetingsByUserWeek[$uid] = ($meetingsByUserWeek[$uid] ?? 0) + 1;
                    }
                }
            }

            // Bulk-fetch this week's attendance rows. We count tracked vs present from
            // the same source so the ratio is self-consistent — meetings whose
            // attendance was never captured (no Slack huddle sync, non-Slack meeting,
            // etc.) are excluded from both numerator and denominator.
            if (! empty($weekdayDates)) {
                $attendanceRows = MeetingAttendance::whereIn('occurrence_date', $weekdayDates)
                    ->get(['user_id', 'status']);
                foreach ($attendanceRows as $row) {
                    $meetingsTrackedByUserWeek[$row->user_id] = ($meetingsTrackedByUserWeek[$row->user_id] ?? 0) + 1;
                    if ($row->status === 'present') {
                        $meetingsAttendedByUserWeek[$row->user_id] = ($meetingsAttendedByUserWeek[$row->user_id] ?? 0) + 1;
                    }
                }
            }
        }

        // ── Minutes-of-meeting / Agenda per owner ───────────────────────────
        // For every meeting a user owns that occurs in the period, check whether
        // agenda is filled (all DiscussionPoint.answer non-empty) and whether notes
        // are filled (MeetingNote.content non-empty). Keys use the per-day suffix
        // scheme: Monday = '', Tue = '-tue', Wed = '-wed', Thu = '-thu', Fri = '-fri'.
        $momDates = $mode === 'week' ? $weekdayDates : [$dateStr];
        $momByUser = []; // uid => ['notesFilled' => N, 'notesExpected' => N, 'agendaFilled' => N, 'agendaExpected' => N, 'meetings' => [details]]

        // Collect the full set of (meeting, date) keys we'll need to query.
        $needMeetingKeys = [];  // for DiscussionPoint + MeetingNote where-in
        $needWeekKeys = [];
        $occurrences = [];      // rows: [ownerId, meetingKey, keyed, date, weekKey, title, time]
        foreach ($momDates as $day) {
            $dateCarbon = Carbon::parse($day);
            if ($dateCarbon->isWeekend()) continue;
            $dayName = $dateCarbon->format('l');
            $weekKey = $dateCarbon->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
            foreach ($allMeetings as $m) {
                if (! $meetingOccursOn($m, $dayName)) continue;
                if (! in_array($m->owner_id, $userIds, true)) continue;
                $keyed = $this->effectiveMeetingKey($m->meeting_key, $m->recurrence, $dayName);
                $needMeetingKeys[] = $keyed;
                $needWeekKeys[] = $weekKey;
                $occurrences[] = [
                    'ownerId' => $m->owner_id,
                    'meetingKey' => $m->meeting_key,
                    'keyed' => $keyed,
                    'date' => $day,
                    'weekKey' => $weekKey,
                ];
            }
        }
        $needMeetingKeys = array_values(array_unique($needMeetingKeys));
        $needWeekKeys = array_values(array_unique($needWeekKeys));

        // Fetch notes & discussion points in bulk.
        $notesIndex = [];
        $pointsIndex = []; // keyed => ['total' => N, 'filled' => N]
        if (! empty($needMeetingKeys)) {
            $notesRows = MeetingNote::whereIn('meeting_id', $needMeetingKeys)
                ->whereIn('week_key', $needWeekKeys)
                ->get(['meeting_id', 'week_key', 'content']);
            foreach ($notesRows as $n) {
                $idxKey = $n->meeting_id . '|' . Carbon::parse($n->week_key)->format('Y-m-d');
                $notesIndex[$idxKey] = trim((string) ($n->content ?? '')) !== '';
            }

            $pointRows = DiscussionPoint::whereIn('meeting_id', $needMeetingKeys)
                ->whereIn('week_key', $needWeekKeys)
                ->get(['meeting_id', 'week_key', 'answer']);
            foreach ($pointRows as $p) {
                $idxKey = $p->meeting_id . '|' . Carbon::parse($p->week_key)->format('Y-m-d');
                if (! isset($pointsIndex[$idxKey])) $pointsIndex[$idxKey] = ['total' => 0, 'filled' => 0];
                $pointsIndex[$idxKey]['total']++;
                if (trim((string) ($p->answer ?? '')) !== '') {
                    $pointsIndex[$idxKey]['filled']++;
                }
            }
        }

        foreach ($occurrences as $occ) {
            $uid = $occ['ownerId'];
            if (! isset($momByUser[$uid])) {
                $momByUser[$uid] = ['notesFilled' => 0, 'notesExpected' => 0, 'agendaFilled' => 0, 'agendaExpected' => 0];
            }
            $idxKey = $occ['keyed'] . '|' . $occ['weekKey'];

            $momByUser[$uid]['notesExpected']++;
            if (! empty($notesIndex[$idxKey])) $momByUser[$uid]['notesFilled']++;

            $agenda = $pointsIndex[$idxKey] ?? null;
            if ($agenda && $agenda['total'] > 0) {
                $momByUser[$uid]['agendaExpected']++;
                if ($agenda['filled'] >= $agenda['total']) $momByUser[$uid]['agendaFilled']++;
            }
        }

        $employees = [];
        $kpi = [
            'totalUsers' => 0,
            'signedIn' => 0,
            'signedOff' => 0,
            'notSignedIn' => 0,
            'reportsSubmitted' => 0,
            'reportsPartial' => 0,
            'reportsMissing' => 0,
            'reportsNA' => 0,
            'tasksOpen' => 0,
            'tasksOverdue' => 0,
            'ticketsOpen' => 0,
            'bugsOpen' => 0,
            'needsAttention' => 0,
        ];

        foreach ($users as $user) {
            $signin = $signinByUser->get($user->id);
            $signoff = $signoffByUser->get($user->id);
            $signedIn = (bool) $signin;
            $signedOff = (bool) $signoff;

            $leaves = $leavesByUser->get($user->id, collect());
            $blockingLeave = $leaves->first(fn ($l) => ! ($l->leaveType?->is_hourly) && $l->leaveType?->slug !== 'wfh');
            $isOnLeave = (bool) $blockingLeave;
            if ($isOnLeave) {
                $dayStatus = 'on_leave';
            } elseif ($signedIn) {
                $dayStatus = 'signed_in';
            } elseif ($isHoliday) {
                $dayStatus = 'holiday';
            } else {
                $dayStatus = 'missing';
            }

            $defs = $kpiDefsByUser->get($user->id) ?? collect();
            $requiredCount = $defs->where('optional', false)->count();
            // Fall back to optional fields when the user has no required ones
            // (e.g. Ranjini, whose 11 KPIs are all flagged optional). Without
            // this fallback she'd always read as "n/a" even when submitting.
            $totalFields = $requiredCount > 0 ? $requiredCount : $defs->count();
            $filled = ($dailyEntries->get($user->id) ?? collect())->count();
            if ($totalFields === 0) {
                $reportStatus = 'na';
            } elseif ($filled >= $totalFields) {
                $reportStatus = 'submitted';
            } elseif ($filled > 0) {
                $reportStatus = 'partial';
            } else {
                $reportStatus = 'missing';
            }

            $tasks = $openTasks->get($user->id, collect());
            $tasksOpen = $tasks->count();
            $tasksOverdue = $tasks->filter(fn ($t) => $t->deadline && $t->deadline->lt($now))->count();

            $tickets = $openTicketsByUser->get($user->id, collect());
            $ticketsOpen = $tickets->count();
            $ticketsResolved = (int) ($ticketResolvedByUser[$user->id] ?? 0);

            $bugs = $openBugsByUser->get($user->id, collect());
            $bugsOpen = $bugs->count();
            $bugsResolved = (int) ($bugResolvedByUser[$user->id] ?? 0);
            $meetingsToday = (int) ($meetingsByUser[$user->id] ?? 0);

            // Titles (capped) so the UI can render pending work inline without a second request.
            $todayCarbon = $now->copy()->startOfDay();
            $pendingTaskItems = $tasks->take(5)->map(function ($t) use ($now, $todayCarbon) {
                $isOverdue = $t->deadline && $t->deadline->lt($now);
                $lastCheckin = $t->last_checkin_at;
                $hasUpdateToday = $lastCheckin && $lastCheckin->gte($todayCarbon);
                // daysSinceLastUpdate: null if never; else integer days from checkin's IST date to today's IST date.
                // Both sides must be in the same tz or diffInDays can round to 0 across the UTC/IST boundary.
                $daysSinceUpdate = null;
                if ($lastCheckin) {
                    $daysSinceUpdate = (int) $lastCheckin->copy()
                        ->setTimezone('Asia/Kolkata')
                        ->startOfDay()
                        ->diffInDays($todayCarbon);
                }
                return [
                    'id' => $t->id,
                    'title' => $t->title,
                    'priority' => $t->priority,
                    'status' => $t->status,
                    'deadline' => $t->deadline?->toIso8601String(),
                    'isOverdue' => $isOverdue,
                    'progress' => (int) ($t->progress ?? 0),
                    'health' => $t->blocker_status ?: 'no_update',
                    'statusNote' => $t->status_note,
                    'blockerNote' => $t->blocker_note,
                    'lastCheckinAt' => $lastCheckin?->toIso8601String(),
                    'hasUpdateToday' => $hasUpdateToday,
                    'daysSinceUpdate' => $daysSinceUpdate,
                ];
            })->values()->all();
            $pendingTicketItems = $tickets->take(3)->map(fn ($t) => [
                'id' => $t->id, 'title' => $t->title, 'priority' => $t->priority, 'status' => $t->status,
            ])->values()->all();
            $pendingBugItems = $bugs->take(3)->map(fn ($b) => [
                'id' => $b->id, 'title' => $b->title, 'priority' => $b->priority, 'status' => $b->status,
            ])->values()->all();

            // Health & attention reasons
            $reasons = [];
            if (! $signedIn && $isWeekday) {
                $reasons[] = 'Not signed in';
            }
            if ($tasksOverdue > 0) {
                $reasons[] = $tasksOverdue . ' overdue task' . ($tasksOverdue > 1 ? 's' : '');
            }
            if ($reportStatus === 'missing') {
                $reasons[] = 'Daily report missing';
            } elseif ($reportStatus === 'partial') {
                $reasons[] = 'Daily report partial';
            }

            if ($tasksOverdue > 0 || $reportStatus === 'missing') {
                $health = 'red';
            } elseif (count($reasons) > 0 || $tasksOpen > 5 || $bugsOpen > 10) {
                $health = 'yellow';
            } else {
                $health = 'green';
            }

            // Roll-up counters
            $kpi['totalUsers']++;
            if ($signedIn) $kpi['signedIn']++;
            if ($signedOff) $kpi['signedOff']++;
            if (! $signedIn && $isWeekday) $kpi['notSignedIn']++;
            if ($reportStatus === 'submitted') $kpi['reportsSubmitted']++;
            elseif ($reportStatus === 'partial') $kpi['reportsPartial']++;
            elseif ($reportStatus === 'missing') $kpi['reportsMissing']++;
            else $kpi['reportsNA']++;
            $kpi['tasksOpen'] += $tasksOpen;
            $kpi['tasksOverdue'] += $tasksOverdue;
            $kpi['ticketsOpen'] += $ticketsOpen;
            $kpi['bugsOpen'] += $bugsOpen;
            if ($health !== 'green') $kpi['needsAttention']++;

            $employees[] = [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->roleRelation?->name ?? '',
                'roleSlug' => $user->role,
                'projects' => $user->projects->pluck('name')->all(),
                'bank' => [
                    'account_holder_name' => $user->bank_account_holder_name,
                    'account_number' => $user->bank_account_number,
                    'ifsc_code' => $user->bank_ifsc_code,
                    'passbook_path' => $user->bank_passbook_path ? asset('storage/' . $user->bank_passbook_path) : null,
                    'has_passbook' => (bool) $user->bank_passbook_path,
                ],
                'signIn' => [
                    'signedIn' => $signedIn,
                    'signedInAt' => $signin?->signed_in_at?->toIso8601String(),
                    'signedOff' => $signedOff,
                    'signedOffAt' => $signoff?->signed_off_at?->toIso8601String(),
                    'indicator' => DailySignin::signinIndicator(
                        $signin?->signed_in_at,
                        $dayStatus,
                        $now,
                        $dateStr,
                    ),
                    'delayed' => DailySignin::signinDelayed($signin?->signed_in_at, $dateStr),
                ],
                'dailyReport' => [
                    'filled' => $filled,
                    'total' => $totalFields,
                    'status' => $reportStatus,
                ],
                'tasks' => [
                    'open' => $tasksOpen,
                    'overdue' => $tasksOverdue,
                    'items' => $pendingTaskItems,
                    'truncated' => $tasksOpen > count($pendingTaskItems),
                ],
                'tickets' => [
                    'open' => $ticketsOpen,
                    'resolved' => $ticketsResolved,
                    'items' => $pendingTicketItems,
                    'truncated' => $ticketsOpen > count($pendingTicketItems),
                ],
                'bugs' => [
                    'open' => $bugsOpen,
                    'resolved' => $bugsResolved,
                    'items' => $pendingBugItems,
                    'truncated' => $bugsOpen > count($pendingBugItems),
                ],
                'meetings' => [
                    'today' => $meetingsToday,
                ],
                'mom' => $momByUser[$user->id] ?? [
                    'notesFilled' => 0, 'notesExpected' => 0,
                    'agendaFilled' => 0, 'agendaExpected' => 0,
                ],
                'health' => $health,
                'attentionReasons' => $reasons,
            ];

            // Weekly period block — included only when mode=week
            if ($mode === 'week') {
                $userSignins = $weekSigninsByUser->get($user->id, collect());
                $userSignoffs = $weekSignoffsByUser->get($user->id, collect());
                $userReports = $weekReportByDayByUser->get($user->id, []);

                $daysPerUser = [];
                $signInDays = 0;
                $signOffDays = 0;
                $reportSubmittedDays = 0;
                $reportPartialDays = 0;
                $reportMissingDays = 0;
                $reportNaDays = 0;

                foreach ($weekdayDates as $day) {
                    $isInFuture = $day > $todayStr;
                    $si = $userSignins->get($day);
                    $so = $userSignoffs->get($day);
                    $filledOnDay = (int) ($userReports[$day] ?? 0);
                    if ($si) $signInDays++;
                    if ($so) $signOffDays++;

                    if ($isInFuture) {
                        $dayReportStatus = 'future';
                    } elseif ($totalFields === 0) {
                        $dayReportStatus = 'na';
                        $reportNaDays++;
                    } elseif ($filledOnDay >= $totalFields) {
                        $dayReportStatus = 'submitted';
                        $reportSubmittedDays++;
                    } elseif ($filledOnDay > 0) {
                        $dayReportStatus = 'partial';
                        $reportPartialDays++;
                    } else {
                        $dayReportStatus = 'missing';
                        $reportMissingDays++;
                    }

                    $daysPerUser[] = [
                        'date' => $day,
                        'weekday' => Carbon::parse($day)->format('D'), // Mon, Tue, …
                        'signedIn' => (bool) $si,
                        'signedInAt' => $si,
                        'signedOff' => (bool) $so,
                        'signedOffAt' => $so,
                        'reportStatus' => $dayReportStatus,
                        'reportFilled' => $filledOnDay,
                    ];
                }

                $totalDays = count($weekdayDates);
                $employees[count($employees) - 1]['period'] = [
                    'start' => $periodStartStr,
                    'end' => $periodEndStr,
                    'totalDays' => $totalDays,
                    'signInDays' => $signInDays,
                    'signOffDays' => $signOffDays,
                    'reportSubmittedDays' => $reportSubmittedDays,
                    'reportPartialDays' => $reportPartialDays,
                    'reportMissingDays' => $reportMissingDays,
                    'reportNaDays' => $reportNaDays,
                    'ticketsResolved' => (int) ($weekTicketsResolvedByUser[$user->id] ?? 0),
                    'bugsResolved' => (int) ($weekBugsResolvedByUser[$user->id] ?? 0),
                    'meetingsInWeek' => (int) ($meetingsByUserWeek[$user->id] ?? 0),
                    'meetingsTrackedInWeek' => (int) ($meetingsTrackedByUserWeek[$user->id] ?? 0),
                    'meetingsAttendedInWeek' => (int) ($meetingsAttendedByUserWeek[$user->id] ?? 0),
                    'days' => $daysPerUser,
                ];

                // Weekly KRA composite + per-bucket breakdown — always last
                // completed week, not the currently viewed week. See
                // $lastWeekAnchor above for rationale.
                if (in_array((int) $user->id, $kraExcludedIds, true)) {
                    $employees[count($employees) - 1]['kra'] = null;
                } else {
                    $kraWeek = $kraService->buildWeek($user, $lastWeekAnchor);
                    $employees[count($employees) - 1]['kra'] = [
                        'composite' => $kraWeek['composite'],
                        'kras'      => $kraWeek['kras'],
                        'weights'   => $kraWeek['weights'],
                        'weekStart' => $kraWeek['weekStart'],
                        'weekEnd'   => $kraWeek['weekEnd'],
                    ];
                }
            }
        }

        return response()->json([
            'ok' => true,
            'mode' => $mode,
            'date' => $dateStr,
            'dayName' => $dayName,
            'isWeekday' => $isWeekday,
            'period' => $mode === 'week' ? [
                'start' => $periodStartStr,
                'end' => $periodEndStr,
                'totalDays' => count($weekdayDates),
                'days' => $weekdayDates,
            ] : null,
            'summary' => $kpi,
            'employees' => $employees,
        ]);
    }

    public function tasksOverview(Request $request): JsonResponse
    {
        $users = User::where('is_active', true)
            ->whereNotNull('reporting_manager_id')
            ->with('roleRelation')
            ->orderBy('name')
            ->get();

        if ($users->isEmpty()) {
            return response()->json([
                'ok' => true,
                'summary' => [
                    'totalUsers' => 0,
                    'tasksOpen' => 0,
                    'tasksOverdue' => 0,
                    'ticketsOpen' => 0,
                    'bugsOpen' => 0,
                ],
                'items' => [],
            ]);
        }

        $userIds = $users->pluck('id')->all();
        $now = Carbon::now('Asia/Kolkata');

        // Pull all pending items once, then group in memory — cheaper than per-user queries.
        $openTasks = TessaTask::whereIn('assigned_to', $userIds)
            ->whereIn('status', TessaTask::ACTIVE_STATUSES)
            ->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END, deadline ASC')
            ->get(['id', 'assigned_to', 'title', 'priority', 'status', 'deadline', 'last_checkin_at', 'progress', 'status_note'])
            ->groupBy('assigned_to');

        $openTickets = Ticket::whereIn('assignee_id', $userIds)
            ->whereIn('status', ['open', 'in_progress'])
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->orderByDesc('created_at')
            ->get(['id', 'assignee_id', 'title', 'priority', 'status', 'category', 'created_at'])
            ->groupBy('assignee_id');

        $openBugs = Bug::whereIn('assignee_id', $userIds)
            ->whereIn('status', ['open', 'in_progress'])
            ->orderByRaw("FIELD(priority, 'critical', 'high', 'medium', 'low')")
            ->orderByDesc('created_at')
            ->get(['id', 'assignee_id', 'title', 'priority', 'severity', 'status', 'created_at'])
            ->groupBy('assignee_id');

        // Lifetime resolved counts — cheap aggregate.
        $ticketResolvedByUser = Ticket::whereIn('assignee_id', $userIds)
            ->whereIn('status', ['resolved', 'closed'])
            ->selectRaw('assignee_id as uid, COUNT(*) as c')
            ->groupBy('assignee_id')
            ->pluck('c', 'uid');

        $bugResolvedByUser = Bug::whereIn('assignee_id', $userIds)
            ->whereIn('status', ['fixed', 'verified', 'closed'])
            ->selectRaw('assignee_id as uid, COUNT(*) as c')
            ->groupBy('assignee_id')
            ->pluck('c', 'uid');

        $items = [];
        $totalTasksOpen = 0;
        $totalTasksOverdue = 0;
        $totalTicketsOpen = 0;
        $totalBugsOpen = 0;

        $maxDetailsPerType = 15; // cap per-user list to keep the payload sane

        foreach ($users as $user) {
            $userTasks = $openTasks->get($user->id, collect());
            $userTickets = $openTickets->get($user->id, collect());
            $userBugs = $openBugs->get($user->id, collect());

            $tasksOpen = $userTasks->count();
            $tasksOverdue = $userTasks->filter(fn ($t) => $t->deadline && $t->deadline->lt($now))->count();
            $ticketsOpen = $userTickets->count();
            $ticketsResolved = (int) ($ticketResolvedByUser[$user->id] ?? 0);
            $bugsOpen = $userBugs->count();
            $bugsResolved = (int) ($bugResolvedByUser[$user->id] ?? 0);

            $totalTasksOpen += $tasksOpen;
            $totalTasksOverdue += $tasksOverdue;
            $totalTicketsOpen += $ticketsOpen;
            $totalBugsOpen += $bugsOpen;

            if ($tasksOverdue > 0) {
                $status = 'overdue';
            } elseif ($tasksOpen > 0 || $ticketsOpen > 0 || $bugsOpen > 0) {
                $status = 'pending';
            } else {
                $status = 'clear';
            }

            $taskDetails = $userTasks->take($maxDetailsPerType)->map(function ($t) use ($now) {
                $isOverdue = $t->deadline && $t->deadline->lt($now);
                return [
                    'id' => $t->id,
                    'title' => $t->title,
                    'priority' => $t->priority,
                    'status' => $t->status,
                    'deadline' => $t->deadline?->toIso8601String(),
                    'isOverdue' => $isOverdue,
                    'daysLate' => $isOverdue ? $t->deadline->diffInDays($now) : null,
                    'progress' => $t->progress,
                    'statusNote' => $t->status_note,
                    'lastCheckinAt' => $t->last_checkin_at?->toIso8601String(),
                ];
            })->values()->all();

            $ticketDetails = $userTickets->take($maxDetailsPerType)->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'priority' => $t->priority,
                'status' => $t->status,
                'category' => $t->category,
                'createdAt' => $t->created_at?->toIso8601String(),
            ])->values()->all();

            $bugDetails = $userBugs->take($maxDetailsPerType)->map(fn ($b) => [
                'id' => $b->id,
                'title' => $b->title,
                'priority' => $b->priority,
                'severity' => $b->severity,
                'status' => $b->status,
                'createdAt' => $b->created_at?->toIso8601String(),
            ])->values()->all();

            $items[] = [
                'userId' => $user->id,
                'userName' => $user->name,
                'role' => $user->roleRelation?->name ?? '',
                'tasks' => [
                    'open' => $tasksOpen,
                    'overdue' => $tasksOverdue,
                    'items' => $taskDetails,
                    'truncated' => $tasksOpen > count($taskDetails),
                ],
                'tickets' => [
                    'open' => $ticketsOpen,
                    'resolved' => $ticketsResolved,
                    'items' => $ticketDetails,
                    'truncated' => $ticketsOpen > count($ticketDetails),
                ],
                'bugs' => [
                    'open' => $bugsOpen,
                    'resolved' => $bugsResolved,
                    'items' => $bugDetails,
                    'truncated' => $bugsOpen > count($bugDetails),
                ],
                'status' => $status,
            ];
        }

        return response()->json([
            'ok' => true,
            'summary' => [
                'totalUsers' => count($items),
                'tasksOpen' => $totalTasksOpen,
                'tasksOverdue' => $totalTasksOverdue,
                'ticketsOpen' => $totalTicketsOpen,
                'bugsOpen' => $totalBugsOpen,
            ],
            'items' => $items,
        ]);
    }

    public function dashboardStatus(Request $request): JsonResponse
    {
        $requestUser = $request->user();
        if (! $requestUser || ! ProjectRoleService::hasFeature($requestUser->role, 'dashboard')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $dateStr = $request->query('date', '');
        if ($dateStr === '') {
            $dateStr = Carbon::now('Asia/Kolkata')->format('Y-m-d');
        } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($dateStr))) {
            return response()->json(['error' => 'date must be YYYY-MM-DD'], 422);
        } else {
            $dateStr = trim($dateStr);
        }

        $selectedDate = DateHelper::parse($dateStr);
        $dayName = $selectedDate->format('l');
        $weekKey = $selectedDate->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        $now = Carbon::now('Asia/Kolkata');
        $isToday = $selectedDate->isSameDay($now);
        $allowedUserIds = ProjectRoleService::getAllowedUserIdsForUser($requestUser);
        if (! in_array($requestUser->id, $allowedUserIds, true)) {
            $allowedUserIds[] = $requestUser->id;
        }
        $allTeamUserIds = User::where('is_active', true)
            ->whereNotNull('reporting_manager_id')
            ->pluck('id')
            ->toArray();

        if (empty($allTeamUserIds)) {
            return response()->json([
                'ok' => true,
                'date' => $dateStr,
                'dayName' => $dayName,
                'summary' => [
                    'clearCount' => 0,
                    'totalCount' => 0,
                    'signedInCount' => 0,
                ],
                'users' => [],
            ]);
        }

        $users = User::whereIn('id', $allTeamUserIds)
            ->where('is_active', true)
            ->with(['roleRelation', 'projects', 'reportingManager'])
            ->orderBy('name')
            ->get();

        $dashboardSignins = DailySignin::where('signin_date', $dateStr)
            ->whereIn('user_id', $allTeamUserIds)
            ->get(['user_id', 'signed_in_at']);

        $signedInUserIds = $dashboardSignins->pluck('user_id')->unique()->values()->all();

        $signedInAtMap = $dashboardSignins->mapWithKeys(fn ($ds) => [
            $ds->user_id => $ds->signed_in_at->toDateTimeString(),
        ]);

        $items = [];
        $clearCount = 0;
        $signedInCount = 0;

        foreach ($users as $user) {
            $meetings = $this->getMeetingsForDashboardUser($user, $dayName, $dateStr);
            $meetingItems = [];
            $hasPendingStatus = false;

            foreach ($meetings as $meeting) {
                $effectiveKey = $this->effectiveMeetingKey($meeting->meeting_key, $meeting->recurrence, $dayName);
                $timePassed = $this->meetingTimeHasPassed($meeting->time, $selectedDate, $isToday, $now);
                $note = MeetingNote::where('meeting_id', $effectiveKey)
                    ->where('week_key', $weekKey)
                    ->first();
                $hasNotes = $note && trim((string) ($note->content ?? '')) !== '';

                $notesTone = ! $timePassed && ! $hasNotes ? 'grey' : ($hasNotes ? 'green' : 'red');
                $upcoming = ! $timePassed;

                if ($notesTone === 'red') {
                    $hasPendingStatus = true;
                }

                $meetingItems[] = [
                    'meetingKey' => $meeting->meeting_key,
                    'title' => $meeting->title,
                    'time' => $meeting->time,
                    'recurrence' => $meeting->recurrence ?? 'none',
                    'upcoming' => $upcoming,
                    'notesTone' => $notesTone,
                    'actionsTone' => 'grey',
                    'actionsPending' => 0,
                ];
            }

            $dailyFields = $this->getDefinedFieldsForUser($user->id);
            $totalFields = count($dailyFields);
            $filledCount = DailyReport::where('user_id', $user->id)
                ->where('report_date', $dateStr)
                ->get()
                ->filter(fn ($entry) => trim((string) ($entry->value ?? '')) !== '')
                ->count();

            if ($selectedDate->isWeekend() || $totalFields === 0) {
                $dailyTone = 'grey';
                $dailyStatus = 'n/a';
            } elseif ($filledCount >= $totalFields) {
                $dailyTone = 'green';
                $dailyStatus = 'complete';
            } else {
                $dailyTone = 'red';
                $dailyStatus = 'pending';
                $hasPendingStatus = true;
            }

            $allClear = ! $hasPendingStatus;
            if ($allClear) {
                $clearCount++;
            }

            $signedIn = in_array($user->id, $signedInUserIds);
            if ($signedIn) {
                $signedInCount++;
            }
            $firstAt = $signedInAtMap->get($user->id);
            $tessaSignIn = [
                'signedIn' => $signedIn,
                'signedInAt' => $firstAt ? Carbon::parse($firstAt)->toIso8601String() : null,
            ];

            $signoff = DailySignoff::where('user_id', $user->id)
                ->where('signoff_date', $dateStr)
                ->first();
            $signoffData = [
                'signedOff' => (bool) $signoff,
                'signedOffAt' => $signoff?->signed_off_at?->toIso8601String(),
            ];

            $items[] = [
                'userId' => $user->id,
                'userName' => $user->name,
                'role' => $user->roleRelation?->name ?? '',
                'project' => $user->projects->pluck('name')->join(', '),
                'reportingManager' => $user->reportingManager?->name ?? '',
                'reportingManagerId' => $user->reporting_manager_id,
                'isOwnTeam' => in_array($user->id, $allowedUserIds, true),
                'allClear' => $allClear,
                'tessaSignIn' => $tessaSignIn,
                'meetings' => $meetingItems,
                'dailyReport' => [
                    'tone' => $dailyTone,
                    'status' => $dailyStatus,
                    'filled' => $filledCount,
                    'total' => $totalFields,
                ],
                'signoff' => $signoffData,
            ];
        }

        return response()->json([
            'ok' => true,
            'date' => $dateStr,
            'dayName' => $dayName,
            'summary' => [
                'clearCount' => $clearCount,
                'totalCount' => count($items),
                'signedInCount' => $signedInCount,
            ],
            'users' => $items,
        ]);
    }

    private function getFieldsForUser(int $userId): array
    {
        $definitions = KpiDefinition::where('user_id', $userId)
            ->whereNotIn('field_key', ['_group_init', '_person_init', '_placeholder'])
            ->where('optional', false)
            ->orderBy('group_name')
            ->orderBy('sort_order')
            ->get();

        $fields = [];
        foreach ($definitions as $d) {
            $fields[] = [
                'key' => $d->field_key,
                'label' => $d->field_label,
                'group' => $d->group_name ?: 'Metrics',
            ];
        }

        if (empty($fields)) {
            foreach (self::DAILY_FIELD_KEYS as $key) {
                $fields[] = [
                    'key' => $key,
                    'label' => ucwords(str_replace('_', ' ', $key)),
                    'group' => 'Metrics',
                ];
            }
        }

        return ['fields' => $fields];
    }

    private function getDefinedFieldsForUser(int $userId): array
    {
        return KpiDefinition::where('user_id', $userId)
            ->whereNotIn('field_key', ['_group_init', '_person_init', '_placeholder'])
            ->where('optional', false)
            ->orderBy('group_name')
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($d) => [
                'key' => $d->field_key,
                'label' => $d->field_label,
                'group' => $d->group_name ?: 'Metrics',
            ])
            ->values()
            ->all();
    }

    private function getMeetingsForDashboardUser(User $user, string $dayName, ?string $dateStr = null)
    {
        $skippedKeys = $dateStr
            ? \DB::table('meeting_skips')->where('skip_date', $dateStr)->pluck('meeting_key')->toArray()
            : [];

        return Meeting::where(function ($q) use ($user) {
            $userId = $user->id;
            if ($user->role === Role::SLUG_PRODUCT_MANAGER) {
                $q->where('owner_id', $userId)
                    ->orWhereJsonContains('attendees', $userId);

                return;
            }

            $q->where('portal', $user->role)
                ->orWhere('owner_id', $userId)
                ->orWhereJsonContains('attendees', $userId);
        })
            ->where(function ($q) use ($dayName) {
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
            })
            ->when(! empty($skippedKeys), fn ($q) => $q->whereNotIn('meeting_key', $skippedKeys))
            ->orderBy('time')
            ->orderBy('id')
            ->get();
    }
}
