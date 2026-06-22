<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Feature 6 — upload an employee's scanned ID documents to Google Drive: into the
 * person's subfolder under the master HR folder, named to match the existing
 * convention ("<first-name> <doc>.<ext>", e.g. "priya pan card.pdf").
 *
 * Writes through a connected HR member's Google account (GoogleHrWriter →
 * GoogleUserService), NOT a service account. DORMANT-safe: returns false (no throw)
 * when no HR writer is connected or the master folder id is unset. Master-folder id
 * comes from config('services.google.service_account.drive_folder_id').
 */
class GoogleDriveService
{
    public function __construct(private GoogleHrWriter $writer) {}

    public function isConfigured(): bool
    {
        return $this->writer->hasWriter()
            && ! empty(config('services.google.service_account.drive_folder_id'));
    }

    /**
     * Match the existing Drive convention: "<first-name> <doc>.<ext>" (lowercase),
     * e.g. "priya pan card.jpeg". Doc tokens mirror the existing files
     * (photo / pan card / adhar card front|back). Aadhaar front + back stay distinct
     * so both upload without colliding.
     */
    public function fileName(string $personName, string $docLabel, string $ext): string
    {
        $first = mb_strtolower((string) (preg_split('/\s+/', trim($personName))[0] ?? 'employee'));
        $first = preg_replace('/[^a-z0-9]/u', '', $first) ?: 'employee';

        $doc = match ($docLabel) {
            'Photo' => 'photo',
            'PAN Card' => 'pan card',
            'Aadhar Front' => 'adhar card front',
            'Aadhar Back' => 'adhar card back',
            default => mb_strtolower(trim($docLabel)),
        };

        return $first . ' ' . $doc . '.' . ($ext ?: 'pdf');
    }

    /**
     * Upload a stored document to the person's Drive folder. Returns true on
     * success, false when unconfigured or on a handled failure (logged).
     */
    public function uploadDocument(User $user, string $localPath, string $docLabel): bool
    {
        if (! $this->isConfigured() || ! is_file($localPath)) {
            return false;
        }

        try {
            $client = $this->writer->client();
            if (! $client) {
                return false;
            }

            $folderId = $this->ensureFolder($user, $client);
            if (! $folderId) {
                return false;
            }

            $ext = pathinfo($localPath, PATHINFO_EXTENSION) ?: 'pdf';
            $name = $this->fileName((string) $user->name, $docLabel, $ext);
            $mime = mime_content_type($localPath) ?: 'application/octet-stream';

            $fileId = $client->uploadFileToFolder($folderId, $name, (string) file_get_contents($localPath), $mime);

            return $fileId !== null;
        } catch (\Throwable $e) {
            Log::warning('GoogleDriveService: upload errored', ['person' => $user->name, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Ensure this employee's Drive folder exists (match an existing one → else create
     * a canonical one) WITHOUT uploading a document — used to provision a folder at
     * hire time so the person shows in Employee Records before their first upload.
     * Returns true once a folder id is set; false when unconfigured / on a handled error.
     */
    public function ensureFolderFor(User $user): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $client = $this->writer->client();
            if (! $client) {
                return false;
            }

            return $this->ensureFolder($user, $client) !== null;
        } catch (\Throwable $e) {
            Log::warning('GoogleDriveService: ensureFolderFor failed', ['person' => $user->name, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * List the immediate children (subfolders + files) of a Drive folder, read through
     * the HR-writer account so it works for any authorised viewer regardless of their
     * own Google connection. Defaults to the master HR folder. DORMANT-safe: returns
     * ['ok' => false, 'reason' => 'unconfigured'] when no writer is connected.
     *
     * The CALLER must restrict which folder ids may be listed (the writer has full Drive
     * scope) — see EmployeeController::driveFolder, which allows only the master folder
     * and known per-employee subfolders.
     *
     * @return array{ok:bool, folder:?string, files:array<int,array<string,mixed>>, reason?:string}
     */
    public function listFolder(?string $folderId = null): array
    {
        $folderId = $folderId ?: (string) config('services.google.service_account.drive_folder_id');

        if (! $this->isConfigured()) {
            return ['ok' => false, 'folder' => $folderId, 'files' => [], 'reason' => 'unconfigured'];
        }

        try {
            $client = $this->writer->client();
            if (! $client) {
                return ['ok' => false, 'folder' => $folderId, 'files' => [], 'reason' => 'unconfigured'];
            }

            $res = $client->listFiles(1000, "'{$folderId}' in parents and trashed = false");

            return ['ok' => true, 'folder' => $folderId, 'files' => $res['files'] ?? []];
        } catch (\Throwable $e) {
            Log::warning('GoogleDriveService: listFolder failed', ['folder' => $folderId, 'error' => $e->getMessage()]);

            return ['ok' => false, 'folder' => $folderId, 'files' => [], 'reason' => 'error'];
        }
    }

    /**
     * True when $folderId is the master HR folder or a descendant of it. Walks the Drive
     * parent chain (bounded) through the writer client, so the browser can never list a
     * folder outside the master HR tree even though the writer account has full Drive
     * scope. Conservative: false when unconfigured / on any error.
     */
    public function isWithinMaster(string $folderId): bool
    {
        $master = (string) config('services.google.service_account.drive_folder_id');
        if ($folderId === '' || $master === '') {
            return false;
        }
        if ($folderId === $master) {
            return true;
        }
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $client = $this->writer->client();
            if (! $client) {
                return false;
            }

            $current = $folderId;
            $seen = [];
            for ($hops = 0; $hops < 15 && $current !== '' && ! isset($seen[$current]); $hops++) {
                $seen[$current] = true;
                $parents = $client->fileParents($current);
                if (in_array($master, $parents, true)) {
                    return true;
                }
                $current = $parents[0] ?? '';
            }
        } catch (\Throwable $e) {
            Log::warning('GoogleDriveService: isWithinMaster failed', ['folder' => $folderId, 'error' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * Move a Drive file/folder to Trash through the HR-writer account (recoverable ~30 days).
     * DORMANT-safe: returns false (no throw) when no writer is connected or on a handled error.
     * The CALLER must restrict which ids may be trashed (see EmployeeController::trashDriveItem,
     * which allows only items inside the master HR tree).
     */
    public function trashItem(string $fileId): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $client = $this->writer->client();
            if (! $client) {
                return false;
            }

            $client->trashFile($fileId);

            return true;
        } catch (\Throwable $e) {
            Log::warning('GoogleDriveService: trashItem failed', ['file' => $fileId, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Resolve the person's subfolder: cached id → an existing (manually-named)
     * match → else create a canonical one. Caches the id on the user afterward.
     */
    private function ensureFolder(User $user, GoogleUserService $client): ?string
    {
        if (! empty($user->google_drive_folder_id)) {
            return $user->google_drive_folder_id;
        }

        $parent = (string) config('services.google.service_account.drive_folder_id');
        $folderId = $this->matchExistingFolder($client, $parent, (string) $user->name)
            ?? $client->ensureChildFolder($parent, (string) $user->name);

        if ($folderId) {
            $user->update(['google_drive_folder_id' => $folderId]);
        }

        return $folderId;
    }

    /**
     * Find this person's existing subfolder under $parent (lists folders once, then
     * delegates to matchFolderId). Null when none/ambiguous — the caller then
     * creates a canonical folder, so we never file docs into the wrong folder.
     */
    private function matchExistingFolder(GoogleUserService $client, string $parent, string $name): ?string
    {
        $res = $client->listFiles(500, "('{$parent}' in parents) and mimeType = 'application/vnd.google-apps.folder' and trashed = false");

        return self::matchFolderId($res['files'] ?? [], $name);
    }

    /**
     * Pure name→folder-id match against a pre-fetched folder list — shared by the
     * on-upload path and the bulk `hr:link-drive-folders` backfill so both behave
     * identically: exact (normalized) full-name match first, else a UNIQUE
     * first-name match (handles "Bhoomika" → "Bhoomika S", "Sumit " trailing space,
     * "Prajwal B"). Returns null when none or ambiguous — never guesses between
     * folders that share a first name.
     *
     * @param array<int,array{id?:string,name?:string}> $folders Drive folder entries.
     */
    public static function matchFolderId(array $folders, string $name): ?string
    {
        if (! $folders) {
            return null;
        }

        $norm = fn ($s) => preg_replace('/\s+/', ' ', trim(mb_strtolower((string) $s)));
        $target = $norm($name);
        if ($target === '') {
            return null;
        }
        $firstName = explode(' ', $target)[0];

        // 1. Exact normalized full-name match.
        foreach ($folders as $f) {
            if ($norm($f['name'] ?? '') === $target) {
                return $f['id'] ?? null;
            }
        }

        // 2. Unique first-name match (only when exactly one folder shares the first name).
        $firstMatches = array_values(array_filter(
            $folders,
            fn ($f) => (explode(' ', $norm($f['name'] ?? ''))[0] ?? '') === $firstName
        ));

        return count($firstMatches) === 1 ? ($firstMatches[0]['id'] ?? null) : null;
    }
}
