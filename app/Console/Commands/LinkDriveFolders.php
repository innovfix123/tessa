<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\GoogleDriveService;
use App\Services\GoogleHrWriter;
use Illuminate\Console\Command;

/**
 * One-time backfill: link each active employee to their EXISTING Drive subfolder
 * under the master HR folder (sets users.google_drive_folder_id), so the Employee
 * Documents embedded view shows their folder.
 *
 * LINKS ONLY — never uploads, never creates folders, never overwrites an existing
 * link. Skips employees with no folder match, folders already claimed by another
 * user, and conflicts (one folder matched by >1 employee) — so two people are never
 * pointed at the same folder. Skipped employees link on their next upload.
 */
class LinkDriveFolders extends Command
{
    protected $signature = 'hr:link-drive-folders {--dry-run : Preview matches without writing}';

    protected $description = 'Link active employees to their existing Google Drive folders (sets google_drive_folder_id).';

    public function handle(GoogleHrWriter $writer): int
    {
        $dry = (bool) $this->option('dry-run');

        $client = $writer->client();
        if (! $client) {
            $this->error('No connected HR writer (config services.google.hr_writer_ids). Have one Connect Google first.');

            return self::FAILURE;
        }

        $parent = (string) config('services.google.service_account.drive_folder_id');
        if ($parent === '') {
            $this->error('No master Drive folder configured (services.google.service_account.drive_folder_id).');

            return self::FAILURE;
        }

        // Single API call: the master folder's subfolders.
        try {
            $res = $client->listFiles(500, "('{$parent}' in parents) and mimeType = 'application/vnd.google-apps.folder' and trashed = false");
        } catch (\Throwable $e) {
            $this->error('Could not list the master Drive folder: ' . $e->getMessage());

            return self::FAILURE;
        }
        $folders = $res['files'] ?? [];
        $this->line('Master folder has ' . count($folders) . ' subfolders.');

        // Folder ids already claimed by some user — never reassign these.
        $claimed = array_flip(
            User::whereNotNull('google_drive_folder_id')->pluck('google_drive_folder_id')->all()
        );

        // Active employees (mirrors the Employee Documents view: is_active, excl. admin #33) without a link.
        $employees = User::query()
            ->where('is_active', true)
            ->where('id', '!=', 33)
            ->whereNull('google_drive_folder_id')
            ->orderBy('name')
            ->get(['id', 'name', 'google_drive_folder_id']);

        // Pass 1 — propose matches; track how many employees want each folder.
        $proposed = [];   // userId => folderId
        $byFolder = [];   // folderId => [userId, ...]
        $unmatched = [];
        foreach ($employees as $u) {
            $id = GoogleDriveService::matchFolderId($folders, (string) $u->name);
            if (! $id || isset($claimed[$id])) {
                $unmatched[] = $u;
                continue;
            }
            $proposed[$u->id] = $id;
            $byFolder[$id][] = $u->id;
        }

        // Pass 2 — link only folders wanted by exactly one employee.
        $linked = 0;
        $conflicts = [];
        foreach ($employees as $u) {
            if (! isset($proposed[$u->id])) {
                continue;
            }
            $id = $proposed[$u->id];
            if (count($byFolder[$id]) > 1) {
                $conflicts[] = $u; // same folder matched >1 employee — skip to avoid mis-linking
                continue;
            }
            $this->line(($dry ? '[dry] ' : '') . sprintf('LINK  #%-3d %-26s -> %s', $u->id, $u->name, $id));
            if (! $dry) {
                $u->update(['google_drive_folder_id' => $id]);
            }
            $linked++;
        }

        $this->newLine();
        $this->info(($dry ? 'DRY-RUN — ' : '') . "Linked: {$linked}   Conflicts: " . count($conflicts) . '   Unmatched: ' . count($unmatched));
        if ($conflicts) {
            $this->warn('Conflicts (one folder matched multiple employees — handle manually):');
            foreach ($conflicts as $u) {
                $this->line('  - #' . $u->id . ' ' . $u->name);
            }
        }
        if ($unmatched) {
            $this->line('Unmatched (no folder / already-claimed — will link on next upload):');
            foreach ($unmatched as $u) {
                $this->line('  - #' . $u->id . ' ' . $u->name);
            }
        }

        return self::SUCCESS;
    }
}
