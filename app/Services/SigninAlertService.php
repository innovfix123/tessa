<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sends a Slack DM to a manager when one of their direct reports signs in for
 * the day on Tessa. Opt-in per manager via config/signin_manager_alerts.php.
 * Fired once per fresh daily sign-in from SignoffController::signIn().
 */
class SigninAlertService
{
    public function __construct(private SlackService $slack) {}

    public function notifyManagerOnSignIn(User $user, Carbon $signedInAt): void
    {
        $managerId = (int) $user->reporting_manager_id;
        if ($managerId <= 0) {
            return;
        }

        $allowed = array_map('intval', (array) config('signin_manager_alerts.manager_ids', []));
        if (! in_array($managerId, $allowed, true)) {
            return;
        }

        $istDate = $signedInAt->copy()->timezone('Asia/Kolkata')->toDateString();

        // Dedup: at most one DM per report, per manager, per IST day — so an
        // undo + re-sign-in within the 5-min window doesn't fire a second time.
        $cacheKey = "signin_alert:{$managerId}:{$user->id}:{$istDate}";
        if (! Cache::add($cacheKey, 1, now()->addHours(20))) {
            return;
        }

        $manager = User::where('id', $managerId)->where('is_active', true)->first(['name']);
        if (! $manager || ! $manager->name) {
            return;
        }

        $time = $signedInAt->copy()->timezone('Asia/Kolkata')->format('g:i A');
        $desig = $user->designation ? " ({$user->designation})" : '';
        $portal = rtrim((string) config('app.url'), '/') . '/#view=attendance';
        $message = ":wave: *{$user->name}*{$desig} from your team just signed in on Tessa — {$time} IST.\n"
            . "<{$portal}|View team attendance>";

        try {
            $slackId = $this->slack->getUserIdByName($manager->name);
            if ($slackId) {
                $this->slack->sendDirectMessage($slackId, $message, bypassQuietWindow: true);
            } else {
                Log::warning('SigninAlertService: could not resolve Slack user', ['manager' => $manager->name]);
            }
        } catch (\Throwable $e) {
            Log::error('SigninAlertService: Slack DM failed', ['manager' => $manager->name, 'error' => $e->getMessage()]);
        }
    }
}
