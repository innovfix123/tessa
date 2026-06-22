<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SlackHuddleSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncSlackHuddleNotes extends Command
{
    protected $signature = 'slack:sync-huddle-notes
        {--attendance-only : Only record attendance, skip writing meeting notes}
        {--with-insights : Extract action items/decisions/etc. as dashboard insights}
        {--since= : YYYY-MM-DD lower bound for backfill (default: last 24h)}
        {--all : Backfill every huddle Slack can still surface (overrides --since)}
        {--for-user= : Run the sync as a specific user (id). DM huddles only surface in that user\'s own Slack search.}
        {--per-user : Iterate over every Slack-connected user and run the sync from each view. Use sparingly — multiplies API calls.}';
    protected $description = 'Fetch Slack huddle AI notes and auto-sync to matching Tessa meeting notes';

    public function handle(): int
    {
        $now = Carbon::now('Asia/Kolkata');

        $isBackfill = (bool) $this->option('all') || $this->option('since');

        // Cron runs weekday-only for the 24h window; manual backfills run any day.
        if ($now->isWeekend() && ! $isBackfill) {
            $this->line('Weekend — skipping.');
            return self::SUCCESS;
        }

        $attendanceOnly = (bool) $this->option('attendance-only');
        $withInsights   = (bool) $this->option('with-insights');

        $sinceTs = null;
        if ($this->option('all')) {
            // Slack search can usually reach a few months back. Pin to a far-past
            // anchor so the per-message $sinceTs filter never trims results.
            $sinceTs = 0;
        } elseif ($this->option('since')) {
            $sinceRaw = strtolower(trim((string) $this->option('since')));
            if ($sinceRaw === 'today') {
                $sinceTs = Carbon::today('Asia/Kolkata')->getTimestamp();
            } else {
                try {
                    $sinceTs = (int) Carbon::parse($this->option('since'), 'Asia/Kolkata')
                        ->startOfDay()
                        ->getTimestamp();
                } catch (\Throwable $e) {
                    $this->error('Invalid --since date. Use YYYY-MM-DD or "today".');
                    return self::FAILURE;
                }
            }
        }

        $modeBits = [];
        $modeBits[] = $attendanceOnly ? 'attendance only' : 'full sync';
        if ($withInsights) $modeBits[] = 'with insights';
        if ($this->option('all')) {
            $modeBits[] = 'backfill ALL';
        } elseif ($sinceTs !== null) {
            $modeBits[] = 'since ' . date('Y-m-d', $sinceTs);
        }
        $this->info('Syncing Slack huddle notes (' . implode(', ', $modeBits) . ') at ' . $now->format('Y-m-d H:i') . '...');

        $service = new SlackHuddleSyncService();

        // Determine which Slack-connected user(s) to run the sync from.
        $callers = $this->resolveCallers();
        if ($callers === null) {
            return self::FAILURE;
        }

        $totalSynced = 0;
        $totalSkipped = 0;
        $totalErrors = 0;
        $insightTotal = 0;

        foreach ($callers as $caller) {
            if ($caller) {
                $this->line('—— Slack view: ' . $caller->name . ' (id ' . $caller->id . ')');
            }
            $result = $service->syncAll(
                callerUser: $caller,
                attendanceOnly: $attendanceOnly,
                withInsights: $withInsights,
                sinceTs: $sinceTs,
            );

            $totalSynced  += (int) ($result['synced'] ?? 0);
            $totalSkipped += (int) ($result['skipped'] ?? 0);
            $totalErrors  += (int) ($result['errors'] ?? 0);

            foreach ($result['details'] ?? [] as $d) {
                $insightTotal += (int) ($d['insights_created'] ?? 0);
                if (($d['status'] ?? '') === 'synced') {
                    $insightSuffix = isset($d['insights_created']) ? " [+{$d['insights_created']} insights]" : '';
                    $adHocTag      = empty($d['ad_hoc']) ? '' : ' [ad-hoc]';
                    $this->line("  ✓ Synced to: {$d['meeting']} ({$d['meeting_key']}){$adHocTag}{$insightSuffix}");
                } elseif (($d['status'] ?? '') === 'error') {
                    $this->error("  ✗ Error: {$d['error']}");
                }
            }
            if (! empty($result['message'])) {
                $this->warn('  ' . $result['message']);
            }
        }

        $this->info("Done: {$totalSynced} synced, {$totalSkipped} skipped, {$totalErrors} errors, {$insightTotal} insights created");

        return self::SUCCESS;
    }

    /**
     * Build the list of Slack-connected callers the sync should run from.
     * Returns null on validation failure (caller already printed the error).
     *
     * @return array<int, ?\App\Models\User>|null
     *   - Single-entry [null] = pick whichever connected user the service finds first (cron default).
     *   - Single-entry [User] = run as that explicit user (--for-user=ID).
     *   - Multi-entry [User, User, ...] = iterate over every connected user (--per-user).
     */
    private function resolveCallers(): ?array
    {
        if ($this->option('per-user')) {
            $users = User::whereNotNull('slack_access_token')->where('is_active', true)->orderBy('id')->get();
            if ($users->isEmpty()) {
                $this->warn('No Slack-connected users found.');
                return [null];
            }
            return $users->all();
        }

        $forUser = $this->option('for-user');
        if ($forUser) {
            $user = User::find((int) $forUser);
            if (! $user) {
                $this->error("User id {$forUser} not found.");
                return null;
            }
            if (! $user->hasSlackConnection()) {
                $this->error("User {$user->name} (id {$user->id}) has no Slack connection.");
                return null;
            }
            return [$user];
        }

        return [null];
    }
}
