<?php

namespace App\Services;

use App\Models\Bug;
use App\Models\ClaudeContext;
use App\Models\DailyReport;
use App\Models\DailySignin;
use App\Models\DailySignoff;
use App\Models\KpiDefinition;
use App\Models\LeaveRequest;
use App\Models\ManagerWorkReview;
use App\Models\Meeting;
use App\Models\MeetingNote;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\TaskCheckin;
use App\Models\TessaTask;
use App\Models\User;
use App\Services\UserFeatureService;
use App\Support\DailyReportsAccess;
use Carbon\Carbon;

class KraScorecardService
{
    public function buildMonth(User $user, string $monthYm): array
    {
        [$year, $month] = array_map('intval', explode('-', $monthYm));
        $monthStart = Carbon::create($year, $month, 1, 0, 0, 0, 'Asia/Kolkata')->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->endOfDay();
        $today = Carbon::now('Asia/Kolkata')->startOfDay();

        $role = $user->role ?? 'default';
        $weights = $this->weightsForRole($role);

        $effectiveMonthEnd = $today->lt($monthEnd) ? $today->copy()->endOfDay() : $monthEnd;

        $managerReviewData = $this->managerReviewDetail($user, $monthStart, $effectiveMonthEnd);

        $kras = [
            'discipline'     => $this->scoreDiscipline($user, $monthStart, $effectiveMonthEnd),
            'deliverables'   => $managerReviewData['rating_deliverables'],
            'manager_review' => $managerReviewData['rating_quality'],
        ];

        $weeks = [];
        $bestWeek = null;
        foreach ($this->iterateMonthWeeks($monthStart, $monthEnd) as $w) {
            $wStart = $w['start'];
            $wEnd = $w['end'];

            if ($wStart->gt($today)) {
                continue;
            }

            $effEnd = $wEnd->gt($today) ? $today->copy()->endOfDay() : $wEnd;

            $wMr = $this->managerReviewDetail($user, $wStart, $effEnd);
            $weekKras = [
                'discipline'     => $this->scoreDiscipline($user, $wStart, $effEnd),
                'deliverables'   => $wMr['rating_deliverables'],
                'manager_review' => $wMr['rating_quality'],
            ];
            $weekAvg = $this->compositeFor($weekKras, $weights);

            $weeks[] = [
                'weekLabel' => 'Week '.$w['index'],
                'weekRange' => $wStart->format('M j').' – '.$wEnd->format('M j'),
                'weekKey'   => $wStart->format('Y-m-d'),
                'average'   => $weekAvg,
                'status'    => $wEnd->lt($today) ? 'elapsed' : 'in_progress',
            ];

            if ($weekAvg !== null && ($bestWeek === null || $weekAvg > $bestWeek['score'])) {
                $bestWeek = ['label' => 'Week '.$w['index'], 'score' => $weekAvg];
            }
        }

        $monthAverage = $this->compositeFor($kras, $weights);

        return [
            'monthLabel'   => $monthStart->format('F Y'),
            'monthAverage' => $monthAverage,
            'kras'         => $kras,
            'weeks'        => $weeks,
            'bestWeek'     => $bestWeek,
            'coverage'     => $this->coverageFor($kras),
            'weights'      => $weights,
            'role'         => $role,
        ];
    }

    /**
     * Compute KRA for a single ISO week (Mon-Sun). If the selected week is the
     * current one, scoring ends at today (so mid-week scores aren't dragged down
     * by "future uncompleted" work). Used by the admin dashboard's week view.
     *
     * @param  string $anchor  Any YYYY-MM-DD inside the target week (IST).
     * @return array{weekStart: string, weekEnd: string, kras: array, composite: ?float, weights: array, role: string}
     */
    public function buildWeek(User $user, string $anchor): array
    {
        $anchorDate = Carbon::createFromFormat('Y-m-d', $anchor, 'Asia/Kolkata')->startOfDay();
        $today = Carbon::now('Asia/Kolkata')->startOfDay();

        $weekStart = $anchorDate->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $weekEndFull = $weekStart->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay();
        $weekEnd = $weekEndFull->gt($today) ? $today->copy()->endOfDay() : $weekEndFull;

        $role = $user->role ?? 'default';
        $weights = $this->weightsForRole($role);

        $weekMr = $this->managerReviewDetail($user, $weekStart, $weekEnd);

        $kras = [
            'discipline'     => $this->scoreDiscipline($user, $weekStart, $weekEnd),
            'deliverables'   => $weekMr['rating_deliverables'],
            'manager_review' => $weekMr['rating_quality'],
        ];

        return [
            'weekStart' => $weekStart->toDateString(),
            'weekEnd'   => $weekEnd->toDateString(),
            'kras'      => $kras,
            'composite' => $this->compositeFor($kras, $weights),
            'weights'   => $weights,
            'role'      => $role,
        ];
    }

    private function weightsForRole(string $role): array
    {
        $config = config('kra_weights', []);
        return $config[$role] ?? $config['default'] ?? [
            'discipline' => 0.25, 'deliverables' => 0.50, 'quality' => 0.25,
        ];
    }

    private function coverageFor(array $kras): array
    {
        $withData = 0;
        foreach ($kras as $v) {
            if ($v !== null) $withData++;
        }
        return ['signals_with_data' => $withData, 'signals_total' => count($kras)];
    }

    /**
     * If the user has a kra_effective_from override later than $start,
     * return that effective date. Otherwise return $start unchanged.
     * Used to clamp discipline + manager-review windows for users whose
     * scoring should begin later than the natural window start
     * (e.g. new hires in their training week).
     */
    private function clampToEffectiveStart(User $user, Carbon $start): Carbon
    {
        $override = config('kra_effective_from.'.$user->id);
        if (! $override) return $start;
        $cap = Carbon::parse($override, 'Asia/Kolkata')->startOfDay();
        return $cap->gt($start) ? $cap : $start;
    }

    /**
     * Weighted composite with neutral-fill for null buckets.
     *
     * Instead of redistributing null-bucket weight (which rewards having
     * less tracked work), null buckets receive a configurable baseline
     * score (default 3.0). Returns null only if every KRA is null.
     */
    public function compositeFor(array $kras, array $weights): ?float
    {
        $nullBaseline = (float) config('kra_weights.null_bucket_baseline', 3.0);
        $sum = 0.0;
        $wsum = 0.0;
        $hasAnyData = false;
        foreach ($weights as $key => $w) {
            if (isset($kras[$key]) && $kras[$key] !== null) {
                $sum += $kras[$key] * $w;
                $hasAnyData = true;
            } else {
                $sum += $nullBaseline * $w;
            }
            $wsum += $w;
        }
        if (!$hasAnyData) return null;
        return round($sum / $wsum, 1);
    }

    // ─── Discipline ────────────────────────────────────────────────

    private function scoreDiscipline(User $user, Carbon $start, Carbon $end): ?float
    {
        // Per-user KRA effective-from override: a user's discipline window
        // never extends before this date even if the caller asks for an
        // earlier start. Lets us start scoring a new hire from a clean
        // baseline date instead of dragging in days they weren't yet
        // trained/onboarded. See config/kra_effective_from.php.
        $start = $this->clampToEffectiveStart($user, $start);
        if ($start->gt($end)) return null;

        $businessDays = $this->businessDaysInRange($user, $start, $end);
        $items = []; // [{score, weight}, ...]

        if (!empty($businessDays)) {
            $signoffWeight = (float) config('kra_weights.signoff_discipline_weight', 0.5);

            // Sign-in (full weight)
            $signinCount = DailySignin::where('user_id', $user->id)
                ->whereIn('signin_date', $businessDays)
                ->count();
            $items[] = ['score' => min(1.0, $signinCount / count($businessDays)) * 5, 'weight' => 1.0];

            // Sign-off (reduced weight — missing sign-off still hurts but doesn't
            // halve the discipline score the way an equal-weight average did)
            $signoffCount = DailySignoff::where('user_id', $user->id)
                ->whereIn('signoff_date', $businessDays)
                ->count();
            $items[] = ['score' => min(1.0, $signoffCount / count($businessDays)) * 5, 'weight' => $signoffWeight];

            // Daily KPI report completion — only days where this user had KPI definitions
            $kpiScore = $this->scoreDailyReports($user, $businessDays);
            if ($kpiScore !== null) $items[] = ['score' => $kpiScore, 'weight' => 1.0];

            // Claude Context completion — the post-rollback (2026-06-18) discipline
            // component for staff moved off Daily Reports. At most one of the two
            // applies to any user (the other returns null).
            $ccScore = $this->scoreClaudeContext($user, $businessDays);
            if ($ccScore !== null) $items[] = ['score' => $ccScore, 'weight' => 1.0];

            // Meeting notes — only if the user owned meetings whose time has passed in range
            $meetingScore = $this->scoreMeetingNotes($user, $start, $end);
            if ($meetingScore !== null) $items[] = ['score' => $meetingScore, 'weight' => 1.0];

            // Task check-ins — only if user has active tasks in range
            $checkinScore = $this->scoreTaskCheckins($user, $start, $end, $businessDays);
            if ($checkinScore !== null) $items[] = ['score' => $checkinScore, 'weight' => 1.0];
        }

        $baseScore = null;
        if (!empty($items)) {
            $wSum = 0.0;
            $wTotal = 0.0;
            foreach ($items as $item) {
                $wSum += $item['score'] * $item['weight'];
                $wTotal += $item['weight'];
            }
            $baseScore = $wSum / $wTotal;
        }

        // Overdue work penalty — punches through leave so unfinished assigned
        // work still counts even if sign-in/report signals are excused.
        $penalty = $this->overdueWorkPenalty($user, $start, $end);

        if ($baseScore === null && $penalty <= 0) return null;

        $effectiveBase = $baseScore ?? 5.0;
        return round(max(0.0, $effectiveBase - $penalty), 1);
    }

    /**
     * One-time flat penalty per overdue assigned work item. An item is
     * penalised once — not per day late — if it was overdue on any
     * evaluation day inside the scoring window, whether it is still open or
     * was eventually completed late. Evaluation days are weekdays in range
     * minus company holidays; leave days are included on purpose so overdue
     * work isn't masked by leave.
     */
    private function overdueWorkPenalty(User $user, Carbon $start, Carbon $end): float
    {
        $perItem = (float) config('kra_weights.overdue_penalty_per_item', 0.20);
        if ($perItem <= 0) return 0.0;

        $today = Carbon::now('Asia/Kolkata')->endOfDay();
        $rangeEnd = $end->copy()->endOfDay();
        if ($rangeEnd->gt($today)) $rangeEnd = $today;

        $evalDays = $this->overdueEvalDays($start, $rangeEnd);
        if (empty($evalDays)) return 0.0;

        $rangeEndDate = $rangeEnd->toDateString();
        $overdueItems = 0;

        // Agile/sprint penalties only apply when the user is currently involved
        // in at least one open (non-closed) sprint. If all their sprints are
        // closed — or they have none — skip sprint/story/bug overdue checks
        // so leftover unclosed sprints don't keep accruing penalties forever
        // against people no longer doing sprint work.
        if ($this->userHasOpenSprintInvolvement($user)) {
            // Sprints: owner = created_by; overdue when end_date passed and not closed.
            $sprints = Sprint::where('created_by', $user->id)
                ->where('status', '!=', Sprint::STATUS_CLOSED)
                ->whereNotNull('end_date')
                ->where('end_date', '<', $rangeEndDate)
                ->get(['end_date']);
            foreach ($sprints as $sprint) {
                $overdueStart = Carbon::parse($sprint->end_date)->addDay()->startOfDay();
                if ($this->countDaysInWindow($evalDays, $overdueStart, $rangeEnd) > 0) {
                    $overdueItems++;
                }
            }

            // Stories assigned to user, in a sprint past end_date, story not done.
            // Closed sprints are excluded — once a sprint is closed, anything left
            // unfinished was rolled over/deferred and should not keep accruing
            // a per-day discipline penalty against the assignee.
            $stories = Story::where('assignee_id', $user->id)
                ->where('status', '!=', Story::STATUS_DONE)
                ->whereNotNull('sprint_id')
                ->whereHas('sprint', function ($q) use ($rangeEndDate) {
                    $q->whereNotNull('end_date')
                        ->where('end_date', '<', $rangeEndDate)
                        ->where('status', '!=', Sprint::STATUS_CLOSED);
                })
                ->with(['sprint:id,end_date'])
                ->get();
            foreach ($stories as $story) {
                if (!$story->sprint || !$story->sprint->end_date) continue;
                $overdueStart = Carbon::parse($story->sprint->end_date)->addDay()->startOfDay();
                if ($this->countDaysInWindow($evalDays, $overdueStart, $rangeEnd) > 0) {
                    $overdueItems++;
                }
            }

            // Bugs assigned to user, in a sprint past end_date, bug still open.
            // Same closed-sprint exclusion as stories above.
            $bugFinal = [Bug::STATUS_FIXED, Bug::STATUS_VERIFIED, Bug::STATUS_CLOSED, Bug::STATUS_WONT_FIX];
            $bugs = Bug::where('assignee_id', $user->id)
                ->whereNotIn('status', $bugFinal)
                ->whereNotNull('sprint_id')
                ->whereHas('sprint', function ($q) use ($rangeEndDate) {
                    $q->whereNotNull('end_date')
                        ->where('end_date', '<', $rangeEndDate)
                        ->where('status', '!=', Sprint::STATUS_CLOSED);
                })
                ->with(['sprint:id,end_date'])
                ->get();
            foreach ($bugs as $bug) {
                if (!$bug->sprint || !$bug->sprint->end_date) continue;
                $overdueStart = Carbon::parse($bug->sprint->end_date)->addDay()->startOfDay();
                if ($this->countDaysInWindow($evalDays, $overdueStart, $rangeEnd) > 0) {
                    $overdueItems++;
                }
            }
        }

        // TessaTasks assigned to user, deadline passed. Cancelled and on-hold
        // tasks are excluded — cancelled work is dropped (not the assignee's
        // failure to finish) and on-hold work is intentionally paused, so
        // neither should accrue per-day discipline penalty.
        $tessaTasks = TessaTask::where('assigned_to', $user->id)
            ->whereNotIn('status', ['cancelled', 'on_hold'])
            ->whereNotNull('deadline')
            ->where('deadline', '<', $rangeEnd)
            ->where(function ($q) use ($start) {
                $q->whereNull('completed_at')->orWhere('completed_at', '>=', $start);
            })
            ->get(['deadline', 'completed_at']);
        foreach ($tessaTasks as $task) {
            if (!$task->deadline) continue;
            $overdueStart = $task->deadline->copy()->addDay()->startOfDay();
            $itemEnd = $task->completed_at
                ? $task->completed_at->copy()->endOfDay()
                : $rangeEnd;
            if ($itemEnd->gt($rangeEnd)) $itemEnd = $rangeEnd;
            if ($this->countDaysInWindow($evalDays, $overdueStart, $itemEnd) > 0) {
                $overdueItems++;
            }
        }

        return $overdueItems * $perItem;
    }

    /**
     * True if the user is currently involved in any non-closed sprint —
     * either as creator, story assignee, or bug assignee. Used to gate
     * the agile-related discipline penalties so users with only closed
     * sprints (or none) aren't penalised for stale unclosed sprints.
     */
    private function userHasOpenSprintInvolvement(User $user): bool
    {
        $createdOpen = Sprint::where('created_by', $user->id)
            ->where('status', '!=', Sprint::STATUS_CLOSED)
            ->exists();
        if ($createdOpen) return true;

        $storyOpen = Story::where('assignee_id', $user->id)
            ->whereNotNull('sprint_id')
            ->whereHas('sprint', function ($q) {
                $q->where('status', '!=', Sprint::STATUS_CLOSED);
            })
            ->exists();
        if ($storyOpen) return true;

        return Bug::where('assignee_id', $user->id)
            ->whereNotNull('sprint_id')
            ->whereHas('sprint', function ($q) {
                $q->where('status', '!=', Sprint::STATUS_CLOSED);
            })
            ->exists();
    }

    /** Weekdays in [start, end] excluding company holidays. Leave days are included. */
    private function overdueEvalDays(Carbon $start, Carbon $end): array
    {
        $holidays = config('holidays', []);
        $days = [];
        $cursor = $start->copy()->startOfDay();
        $boundary = $end->copy()->endOfDay();
        while ($cursor->lte($boundary)) {
            if (!$cursor->isWeekend()) {
                $str = $cursor->format('Y-m-d');
                if (!array_key_exists($str, $holidays)) {
                    $days[] = $cursor->copy();
                }
            }
            $cursor->addDay();
        }
        return $days;
    }

    /** @param Carbon[] $evalDays */
    private function countDaysInWindow(array $evalDays, Carbon $windowStart, Carbon $windowEnd): int
    {
        if ($windowStart->gt($windowEnd)) return 0;
        $count = 0;
        foreach ($evalDays as $d) {
            if ($d->gte($windowStart) && $d->lte($windowEnd)) $count++;
        }
        return $count;
    }

    private function scoreDailyReports(User $user, array $businessDays): ?float
    {
        // Daily Reports rollback (2026-06-18): only the allow-list (Krishnan's
        // Content team + Shoyab) is graded on daily reports. Everyone else is
        // graded on their Claude Context summary instead (scoreClaudeContext),
        // so at most one of the two contributes to a user's discipline score.
        if (! DailyReportsAccess::enabledFor($user)) {
            return null;
        }

        $kpis = KpiDefinition::where('user_id', $user->id)
            ->whereNotIn('field_key', ['_group_init', '_person_init', '_placeholder'])
            ->get();
        if ($kpis->isEmpty()) return null;

        // Determine when these KPIs became effective (use earliest effective_from or created_at)
        $effectiveFrom = $kpis->map(function ($k) {
            return $k->effective_from ? Carbon::parse($k->effective_from) : Carbon::parse($k->created_at);
        })->min();

        // Filter business days to only those on/after the effective_from date
        $applicableDays = array_filter($businessDays, function ($d) use ($effectiveFrom) {
            return Carbon::parse($d)->gte($effectiveFrom);
        });
        if (empty($applicableDays)) return null;

        $filledDays = DailyReport::where('user_id', $user->id)
            ->whereIn('report_date', array_values($applicableDays))
            ->where('value', '!=', '')
            ->whereNotNull('value')
            ->distinct('report_date')
            ->count('report_date');

        return min(1.0, $filledDays / count($applicableDays)) * 5;
    }

    /**
     * Post-rollback (2026-06-18) replacement for scoreDailyReports: grades the
     * employee on how many working days they logged their daily Claude Context
     * summary. Applies ONLY to staff moved off Daily Reports (the allow-list is
     * graded on daily reports instead), and only from the rollback date onward
     * (before it, Daily Reports were the rule — don't penalise retroactively).
     */
    private function scoreClaudeContext(User $user, array $businessDays): ?float
    {
        if (DailyReportsAccess::enabledFor($user)) {
            return null;
        }
        if (! in_array('claude_context', UserFeatureService::featuresFor($user), true)) {
            return null;
        }

        $from = (string) config('daily_reports_access.claude_context_kra_from', '');
        $applicable = $from !== ''
            ? array_filter($businessDays, fn ($d) => $d >= $from)
            : $businessDays;
        if (empty($applicable)) {
            return null;
        }

        $loggedDays = ClaudeContext::where('user_id', $user->id)
            ->whereIn('context_date', array_values($applicable))
            ->distinct('context_date')
            ->count('context_date');

        return min(1.0, $loggedDays / count($applicable)) * 5;
    }

    private function scoreMeetingNotes(User $user, Carbon $start, Carbon $end): ?float
    {
        $ownedMeetings = Meeting::where('owner_id', $user->id)->get();
        if ($ownedMeetings->isEmpty()) return null;

        $expected = 0;
        $written = 0;
        $cursor = $start->copy()->startOfDay();
        while ($cursor->lte($end)) {
            if ($this->isWorkingDayForUser($user, $cursor)) {
                $dayName = $cursor->format('l');
                $weekKey = $cursor->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
                foreach ($ownedMeetings as $meeting) {
                    if (! $this->meetingOccursOn($meeting, $dayName)) continue;
                    $effId = $this->effectiveMeetingId($meeting, $dayName);
                    $note = MeetingNote::where('meeting_id', $effId)
                        ->where('week_key', $weekKey)
                        ->first();
                    $hasNote = $note && trim((string) ($note->content ?? '')) !== '';
                    // Only count meetings that actually happened (have notes)
                    // Missing notes = meeting did not happen, do not penalize
                    if ($hasNote) {
                        $expected++;
                        $written++;
                    }
                }
            }
            $cursor->addDay();
        }
        if ($expected === 0) return null;
        return min(1.0, $written / $expected) * 5;
    }

    private function scoreTaskCheckins(User $user, Carbon $start, Carbon $end, array $businessDays): ?float
    {
        // Only count tasks that actually existed during the window. Without
        // the created_at gate, tasks assigned today retroactively flip on the
        // task-checkin signal for every prior week — dragging historical
        // discipline scores down for work that didn't exist yet.
        //
        // The deadline gate (added per Fida 2026-05-25) drops tasks whose
        // deadline is still in the future at window end — those tasks aren't
        // yet "due" so we shouldn't expect daily progress check-ins against
        // them. overdueWorkPenalty() already handles tasks that genuinely
        // miss their deadline, so without this gate we were double-penalising
        // (one penalty for being overdue + one for not checking in daily on
        // work that wasn't even late yet). Tasks with NULL deadline stay
        // due-worthy — open-ended work should still get progress updates.
        $activeTasks = TessaTask::where('assigned_to', $user->id)
            ->whereIn('status', TessaTask::ACTIVE_STATUSES)
            ->where('created_at', '<=', $end)
            ->where(function ($q) use ($start) {
                $q->whereNull('completed_at')->orWhere('completed_at', '>=', $start);
            })
            ->where(function ($q) use ($end) {
                $q->whereNull('deadline')->orWhere('deadline', '<=', $end);
            })
            ->exists();
        if (!$activeTasks) return null;

        $checkinDays = TaskCheckin::where('user_id', $user->id)
            ->whereBetween('created_at', [$start, $end])
            ->get()
            ->pluck('created_at')
            ->map(fn ($d) => $d->copy()->timezone('Asia/Kolkata')->format('Y-m-d'))
            ->unique()
            ->count();

        return min(1.0, $checkinDays / count($businessDays)) * 5;
    }

    private function managerReviewDetail(User $user, Carbon $start, Carbon $end): array
    {
        // Mirror scoreDiscipline: a KRA effective-from override pins the
        // earliest week we'll surface manager ratings for. Reviews stored
        // before the effective-from date are excluded so a stray
        // training-week rating can't drag the composite.
        $start = $this->clampToEffectiveStart($user, $start);
        if ($start->gt($end)) {
            return ['score' => null, 'rating_deliverables' => null, 'rating_quality' => null];
        }

        $rangeStart = $start->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $rangeEnd = $end->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();

        $reviews = ManagerWorkReview::where('subordinate_id', $user->id)
            ->whereBetween('week_key', [$rangeStart, $rangeEnd])
            ->get(['rating_deliverables', 'rating_quality']);

        if ($reviews->isEmpty()) {
            return ['score' => null, 'rating_deliverables' => null, 'rating_quality' => null];
        }

        $delSum = 0.0; $delCount = 0;
        $qualSum = 0.0; $qualCount = 0;
        $overallSum = 0.0; $overallCount = 0;

        foreach ($reviews as $r) {
            if ($r->rating_deliverables !== null) { $delSum += $r->rating_deliverables; $delCount++; }
            if ($r->rating_quality !== null)      { $qualSum += $r->rating_quality; $qualCount++; }
            $vals = array_filter([$r->rating_deliverables, $r->rating_quality], fn ($v) => $v !== null);
            if (!empty($vals)) {
                $overallSum += array_sum($vals) / count($vals);
                $overallCount++;
            }
        }

        return [
            'score'               => $overallCount > 0 ? round($overallSum / $overallCount, 1) : null,
            'rating_deliverables' => $delCount > 0 ? round($delSum / $delCount, 1) : null,
            'rating_quality'      => $qualCount > 0 ? round($qualSum / $qualCount, 1) : null,
        ];
    }

    // ─── Helpers ───────────────────────────────────────────────────

    /** Business days the user is expected to work, as Y-m-d strings. */
    private function businessDaysInRange(User $user, Carbon $start, Carbon $end): array
    {
        $holidays = config('holidays', []);
        // WFH and Permission are not time off — the person is working — so they
        // are excluded here and the day still counts as a business day.
        $leaveRanges = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereHas('leaveType', function ($q) {
                $q->whereNotIn('slug', ['wfh', 'permission']);
            })
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('start_date', [$start->toDateString(), $end->toDateString()])
                    ->orWhereBetween('end_date', [$start->toDateString(), $end->toDateString()])
                    ->orWhere(function ($qq) use ($start, $end) {
                        $qq->where('start_date', '<=', $start->toDateString())
                            ->where('end_date', '>=', $end->toDateString());
                    });
            })
            ->get(['start_date', 'end_date']);

        // Pre-stringify leave bounds so we can compare on date alone — Carbon's
        // between() normalises to timestamps, which silently fails when the
        // cursor (Asia/Kolkata) and the DB-loaded leave datetimes (UTC) differ
        // by 5:30, causing same-day leaves to slip through.
        $leaveBounds = $leaveRanges->map(function ($lr) {
            $s = $lr->start_date instanceof \Carbon\CarbonInterface ? $lr->start_date->toDateString() : (string) $lr->start_date;
            $e = $lr->end_date   instanceof \Carbon\CarbonInterface ? $lr->end_date->toDateString()   : (string) $lr->end_date;
            return [substr($s, 0, 10), substr($e, 0, 10)];
        })->all();

        $days = [];
        $cursor = $start->copy()->startOfDay();
        while ($cursor->lte($end)) {
            if ($cursor->isWeekend()) { $cursor->addDay(); continue; }
            $str = $cursor->format('Y-m-d');
            if (array_key_exists($str, $holidays)) { $cursor->addDay(); continue; }
            $onLeave = false;
            foreach ($leaveBounds as [$s, $e]) {
                if ($str >= $s && $str <= $e) { $onLeave = true; break; }
            }
            if (!$onLeave) $days[] = $str;
            $cursor->addDay();
        }
        return $days;
    }

    private function isWorkingDayForUser(User $user, Carbon $day): bool
    {
        if ($day->isWeekend()) return false;
        if (array_key_exists($day->format('Y-m-d'), config('holidays', []))) return false;
        // WFH/Permission are working days — only actual leave types disqualify.
        return !LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereHas('leaveType', function ($q) {
                $q->whereNotIn('slug', ['wfh', 'permission']);
            })
            ->where('start_date', '<=', $day->toDateString())
            ->where('end_date', '>=', $day->toDateString())
            ->exists();
    }

    private function meetingOccursOn(Meeting $meeting, string $dayName): bool
    {
        // monthly_first stores a day_of_week but only occurs the first such weekday of the
        // month; it's a monthly 1:1, not a daily/weekly meeting, so exclude it from scoring.
        if (($meeting->day_of_week ?? null) === $dayName && ($meeting->recurrence ?? '') !== 'monthly_first') return true;
        if (($meeting->recurrence ?? '') === 'daily_weekdays'
            && in_array($dayName, ['Monday','Tuesday','Wednesday','Thursday','Friday'], true)) {
            return true;
        }
        return false;
    }

    private function effectiveMeetingId(Meeting $meeting, string $dayName): string
    {
        if (($meeting->recurrence ?? '') !== 'daily_weekdays' || $dayName === 'Monday') {
            return $meeting->meeting_key;
        }
        return $meeting->meeting_key.'-'.strtolower(substr($dayName, 0, 3));
    }

    /** Yield ISO weeks (Mon-Sun) that overlap the given month, numbered from 1. */
    private function iterateMonthWeeks(Carbon $monthStart, Carbon $monthEnd): array
    {
        $weeks = [];
        $cursor = $monthStart->copy()->startOfWeek(Carbon::MONDAY);
        $index = 1;
        while ($cursor->lte($monthEnd)) {
            $wEnd = $cursor->copy()->endOfWeek(Carbon::SUNDAY);
            $weeks[] = [
                'index' => $index,
                'start' => $cursor->copy()->startOfDay(),
                'end'   => $wEnd->copy()->endOfDay(),
            ];
            $cursor->addWeek();
            $index++;
        }
        return $weeks;
    }
}
