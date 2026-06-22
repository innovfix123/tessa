<?php

namespace App\Services;

use App\Models\User;

/**
 * Resolves which connected Google account Tessa writes Anirudh's CPA Master Sheet
 * through (sync:hima-cpa-sheet). Returns the first user in
 * config('cpa_master_sheet.writer_user_ids') that has actually granted the WRITE
 * scopes (Drive + Sheets), so reconnecting Anirudh activates the sync. None
 * connected / none write-capable => null, and the sync no-ops (dormant-safe).
 *
 * Mirrors GoogleHrWriter / TravelLedgerWriter — the OAuth path that replaced the
 * dormant Google service account (no service-account key file required). The
 * target sheet is "anyone-with-link can edit", so the writer only needs the
 * spreadsheets scope, not explicit sharing.
 */
class CpaSheetWriter
{
    /**
     * The writer to use. Prefers (in config order) one that has granted the WRITE
     * scopes; falls back to the first merely-connected account (writes then fail
     * gracefully until someone reconnects). Null when none are connected.
     */
    public function user(): ?User
    {
        $candidates = [];
        foreach ((array) config('cpa_master_sheet.writer_user_ids', []) as $id) {
            $u = User::find((int) $id);
            if ($u && $u->google_access_token) {
                $candidates[] = $u;
            }
        }

        foreach ($candidates as $u) {
            if (GoogleHrWriter::hasWriteScopes($u)) {
                return $u;
            }
        }

        return $candidates[0] ?? null;
    }

    /** True when a write-capable (Drive + Sheets) writer is connected. */
    public function hasWriter(): bool
    {
        $u = $this->user();

        return $u !== null && GoogleHrWriter::hasWriteScopes($u);
    }

    /** A GoogleUserService bound to the writer (token auto-refreshed), or null. */
    public function client(): ?GoogleUserService
    {
        $u = $this->user();

        return $u ? GoogleUserService::forUser($u) : null;
    }
}
