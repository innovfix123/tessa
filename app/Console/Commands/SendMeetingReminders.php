<?php

namespace App\Console\Commands;

use App\Jobs\SendMeetingReminderJob;
use App\Models\Meeting;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendMeetingReminders extends Command
{
    protected $signature = 'meetings:send-reminders';

    protected $description = 'Send Slack DM reminders before, at start, and after meetings';

    public function handle(): int
    {
        $ist = Carbon::now('Asia/Kolkata');
        $todayStr = $ist->format('Y-m-d');

        if (array_key_exists($todayStr, config('holidays', []))) {
            $this->line("Skipping — {$todayStr} is a holiday.");

            return self::SUCCESS;
        }

        $skippedKeys = DB::table('meeting_skips')
            ->where('skip_date', $todayStr)
            ->pluck('meeting_key')
            ->toArray();

        // 10 min before — reminder for everyone
        $this->dispatchForTime($ist->copy()->addMinutes(10), $skippedKeys, 'reminder');

        // At meeting time — "started" alert (different for host vs attendees)
        $this->dispatchForTime($ist->copy(), $skippedKeys, 'started');

        // 30 min after start — MOM reminder for host
        $this->dispatchForTime($ist->copy()->subMinutes(30), $skippedKeys, 'mom');

        return self::SUCCESS;
    }

    private function dispatchForTime(Carbon $target, array $skippedKeys, string $type): void
    {
        $targetDay = $target->format('l');
        $targetTime = $target->format('h:i A');
        $isWeekday = $target->isWeekday();

        $monThuMatch = in_array($targetDay, ['Monday', 'Thursday'], true);
        $monWedFriMatch = in_array($targetDay, ['Monday', 'Wednesday', 'Friday'], true);
        // The first <weekday> of any month always falls on day-of-month 1–7.
        $isFirstWeek = $target->day <= 7;

        $meetings = Meeting::where('time', $targetTime)
            ->where(function ($q) use ($targetDay, $isWeekday, $monThuMatch, $monWedFriMatch, $isFirstWeek) {
                $q->where(function ($q2) use ($isWeekday) {
                    $q2->where('recurrence', 'daily_weekdays')
                        ->where(fn ($q3) => $isWeekday ? $q3 : $q3->whereRaw('1 = 0'));
                })
                    ->orWhere(function ($q2) use ($isWeekday, $targetDay) {
                        $q2->where('recurrence', 'tue_to_fri')
                            ->where(fn ($q3) => ($isWeekday && $targetDay !== 'Monday') ? $q3 : $q3->whereRaw('1 = 0'));
                    })
                    ->orWhere(function ($q2) use ($monThuMatch) {
                        $q2->where('recurrence', 'mon_thu')
                            ->where(fn ($q3) => $monThuMatch ? $q3 : $q3->whereRaw('1 = 0'));
                    })
                    ->orWhere(function ($q2) use ($monWedFriMatch) {
                        $q2->where('recurrence', 'mon_wed_fri')
                            ->where(fn ($q3) => $monWedFriMatch ? $q3 : $q3->whereRaw('1 = 0'));
                    })
                    ->orWhere(function ($q2) use ($targetDay) {
                        $q2->where('recurrence', 'weekly')
                            ->where('day_of_week', $targetDay);
                    })
                    ->orWhere(function ($q2) use ($targetDay, $target) {
                        // One-time meetings: match the weekday AND, if a specific
                        // meeting_date is pinned, only fire on that exact date.
                        // Rows with NULL meeting_date keep the legacy every-week
                        // behavior so older one-times don't go silent.
                        $q2->where('recurrence', 'none')
                            ->where('day_of_week', $targetDay)
                            ->where(function ($q3) use ($target) {
                                $q3->whereNull('meeting_date')
                                    ->orWhereDate('meeting_date', $target->toDateString());
                            });
                    })
                    ->orWhere(function ($q2) use ($targetDay, $isFirstWeek) {
                        // monthly_first: the first <day_of_week> of the month only.
                        $q2->where('recurrence', 'monthly_first')
                            ->where('day_of_week', $targetDay)
                            ->where(fn ($q3) => $isFirstWeek ? $q3 : $q3->whereRaw('1 = 0'));
                    });
            })
            ->get();

        foreach ($meetings as $meeting) {
            if (in_array($meeting->meeting_key, $skippedKeys, true)) {
                $this->line("Skipped ({$type}): {$meeting->title}");

                continue;
            }
            SendMeetingReminderJob::dispatch($meeting, $type);
            $this->info("Queued {$type}: {$meeting->title} ({$targetTime})");
        }
    }
}
