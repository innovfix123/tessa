<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\GoogleUserService;
use App\Services\SlackService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Morning Slack DM listing each Calendar user's all-day NOTES due today (timed
 * meetings stay in their normal calendar flow). One bundled DM per user.
 *
 * Scheduled at 09:05 IST on purpose — the Slack quiet window is 10pm→9am, so an
 * earlier send is silently suppressed by SlackService::sendDirectMessage().
 * Reads notes live from each user's own Google Calendar (no local table).
 */
class NotifyCalendarNotes extends Command
{
    protected $signature = 'notify:calendar-notes {--dry-run : Print what would be sent without sending}';

    protected $description = "Morning Slack DM of each Calendar user's all-day notes due today";

    public function handle(): int
    {
        if (! config('calendar_access.slack_reminder_enabled', true)) {
            $this->info('Calendar Slack reminder disabled (calendar_access.slack_reminder_enabled=false).');

            return self::SUCCESS;
        }

        $userIds = config('calendar_access.viewer_user_ids', []);
        if (empty($userIds)) {
            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $today = Carbon::today('Asia/Kolkata')->toDateString();
        $slack = new SlackService;
        $sent = 0;

        foreach (User::whereIn('id', $userIds)->get() as $user) {
            if (! $user->hasGoogleConnection()) {
                $this->line("· {$user->name} (#{$user->id}): Google not connected — skipped.");
                continue;
            }

            try {
                $events = GoogleUserService::forUser($user)->getEventsForRange($today, $today);
            } catch (\Throwable $e) {
                $this->warn("· {$user->name} (#{$user->id}): calendar read failed — {$e->getMessage()}");
                continue;
            }

            // Only all-day "notes" — timed events are her meetings, not reminders.
            $notes = collect($events)->filter(
                fn ($e) => ($e['all_day'] ?? false) && ($e['date'] ?? null) === $today
            );
            if ($notes->isEmpty()) {
                $this->line("· {$user->name} (#{$user->id}): no notes today.");
                continue;
            }

            $count = $notes->count();
            $list = $notes->map(fn ($e) => '• '.($e['title'] ?? '(no title)'))->implode("\n");
            $message = ":calendar: *Your notes for today*\n\n{$list}\n\n_Manage these in Tessa → Calendar._";

            if ($dry) {
                $this->info("[dry-run] Would DM {$user->name} (#{$user->id}) — {$count} note(s):");
                $this->line($message);
                continue;
            }

            $slackId = $slack->getUserIdByName($user->name);
            if (! $slackId) {
                $this->warn("· {$user->name} (#{$user->id}): no Slack id — skipped.");
                continue;
            }

            // One bundled DM per user; respects the Slack quiet window (no bypass).
            if ($slack->sendDirectMessage($slackId, $message)) {
                $sent++;
                Log::info('CalendarNotes reminder sent', ['user' => $user->name, 'count' => $count]);
                $this->info("· {$user->name} (#{$user->id}): sent {$count} note(s).");
            }
        }

        if ($sent) {
            $this->info("Sent {$sent} calendar-note DM(s).");
        }

        return self::SUCCESS;
    }
}
