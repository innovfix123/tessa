<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Gives Krishnan a dotted-line (secondary) view of Anas's daily reports.
 *
 * Anas (#18) keeps reporting to Nandha (#3) as his primary manager — leaves,
 * Friday Work-Quality ratings, tasks and attendance all still resolve from
 * reporting_manager_id and are unaffected. Setting secondary_manager_id only
 * widens the Daily Reports team picker in DashboardController and the paired
 * ProjectRoleService::canAccessUserDailyReport() gate, so Krishnan also sees
 * Anas's daily-report tab.
 *
 * Rationale: Anas is the receiver end of the Krishnan-team video handoff flow
 * (see 2026_05_24_000017_backfill_video_handoff_choices) — Krishnan needs
 * visibility into Anas's videos_delivered report without owning his reviews.
 *
 * Same dotted-line pattern as Gargi (#57): primary manager Nandha, secondary
 * manager Anindita.
 */
return new class extends Migration
{
    private const ANAS_USER_ID = 18;
    private const KRISHNAN_USER_ID = 20;

    public function up(): void
    {
        User::where('id', self::ANAS_USER_ID)
            ->update(['secondary_manager_id' => self::KRISHNAN_USER_ID]);
    }

    public function down(): void
    {
        // Only clear it if it's still pointing at Krishnan, so a later
        // reassignment isn't clobbered by a rollback.
        User::where('id', self::ANAS_USER_ID)
            ->where('secondary_manager_id', self::KRISHNAN_USER_ID)
            ->update(['secondary_manager_id' => null]);
    }
};
