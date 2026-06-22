<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class TimesheetNudgeService
{
    public function __construct(private SlackService $slackService) {}

    public function nudgeUser(User $user, Carbon $refDate, ?User $nudgedBy = null): bool
    {
        if (! $user->is_active) {
            return false;
        }

        $portalUrl = config('app.url') . '/#view=timesheets';
        $by = $nudgedBy ? " — {$nudgedBy->name} from HR is checking in." : '';

        $msg = "Hi {$user->name}, just a reminder to log your timesheet for {$refDate->format('D, j M Y')} if you worked overtime.{$by}\n\n"
            . "<{$portalUrl}|Open Tessa Portal → Timesheets>";

        try {
            $sid = $this->slackService->getUserIdByName($user->name);
            if (! $sid) {
                Log::warning('TimesheetNudgeService: no slack id resolved', ['user' => $user->name]);
                return false;
            }
            // bypassQuietWindow=false — these are non-urgent reminders.
            return $this->slackService->sendDirectMessage($sid, $msg, bypassQuietWindow: false);
        } catch (\Throwable $e) {
            Log::error('TimesheetNudgeService::nudgeUser failed', [
                'user' => $user->name,
                'err' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send nudges to a collection of users. Throttled to avoid Slack rate limits.
     *
     * @return array{sent: int, failed: int, failed_names: array<int, string>}
     */
    public function nudgeBulk(Collection $users, Carbon $refDate, ?User $nudgedBy = null, int $cap = 50): array
    {
        $sent = 0;
        $failed = [];
        $i = 0;

        foreach ($users as $user) {
            if ($i >= $cap) {
                break;
            }
            if ($this->nudgeUser($user, $refDate, $nudgedBy)) {
                $sent++;
            } else {
                $failed[] = $user->name;
            }
            $i++;
            usleep(250_000); // 250ms throttle (Tessa convention)
        }

        return [
            'sent' => $sent,
            'failed' => count($failed),
            'failed_names' => $failed,
        ];
    }
}
