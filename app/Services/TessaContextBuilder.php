<?php

namespace App\Services;

use App\Models\DailyReport;
use App\Models\DiscussionPoint;
use App\Models\KpiDefinition;
use App\Models\KpiEntry;
use App\Models\KpiTarget;
use App\Models\Meeting;
use App\Models\MeetingNote;
use App\Models\Role;
use App\Models\Ticket;
use App\Helpers\DateHelper;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TessaContextBuilder
{
    /**
     * Build a context string for Tessa with live business data.
     * Data is scoped to the user's portal/role permissions.
     */
    public function build(User $user): string
    {
        $today = Carbon::now('Asia/Kolkata');
        $dayName = $today->format('l');
        $dateStr = $today->format('Y-m-d');
        $weekKey = $today->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');

        Log::debug('TessaContextBuilder: starting build', [
            'user_id' => $user->id,
            'role' => $user->role,
            'date' => $dateStr,
            'day' => $dayName,
            'week_key' => $weekKey,
        ]);

        $sections = [];

        $sections[] = "Today: {$dateStr} ({$dayName}), current time: {$today->format('g:i A')} IST";

        if (ProjectRoleService::hasFeature($user->role, 'dashboard')) {
            $dashboard = $this->buildDashboardSection($user, $dateStr, $dayName, $weekKey, $today);
            if ($dashboard !== '') {
                $sections[] = "## Dashboard Status\n{$dashboard}";
            }
            Log::debug('TessaContextBuilder: dashboard section', ['length' => strlen($dashboard)]);
        }

        $meetings = $this->getMeetingsForUser($user, $dayName);
        Log::debug('TessaContextBuilder: meetings found', ['count' => $meetings->count()]);
        if ($meetings->isNotEmpty()) {
            $meetingLines = [];
            foreach ($meetings as $m) {
                $attendees = collect($m->attendees ?? [])
                    ->map(fn ($id) => User::find($id)?->name)
                    ->filter()
                    ->values()
                    ->join(', ');
                $isOwner = (int) ($m->owner_id ?? 0) === (int) $user->id;
                if ($isOwner) {
                    $agendaStatus = $this->getAgendaStatus($m, $weekKey, $dayName);
                    $notesStatus = $this->getNotesStatus($m, $weekKey, $dayName);
                    $meetingLines[] = "- {$m->title} @ {$m->time} | Role: owner | Agenda: {$agendaStatus} | Notes: {$notesStatus} | Attendees: {$attendees}";
                } else {
                    $meetingLines[] = "- {$m->title} @ {$m->time} | Role: attendee | Attendees: {$attendees}";
                }
            }
            $sections[] = "## Today's Meetings\n" . implode("\n", $meetingLines);
        }


        $kpiSummary = $this->buildKpiSummary($user, $weekKey);
        if ($kpiSummary !== '') {
            $sections[] = "## KPI Summary (This Week)\n{$kpiSummary}";
        }

        $yesterday = $today->copy()->subDay();
        $yesterdayStr = $yesterday->format('Y-m-d');
        $yesterdayDay = $yesterday->format('l');
        $yesterdayReports = $this->buildDailyReportStatus($user, $yesterdayStr);
        if ($yesterdayReports !== '') {
            $sections[] = "## Daily Reports (Yesterday, {$yesterdayDay} {$yesterdayStr})\n{$yesterdayReports}";
        }

        $prevWeekKey = $today->copy()->subWeek()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        if ($prevWeekKey !== $weekKey) {
            $prevKpi = $this->buildKpiSummary($user, $prevWeekKey);
            if ($prevKpi !== '') {
                $sections[] = "## KPI Summary (Previous Week, {$prevWeekKey})\n{$prevKpi}";
            }
        }

        $result = implode("\n\n", $sections);
        Log::debug('TessaContextBuilder: build complete', ['total_length' => strlen($result)]);

        return $result;
    }

    /**
     * Build sign-off status context for Tessa.
     * Reuses logic from SignoffController via SignoffStatusService.
     */
    public function buildSignoffContext(User $user): string
    {
        $dateStr = Carbon::now('Asia/Kolkata')->format('Y-m-d');
        $dayName = DateHelper::parse($dateStr)->format('l');

        $status = SignoffStatusService::getStatus($user, $dateStr);
        if (! ($status['ok'] ?? true)) {
            return "## Sign-Off Status\nUnable to load sign-off status.";
        }

        $signedOff = $status['signedOff'] ?? false;
        $canSignOff = $status['canSignOff'] ?? false;
        $items = $status['items'] ?? [];

        $lines = [];
        $lines[] = "## Sign-Off Status for Today ({$dayName}, {$dateStr})";
        $lines[] = "Already signed off: " . ($signedOff ? 'Yes' : 'No');
        $pendingCount = collect($items)->where('status', 'pending')->count();
        // Reason for canSignOff=false is either (a) already signed off, or (b)
        // pending blockers. Be explicit so the AI doesn't conflate them.
        if ($canSignOff) {
            $reason = 'Yes';
        } elseif ($signedOff) {
            $reason = 'No (already signed off earlier today — do not ask the user to complete pending items; just acknowledge they are done)';
        } else {
            $reason = "No ({$pendingCount} items pending)";
        }
        $lines[] = "Can sign off: {$reason}";
        $lines[] = '';
        $lines[] = 'Checklist:';
        foreach ($items as $item) {
            $statusLabel = ($item['status'] ?? '') === 'complete' ? 'COMPLETE' : 'PENDING';
            $label = $item['label'] ?? 'Unknown';
            $detail = $item['detail'] ?? '';
            $lines[] = "- [{$statusLabel}] {$label}: {$detail}";
        }

        return implode("\n", $lines);
    }

    /**
     * Build sign-in / morning briefing context for Tessa.
     * Presents today's schedule, pending items, and what needs to be done.
     */
    public function buildSigninContext(User $user): string
    {
        $today = Carbon::now('Asia/Kolkata');
        $dayName = $today->format('l');
        $dateStr = $today->format('Y-m-d');
        $weekKey = $today->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');

        $sections = [];
        $sections[] = "## Morning Briefing for Today ({$dayName}, {$dateStr})";
        $roleDisplay = $user->roleRelation?->name ?? $user->role ?? 'user';
        $sections[] = "User: {$user->name} ({$roleDisplay})";
        $sections[] = "Current time: {$today->format('g:i A')} IST";
        $sections[] = '';

        $meetings = $this->getMeetingsForUser($user, $dayName);
        if ($meetings->isNotEmpty()) {
            $meetingLines = [];
            foreach ($meetings as $m) {
                $attendees = collect($m->attendees ?? [])
                    ->map(fn ($id) => User::find($id)?->name)
                    ->filter()
                    ->values()
                    ->join(', ');
                $isOwner = (int) ($m->owner_id ?? 0) === (int) $user->id;
                if ($isOwner) {
                    $agendaStatus = $this->getAgendaStatus($m, $weekKey, $dayName);
                    $notesStatus = $this->getNotesStatus($m, $weekKey, $dayName);
                    $meetingLines[] = "- {$m->title} @ {$m->time} | Role: owner | Agenda: {$agendaStatus} | Notes: {$notesStatus} | Attendees: {$attendees}";
                } else {
                    $meetingLines[] = "- {$m->title} @ {$m->time} | Role: attendee | Attendees: {$attendees}";
                }
            }
            $sections[] = "### Today's Meetings\n" . implode("\n", $meetingLines);
            $sections[] = '';
        }

        $yesterday = $today->copy()->subDay();
        $yesterdayStr = $yesterday->format('Y-m-d');
        $yesterdayDay = $yesterday->format('l');
        $yesterdayReports = $this->buildDailyReportStatus($user, $yesterdayStr);
        if ($yesterdayReports !== '') {
            $sections[] = "### Daily Report (Yesterday, {$yesterdayDay})\n{$yesterdayReports}";
            $sections[] = '';
        }

        $kpiSummary = $this->buildKpiSummary($user, $weekKey);
        if ($kpiSummary !== '') {
            $sections[] = "### KPI Status (This Week)\n{$kpiSummary}";
        }

        return implode("\n", $sections);
    }

    /**
     * Build pending work context for Tessa.
     * Focuses only on items that need attention: overdue/pending actions, meetings with missing notes, incomplete daily report, KPIs.
     */
    public function buildPendingWorkContext(User $user): string
    {
        $today = Carbon::now('Asia/Kolkata');
        $dayName = $today->format('l');
        $dateStr = $today->format('Y-m-d');
        $weekKey = $today->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');

        $sections = [];
        $sections[] = "## Pending Work for {$user->name}";
        $sections[] = "Current time: {$today->format('g:i A')} IST ({$dateStr})";
        $sections[] = '';


        $openTickets = Ticket::with('reporter')
            ->where('assignee_id', $user->id)
            ->whereIn('status', [Ticket::STATUS_OPEN, Ticket::STATUS_IN_PROGRESS])
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->orderBy('created_at')
            ->get();

        if ($openTickets->isNotEmpty()) {
            $ticketLines = [];
            foreach ($openTickets as $t) {
                $reporter = $t->reporter?->name ?? 'Unknown';
                $ticketLines[] = "- #{$t->id} {$t->title} | From: {$reporter} | Priority: {$t->priority} | Status: {$t->status} | Category: {$t->category}";
            }
            $sections[] = "### Pending Tickets Assigned to You\n" . implode("\n", $ticketLines);
            $sections[] = '';
        }

        $meetings = $this->getMeetingsForUser($user, $dayName);
        $meetingLines = [];
        foreach ($meetings as $m) {
            $timePassed = $this->meetingTimeHasPassed($m->time ?? '', $today, true, $today);
            if (! $timePassed) {
                continue;
            }
            $parts = [];
            $isOwner = (int) ($m->owner_id ?? 0) === (int) $user->id;
            if ($isOwner) {
                $notesStatus = $this->getNotesStatus($m, $weekKey, $dayName);
                if ($notesStatus === 'empty') {
                    $parts[] = 'Notes: missing';
                }
            }
            if (! empty($parts)) {
                $meetingLines[] = "- {$m->title} @ {$m->time} | " . implode('; ', $parts);
            }
        }
        if (! empty($meetingLines)) {
            $sections[] = "### Meeting Notes / Actions Pending\n" . implode("\n", $meetingLines);
            $sections[] = '';
        }

        $yesterday = $today->copy()->subDay();
        $yesterdayStr = $yesterday->format('Y-m-d');
        $yesterdayDay = $yesterday->format('l');
        $yesterdayReports = $this->buildDailyReportStatus($user, $yesterdayStr);
        if ($yesterdayReports !== '') {
            $sections[] = "### Daily Report (Yesterday, {$yesterdayDay})\n{$yesterdayReports}";
            $sections[] = '';
        }

        $kpiSummary = $this->buildKpiSummary($user, $weekKey);
        if ($kpiSummary !== '') {
            $sections[] = "### KPI Status (This Week)\n{$kpiSummary}";
        }

        return implode("\n", $sections);
    }

    /**
     * Build context for a specific intent (dates, data_types, people).
     * Used when the user asks about specific dates/people.
     */
    public function buildForIntent(User $user, array $intent): string
    {
        $today = Carbon::now('Asia/Kolkata');
        $sections = [];

        $sections[] = "Today: {$today->format('Y-m-d')} ({$today->format('l')}), current time: {$today->format('g:i A')} IST";

        if ($intent['is_general'] ?? true) {
            return $this->build($user);
        }

        $targetUsers = $this->resolveTargetUsers($user, $intent['people'] ?? []);
        if (! empty($intent['people'])) {
            $resolvedNames = $targetUsers->pluck('name')->join(', ');
            $sections[] = "User is asking about: " . implode(', ', $intent['people'])
                . ($resolvedNames ? " (matched: {$resolvedNames})" : ' (no match found)');
        }

        $dates = $intent['dates'] ?? [];
        if (empty($dates)) {
            $dates = [$today->format('Y-m-d')];
        }

        $dataTypes = $intent['data_types'] ?? [];
        $validTypes = ['dashboard', 'meetings', 'daily_reports', 'kpis'];
        $dataTypes = array_intersect($dataTypes, $validTypes);

        if (empty($dataTypes)) {
            $dataTypes = ['daily_reports', 'meetings', 'kpis'];
        }

        $seenWeekKeys = [];
        $seenDashboardDates = [];

        foreach ($dataTypes as $type) {
            foreach ($dates as $dateStr) {
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
                    continue;
                }
                $date = Carbon::parse($dateStr, 'Asia/Kolkata');
                $dayName = $date->format('l');
                $weekKey = $date->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');

                if ($type === 'daily_reports') {
                    if ($targetUsers->isNotEmpty()) {
                        $reports = $this->buildDailyReportStatusForUsers($targetUsers, $dateStr);
                    } else {
                        $reports = $this->buildDailyReportStatus($user, $dateStr);
                    }
                    if ($reports !== '') {
                        $sections[] = "## Daily Reports ({$dayName} {$dateStr})\n{$reports}";
                    }
                }

                if ($type === 'meetings') {
                    $allMeetings = collect();
                    if ($targetUsers->isNotEmpty()) {
                        foreach ($targetUsers as $tu) {
                            $allMeetings = $allMeetings->merge($this->getMeetingsForUser($tu, $dayName));
                        }
                        $allMeetings = $allMeetings->unique('id');
                    } else {
                        $allMeetings = $this->getMeetingsForUser($user, $dayName);
                    }
                    if ($allMeetings->isNotEmpty()) {
                        $meetingLines = [];
                        foreach ($allMeetings as $m) {
                            $owner = $m->ownerUser?->name ?? $m->owner ?? '';
                            $attendees = collect($m->attendees ?? [])
                                ->map(fn ($id) => User::find($id)?->name)
                                ->filter()
                                ->values()
                                ->join(', ');
                            $isOwner = (int) ($m->owner_id ?? 0) === (int) $user->id;
                            if ($isOwner) {
                                $agendaStatus = $this->getAgendaStatus($m, $weekKey, $dayName);
                                $notesStatus = $this->getNotesStatus($m, $weekKey, $dayName);
                                $meetingLines[] = "- {$m->title} @ {$m->time} | Owner: {$owner} | Role: owner | Agenda: {$agendaStatus} | Notes/MOM: {$notesStatus} | Attendees: {$attendees}";
                            } else {
                                $meetingLines[] = "- {$m->title} @ {$m->time} | Owner: {$owner} | Role: attendee | Attendees: {$attendees}";
                            }
                        }
                        $sections[] = "## Meetings ({$dayName} {$dateStr})\n" . implode("\n", $meetingLines);
                    } else {
                        $sections[] = "## Meetings ({$dayName} {$dateStr})\nNo meetings found.";
                    }
                }

                if ($type === 'kpis' && ! isset($seenWeekKeys[$weekKey])) {
                    $kpiSummary = $this->buildKpiSummary($user, $weekKey);
                    if ($kpiSummary !== '') {
                        $sections[] = "## KPI Summary (Week of {$weekKey})\n{$kpiSummary}";
                    }
                    $seenWeekKeys[$weekKey] = true;
                }

                if ($type === 'dashboard' && ProjectRoleService::hasFeature($user->role, 'dashboard') && ! isset($seenDashboardDates[$dateStr])) {
                    $dashboard = $this->buildDashboardSection($user, $dateStr, $dayName, $weekKey, $date);
                    if ($dashboard !== '') {
                        $sections[] = "## Dashboard Status ({$dayName} {$dateStr})\n{$dashboard}";
                    }
                    $seenDashboardDates[$dateStr] = true;
                }
            }
        }

        $result = implode("\n\n", $sections);
        Log::debug('TessaContextBuilder: buildForIntent complete', [
            'intent' => $intent,
            'target_users' => $targetUsers->pluck('name', 'id')->toArray(),
            'total_length' => strlen($result),
        ]);

        return $result;
    }

    /**
     * Resolve a single person name to a User (for follow-ups).
     * Uses fuzzy matching within the requesting user's access scope.
     */
    public static function resolveTargetUser(User $requestingUser, string $name): ?User
    {
        $allowedIds = ProjectRoleService::getAllowedUserIdsForUser($requestingUser);
        $allAllowed = array_merge($allowedIds, [$requestingUser->id]);
        $trimmed = trim($name);
        if ($trimmed === '') {
            return null;
        }
        return User::where('is_active', true)
            ->whereIn('id', $allAllowed)
            ->where('name', 'LIKE', "%{$trimmed}%")
            ->first();
    }

    /**
     * Resolve people names from intent to User models.
     * Uses fuzzy matching (LIKE) on the name field.
     */
    private function resolveTargetUsers(User $requestingUser, array $names): \Illuminate\Support\Collection
    {
        if (empty($names)) {
            return collect();
        }

        $allowedIds = ProjectRoleService::getAllowedUserIdsForUser($requestingUser);
        $allAllowed = array_merge($allowedIds, [$requestingUser->id]);

        $users = collect();
        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $found = User::where('is_active', true)
                ->whereIn('id', $allAllowed)
                ->where('name', 'LIKE', "%{$name}%")
                ->get();
            $users = $users->merge($found);
        }

        return $users->unique('id');
    }

    /**
     * Build daily report status for specific target users (not all allowed).
     */
    private function buildDailyReportStatusForUsers(\Illuminate\Support\Collection $targetUsers, string $dateStr): string
    {
        $lines = [];
        foreach ($targetUsers as $u) {
            $totalFields = KpiDefinition::where('user_id', $u->id)
                ->whereNotIn('field_key', ['_group_init', '_person_init', '_placeholder'])
                ->count();

            if ($totalFields === 0) {
                $status = 'n/a';
            } else {
                $filledCount = DailyReport::where('user_id', $u->id)
                    ->where('report_date', $dateStr)
                    ->get()
                    ->filter(fn ($e) => trim((string) ($e->value ?? '')) !== '')
                    ->count();
                $status = $filledCount >= $totalFields ? 'submitted' : ($filledCount > 0 ? 'partial' : 'missing');
            }

            $roleName = $u->roleRelation?->name ?? '';
            $lines[] = "- {$u->name} ({$roleName}): {$status}";
        }

        return empty($lines) ? '' : implode("\n", $lines);
    }

    private function buildDashboardSection(User $user, string $dateStr, string $dayName, string $weekKey, Carbon $today): string
    {
        $allowedUserIds = ProjectRoleService::getAllowedUserIdsForUser($user);
        if (empty($allowedUserIds)) {
            return '';
        }

        $users = User::whereIn('id', $allowedUserIds)
            ->where('is_active', true)
            ->with(['roleRelation', 'reportingManager'])
            ->orderBy('name')
            ->get();

        $isToday = $today->isSameDay(Carbon::now('Asia/Kolkata'));
        $now = Carbon::now('Asia/Kolkata');
        $lines = [];
        $clearCount = 0;

        foreach ($users as $u) {
            $meetings = $this->getMeetingsForUser($u, $dayName);
            $hasPending = false;

            foreach ($meetings as $m) {
                $timePassed = $this->meetingTimeHasPassed($m->time, $today, $isToday, $now);
                $dayMeetingId = $this->resolveDayMeetingId($m, $dayName);
                $note = MeetingNote::where('meeting_id', $dayMeetingId)->where('week_key', $weekKey)->first();
                if (! $note) {
                    $note = MeetingNote::where('meeting_id', $m->meeting_key)->where('week_key', $weekKey)->first();
                }
                $hasNotes = $note && trim((string) ($note->content ?? '')) !== '';

                if (! $timePassed && ! $hasNotes) {
                    $hasPending = true;
                }
            }

            $dailyFields = KpiDefinition::where('user_id', $u->id)
                ->whereNotIn('field_key', ['_group_init', '_person_init', '_placeholder'])
                ->count();
            $filledCount = DailyReport::where('user_id', $u->id)
                ->where('report_date', $dateStr)
                ->get()
                ->filter(fn ($e) => trim((string) ($e->value ?? '')) !== '')
                ->count();

            if ($dailyFields > 0 && $filledCount < $dailyFields) {
                $hasPending = true;
            }

            $status = $hasPending ? 'pending' : 'clear';
            if (!$hasPending) {
                $clearCount++;
            }
            $roleName = $u->roleRelation?->name ?? '';
            $lines[] = "- {$u->name} ({$roleName}): {$status}";
        }

        $total = count($lines);
        $summary = "{$clearCount}/{$total} team members clear.";
        return $summary . "\n" . implode("\n", $lines);
    }

    private function getMeetingsForUser(User $user, string $dayName)
    {
        return Meeting::where(function ($q) use ($user) {
            $userId = $user->id;
            if ($user->role === Role::SLUG_PRODUCT_MANAGER) {
                $q->where('owner_id', $userId)->orWhereJsonContains('attendees', $userId);
                return;
            }
            $q->where('portal', $user->role)
                ->orWhere('owner_id', $userId)
                ->orWhereJsonContains('attendees', $userId);
        })
            ->where(function ($q) use ($dayName) {
                // monthly_first stores day_of_week but only fires the first such weekday
                // of the month (reminder cron handles it) — exclude from this daily view.
                $q->where(function ($w) use ($dayName) {
                    $w->where('day_of_week', $dayName)->where('recurrence', '!=', 'monthly_first');
                });
                if (in_array($dayName, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'])) {
                    $q->orWhere('recurrence', 'daily_weekdays');
                }
            })
            ->orderBy('time')
            ->orderBy('id')
            ->get();
    }

    private function getAgendaStatus(Meeting $meeting, string $weekKey, string $dayName = ''): string
    {
        $meetingId = $this->resolveDayMeetingId($meeting, $dayName);
        $points = DiscussionPoint::where('meeting_id', $meetingId)
            ->where('week_key', $weekKey)
            ->get();

        if ($points->isEmpty()) {
            $points = DiscussionPoint::where('meeting_id', $meeting->meeting_key)
                ->where('week_key', $weekKey)
                ->get();
        }

        $total = $points->count();
        if ($total === 0) {
            return 'empty';
        }
        $filled = $points->filter(fn ($p) => trim((string) ($p->answer ?? '')) !== '')->count();
        return $filled >= $total ? 'filled' : 'partial';
    }

    private function getNotesStatus(Meeting $meeting, string $weekKey, string $dayName = ''): string
    {
        $meetingId = $this->resolveDayMeetingId($meeting, $dayName);
        $note = MeetingNote::where('meeting_id', $meetingId)
            ->where('week_key', $weekKey)
            ->first();

        if (! $note) {
            $note = MeetingNote::where('meeting_id', $meeting->meeting_key)
                ->where('week_key', $weekKey)
                ->first();
        }

        return ($note && trim((string) ($note->content ?? '')) !== '') ? 'written' : 'empty';
    }

    /**
     * For daily_weekdays meetings, the actual meeting_id used for notes/agenda
     * is the base key + day suffix (e.g. "-fri"). Monday uses the base key.
     */
    private function resolveDayMeetingId(Meeting $meeting, string $dayName): string
    {
        if ($meeting->recurrence !== 'daily_weekdays' || $dayName === '') {
            return $meeting->meeting_key;
        }

        $daySuffixes = [
            'Monday' => '',
            'Tuesday' => '-tue',
            'Wednesday' => '-wed',
            'Thursday' => '-thu',
            'Friday' => '-fri',
        ];

        $suffix = $daySuffixes[$dayName] ?? null;
        if ($suffix === null) {
            return $meeting->meeting_key;
        }
        return $meeting->meeting_key . $suffix;
    }

    private function meetingTimeHasPassed(string $timeStr, Carbon $selectedDate, bool $isToday, Carbon $now): bool
    {
        if (!$isToday) {
            return $selectedDate->isPast();
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

    /** Get all meeting keys a user has access to (any day). */
    private function getAllMeetingKeysForUser(User $user): array
    {
        $q = Meeting::query();
        $userId = $user->id;
        if ($user->role === Role::SLUG_PRODUCT_MANAGER) {
            $q->where(function ($q2) use ($userId) {
                $q2->where('owner_id', $userId)->orWhereJsonContains('attendees', $userId);
            });
        } else {
            $q->where(function ($q2) use ($user, $userId) {
                $q2->where('portal', $user->role)
                    ->orWhere('owner_id', $userId)
                    ->orWhereJsonContains('attendees', $userId);
            });
        }
        return $q->pluck('meeting_key')->toArray();
    }

    private function buildKpiSummary(User $user, string $weekKey): string
    {
        $allowedUserIds = ProjectRoleService::getAllowedUserIdsForUser($user);
        if (empty($allowedUserIds)) {
            return '';
        }

        $entries = KpiEntry::where('week_key', $weekKey)->whereIn('user_id', $allowedUserIds)->get();
        $targets = KpiTarget::where('week_key', $weekKey)->whereIn('user_id', $allowedUserIds)->get();

        $items = [];
        foreach ($entries as $e) {
            $uid = $e->user_id;
            if (!isset($items[$uid])) {
                $items[$uid] = ['entries' => [], 'targets' => []];
            }
            $items[$uid]['entries'][$e->field_key] = $e->value ?? '';
        }
        foreach ($targets as $t) {
            $uid = $t->user_id;
            if (!isset($items[$uid])) {
                $items[$uid] = ['entries' => [], 'targets' => []];
            }
            $items[$uid]['targets'][$t->field_key] = $t->value ?? '';
        }

        $userNames = User::whereIn('id', array_keys($items))->pluck('name', 'id')->toArray();
        $lines = [];
        foreach ($items as $uid => $data) {
            $name = $userNames[$uid] ?? "User {$uid}";
            $entryCount = count($data['entries']);
            $targetCount = count($data['targets']);
            $lines[] = "- {$name}: {$entryCount} entries, {$targetCount} targets";
        }

        return empty($lines) ? '' : implode("\n", $lines);
    }

    private function buildDailyReportStatus(User $user, string $dateStr): string
    {
        $allowedUserIds = ProjectRoleService::getAllowedUserIdsForUser($user);
        if (empty($allowedUserIds)) {
            return '';
        }

        $users = User::whereIn('id', $allowedUserIds)->where('is_active', true)->with('roleRelation')->get();
        $lines = [];

        foreach ($users as $u) {
            $totalFields = KpiDefinition::where('user_id', $u->id)
                ->whereNotIn('field_key', ['_group_init', '_person_init', '_placeholder'])
                ->count();

            if ($totalFields === 0) {
                $status = 'n/a';
            } else {
                $filledCount = DailyReport::where('user_id', $u->id)
                    ->where('report_date', $dateStr)
                    ->get()
                    ->filter(fn ($e) => trim((string) ($e->value ?? '')) !== '')
                    ->count();
                $status = $filledCount >= $totalFields ? 'submitted' : ($filledCount > 0 ? 'partial' : 'missing');
            }

            $roleName = $u->roleRelation?->name ?? '';
            $lines[] = "- {$u->name} ({$roleName}): {$status}";
        }

        return empty($lines) ? '' : implode("\n", $lines);
    }
}
