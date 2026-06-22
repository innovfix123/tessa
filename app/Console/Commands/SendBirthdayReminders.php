<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SlackService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Evening-before Slack reminder for tomorrow's birthdays. Designed to fire
 * around 17:30 IST on weekdays. Friday runs additionally cover Saturday,
 * Sunday, and Monday birthdays so the team gets advance notice even when
 * the cron doesn't run over the weekend.
 *
 * Recipients: every active user except the birthday person(s) themselves.
 */
class SendBirthdayReminders extends Command
{
    protected $signature = 'birthdays:remind-eve
        {--date= : Override "today" reference (Y-m-d) for testing}
        {--dry-run : Show who would be reminded and the message, without sending any Slack DMs}';

    protected $description = 'Slack DM the team about tomorrow\'s birthdays (Friday covers the weekend + Monday).';

    public function handle(SlackService $slackService): int
    {
        $today = $this->resolveToday();
        if ($today === null) {
            $this->error('--date must be YYYY-MM-DD.');

            return self::FAILURE;
        }

        // Friday runs cover Sat + Sun + Mon so weekend birthdays don't slip
        // through. Every other weekday just looks at "tomorrow". This keeps
        // the cron itself weekday-only — no extra weekend scheduling needed.
        $targets = $this->targetDates($today);

        $allUsers = User::where('is_active', true)
            ->where('id', '!=', 33) // skip generic Admin account, same as other HR pipelines
            ->whereNotIn('id', config('birthday_exclusions.user_ids', [])) // opted-out of birthday announcements
            ->whereNotNull('date_of_birth')
            ->get(['id', 'name', 'date_of_birth']);

        // Group upcoming birthdays by the absolute target date so a single
        // Slack message can list all of them in chronological order.
        $birthdaysByDate = [];
        foreach ($targets as $date) {
            $md = $date->format('m-d');
            $matches = $allUsers
                ->filter(fn ($u) => $u->date_of_birth && $u->date_of_birth->format('m-d') === $md)
                ->values();
            if ($matches->isNotEmpty()) {
                $birthdaysByDate[$date->format('Y-m-d')] = [
                    'date' => $date,
                    'users' => $matches,
                ];
            }
        }

        if (empty($birthdaysByDate)) {
            $this->info("No upcoming birthdays in the next " . count($targets) . " day(s). Nothing to send.");

            return self::SUCCESS;
        }

        $message = $this->composeMessage($today, $birthdaysByDate);
        $birthdayUserIds = collect($birthdaysByDate)
            ->flatMap(fn ($g) => $g['users']->pluck('id'))
            ->unique()
            ->all();

        // Everyone except the birthday people themselves. We don't want the
        // celebrant to get a "remember to wish yourself" DM.
        $recipients = User::where('is_active', true)
            ->where('id', '!=', 33)
            ->whereNotIn('id', $birthdayUserIds)
            ->orderBy('name')
            ->get(['id', 'name']);

        $isDryRun = (bool) $this->option('dry-run');
        if ($isDryRun) {
            $this->line('--- DRY RUN — no Slack DMs will be sent ---');
            $this->line('Message preview:');
            $this->line('');
            foreach (explode("\n", $message) as $ln) {
                $this->line('  ' . $ln);
            }
            $this->line('');
            $this->line('Would notify ' . $recipients->count() . ' recipient(s):');
            foreach ($recipients as $u) {
                $this->line('  - ' . $u->name);
            }

            return self::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;
        foreach ($recipients as $user) {
            try {
                $slackUserId = $slackService->getUserIdByName($user->name);
                if ($slackUserId && $slackService->sendDirectMessage($slackUserId, $message)) {
                    $sent++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $this->warn("Failed for {$user->name}: {$e->getMessage()}");
                $skipped++;
            }
        }

        $celebrants = collect($birthdaysByDate)
            ->flatMap(fn ($g) => $g['users']->pluck('name'))
            ->implode(', ');
        $this->info("Birthday reminder for: {$celebrants}. Sent: {$sent}, Skipped: {$skipped}");

        return self::SUCCESS;
    }

    /**
     * Returns the dates we should announce birthdays for, given "today".
     * Mon-Thu → just tomorrow. Friday → Sat + Sun + Mon (since the cron is
     * weekday-only and we want the team to know before the weekend).
     *
     * @return CarbonImmutable[]
     */
    private function targetDates(CarbonImmutable $today): array
    {
        $tomorrow = $today->addDay();
        if ($today->isFriday()) {
            return [$tomorrow, $today->addDays(2), $today->addDays(3)];
        }

        return [$tomorrow];
    }

    /**
     * @param  array<string, array{date: CarbonImmutable, users: \Illuminate\Support\Collection}>  $birthdaysByDate
     */
    private function composeMessage(CarbonImmutable $today, array $birthdaysByDate): string
    {
        ksort($birthdaysByDate); // chronological order by date string

        $multiDay = count($birthdaysByDate) > 1;
        $isFriday = $today->isFriday();

        $lines = [];
        foreach ($birthdaysByDate as $group) {
            $date = $group['date'];
            $names = $group['users']->pluck('name')->all();
            $namePart = $this->joinNames($names);
            $relative = $this->relativeLabel($today, $date);
            $absolute = $date->format('D, j M');

            if ($multiDay) {
                $lines[] = "• *{$namePart}* — {$relative} ({$absolute})";
            } else {
                // Single-day case reads more naturally as one sentence.
                // "tomorrow" / "day after tomorrow" flow without a preposition;
                // weekday names ("Monday") read better with "on" in front.
                $whenPhrase = in_array($relative, ['tomorrow', 'day after tomorrow'], true)
                    ? $relative
                    : 'on ' . $relative;
                $pronoun = count($names) > 1 ? 'them' : $this->resolvePronoun($group['users']->first());
                $lines[] = "🎂 Heads up — {$whenPhrase} is *{$namePart}*'s birthday ({$absolute}). Don't forget to wish {$pronoun}!";
            }
        }

        if ($multiDay) {
            $header = $isFriday
                ? "🎂 Birthday heads-up for the weekend + Monday:"
                : "🎂 Upcoming birthdays:";

            return $header . "\n" . implode("\n", $lines);
        }

        return $lines[0];
    }

    private function relativeLabel(CarbonImmutable $today, CarbonImmutable $date): string
    {
        // Carbon v3 returns a float here; cast so the matches below fire.
        $diff = (int) round($today->diffInDays($date, false));

        return match ($diff) {
            1 => 'tomorrow',
            2 => 'day after tomorrow',
            default => $date->format('l'), // e.g. "Monday" (no "on" prefix, since the sentence template adds context)
        };
    }

    private function joinNames(array $names): string
    {
        if (count($names) === 1) {
            return $names[0];
        }
        if (count($names) === 2) {
            return $names[0] . ' & ' . $names[1];
        }
        $last = array_pop($names);

        return implode(', ', $names) . ' & ' . $last;
    }

    private function resolvePronoun(User $user): string
    {
        // Gender column on users is loosely populated; fall back to neutral.
        $g = strtolower((string) ($user->gender ?? ''));
        if (str_starts_with($g, 'm')) {
            return 'him';
        }
        if (str_starts_with($g, 'f')) {
            return 'her';
        }

        return 'them';
    }

    private function resolveToday(): ?CarbonImmutable
    {
        $raw = $this->option('date');
        if (! $raw) {
            return CarbonImmutable::now('Asia/Kolkata')->startOfDay();
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $raw, 'Asia/Kolkata')->startOfDay();
    }
}
