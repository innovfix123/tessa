<?php

namespace App\Console\Commands;

use App\Services\HimaCpaSheetSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Fill Anirudh's CPA Master Sheet metric columns from the Hima admin "user
 * report" for a date (default: yesterday IST — the just-completed day). Mirrors
 * sync:hima-paid-users, but writes to a Google Sheet instead of the DB. Safely
 * no-ops (reports the reason) until the Hima endpoint is configured AND a writer
 * has connected Google with write scopes — see config/cpa_master_sheet.php.
 */
class SyncHimaCpaSheet extends Command
{
    protected $signature = 'sync:hima-cpa-sheet {--date= : Target date YYYY-MM-DD (default: yesterday IST)} {--dry-run : Fetch + map + print, do not write}';

    protected $description = "Fill Anirudh's CPA Master Sheet metric columns from the Hima admin user report for a date.";

    public function handle(HimaCpaSheetSyncService $svc): int
    {
        $date = (string) ($this->option('date') ?: Carbon::yesterday('Asia/Kolkata')->toDateString());
        $dry = (bool) $this->option('dry-run');

        $res = $svc->syncForDate($date, $dry);

        $this->line(($dry ? '[dry-run] ' : '') . "CPA sheet sync for {$date}:");
        foreach ($res['values'] as $header => $value) {
            $cell = $res['target_cells'][$header] ?? null;
            $this->line(sprintf(
                '   %-18s = %s%s',
                $header,
                $value === null ? '(null)' : $value,
                $cell ? "  → {$cell}" : ''
            ));
        }

        if ($res['error']) {
            // A missing endpoint / unprovisioned service account is the expected
            // dormant state, not a hard failure — don't fail the scheduler.
            $this->warn('   ' . $res['error']);
            return self::SUCCESS;
        }

        $this->info(($dry ? '[dry-run] ' : '') . "Done. Cells written: {$res['written']}");

        return self::SUCCESS;
    }
}
