<?php

namespace App\Console\Commands;

use App\Services\OfferAcceptanceScanner;
use Illuminate\Console\Command;

class DetectOfferAcceptances extends Command
{
    protected $signature = 'hiring:detect-offer-acceptances
        {--candidate= : Limit to one candidate id}
        {--dry-run : Classify + report without flagging or notifying}';

    protected $description = 'Auto-detect candidates'."'".' emailed acceptance of their offer letter (Gmail + gemini-2.5-flash) and flag them for "Add to Team"';

    public function handle(OfferAcceptanceScanner $scanner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $only = $this->option('candidate') ? (int) $this->option('candidate') : null;

        $res = $scanner->run($dryRun, $only);

        if ($res['error']) {
            $this->warn($res['error']);
        }
        $this->line("Offer-stage candidates scanned: {$res['candidates']} · connected inboxes: {$res['inboxes']}");
        foreach ($res['skipped'] as $s) {
            $this->line("  inbox skipped: {$s['inbox']} ({$s['reason']})");
        }

        if (! $res['accepted']) {
            $this->info(($dryRun ? '[dry-run] ' : '') . 'No new acceptances detected.');
            return self::SUCCESS;
        }

        foreach ($res['accepted'] as $a) {
            $this->line("  ✅ #{$a['candidate_id']} {$a['name']} <{$a['email']}> — conf {$a['confidence']} via {$a['inbox']} — \"{$a['subject']}\""
                . ($dryRun ? '  (dry-run, not saved)' : ''));
        }
        $this->info(($dryRun ? '[dry-run] ' : '') . 'Detected ' . count($res['accepted']) . ' acceptance(s).');

        return self::SUCCESS;
    }
}
