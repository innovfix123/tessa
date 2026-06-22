<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\GmailInsightsService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class SyncGmailImportant extends Command
{
    protected $signature = 'gmail:sync-important
        {--user= : Limit to one user id (overrides the allowlist)}
        {--all : Run for every Google-connected active user (ignores the allowlist)}
        {--dry-run : Classify and report without persisting}';

    protected $description = 'Fetch + AI-classify recent Gmail messages into dashboard "important email" insights';

    public function handle(GmailInsightsService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $users  = $this->targetUsers();

        if ($users->isEmpty()) {
            $this->warn('No target users (check config/gmail_insights.php sync_user_ids, or pass --user / --all).');
            return self::SUCCESS;
        }

        $totalCreated = 0;
        foreach ($users as $user) {
            $res = $service->syncForUser($user, $dryRun);
            $totalCreated += $res['created'];

            $label = "#{$user->id} {$user->name}";
            if ($res['error']) {
                $this->warn("  {$label}: skipped ({$res['error']})");
                continue;
            }

            $this->line("  {$label}: fetched {$res['fetched']}, classified {$res['scanned']}, important "
                . count($res['important'])
                . ($dryRun ? ' (dry-run, not saved)' : ", created {$res['created']}"));

            if ($dryRun) {
                foreach ($res['important'] as $imp) {
                    $this->line("      [{$imp['priority']}/{$imp['category']}] {$imp['subject']}  —  {$imp['summary']}");
                }
            }
        }

        $this->info(($dryRun ? '[dry-run] ' : '') . "Done. Total important created: {$totalCreated}");

        return self::SUCCESS;
    }

    private function targetUsers(): Collection
    {
        if ($id = $this->option('user')) {
            return User::where('id', (int) $id)->where('is_active', true)->get();
        }

        $q = User::where('is_active', true)->whereNotNull('google_access_token');

        if (! $this->option('all')) {
            $ids = array_map('intval', (array) config('gmail_insights.sync_user_ids', []));
            $q->whereIn('id', $ids ?: [0]);
        }

        return $q->get();
    }
}
