<?php

namespace App\Console\Commands;

use App\Models\DailySignin;
use App\Models\User;
use App\Services\SlackService;
use App\Helpers\DateHelper;
use Carbon\Carbon;
use Illuminate\Console\Command;

class NudgeSignIn extends Command
{
    protected $signature = 'nudge:sign-in {--date= : Date (Y-m-d), defaults to today}';

    protected $description = 'Send Slack reminders to users who have not signed in to Tessa today';

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

        $notSignedInUserIds = array_diff($allTeamUserIds, $signedInUserIds);
        $usersToNudge = User::whereIn('id', $notSignedInUserIds)->orderBy('name')->get();

        if ($usersToNudge->isEmpty()) {
            $this->info("Everyone has signed in to Tessa for {$dateStr}. Nothing to send.");

            return self::SUCCESS;
        }

        $message = "Hey! You haven't checked in with Tessa today. Please sign in and update your daily status.";

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
