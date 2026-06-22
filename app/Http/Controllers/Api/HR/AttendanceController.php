<?php

namespace App\Http\Controllers\Api\HR;

use App\Http\Controllers\Controller;
use App\Models\DailyReport;
use App\Models\DailySignin;
use App\Models\DailySignoff;
use App\Models\KpiDefinition;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only attendance roster for the HR + Accountant allow-list
 * (Shoyab, Meghana, Akshara — see config/attendance_view_access.php).
 *
 * Combines daily_signins / daily_signoffs / leave_requests / daily_reports
 * into a single per-employee status for any chosen date so HR has one
 * screen to answer "who's actually working today, and did they report?".
 */
class AttendanceController extends Controller
{
    // Slugs grouped for the monthly summary's four columns. WFH and
    // Permission deliberately sit OUTSIDE the "leave" bucket — they count
    // as working days, not leave (project memory: wfh_permission_not_leave).
    private const LEAVE_SLUGS_REAL = ['sick', 'casual', 'emergency', 'menstrual'];
    private const SLUG_WFH = 'wfh';
    private const SLUG_PERMISSION = 'permission';
    // Compensate isn't a leave at all — the original weekday is swapped out
    // of the user's working-day set and the compensation_date (Sat/Sun) is
    // swapped in, so the totals still balance. Surfaced as its own info
    // column so HR can see how many days each person compensated.
    private const SLUG_COMPENSATE = 'compensate';

    public function daily(Request $request)
    {
        $dateStr = $this->parseDate($request->query('date'));
        if ($dateStr === null) {
            return response()->json(['error' => 'date must be YYYY-MM-DD'], 422);
        }

        $payload = $this->buildDaily($dateStr);

        if (strtolower((string) $request->query('format')) === 'xlsx') {
            return $this->streamDailyXlsx($payload);
        }

        return response()->json($payload);
    }

    public function monthly(Request $request)
    {
        $month = $request->query('month');
        if (! is_string($month) || ! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = Carbon::now('Asia/Kolkata')->format('Y-m');
        }

        $payload = $this->buildMonthly($month);

        if (strtolower((string) $request->query('format')) === 'xlsx') {
            return $this->streamMonthlyXlsx($payload);
        }

        return response()->json($payload);
    }

    /* ── data builders ─────────────────────────────────────────────── */

    private function buildDaily(string $dateStr): array
    {
        $users = User::with(['roleRelation', 'department'])
            ->where('is_active', true)
            ->where('id', '!=', 33) // exclude generic Admin account, same as HR controllers
            ->onSigninRoster()
            ->orderBy('name')
            ->get();

        if ($users->isEmpty()) {
            return $this->emptyDailyPayload($dateStr);
        }

        // Company holiday → nobody is "missing"; the day just isn't a work
        // day. Anyone who signed in or is on approved leave is still bucketed
        // normally; everyone else gets the new 'holiday' status (and drops
        // out of absentCount). Matches the monthly view's holiday handling.
        $isHoliday = array_key_exists($dateStr, config('holidays', []));
        $holidayLabel = $isHoliday ? config('holidays')[$dateStr] : null;
        $now = Carbon::now('Asia/Kolkata');

        $userIds = $users->pluck('id')->all();

        $signinByUser = DailySignin::where('signin_date', $dateStr)
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'signed_in_at'])
            ->keyBy('user_id');

        $signoffByUser = DailySignoff::where('signoff_date', $dateStr)
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'signed_off_at'])
            ->keyBy('user_id');

        $leavesByUser = LeaveRequest::with('leaveType:id,name,slug,is_hourly')
            ->whereIn('user_id', $userIds)
            ->where('status', 'approved')
            ->where('start_date', '<=', $dateStr)
            ->where('end_date', '>=', $dateStr)
            ->get()
            ->groupBy('user_id');

        // Anyone with active KPI definitions is "expected" to file a daily
        // report. The same set powers the manager Daily Reports tab in the
        // portal, so we mirror that filter to stay consistent.
        $expectedReportUserIds = KpiDefinition::whereNull('deleted_at')
            ->whereNotIn('field_key', ['_group_init', '_person_init', '_placeholder'])
            ->whereIn('user_id', $userIds)
            ->distinct()
            ->pluck('user_id')
            ->flip()
            ->toArray();

        $reportSubmittedUserIds = DailyReport::where('report_date', $dateStr)
            ->whereIn('user_id', $userIds)
            ->distinct()
            ->pluck('user_id')
            ->flip()
            ->toArray();

        $items = [];
        $counts = [
            'signed_in' => 0,
            'on_leave' => 0,
            'wfh' => 0,
            'missing' => 0,
            'holiday' => 0,
            'signed_off' => 0,
            'report_submitted' => 0,
            'report_missing' => 0,
        ];

        foreach ($users as $user) {
            $signin = $signinByUser->get($user->id);
            $signoff = $signoffByUser->get($user->id);
            $leaves = $leavesByUser->get($user->id, collect());

            [$leaveBlock, $isOnLeave, $isWfh] = $this->resolveLeaveBlock($leaves);

            if ($isOnLeave) {
                $status = 'on_leave';
                $counts['on_leave']++;
            } elseif ($isWfh) {
                // Approved WFH is a worked day, not leave (project rule:
                // wfh_permission_not_leave). Label it "WFH" explicitly — even
                // when the person also signed in — so HR sees remote days at a
                // glance. Their sign-in/off times still show in their columns.
                $status = 'wfh';
                $counts['wfh']++;
                if ($signoff) {
                    $counts['signed_off']++;
                }
            } elseif ($signin) {
                $status = 'signed_in';
                $counts['signed_in']++;
                if ($signoff) {
                    $counts['signed_off']++;
                }
            } elseif ($isHoliday) {
                $status = 'holiday';
                $counts['holiday']++;
            } else {
                $status = 'missing';
                $counts['missing']++;
            }

            $reportExpected = isset($expectedReportUserIds[$user->id]);
            $reportSubmitted = isset($reportSubmittedUserIds[$user->id]);
            // Don't penalise people who are on leave (or on a company holiday)
            // for not filing a report. Anyone who reports anyway still gets
            // credit so the count never under-states submissions.
            $isNonWorkingDay = $isOnLeave || $isHoliday;
            if ($reportExpected && ! $isNonWorkingDay) {
                if ($reportSubmitted) {
                    $counts['report_submitted']++;
                } else {
                    $counts['report_missing']++;
                }
            } elseif ($reportExpected && $isNonWorkingDay && $reportSubmitted) {
                $counts['report_submitted']++;
            }

            $items[] = [
                'userId' => $user->id,
                'userName' => $user->name,
                'role' => $user->roleRelation?->name ?? '',
                'department' => $user->department?->name ?? '',
                'status' => $status,
                'signedInAt' => $signin?->signed_in_at?->setTimezone('Asia/Kolkata')->format('H:i'),
                'signedOffAt' => $signoff?->signed_off_at?->setTimezone('Asia/Kolkata')->format('H:i'),
                'signin_indicator' => DailySignin::signinIndicator(
                    $signin?->signed_in_at,
                    $status,
                    $now,
                    $dateStr,
                ),
                'signin_delayed' => DailySignin::signinDelayed($signin?->signed_in_at, $dateStr),
                'leave' => $leaveBlock,
                'dailyReportExpected' => $reportExpected,
                'dailyReportSubmitted' => $reportSubmitted,
            ];
        }

        return [
            'ok' => true,
            'date' => $dateStr,
            'isHoliday' => $isHoliday,
            'holidayLabel' => $holidayLabel,
            'summary' => [
                'totalCount' => count($items),
                'signedInCount' => $counts['signed_in'],
                'onLeaveCount' => $counts['on_leave'],
                'wfhCount' => $counts['wfh'],
                'missingCount' => $counts['missing'],
                'holidayCount' => $counts['holiday'],
                // Absent = on-leave + not-signed-in (excludes holiday — a
                // public holiday isn't an absence — and WFH, which is a worked
                // day). Same definition the monthly summary uses, so both
                // views agree on what "absent" means.
                'absentCount' => $counts['on_leave'] + $counts['missing'],
                'signedOffCount' => $counts['signed_off'],
                'reportSubmittedCount' => $counts['report_submitted'],
                'reportMissingCount' => $counts['report_missing'],
            ],
            'items' => $items,
        ];
    }

    private function buildMonthly(string $month): array
    {
        $monthStart = Carbon::createFromFormat('Y-m', $month, 'Asia/Kolkata')->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $today = Carbon::now('Asia/Kolkata')->startOfDay();
        // Don't count future weekdays in the current month — nobody can have
        // signed in tomorrow yet, so they'd all look "absent".
        $effectiveEnd = $monthEnd->copy()->isAfter($today) ? $today->copy() : $monthEnd->copy();

        // Company holidays come out of the working-day set so people who
        // didn't sign in on (e.g.) May Day aren't counted as no-shows. Same
        // source the KRA scorecard uses — keeps both surfaces aligned.
        $holidays = config('holidays', []);

        $weekdayDates = [];
        if ($effectiveEnd->isAfter($monthStart) || $effectiveEnd->isSameDay($monthStart)) {
            foreach (CarbonPeriod::create($monthStart, $effectiveEnd) as $d) {
                if ($d->isWeekend()) {
                    continue;
                }
                if (array_key_exists($d->format('Y-m-d'), $holidays)) {
                    continue;
                }
                $weekdayDates[] = $d->format('Y-m-d');
            }
        }

        $users = User::with('roleRelation')
            ->where('is_active', true)
            ->where('id', '!=', 33)
            ->onSigninRoster()
            ->orderBy('name')
            ->get();
        $userIds = $users->pluck('id')->all();

        $signinsByUser = empty($weekdayDates) || empty($userIds)
            ? collect()
            : DailySignin::whereIn('user_id', $userIds)
                ->whereIn('signin_date', $weekdayDates)
                ->get(['user_id', 'signin_date'])
                ->groupBy('user_id');

        // First-ever sign-in per user — used to "credit" mid-month joiners
        // for the gap between their DOJ and the day their Tessa account
        // actually went live. Without this, a new hire who joined on the
        // 15th but only got an account on the 22nd would look like 5
        // no-shows. We treat the [DOJ, firstSignin - 1] window as present.
        $firstSigninByUser = empty($userIds)
            ? []
            : DailySignin::whereIn('user_id', $userIds)
                ->selectRaw('user_id, MIN(signin_date) as first_date')
                ->groupBy('user_id')
                ->get()
                ->pluck('first_date', 'user_id')
                ->map(fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : (string) $d)
                ->all();

        // Compensate spans two dates (start_date = weekday off, compensation_date
        // = weekend worked), so widen the OR so swaps with the weekend day in
        // this month but the original weekday in the prior/next month still
        // show up.
        $monthStartStr = $monthStart->format('Y-m-d');
        $monthEndStr = $monthEnd->format('Y-m-d');
        $leaves = empty($userIds)
            ? collect()
            : LeaveRequest::with('leaveType:id,slug,is_hourly')
                ->whereIn('user_id', $userIds)
                ->where('status', 'approved')
                ->where(function ($q) use ($monthStartStr, $monthEndStr) {
                    $q->where(function ($qq) use ($monthStartStr, $monthEndStr) {
                        $qq->where('start_date', '<=', $monthEndStr)
                            ->where('end_date', '>=', $monthStartStr);
                    })->orWhereBetween('compensation_date', [$monthStartStr, $monthEndStr]);
                })
                ->get();

        // Expand each approved leave into a per-user set of dates inside the
        // window, bucketed by slug. WFH days come out of the WFH bucket and
        // permission hours come out of the permission bucket — keeps the
        // monthly columns aligned with what HR/Accounting actually want.
        $leaveDays = []; // [user_id]['leave'|'wfh'][YYYY-MM-DD] = true
        $permissionHours = []; // [user_id] = float
        // Compensate swaps recorded per user:
        //   originals[YYYY-MM-DD] = true   ← weekday to subtract from working set
        //   weekends[YYYY-MM-DD]  = true   ← weekend to add to working set
        //   count                          ← count of days compensated (info col)
        // count is anchored on the ORIGINAL weekday's month so a swap doesn't
        // show twice if it spans two months.
        $compensate = []; // [user_id] => ['originals'=>[], 'weekends'=>[], 'count'=>int]
        foreach ($leaves as $lr) {
            $slug = $lr->leaveType?->slug;
            if (! $slug) {
                continue;
            }
            if ($slug === self::SLUG_COMPENSATE) {
                $uid = $lr->user_id;
                if (! isset($compensate[$uid])) {
                    $compensate[$uid] = ['originals' => [], 'weekends' => [], 'count' => 0];
                }
                $origStr = $lr->start_date->format('Y-m-d');
                $compStr = $lr->compensation_date?->format('Y-m-d');
                $origInMonth = $origStr >= $monthStartStr && $origStr <= $effectiveEnd->format('Y-m-d');
                $compInMonth = $compStr !== null && $compStr >= $monthStartStr && $compStr <= $effectiveEnd->format('Y-m-d');
                if ($origInMonth) {
                    $compensate[$uid]['originals'][$origStr] = true;
                    $compensate[$uid]['count']++;
                }
                if ($compInMonth) {
                    $compensate[$uid]['weekends'][$compStr] = true;
                }
                continue;
            }
            if ($slug === self::SLUG_PERMISSION) {
                $hrs = (float) ($lr->hours ?? 0);
                if ($hrs > 0) {
                    $permissionHours[$lr->user_id] = ($permissionHours[$lr->user_id] ?? 0.0) + $hrs;
                }
                continue;
            }

            $bucket = $slug === self::SLUG_WFH
                ? 'wfh'
                : (in_array($slug, self::LEAVE_SLUGS_REAL, true) ? 'leave' : null);
            if ($bucket === null) {
                continue;
            }

            $startStr = max($lr->start_date->format('Y-m-d'), $monthStartStr);
            $endStr = min($lr->end_date->format('Y-m-d'), $effectiveEnd->format('Y-m-d'));
            if ($endStr < $startStr) {
                continue;
            }
            $period = CarbonPeriod::create(
                Carbon::parse($startStr, 'Asia/Kolkata'),
                Carbon::parse($endStr, 'Asia/Kolkata'),
            );
            foreach ($period as $d) {
                if ($d->isWeekend()) {
                    continue;
                }
                if (array_key_exists($d->format('Y-m-d'), $holidays)) {
                    continue;
                }
                $leaveDays[$lr->user_id][$bucket][$d->format('Y-m-d')] = true;
            }
        }

        $totalWorkingDays = count($weekdayDates);
        $rows = [];
        foreach ($users as $u) {
            // Mid-month joiners only count from their joining date forward —
            // otherwise days before they were even hired show up as "no
            // show". A null joining_date means we have no joining record, so
            // fall back to the full month (legacy long-tenured employees).
            $userWeekdays = $weekdayDates;
            $joiningStr = $u->joining_date?->format('Y-m-d');
            if ($joiningStr) {
                $userWeekdays = array_values(array_filter(
                    $weekdayDates,
                    fn ($d) => $d >= $joiningStr,
                ));
            }

            // Compensate swap: original weekday drops out of the working set,
            // weekend compensation day joins it. Net working-days unchanged
            // when both fall in the same month; if only one falls inside,
            // the totals shift by 1 — which is the correct picture for that
            // calendar month.
            $comp = $compensate[$u->id] ?? ['originals' => [], 'weekends' => [], 'count' => 0];
            if (! empty($comp['originals'])) {
                $userWeekdays = array_values(array_filter(
                    $userWeekdays,
                    fn ($d) => ! isset($comp['originals'][$d]),
                ));
            }
            if (! empty($comp['weekends'])) {
                foreach (array_keys($comp['weekends']) as $weekendStr) {
                    // Guard: don't double-add (someone manually re-running
                    // with the same date) and don't add holidays/joined-after.
                    if (in_array($weekendStr, $userWeekdays, true)) {
                        continue;
                    }
                    if (array_key_exists($weekendStr, $holidays)) {
                        continue;
                    }
                    if ($joiningStr && $weekendStr < $joiningStr) {
                        continue;
                    }
                    $userWeekdays[] = $weekendStr;
                }
                sort($userWeekdays);
            }
            $userTotalDays = count($userWeekdays);

            $signinDates = ($signinsByUser->get($u->id) ?? collect())
                ->pluck('signin_date')
                ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
                ->unique()
                ->values()
                ->all();
            // Compensate weekend sign-ins live in daily_signins too (the
            // monthly query above only pulled weekday rows, so re-fetch the
            // user's weekend sign-ins for any compensation date this month).
            if (! empty($comp['weekends'])) {
                $weekendStrs = array_keys($comp['weekends']);
                $weekendSignins = DailySignin::where('user_id', $u->id)
                    ->whereIn('signin_date', $weekendStrs)
                    ->pluck('signin_date')
                    ->map(fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : Carbon::parse($d)->format('Y-m-d'))
                    ->all();
                $signinDates = array_unique(array_merge($signinDates, $weekendSignins));
            }
            $signedInSet = array_flip($signinDates);
            $leaveSet = $leaveDays[$u->id]['leave'] ?? [];
            $wfhSet = $leaveDays[$u->id]['wfh'] ?? [];

            // Grace period: from DOJ up to (but not including) the user's
            // first ever sign-in. Covers the "joined Monday, got an account
            // Wednesday" gap so the missing days don't read as no-shows. If
            // they never signed in or DOJ ≥ first sign-in, no grace.
            $firstSigninStr = $firstSigninByUser[$u->id] ?? null;
            $graceEndStr = null;
            if ($joiningStr && $firstSigninStr && $joiningStr < $firstSigninStr) {
                $graceEndStr = $firstSigninStr; // exclusive — day-of first sign-in already counts as a sign-in
            }

            $signedInCount = 0;
            $leaveCount = 0;
            $wfhCount = 0;
            foreach ($userWeekdays as $d) {
                // Real leave beats everything (the person was off). Otherwise
                // an approved WFH or a Tessa sign-in both count as a worked
                // day — `wfhCount` is a sub-detail of the worked-day total so
                // HR still sees WFH usage explicitly in its own column.
                // For new joiners, days inside the DOJ→first-sign-in grace
                // window also count as logged in.
                if (isset($leaveSet[$d])) {
                    $leaveCount++;
                    continue;
                }
                $isWfh = isset($wfhSet[$d]);
                $isSignedIn = isset($signedInSet[$d])
                    || ($graceEndStr !== null && $d < $graceEndStr);
                if ($isWfh) {
                    $wfhCount++;
                }
                if ($isWfh || $isSignedIn) {
                    $signedInCount++;
                }
                // else: implicit no-show, not surfaced as a column.
            }

            // Working day buckets sum to the working-day total:
            //   working = logged-in (incl. WFH) + leave + missed-login
            // WFH is a sub-detail of logged-in (project rule:
            // wfh_permission_not_leave). Permission is hourly so it overlaps
            // with a logged-in day and doesn't consume a separate slot.
            // Compensate doesn't get its own bucket — the original weekday
            // is already swapped out of working-days, and the weekend
            // compensation day is swapped in (and counts toward logged-in if
            // they signed in). The "Compensated" column is info-only.
            $missedLogin = max(0, $userTotalDays - $signedInCount - $leaveCount);

            // HR-managed per-user leave adjustments (config/leave_adjustments.php).
            // Moves days from "missed login" into "leave" — doesn't inflate working-day total.
            $leaveAdjustments = config('leave_adjustments', []);
            $leaveAdj = (int) (($leaveAdjustments[$u->id] ?? [])[$month] ?? 0);
            if ($leaveAdj !== 0) {
                $leaveCount += $leaveAdj;
                $missedLogin = max(0, $missedLogin - $leaveAdj);
            }

            $rows[] = [
                'userId' => $u->id,
                'userName' => $u->name,
                'role' => $u->roleRelation?->name ?? '',
                'joiningDate' => $joiningStr,
                'workingDays' => $userTotalDays,
                'signedInDays' => $signedInCount,
                'missedLoginDays' => $missedLogin,
                'wfhDays' => $wfhCount,
                'leaveDays' => $leaveCount,
                'permissionHours' => round((float) ($permissionHours[$u->id] ?? 0), 1),
                'compensatedDays' => (int) $comp['count'],
            ];
        }

        // Surface attention items at the top: highest missed-login count
        // first, with name as tiebreaker. This is now the most actionable
        // column for HR (and it's visible, so the sort order matches what
        // the user sees).
        usort($rows, fn ($a, $b) => ($b['missedLoginDays'] <=> $a['missedLoginDays'])
            ?: strcmp($a['userName'], $b['userName']));

        return [
            'ok' => true,
            'month' => $month,
            'monthLabel' => $monthStart->format('F Y'),
            'workingDays' => $totalWorkingDays,
            'items' => $rows,
        ];
    }

    /**
     * @return array{0: ?array, 1: bool, 2: bool} [leave block, on full-day leave, working-from-home (no real leave)]
     */
    private function resolveLeaveBlock($leaves): array
    {
        if ($leaves->isEmpty()) {
            return [null, false, false];
        }

        // What counts as "on leave" for the daily roster:
        //  - Hourly leaves (Permission) DON'T block the working day.
        //  - WFH is NOT leave either — it's a working day (project rule:
        //    wfh_permission_not_leave), so it must not flip the status to
        //    "On leave". It still surfaces in the notes as "Work From Home".
        //  - A real full-day leave (sick/casual/emergency/menstrual, or a
        //    compensate swap) does block.
        $blocking = $leaves->first(fn ($l) => ! ($l->leaveType?->is_hourly) && $l->leaveType?->slug !== self::SLUG_WFH);
        $wfh = $leaves->first(fn ($l) => $l->leaveType?->slug === self::SLUG_WFH);

        $isOnLeave = (bool) $blocking;
        $isWfh = (bool) $wfh && ! $blocking; // a real leave on the same day wins

        // Surface the most relevant approved request in the notes column: a
        // real leave wins, otherwise the WFH row so HR still sees "Work From Home".
        $primary = $blocking ?: $wfh;
        $leaveBlock = null;
        if ($primary) {
            $leaveBlock = [
                'type' => $primary->leaveType?->name,
                'slug' => $primary->leaveType?->slug,
                'start_date' => $primary->start_date->format('Y-m-d'),
                'end_date' => $primary->end_date->format('Y-m-d'),
                'reason' => $primary->reason,
            ];
        }
        $hourly = $leaves->filter(fn ($l) => $l->leaveType?->is_hourly)->values();
        if ($hourly->isNotEmpty()) {
            $leaveBlock = ($leaveBlock ?? []) + [
                'hourly' => $hourly->map(fn ($l) => [
                    'type' => $l->leaveType?->name,
                    'slug' => $l->leaveType?->slug,
                    'from_time' => $l->from_time,
                    'to_time' => $l->to_time,
                    'hours' => $l->hours ? (float) $l->hours : null,
                    'reason' => $l->reason,
                ])->all(),
            ];
        }

        return [$leaveBlock, $isOnLeave, $isWfh];
    }

    /* ── XLSX writers ──────────────────────────────────────────────── */

    private function streamDailyXlsx(array $payload): StreamedResponse
    {
        $rows = $payload['items'] ?? [];
        $dateStr = $payload['date'] ?? Carbon::now('Asia/Kolkata')->format('Y-m-d');

        $statusLabel = function (array $it): string {
            if ($it['status'] === 'on_leave') {
                return 'On leave' . ($it['leave']['type'] ?? '' ? ' (' . $it['leave']['type'] . ')' : '');
            }
            if ($it['status'] === 'signed_in') {
                return $it['signedOffAt'] ? 'Signed off' : 'Signed in';
            }
            if ($it['status'] === 'wfh') {
                return 'WFH';
            }

            return 'Not signed in';
        };

        $reportLabel = function (array $it): string {
            if (! $it['dailyReportExpected']) {
                return '—';
            }

            return $it['dailyReportSubmitted'] ? 'Submitted' : 'Not submitted';
        };

        $headers = ['Name', 'Role', 'Department', 'Status', 'Sign-in', 'Sign-off', 'Daily report', 'Notes'];
        $body = [];
        foreach ($rows as $it) {
            // Mirror the live roster's Notes column: surface the full-day
            // leave OR WFH (type + dates + reason), then any hourly Permission.
            // WFH is shown even when the person also signed in.
            $bits = [];
            if (! empty($it['leave']['type'])) {
                $bits[] = (string) $it['leave']['type'];
                $sd = $it['leave']['start_date'] ?? null;
                $ed = $it['leave']['end_date'] ?? null;
                if ($sd && $ed && $sd !== $ed) {
                    $bits[] = $sd . ' → ' . $ed;
                }
                if (! empty($it['leave']['reason'])) {
                    $bits[] = '“' . trim((string) $it['leave']['reason']) . '”';
                }
            }
            if (! empty($it['leave']['hourly'])) {
                foreach ($it['leave']['hourly'] as $h) {
                    $piece = $h['type'] ?? '';
                    if (! empty($h['from_time']) && ! empty($h['to_time'])) {
                        $piece .= ' ' . $h['from_time'] . '–' . $h['to_time'];
                    } elseif (! empty($h['hours'])) {
                        $piece .= ' ' . $h['hours'] . ' hr';
                    }
                    $bits[] = $piece;
                }
            }
            $leaveNote = implode(' · ', $bits);

            $body[] = [
                $it['userName'],
                $it['role'],
                $it['department'],
                $statusLabel($it),
                $it['signedInAt'] ?? '',
                $it['signedOffAt'] ?? '',
                $reportLabel($it),
                $leaveNote,
            ];
        }

        $filename = 'attendance-' . $dateStr . '.xlsx';

        return $this->streamSheet('Attendance ' . $dateStr, $headers, $body, $filename);
    }

    private function streamMonthlyXlsx(array $payload): StreamedResponse
    {
        $rows = $payload['items'] ?? [];
        $month = $payload['month'] ?? Carbon::now('Asia/Kolkata')->format('Y-m');

        $headers = ['Name', 'Role', 'Joining date', 'Working days', 'Logged in', 'Missed login', 'WFH', 'Permission (hrs)', 'Leaves', 'Compensated'];
        $body = [];
        foreach ($rows as $r) {
            $body[] = [
                $r['userName'],
                $r['role'],
                $r['joiningDate'] ?? '',
                $r['workingDays'],
                $r['signedInDays'],
                $r['missedLoginDays'] ?? 0,
                $r['wfhDays'],
                $r['permissionHours'],
                $r['leaveDays'],
                $r['compensatedDays'] ?? 0,
            ];
        }

        $filename = 'attendance-monthly-' . $month . '.xlsx';
        $title = 'Attendance — ' . ($payload['monthLabel'] ?? $month);

        return $this->streamSheet($title, $headers, $body, $filename);
    }

    /**
     * Write a single-sheet XLSX with bold dark-grey header, auto-sized
     * columns, and frozen header row. Mirrors EmployeeController::export
     * so the look stays consistent across HR exports.
     */
    private function streamSheet(string $sheetTitle, array $headers, array $rows, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        // PhpSpreadsheet rejects sheet titles longer than 31 chars or with
        // certain symbols — trim defensively so a long month label never
        // 500s the export.
        $safeTitle = preg_replace('/[\\\\\\/*?:\\[\\]]/', ' ', $sheetTitle) ?? $sheetTitle;
        $sheet->setTitle(mb_substr($safeTitle, 0, 31));

        $colCount = count($headers);
        foreach ($headers as $i => $label) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($col . '1', $label);
        }
        $lastCol = Coordinate::stringFromColumnIndex(max(1, $colCount));
        $headerRange = 'A1:' . $lastCol . '1';
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F2937');
        $sheet->getStyle($headerRange)->getFont()->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $row = 2;
        foreach ($rows as $r) {
            foreach ($r as $i => $val) {
                $col = Coordinate::stringFromColumnIndex($i + 1);
                $sheet->setCellValue($col . $row, $val);
            }
            $row++;
        }

        for ($c = 1; $c <= max(1, $colCount); $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /* ── small helpers ─────────────────────────────────────────────── */

    private function parseDate(?string $raw): ?string
    {
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '') {
            return Carbon::now('Asia/Kolkata')->format('Y-m-d');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }

        return $raw;
    }

    private function emptyDailyPayload(string $dateStr): array
    {
        return [
            'ok' => true,
            'date' => $dateStr,
            'summary' => [
                'totalCount' => 0,
                'signedInCount' => 0,
                'onLeaveCount' => 0,
                'missingCount' => 0,
                'absentCount' => 0,
                'signedOffCount' => 0,
                'reportSubmittedCount' => 0,
                'reportMissingCount' => 0,
            ],
            'items' => [],
        ];
    }
}
