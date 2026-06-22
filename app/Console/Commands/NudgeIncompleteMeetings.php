<?php

namespace App\Console\Commands;

use App\Models\DiscussionPoint;
use App\Models\LeaveRequest;
use App\Models\Meeting;
use App\Models\MeetingNote;
use App\Models\User;
use App\Services\SlackService;
use App\Helpers\DateHelper;
use Carbon\Carbon;
use Illuminate\Console\Command;

class NudgeIncompleteMeetings extends Command
{
    protected $signature = 'nudge:incomplete-meetings {--date= : Date (Y-m-d), defaults to today}';

    protected $description = 'Send gentle Slack reminders to meeting owners with 0 agenda or empty notes';

    public function handle(SlackService $slackService): int
    {
        $dateStr = $this->option('date') ?: Carbon::now('Asia/Kolkata')->format('Y-m-d');
        $selectedDate = DateHelper::parse($dateStr);
        $dayName = $selectedDate->format('l');
        $weekKey = $selectedDate->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');

        if ($selectedDate->isWeekend()) {
            $this->warn("Selected date {$dateStr} is a weekend. Skipping (no weekday meetings).");
            return self::SUCCESS;
        }

        $meetings = Meeting::where(function ($q) use ($dayName, $dateStr) {
            // Exclude monthly_first (only the first such weekday of the month) so monthly
            // 1:1s don't get nudged for an unfilled MOM every week. One-time meetings
            // pinned to a specific date only nudge on that exact date — without this,
            // an HR one-time eval on Tue 30 Jun would nudge every Tuesday.
            $q->where(function ($w) use ($dayName, $dateStr) {
                $w->where('day_of_week', $dayName)
                    ->where('recurrence', '!=', 'monthly_first')
                    ->where(function ($d) use ($dateStr) {
                        $d->where('recurrence', '!=', 'none')
                            ->orWhereNull('meeting_date')
                            ->orWhereDate('meeting_date', $dateStr);
                    });
            })->orWhere('recurrence', 'daily_weekdays');
        })
            ->whereNotNull('owner_id')
            ->with('ownerUser')
            ->orderBy('time')
            ->get();

        // Bundle by owner so each owner gets one DM covering all their incomplete meetings.
        $buckets = [];

        foreach ($meetings as $meeting) {
            $points = DiscussionPoint::where('meeting_id', $meeting->meeting_key)
                ->where('week_key', $weekKey)
                ->get();
            $total = $points->count();
            $filled = $points->filter(fn ($p) => trim((string) ($p->answer ?? '')) !== '')->count();

            $note = MeetingNote::where('meeting_id', $meeting->meeting_key)
                ->where('week_key', $weekKey)
                ->first();
            $hasNotes = $note && trim((string) ($note->content ?? '')) !== '';

            $needsAgenda = $total > 0 && $filled === 0;
            $needsNotes = ! $hasNotes;

            if (! $needsAgenda && ! $needsNotes) {
                continue;
            }

            $ownerUser = $meeting->ownerUser;
            if (! $ownerUser) {
                continue;
            }

            // Don't ask an owner who is on leave to update meeting notes. WFH/Permission
            // count as working days (project rule: wfh_permission_not_leave).
            $ownerOnLeave = LeaveRequest::where('user_id', $ownerUser->id)
                ->where('status', 'approved')
                ->whereHas('leaveType', fn ($q) => $q->whereNotIn('slug', ['wfh', 'permission']))
                ->where('start_date', '<=', $dateStr)
                ->where('end_date', '>=', $dateStr)
                ->exists();
            if ($ownerOnLeave) {
                continue;
            }

            $uid = $ownerUser->id;
            if (! isset($buckets[$uid])) {
                $buckets[$uid] = [
                    'name' => $ownerUser->name,
                    'lines' => [],
                ];
            }

            $needs = [];
            if ($needsAgenda) {
                $needs[] = 'agenda';
            }
            if ($needsNotes) {
                $needs[] = 'MOM notes';
            }
            $buckets[$uid]['lines'][] = "• *{$meeting->title}* — needs ".implode(' + ', $needs);
        }

        $sent = 0;
        $skipped = 0;
        foreach ($buckets as $bucket) {
            $slackId = $slackService->getUserIdByName($bucket['name']);
            if (! $slackId) {
                $this->warn("Skipped (Slack user not found): {$bucket['name']}");
                $skipped++;
                continue;
            }

            $count = count($bucket['lines']);
            $message = "*Reminder: {$count} meeting(s) need updates* (Week of {$weekKey})\n\n"
                .implode("\n", $bucket['lines'])
                ."\n\nPlease open Tessa to fill these in.";

            try {
                $slackService->sendDirectMessage($slackId, $message);
                $this->info("Sent meeting reminder bundle to {$bucket['name']} ({$count} meeting(s))");
                $sent++;
            } catch (\Throwable $e) {
                $this->warn("Failed bundle for {$bucket['name']}: {$e->getMessage()}");
                $skipped++;
            }
        }

        $this->newLine();
        $this->info("Done. Sent: {$sent} bundle(s), Skipped: {$skipped}");

        return self::SUCCESS;
    }
}
