<?php

namespace App\Console\Commands;

use App\Models\LeaveRequest;
use App\Services\LeaveService;
use App\Services\SlackService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Morning Slack ping for compensation days. For every approved Compensate
 * request whose `compensation_date` is today (Asia/Kolkata), DM the employee
 * AND their reporting manager so both sides know the person is working their
 * swapped weekend day. Mirrors the bundle-per-user pattern (one DM per
 * recipient, even if multiple compensations land on the same day).
 */
class NotifyCompensationDay extends Command
{
    protected $signature = 'notify:compensation-day
        {--date= : Override "today" reference (Y-m-d) for testing}
        {--dry-run : Show who would be notified without sending Slack DMs}';

    protected $description = 'DM employees + managers whose Compensate working day is today.';

    public function handle(SlackService $slackService): int
    {
        $today = $this->resolveToday();
        if ($today === null) {
            $this->error('--date must be YYYY-MM-DD.');

            return self::FAILURE;
        }

        $todayStr = $today->format('Y-m-d');

        $rows = LeaveRequest::with(['user.reportingManager', 'leaveType:id,slug,name'])
            ->where('status', 'approved')
            ->whereDate('compensation_date', $todayStr)
            ->whereHas('leaveType', fn ($q) => $q->where('slug', LeaveService::SLUG_COMPENSATE))
            ->get();

        if ($rows->isEmpty()) {
            $this->info("No compensation days today ({$todayStr}). Nothing to send.");

            return self::SUCCESS;
        }

        // Group by recipient so one user with multiple swaps today (rare but
        // possible) gets a single DM listing all of them — matches the
        // bundle-Slack-DMs-per-user project rule.
        $perEmployee = [];
        $perManager = [];
        foreach ($rows as $lr) {
            $emp = $lr->user;
            if (! $emp) {
                continue;
            }
            $perEmployee[$emp->id]['user'] = $emp;
            $perEmployee[$emp->id]['rows'][] = $lr;

            $mgr = $emp->reportingManager;
            if ($mgr) {
                $perManager[$mgr->id]['user'] = $mgr;
                $perManager[$mgr->id]['rows'][] = $lr;
            }
        }

        // HR gets a company-wide bundle DM listing every swap that's active
        // today, regardless of reporting line. Recipient list lives in
        // config/hr_leave_alerts.php (same allowlist used for the dashboard
        // on-leave card).
        $hrIds = array_values(array_filter(array_map(
            'intval',
            (array) config('hr_leave_alerts.user_ids', [])
        )));
        $hrUsers = empty($hrIds)
            ? collect()
            : \App\Models\User::whereIn('id', $hrIds)->where('is_active', true)->get(['id', 'name']);

        $isDryRun = (bool) $this->option('dry-run');
        $sent = 0;
        $skipped = 0;

        foreach ($perEmployee as $bundle) {
            $msg = $this->messageForEmployee($bundle['user'], $bundle['rows']);
            if ($this->dispatch($slackService, $bundle['user']->name, $msg, $isDryRun)) {
                $sent++;
            } else {
                $skipped++;
            }
        }

        foreach ($perManager as $bundle) {
            $msg = $this->messageForManager($bundle['user'], $bundle['rows']);
            if ($this->dispatch($slackService, $bundle['user']->name, $msg, $isDryRun)) {
                $sent++;
            } else {
                $skipped++;
            }
        }

        if ($hrUsers->isNotEmpty()) {
            $hrMessage = $this->messageForHr($rows);
            foreach ($hrUsers as $hr) {
                if ($this->dispatch($slackService, $hr->name, $hrMessage, $isDryRun)) {
                    $sent++;
                } else {
                    $skipped++;
                }
            }
        }

        $verb = $isDryRun ? 'Would notify' : 'Notified';
        $this->info("{$verb} {$sent} recipient(s) for {$rows->count()} compensation(s). Skipped: {$skipped}");

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection  $rows
     */
    private function messageForHr($rows): string
    {
        $lines = [];
        foreach ($rows as $lr) {
            $name = $lr->user?->name ?? 'Employee';
            $offLabel = $lr->start_date->format('D, j M');
            $lines[] = "• *{$name}* — working today (compensation for {$offLabel} off)";
        }

        return "*Compensation day — HR heads up*\n\nTeam members working a Compensate swap today:\n"
            . implode("\n", $lines);
    }

    /**
     * @param  \Illuminate\Support\Collection|array  $rows
     */
    private function messageForEmployee($employee, $rows): string
    {
        $lines = [];
        foreach ($rows as $lr) {
            $offLabel = $lr->start_date->format('D, j M');
            $lines[] = "• Working today (compensation for {$offLabel} off)";
        }

        return "*Compensation day reminder*\n\nHi {$employee->name}, today is your approved Compensate day:\n"
            . implode("\n", $lines)
            . "\n\nSign in and sign off on Tessa as a regular working day.";
    }

    /**
     * @param  \Illuminate\Support\Collection|array  $rows
     */
    private function messageForManager($manager, $rows): string
    {
        $lines = [];
        foreach ($rows as $lr) {
            $name = $lr->user?->name ?? 'Employee';
            $offLabel = $lr->start_date->format('D, j M');
            $lines[] = "• *{$name}* — working today (compensation for {$offLabel} off)";
        }

        return "*Compensation day — heads up*\n\nThe following team member(s) are working today as a Compensate swap:\n"
            . implode("\n", $lines);
    }

    private function dispatch(SlackService $slackService, string $userName, string $message, bool $isDryRun): bool
    {
        if ($isDryRun) {
            $this->line("--- {$userName} ---");
            foreach (explode("\n", $message) as $ln) {
                $this->line('  ' . $ln);
            }

            return true;
        }

        try {
            $slackUserId = $slackService->getUserIdByName($userName);
            if (! $slackUserId) {
                Log::warning('NotifyCompensationDay: Could not resolve Slack user', ['name' => $userName]);

                return false;
            }

            return (bool) $slackService->sendDirectMessage($slackUserId, $message, bypassQuietWindow: true);
        } catch (\Throwable $e) {
            $this->warn("Failed for {$userName}: {$e->getMessage()}");

            return false;
        }
    }

    private function resolveToday(): ?Carbon
    {
        $raw = $this->option('date');
        if (! $raw) {
            return Carbon::now('Asia/Kolkata')->startOfDay();
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $raw, 'Asia/Kolkata')->startOfDay();
    }
}
