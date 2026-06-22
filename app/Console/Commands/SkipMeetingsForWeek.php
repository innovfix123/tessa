<?php

namespace App\Console\Commands;

use App\Helpers\DateHelper;
use App\Models\Meeting;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-skip every recurring meeting for one Mon–Fri work week by inserting rows
 * into the existing `meeting_skips` table. Honored automatically by:
 *   - MeetingController::index (hides skipped occurrences)
 *   - MeetingController::pendingNotes (no overdue-MOM nudges)
 *   - SendMeetingReminders (no Slack reminders / MOM pings)
 *   - NudgeIncompleteMeetings (no agenda/notes nudges, via the same data path)
 *
 * Reversible at any time with --unskip (matching reason).
 */
class SkipMeetingsForWeek extends Command
{
    protected $signature = 'meetings:skip-week
        {--start= : Monday of the target week (Y-m-d). Defaults to this week\'s Monday in Asia/Kolkata.}
        {--reason=AI First Week — no regular meetings : Reason stored on each skip row.}
        {--include-onetime : Also skip one-time (recurrence=none) meetings whose day_of_week falls in the week.}
        {--unskip : Reverse mode — delete skip rows for the week that match the given reason.}
        {--dry-run : Print what would change, write nothing.}';

    protected $description = 'Skip every recurring meeting for a Mon–Fri work week (e.g. AI First Week). Reversible.';

    /** Mirrors MeetingController::MULTI_DAY_RECURRENCES; kept local so this command is self-contained. */
    private const MULTI_DAY_RECURRENCES = [
        'daily_weekdays' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
        'tue_to_fri'     => ['Tuesday', 'Wednesday', 'Thursday', 'Friday'],
        'mon_thu'        => ['Monday', 'Thursday'],
        'mon_wed_fri'    => ['Monday', 'Wednesday', 'Friday'],
    ];

    private const WEEKDAY_INDEX = [
        'Monday'    => 0,
        'Tuesday'   => 1,
        'Wednesday' => 2,
        'Thursday'  => 3,
        'Friday'    => 4,
    ];

    public function handle(): int
    {
        $startOpt = $this->option('start');
        $weekStart = $startOpt
            ? DateHelper::parse($startOpt)->startOfWeek(Carbon::MONDAY)
            : Carbon::now('Asia/Kolkata')->startOfWeek(Carbon::MONDAY);

        $weekStartStr = $weekStart->format('Y-m-d');
        $weekEndStr   = $weekStart->copy()->addDays(4)->format('Y-m-d');
        $reason       = (string) $this->option('reason');
        $includeOnetime = (bool) $this->option('include-onetime');
        $unskip       = (bool) $this->option('unskip');
        $dryRun       = (bool) $this->option('dry-run');

        $this->line('');
        $this->info(($unskip ? 'UN-SKIP' : 'SKIP') . " mode for week of {$weekStartStr} → {$weekEndStr}");
        $this->line("Reason     : {$reason}");
        $this->line('One-time   : ' . ($includeOnetime ? 'included' : 'left untouched (default)'));
        $this->line('Dry run    : ' . ($dryRun ? 'yes (no writes)' : 'no — changes will be written'));
        $this->line('');

        if ($unskip) {
            return $this->handleUnskip($weekStart, $reason, $dryRun);
        }

        return $this->handleSkip($weekStart, $reason, $includeOnetime, $dryRun);
    }

    private function handleSkip(Carbon $weekStart, string $reason, bool $includeOnetime, bool $dryRun): int
    {
        $weekDates = [];
        foreach (self::WEEKDAY_INDEX as $day => $offset) {
            $weekDates[$day] = $weekStart->copy()->addDays($offset)->format('Y-m-d');
        }

        $rowsToInsert = [];
        $tableRows    = [];
        $now          = now();

        $meetings = Meeting::orderBy('portal')->orderBy('title')->get();

        foreach ($meetings as $m) {
            $isMultiDay = isset(self::MULTI_DAY_RECURRENCES[$m->recurrence]);
            $isWeekly   = $m->recurrence === 'weekly';
            $isOnetime  = $m->recurrence === 'none';

            if ($isOnetime && ! $includeOnetime) {
                continue;
            }

            $days = $isMultiDay
                ? self::MULTI_DAY_RECURRENCES[$m->recurrence]
                : ($m->day_of_week ? [$m->day_of_week] : []);

            $datesForMeeting = [];
            foreach ($days as $day) {
                if (! isset($weekDates[$day])) {
                    continue;
                }
                $datesForMeeting[] = $weekDates[$day];
            }

            if (empty($datesForMeeting)) {
                continue;
            }

            foreach ($datesForMeeting as $date) {
                $rowsToInsert[] = [
                    'meeting_key' => $m->meeting_key,
                    'skip_date'   => $date,
                    'reason'      => $reason,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }

            $tableRows[] = [
                $m->portal,
                $m->meeting_key,
                $m->title,
                $m->recurrence,
                implode(', ', $datesForMeeting),
            ];
        }

        if (empty($rowsToInsert)) {
            $this->warn('No meetings matched. Nothing to do.');
            return self::SUCCESS;
        }

        $this->table(
            ['Portal', 'Meeting Key', 'Title', 'Recurrence', 'Dates to skip'],
            $tableRows
        );

        $this->line('');
        $this->info('Meetings affected: ' . count($tableRows));
        $this->info('Skip rows to write: ' . count($rowsToInsert));

        if ($dryRun) {
            $this->warn('Dry run — no writes performed.');
            return self::SUCCESS;
        }

        // updateOrInsert one row at a time to respect the unique (meeting_key, skip_date)
        // constraint and keep `reason` fresh if the row already exists from a manual skip.
        $written = 0;
        DB::transaction(function () use ($rowsToInsert, $reason, $now, &$written) {
            foreach ($rowsToInsert as $row) {
                DB::table('meeting_skips')->updateOrInsert(
                    [
                        'meeting_key' => $row['meeting_key'],
                        'skip_date'   => $row['skip_date'],
                    ],
                    [
                        'reason'     => $reason,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
                $written++;
            }
        });

        $this->info("Wrote {$written} skip row(s) into meeting_skips.");
        $this->line('To reverse: php artisan meetings:skip-week --unskip --start=' . $rowsToInsert[0]['skip_date'] . " --reason=\"{$reason}\"");

        return self::SUCCESS;
    }

    private function handleUnskip(Carbon $weekStart, string $reason, bool $dryRun): int
    {
        $weekStartStr = $weekStart->format('Y-m-d');
        $weekEndStr   = $weekStart->copy()->addDays(4)->format('Y-m-d');

        $query = DB::table('meeting_skips')
            ->whereBetween('skip_date', [$weekStartStr, $weekEndStr])
            ->where('reason', $reason);

        $rows = $query->get();
        if ($rows->isEmpty()) {
            $this->warn("No matching skip rows found between {$weekStartStr} and {$weekEndStr} with reason \"{$reason}\".");
            return self::SUCCESS;
        }

        $this->table(
            ['Meeting Key', 'Skip Date', 'Reason'],
            $rows->map(fn ($r) => [$r->meeting_key, $r->skip_date, $r->reason])->toArray()
        );

        if ($dryRun) {
            $this->warn('Dry run — would delete ' . $rows->count() . ' row(s).');
            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("Deleted {$deleted} skip row(s). Meetings restored for the week.");

        return self::SUCCESS;
    }
}
