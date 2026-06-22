<?php

namespace App\Services;

use App\Models\User;

/**
 * Resolves which connected HR member's Google account Tessa writes through for the
 * HR sheet upsert (Feature 5) + document upload to Drive (Feature 6). Returns the
 * first user in config('services.google.hr_writer_ids') that has a live Google
 * connection. None connected => null, and the sync services no-op (dormant-safe).
 *
 * This replaces the dormant service-account path — the user chose to write via an
 * HR member's "Connect Google" token instead of provisioning a service account.
 */
class GoogleHrWriter
{
    /**
     * The HR writer to use. Prefers (in config order) one that has actually granted
     * the WRITE scopes (Drive + Sheets) — so reconnecting EITHER Meghana or Akshara
     * activates the sync. Falls back to the first merely-connected account (writes
     * fail gracefully until someone reconnects). Null when none are connected.
     */
    public function user(): ?User
    {
        $candidates = [];
        foreach ((array) config('services.google.hr_writer_ids', []) as $id) {
            $u = User::find((int) $id);
            if ($u && $u->google_access_token) {
                $candidates[] = $u;
            }
        }

        foreach ($candidates as $u) {
            if (self::hasWriteScopes($u)) {
                return $u;
            }
        }

        return $candidates[0] ?? null;
    }

    /** True when the user's granted Google scopes include Drive (write) + Sheets. */
    public static function hasWriteScopes(User $u): bool
    {
        $s = (string) $u->google_scopes;

        return str_contains($s, 'auth/spreadsheets')
            && (bool) preg_match('#auth/drive(\s|$)#', $s);
    }

    public function hasWriter(): bool
    {
        return $this->user() !== null;
    }

    /** A GoogleUserService bound to the writer (token auto-refreshed), or null. */
    public function client(): ?GoogleUserService
    {
        $u = $this->user();

        return $u ? GoogleUserService::forUser($u) : null;
    }
}
