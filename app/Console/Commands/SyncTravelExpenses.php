<?php

namespace App\Console\Commands;

use App\Services\TravelExpenseSyncService;
use App\Services\TravelLedgerWriter;
use Illuminate\Console\Command;

/**
 * Backfill locally-stored travel trips into the writer's Google Drive + the master
 * ledger Sheet. Run once after the writer (Shoyab #32, else Ayush #4) reconnects
 * Google with the Drive + Sheets write scopes, so trips logged while the sync was
 * dormant get their screenshot uploaded and the ledger rebuilt. Idempotent — a
 * screenshot already carrying a drive_file_id isn't re-uploaded and the ledger is a
 * pure projection of the table, so re-running never duplicates.
 */
class SyncTravelExpenses extends Command
{
    protected $signature = 'travel:sync {--user= : Only sync this user id} {--month= : Only this YYYY-MM}';

    protected $description = 'Backfill travel-expense trips into the writer’s Google Drive + the master ledger Sheet';

    public function handle(TravelExpenseSyncService $sync, TravelLedgerWriter $writer): int
    {
        if (! $sync->isConfigured()) {
            $status = $writer->status();
            $this->error('Travel ledger sync is not configured — ' . $status['reason']);
            $this->line('  Fix: the writer (Shoyab #32, else Ayush #4) must Disconnect + Connect Google to grant the Drive + Sheets scopes. See config/travel_expenses.php.');

            return self::FAILURE;
        }

        $filters = array_filter([
            'user' => $this->option('user'),
            'month' => $this->option('month'),
        ]);

        $result = $sync->syncAll($filters);

        if (! ($result['ok'] ?? false)) {
            $this->error('Sync failed: ' . ($result['error'] ?? $result['reason'] ?? 'unknown') . ' (see logs).');

            return self::FAILURE;
        }

        $this->info("Done. {$result['trips']} trip(s) processed, {$result['uploaded']} screenshot(s) uploaded, ledger "
            . (($result['rebuilt'] ?? false) ? 'rebuilt' : 'NOT rebuilt') . '.');

        return self::SUCCESS;
    }
}
