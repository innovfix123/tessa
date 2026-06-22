<?php

namespace App\Services;

use App\Models\DailyReport;
use App\Models\DailySignoff;
use App\Models\DiscussionPoint;
use App\Models\KpiDefinition;
use App\Models\LeaveRequest;
use App\Models\ManagerWorkReview;
use App\Models\Meeting;
use App\Models\MeetingNote;
use App\Models\Role;
use App\Models\User;
use App\Models\ClaudeContext;
use App\Models\WeeklyTimesheet;
use App\Helpers\DateHelper;
use App\Services\TessaTaskService;
use App\Support\DailyReportsAccess;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SignoffStatusService
{
    /**
     * Get sign-off status for a user on a given date.
     * Returns the same structure as SignoffController::status() JSON.
     */
    public static function getStatus(User $user, ?string $dateStr = null): array
    {
        if ($dateStr === null || $dateStr === '') {
            $dateStr = Carbon::now('Asia/Kolkata')->format('Y-m-d');
        }
        $dateStr = trim($dateStr);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            return ['ok' => false, 'error' => 'Invalid date format'];
        }

        $selectedDate = DateHelper::parse($dateStr);
        $dayName = $selectedDate->format('l');
        $weekKey = $selectedDate->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        $now = Carbon::now('Asia/Kolkata');
        $isToday = $selectedDate->isSameDay($now);

        $items = [];
        $canSignOff = true;

        // Some users' KPIs are inherently next-day (ad spend entered the next
        // morning, paid-registered-users synced at 06:15 the following day, CPA
        // derived from both), so their same-day Daily Report can never be complete
        // at end of day. For them the report is shown but does not gate sign-off.
        $dailyReportBlocks = ! in_array($user->id, config('signoff_daily_report_optional.user_ids', []), true);

        // Daily Reports rollback (2026-06-18): only the allow-list (Krishnan's
        // Content team + Shoyab) still has the daily report as a sign-off gate.
        // For everyone else it's gone — the Claude Context summary (section 1b)
        // is their end-of-day gate instead, so the daily-report item below is
        // skipped entirely (neither shown nor blocking).
        $dailyReportsEnabled = DailyReportsAccess::enabledFor($user);

        // A company holiday or a full-day absence means this was never a working
        // day for the user, so the Daily Report is not expected and must not block
        // sign-off — same treatment as a weekend (someone who signed in by mistake
        // while on leave should still be able to sign off cleanly). WFH and
        // Permission are *working* arrangements (they count as worked days), so
        // they still expect a report; only non-hourly, non-WFH leave waives it
        // (Compensate's weekday-off is a genuine day off, so it waives too).
        $isHoliday = array_key_exists($dateStr, config('holidays', []));
        $isOnNonWorkingLeave = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $dateStr)
            ->where('end_date', '>=', $dateStr)
            ->whereHas('leaveType', fn ($q) => $q->where('is_hourly', false)->where('slug', '!=', 'wfh'))
            ->exists();
        // Holiday or full-day leave ⇒ not a working day, so none of the
        // "expected work" gates (Daily Report, meeting agenda/notes, Friday
        // review) apply. Weekend is deliberately NOT folded in here: it has its
        // own per-section handling, and the Fri/Sat/Sun manager-review window
        // must keep running on weekends for people who aren't on leave.
        $isNonWorkingDay = $isHoliday || $isOnNonWorkingLeave;

        // 1. Daily Report
        $dailyFields = self::getDefinedFieldsForUser($user->id);
        $totalFields = count($dailyFields);
        $filledEntries = DailyReport::where('user_id', $user->id)
            ->where('report_date', $dateStr)
            ->get()
            ->filter(fn ($entry) => trim((string) ($entry->value ?? '')) !== '');
        $filledCount = $filledEntries->count();
        // Which required fields are still empty — surfaced on the pending item so the
        // MCP sign_off tool can tell the caller exactly what to fill (display only;
        // the filledCount/totalFields gate below is unchanged).
        $filledKeys = $filledEntries->pluck('field_key')->all();
        $missingFields = array_values(array_filter(
            $dailyFields,
            fn ($d) => ! in_array($d['key'], $filledKeys, true)
        ));

        $hasAnyKpiDefs = KpiDefinition::where('user_id', $user->id)
            ->whereNotIn('field_key', ['_group_init', '_person_init', '_placeholder'])
            ->exists();

        if (! $dailyReportsEnabled) {
            // Daily Reports were rolled back for this user (2026-06-18). Their
            // end-of-day obligation is the Claude Context summary (section 1b
            // below), so the daily-report item is neither shown nor gates
            // sign-off here. The allow-list (Krishnan's Content team + Shoyab)
            // still runs through the normal daily-report flow.
        } elseif ($selectedDate->isWeekend()) {
            $items[] = [
                'type' => 'daily_report',
                'status' => 'complete',
                'label' => 'Daily Report',
                'detail' => 'Weekend — not required',
                'filled' => 0,
                'total' => 0,
            ];
        } elseif ($isNonWorkingDay) {
            $items[] = [
                'type' => 'daily_report',
                'status' => 'complete',
                'label' => 'Daily Report',
                'detail' => $isHoliday ? 'Company holiday — not required' : 'On leave — not required',
                'filled' => $filledCount,
                'total' => $totalFields,
            ];
        } elseif (! $dailyReportBlocks) {
            $items[] = [
                'type' => 'daily_report',
                'status' => 'complete',
                'label' => 'Daily Report',
                'detail' => $totalFields > 0
                    ? $filledCount.' of '.$totalFields.' filled — optional, does not block sign-off'
                    : 'Updates are optional today',
                'filled' => $filledCount,
                'total' => $totalFields,
            ];
        } elseif ($totalFields === 0) {
            $detail = $hasAnyKpiDefs
                ? ($filledCount > 0 ? $filledCount.' fields updated (optional)' : 'Updates are optional today')
                : 'No KPIs assigned';
            $items[] = [
                'type' => 'daily_report',
                'status' => 'complete',
                'label' => 'Daily Report',
                'detail' => $detail,
                'filled' => $filledCount,
                'total' => 0,
            ];
        } elseif ($filledCount >= $totalFields) {
            $items[] = [
                'type' => 'daily_report',
                'status' => 'complete',
                'label' => 'Daily Report',
                'detail' => $totalFields.' of '.$totalFields.' fields filled',
                'filled' => $filledCount,
                'total' => $totalFields,
            ];
        } else {
            $items[] = [
                'type' => 'daily_report',
                'status' => 'pending',
                'label' => 'Daily Report',
                'detail' => $filledCount.' of '.$totalFields.' fields filled',
                'filled' => $filledCount,
                'total' => $totalFields,
                'blocks' => true,
                'missing' => array_map(
                    fn ($d) => ['key' => $d['key'], 'label' => $d['label']],
                    $missingFields
                ),
            ];
            $canSignOff = false;
        }

        // 1b. Claude Context — mandatory end-of-day summary pushed via MCP.
        // Waived on weekends, holidays, and leave days (same as all other work-day
        // gates). Also waived if user lacks the claude_context feature (stripped
        // portals like freelance_recruiter can't push context so must not be blocked).
        $ccExcluded = in_array((int) $user->id, config('claude_context.signoff_excluded_user_ids', []), true);
        $hasCcFeature = in_array('claude_context', UserFeatureService::featuresFor($user), true);

        if (! $selectedDate->isWeekend() && ! $isNonWorkingDay && ! $ccExcluded && $hasCcFeature) {
            $ccLogged = ClaudeContext::where('user_id', $user->id)
                ->where('context_date', $dateStr)
                ->exists();
            $items[] = [
                'type'   => 'claude_context',
                'status' => $ccLogged ? 'complete' : 'pending',
                'label'  => 'Claude Context',
                'detail' => $ccLogged
                    ? 'Claude context logged for today'
                    : 'Log your Claude context before signing off',
                'blocks' => ! $ccLogged,
            ];
            if (! $ccLogged) {
                $canSignOff = false;
            }
        }

        // 2. Meeting Agendas and Notes (for meetings user owns).
        // On a non-working day (holiday or full-day leave) the user wasn't
        // expected to run their meetings, so agenda/notes neither show nor gate
        // — same treatment as the Daily Report waiver above.
        $meetings = $isNonWorkingDay ? collect() : self::getMeetingsForUser($user, $dayName);
        $skippedKeys = $meetings->isEmpty() ? [] : DB::table('meeting_skips')
            ->where('skip_date', $dateStr)
            ->pluck('meeting_key')
            ->toArray();

        foreach ($meetings as $meeting) {
            if (in_array($meeting->meeting_key, $skippedKeys, true)) {
                continue;
            }

            $effectiveMeetingId = $meeting->effectiveKeyForDay($dayName);
            $timePassed = self::meetingTimeHasPassed($meeting->time, $selectedDate, $isToday, $now);

            if ($meeting->owner_id !== $user->id) {
                continue;
            }

            // Agenda
            $points = DiscussionPoint::where('meeting_id', $effectiveMeetingId)
                ->where('week_key', $weekKey)
                ->get();
            $agendaTotal = $points->count();
            $agendaFilled = $points->filter(fn ($p) => trim((string) ($p->answer ?? '')) !== '')->count();
            $agendaComplete = $agendaTotal === 0 || $agendaFilled >= $agendaTotal;

            $items[] = [
                'type' => 'agenda',
                'status' => $agendaComplete ? 'complete' : 'pending',
                'label' => $meeting->title.' - Agenda',
                'detail' => $agendaComplete ? 'Agenda: filled' : ($agendaFilled.' of '.$agendaTotal.' items filled'),
                'meetingKey' => $effectiveMeetingId,
                'recurrence' => $meeting->recurrence ?? 'none',
                'blocks' => ! $agendaComplete,
            ];
            if (! $agendaComplete) {
                $canSignOff = false;
            }

            // Notes (only if meeting has passed)
            if ($timePassed) {
                $note = MeetingNote::where('meeting_id', $effectiveMeetingId)
                    ->where('week_key', $weekKey)
                    ->first();
                $hasNotes = $note && trim((string) ($note->content ?? '')) !== '';

                $items[] = [
                    'type' => 'notes',
                    'status' => $hasNotes ? 'complete' : 'pending',
                    'label' => $meeting->title.' - Notes',
                    'detail' => $hasNotes ? 'Notes: filled' : 'Notes: empty',
                    'meetingKey' => $effectiveMeetingId,
                    'recurrence' => $meeting->recurrence ?? 'none',
                    'blocks' => ! $hasNotes,
                ];
                if (! $hasNotes) {
                    $canSignOff = false;
                }
            }
        }


        // 4. Tessa Tasks (assigned to user, pending)
        $pendingTasks = app(TessaTaskService::class)->getPendingTasksSummary($user->id);
        $items[] = [
            'type' => 'tessa_tasks',
            'status' => $pendingTasks['count'] === 0 ? 'complete' : 'pending',
            'label' => 'Assigned Tasks',
            'detail' => $pendingTasks['count'] === 0 ? '0 pending' : $pendingTasks['count'] . ' pending',
            'pending' => $pendingTasks['count'],
            'tasks' => $pendingTasks['tasks'],
        ];

        // 4b. Tasks awaiting this user's verification (assigned BY user, status=completed).
        // Informational only — does not block signoff. Reporter is nudged via Slack instead.
        $awaitingVerification = app(TessaTaskService::class)->getTasksAwaitingVerification($user->id);
        $awaitingCount = $awaitingVerification->count();
        $items[] = [
            'type' => 'tasks_awaiting_verification',
            'status' => 'complete',
            'label' => 'Tasks Awaiting Your Verification',
            'detail' => $awaitingCount === 0 ? '0 to verify' : $awaitingCount . ' to verify (optional)',
            'pending' => $awaitingCount,
            'tasks' => $awaitingVerification->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'assigned_to' => $t->assignedTo?->name,
                'completed_at' => $t->completed_at?->toIso8601String(),
            ])->all(),
        ];

        // 5. Work-Quality review — applies to managers across the Fri/Sat/Sun
        //    rating window. Non-exempt subordinates must all be rated before
        //    the user can sign off on Friday. Waived on a holiday or while the
        //    manager is on full-day leave (it reappears on the next working day
        //    still inside the Fri/Sat/Sun window).
        if (! $isNonWorkingDay && in_array($selectedDate->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY, Carbon::SUNDAY], true)) {
            $weekFriday = $selectedDate->copy()->startOfWeek(Carbon::MONDAY)->addDays(4)->format('Y-m-d');
            // A waived week (config('review.skip_weeks')) never blocks sign-off.
            $rateables = ManagerWorkReview::isSkippedWeek($weekFriday)
                ? collect()
                : ManagerWorkReview::rateableSubordinatesFor($user, $weekFriday);
            if ($rateables->isNotEmpty()) {
                $ratedIds = ManagerWorkReview::where('manager_id', $user->id)
                    ->where('week_key', $weekFriday)
                    ->pluck('subordinate_id')
                    ->toArray();
                $total = $rateables->count();
                $rated = count(array_intersect($rateables->pluck('id')->toArray(), $ratedIds));
                $missing = $total - $rated;

                $items[] = [
                    'type'    => 'friday_review',
                    'status'  => $missing === 0 ? 'complete' : 'pending',
                    'label'   => 'Friday Work Quality Review',
                    'detail'  => $missing === 0
                        ? "All {$total} team ratings submitted"
                        : "{$missing} of {$total} team members still to rate",
                    'pending' => $missing,
                    'total'   => $total,
                    'blocks'  => $missing > 0,
                ];
                if ($missing > 0) {
                    $canSignOff = false;
                }
            }
        }

        // 6. Weekly Timesheet — mandatory across the Fri–Sun window. Every employee
        //    who fills a weekly timesheet (i.e. NOT in
        //    config('weekly_timesheet.excluded_user_ids')) must submit it before
        //    they can sign off on Fri/Sat/Sun. Waived on a holiday or full-day
        //    leave (same as the other gates); Mon–Thu don't gate.
        $wtsExcluded = array_map('intval', config('weekly_timesheet.excluded_user_ids', []));
        if (! $isNonWorkingDay
            && in_array($selectedDate->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY, Carbon::SUNDAY], true)
            && ! in_array((int) $user->id, $wtsExcluded, true)
        ) {
            $wtsWeekStart = $selectedDate->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
            $wtsSubmitted = WeeklyTimesheet::where('user_id', $user->id)
                ->where('week_start', $wtsWeekStart)
                ->where('status', 'submitted')
                ->exists();
            $items[] = [
                'type'   => 'weekly_timesheet',
                'status' => $wtsSubmitted ? 'complete' : 'pending',
                'label'  => 'Weekly Timesheet',
                'detail' => $wtsSubmitted
                    ? 'Submitted for this week'
                    : 'Submit your weekly timesheet before signing off',
                'blocks' => ! $wtsSubmitted,
            ];
            if (! $wtsSubmitted) {
                $canSignOff = false;
            }
        }

        $signoff = DailySignoff::where('user_id', $user->id)
            ->where('signoff_date', $dateStr)
            ->first();

        return [
            'ok' => true,
            'date' => $dateStr,
            'dayName' => $dayName,
            'signedOff' => (bool) $signoff,
            'signedOffAt' => $signoff?->signed_off_at?->toIso8601String(),
            'canSignOff' => $canSignOff && ! $signoff,
            'items' => $items,
        ];
    }

    private static function meetingTimeHasPassed(string $timeStr, Carbon $selectedDate, bool $isToday, Carbon $now): bool
    {
        if (! $isToday) {
            return $selectedDate->isPast();
        }
        $parsed = self::parseMeetingTime($timeStr);
        if ($parsed === null) {
            return false;
        }
        $meetingDateTime = $selectedDate->copy()->shiftTimezone('Asia/Kolkata')->setTimeFromTimeString($parsed);

        return $now->greaterThan($meetingDateTime);
    }

    private static function parseMeetingTime(string $timeStr): ?string
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

    private static function getDefinedFieldsForUser(int $userId): array
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

    private static function getMeetingsForUser(User $user, string $dayName)
    {
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
                // Exclude monthly_first: it keys off day_of_week but only occurs the
                // first such weekday of the month, so it must not appear every week.
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
            ->orderBy('time')
            ->orderBy('id')
            ->get();
    }
}
