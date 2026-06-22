<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\HR\EmployeeController;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Backfill employees' locally-stored HR documents into their per-person Google
 * Drive folder. Run once after the service account is provisioned so existing
 * docs (and each user's google_drive_folder_id) exist in Drive — otherwise a
 * person's embedded folder stays empty until their next upload.
 */
class SyncHrDriveDocs extends Command
{
    protected $signature = 'drive:sync-hr-docs {--user= : Only sync this user id}';

    protected $description = "Backfill employees' locally-stored HR documents into their Google Drive folder";

    public function handle(GoogleDriveService $drive): int
    {
        if (! $drive->isConfigured()) {
            $this->error('Google Drive service account is not configured — provision it first (see docs/google-drive-hr-docs-setup.md).');

            return self::FAILURE;
        }

        $query = User::query();
        if ($userId = $this->option('user')) {
            $query->where('id', (int) $userId);
        }
        $users = $query->orderBy('id')->get();

        if ($users->isEmpty()) {
            $this->warn('No matching users.');

            return self::SUCCESS;
        }

        $okCount = 0;
        $failCount = 0;
        foreach ($users as $user) {
            foreach (EmployeeController::DOC_FIELDS as $field => $label) {
                $path = $user->{$field};
                if (! $path) {
                    continue;
                }
                $abs = Storage::disk('public')->path($path);
                if (! is_file($abs)) {
                    $this->warn("  · {$user->name} — {$label}: local file missing ({$path})");
                    continue;
                }
                if ($drive->uploadDocument($user, $abs, $label)) {
                    $this->line("  ✓ {$user->name} — {$label}");
                    $okCount++;
                } else {
                    $this->warn("  ✗ {$user->name} — {$label}: upload failed (see logs)");
                    $failCount++;
                }
            }
        }

        $this->info("Done. {$okCount} uploaded, {$failCount} failed across {$users->count()} user(s).");

        return self::SUCCESS;
    }
}
