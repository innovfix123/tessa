<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SlackLogScanService;
use Illuminate\Console\Command;

class ScanSlackLogs extends Command
{
    protected $signature = 'logs:scan-slack
        {--for-user= : Run for a single user id (otherwise all opted-in users)}';

    protected $description = 'Scan opted-in users\' own Slack messages and record meaningful ones into their Logs timeline';

    public function handle(): int
    {
        $service = new SlackLogScanService;

        // Every Slack-connected, active user is auto-synced (connected = syncing).
        $query = User::whereNotNull('slack_access_token')
            ->where('is_active', true);

        if ($this->option('for-user')) {
            $query->where('id', (int) $this->option('for-user'));
        }

        $users = $query->orderBy('id')->get();

        if ($users->isEmpty()) {
            $this->line('No opted-in Slack-connected users.');

            return self::SUCCESS;
        }

        $totalCreated = 0;
        $totalScanned = 0;

        foreach ($users as $user) {
            try {
                $r = $service->scanUser($user);
                $totalCreated += $r['created'];
                $totalScanned += $r['scanned'];
                if ($r['scanned'] > 0) {
                    $this->line("  {$user->name} (id {$user->id}): scanned {$r['scanned']}, logged {$r['created']}, skipped {$r['skipped']}");
                }
            } catch (\Throwable $e) {
                $this->error("  {$user->name} (id {$user->id}): {$e->getMessage()}");
            }
        }

        $this->info("Done: scanned {$totalScanned}, logged {$totalCreated} across {$users->count()} user(s).");

        return self::SUCCESS;
    }
}
