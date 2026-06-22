<?php

namespace App\Console\Commands;

use App\Models\DailySignin;
use App\Models\DailySignoff;
use App\Models\User;
use App\Services\SlackService;
use App\Helpers\DateHelper;
use Carbon\Carbon;
use Illuminate\Console\Command;

class NudgeSignOff extends Command
{
    protected $signature = 'nudge:sign-off {--date= : Date (Y-m-d), defaults to today}';

    protected $description = 'Send Slack reminders to users who signed in but have not signed off today';

    public function handle(SlackService $slackService): int
    {
        $dateStr = $this->option('date') ?: Carbon::now('Asia/Kolkata')->format('Y-m-d');

        if (array_key_exists($dateStr, config('holidays', []))) {
            $this->info("Skipping — {$dateStr} is a holiday.");

            return self::SUCCESS;
        }

        $selectedDate = DateHelper::parse($dateStr);

        $allTeamUserIds = User::where('is_active', true)
            ->whereNotNull('reporting_manager_id')
            ->pluck('id')
            ->toArray();

        if (empty($allTeamUserIds)) {
            $this->warn('No active team users found.');

            return self::SUCCESS;
        }

        $signedInUserIds = DailySignin::whereIn('user_id', $allTeamUserIds)
            ->where('signin_date', $dateStr)
            ->pluck('user_id')
            ->toArray();

        $signedOffUserIds = DailySignoff::whereIn('user_id', $allTeamUserIds)
            ->where('signoff_date', $dateStr)
            ->pluck('user_id')
            ->toArray();

        $needsNudge = array_diff($signedInUserIds, $signedOffUserIds);
        $usersToNudge = User::whereIn('id', $needsNudge)->orderBy('name')->get();

        if ($usersToNudge->isEmpty()) {
            $this->info("Everyone who signed in has signed off for {$dateStr}. Nothing to send.");

            return self::SUCCESS;
        }

        $message = "Hi! You've signed in to Tessa today — please don't forget to click *Sign Off* before you wrap up for the day. It only takes a moment!";

        $sent = 0;
        $skipped = 0;

        foreach ($usersToNudge as $user) {
            try {
                $slackUserId = $slackService->getUserIdByName($user->name);
                if ($slackUserId && $slackService->sendDirectMessage($slackUserId, $message)) {
                    $this->info("Sent: {$user->name}");
                    $sent++;
                } else {
                    $this->warn("Skipped (Slack user not found): {$user->name}");
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $this->warn("Failed for {$user->name}: {$e->getMessage()}");
                $skipped++;
            }
        }

        $this->newLine();
        $this->info("Done. Sent: {$sent}, Skipped: {$skipped}");

        return self::SUCCESS;
    }
}
