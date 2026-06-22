<?php

namespace App\Console\Commands;

use App\Services\GoogleHrWriter;
use Illuminate\Console\Command;

/**
 * Health-check + discovery for the HR Google Drive/Sheet sync (Features 5 & 6,
 * Connect-Google path). Read-only. Shows the resolved writer, then tries to read
 * the master Drive folder's structure (so we can match the existing filename
 * convention) and the HR sheet's header row (so we can reconcile the field map).
 *
 * Diagnoses the common blockers in plain language:
 *   - "API ... disabled"        -> enable Drive/Sheets API in the GCP project
 *   - "insufficient ... scopes" -> HR must Disconnect + Connect Google again
 */
class ProbeHrGoogleSync extends Command
{
    protected $signature = 'hr:google-probe';

    protected $description = 'Diagnose HR Google Drive/Sheet sync: writer connection, master-folder structure, sheet headers.';

    public function handle(GoogleHrWriter $writer): int
    {
        $u = $writer->user();
        if (! $u) {
            $this->error('No connected HR writer. Check config services.google.hr_writer_ids ('
                . implode(',', (array) config('services.google.hr_writer_ids', []))
                . ') and have one of them Connect Google.');

            return self::FAILURE;
        }

        $this->info("Writer: #{$u->id} {$u->name} <{$u->google_email}>");

        $sheetId = (string) config('services.google.service_account.sheet_id');
        $tab = (string) config('services.google.service_account.sheet_tab', 'Sheet1');
        $folderId = (string) config('services.google.service_account.drive_folder_id');
        $this->line("Sheet:        {$sheetId} (tab '{$tab}')");
        $this->line("Drive folder: {$folderId}");
        $this->newLine();

        try {
            $g = $writer->client();
        } catch (\Throwable $e) {
            $this->error('Could not build Google client: ' . $e->getMessage());

            return self::FAILURE;
        }

        // ── Drive: list the master folder, drill into the first subfolder ──
        $this->info('— Drive —');
        try {
            $res = $g->listFiles(100, "('{$folderId}' in parents) and trashed = false");
            $files = $res['files'] ?? [];
            $this->line('Children: ' . count($files));
            $firstFolder = null;
            foreach (array_slice($files, 0, 15) as $f) {
                $isDir = ($f['mimeType'] ?? '') === 'application/vnd.google-apps.folder';
                $this->line('  [' . ($isDir ? 'DIR ' : 'file') . '] ' . ($f['name'] ?? '?'));
                if (! $firstFolder && $isDir) {
                    $firstFolder = $f;
                }
            }
            if ($firstFolder) {
                $this->line("Inside \"{$firstFolder['name']}\" (sample filename convention):");
                $sub = $g->listFiles(50, "('{$firstFolder['id']}' in parents) and trashed = false");
                foreach (($sub['files'] ?? []) as $f) {
                    $this->line('    ' . ($f['name'] ?? '?'));
                }
            }
        } catch (\Throwable $e) {
            $this->error('Drive read failed: ' . $e->getMessage());
            $this->line('  -> "API ... disabled": enable Drive API in the project. "insufficient ... scopes": reconnect Google.');
        }

        $this->newLine();

        // ── Sheet: show the header row so we can map columns ──
        $this->info('— Sheet —');
        try {
            $rows = $g->readSheetValues($sheetId, $tab);
            $headers = $rows[0] ?? [];
            $this->line('Rows: ' . count($rows));
            $this->line('Headers (' . count($headers) . '): ' . implode(' | ', $headers));
        } catch (\Throwable $e) {
            $this->error('Sheet read failed: ' . $e->getMessage());
            $this->line('  -> "API ... disabled": enable Sheets API in the project. "insufficient ... scopes": reconnect Google.');
        }

        return self::SUCCESS;
    }
}
