<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Shared "provision a new hire into Employee Records" hook, called by both account-
 * creation paths (EmployeeController::handleCreate + HiringController::createTessaAccount).
 *
 * After the response (non-blocking, via terminating), it creates the hire's Google Drive
 * folder and upserts their HR-sheet row — so they appear in the Employee Records tabs
 * immediately, before their first document upload. Best-effort: no-ops when the Google
 * sync isn't configured (no connected HR writer), and never lets a failure affect the hire.
 * Idempotent — safe if called more than once for the same user (folder + row are upserts).
 */
class HrGoogleSync
{
    public function __construct(
        private GoogleDriveService $drive,
        private GoogleSheetsService $sheets,
    ) {}

    public function provisionNewHire(int $userId): void
    {
        if (! $this->drive->isConfigured() && ! $this->sheets->isConfigured()) {
            return;
        }

        app()->terminating(function () use ($userId) {
            try {
                $user = User::find($userId);
                if (! $user) {
                    return;
                }
                $this->drive->ensureFolderFor($user);   // create/link their Drive folder
                $this->sheets->upsertEmployeeRow($user); // add their HR-sheet row
            } catch (\Throwable $e) {
                Log::warning('HrGoogleSync: provisionNewHire failed', ['user' => $userId, 'error' => $e->getMessage()]);
            }
        });
    }
}
