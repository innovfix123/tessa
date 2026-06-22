<?php

namespace App\Services;

use App\Models\User;

/**
 * Resolves which connected admin's Google account the Travel-Expense ledger writes
 * through — Shoyab (#32) primary, Ayush (#4) fallback (config travel_expenses
 * .writer_user_ids). The ledger Sheet + screenshot folders live in that account's
 * OWN Drive (no service account), reusing the same "Connect Google" OAuth pattern
 * as GoogleHrWriter. None connected with WRITE scopes ⇒ the sync stays dormant-safe.
 *
 * Note: because the files live in whichever account actually writes, a Shoyab→Ayush
 * fallback moves the ledger into Ayush's Drive — keep Shoyab connected to keep it
 * in one place.
 */
class TravelLedgerWriter
{
    /**
     * The account we actually write through: the first id (in config order) that is
     * connected AND has granted the Drive + Sheets write scopes. Null when none have.
     */
    public function writeUser(): ?User
    {
        foreach ($this->candidates() as $u) {
            if (GoogleHrWriter::hasWriteScopes($u)) {
                return $u;
            }
        }

        return null;
    }

    /** True once an account with write scopes is connected (otherwise the sync no-ops). */
    public function isConfigured(): bool
    {
        return $this->writeUser() !== null;
    }

    /** A GoogleUserService bound to the write user (token auto-refreshed), or null. */
    public function client(): ?GoogleUserService
    {
        $u = $this->writeUser();

        return $u ? GoogleUserService::forUser($u) : null;
    }

    /**
     * Sync state for the admin UI banner — a pure DB read (no Google API call):
     * who the (primary) writer is and whether they still need to reconnect for the
     * write scopes.
     *
     * @return array{enabled:bool,writer_id:?int,writer_name:?string,connected:bool,needs_reconnect:bool,reason:string}
     */
    public function status(): array
    {
        $writer = $this->writeUser();
        if ($writer) {
            return [
                'enabled' => true,
                'writer_id' => $writer->id,
                'writer_name' => $writer->name,
                'connected' => true,
                'needs_reconnect' => false,
                'reason' => 'Auto-syncing to ' . $writer->name . "'s Google Drive.",
            ];
        }

        // No write-scoped account — surface the first configured writer (connected or
        // not) so the banner can name who should (re)connect.
        $first = $this->candidates()[0] ?? $this->firstConfiguredUser();
        $connected = (bool) ($first && $first->google_access_token);

        return [
            'enabled' => false,
            'writer_id' => $first?->id,
            'writer_name' => $first?->name,
            'connected' => $connected,
            'needs_reconnect' => $connected, // connected, but missing the Drive/Sheets scopes
            'reason' => $first
                ? ($connected
                    ? $first->name . ' must reconnect Google to grant Drive + Sheets access.'
                    : $first->name . ' must connect Google to enable auto-sync.')
                : 'No travel-ledger writer is configured.',
        ];
    }

    /** Configured writers (config order) that have connected Google at all. */
    private function candidates(): array
    {
        $candidates = [];
        foreach ((array) config('travel_expenses.writer_user_ids', []) as $id) {
            $u = User::find((int) $id);
            if ($u && $u->google_access_token) {
                $candidates[] = $u;
            }
        }

        return $candidates;
    }

    /** The first configured writer id as a User even if not connected (for the banner). */
    private function firstConfiguredUser(): ?User
    {
        $ids = (array) config('travel_expenses.writer_user_ids', []);
        $id = $ids[0] ?? null;

        return $id ? User::find((int) $id) : null;
    }
}
