<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/*
 * Align Sneha Sunoj's Hima org (Product · Support · N. India Growth) to the org
 * chart in public/shared/org.js / the screenshot. reporting_manager_id alone
 * drives leave/permission approval + the pending queue/badge, the manager Daily
 * Reports tab, and Friday Work-Quality (KRA) ratings — so flipping these
 * pointers moves all those duties to the right managers.
 *
 *   Anindita #17           ← Sneha #5   (was → Nandha #3): joins the Hima org.
 *   Gousia #26, Reshma #28,
 *   Anjali Bhatt #48,
 *   Smrithy #67            ← Deeksha #25 (were → Sneha #5): under their Team Lead.
 *   Gargi Bisht #57        ← Anindita #17 (was → Nandha #3): the dotted line
 *                            (secondary_manager_id=17) becomes the primary one.
 *
 * No role/permission change is needed: Deeksha (technical_support, role 16) and
 * Anindita (growth_manager, role 9) already carry feature.daily_reports /
 * feature.signoff / feature.kpi / kpi.edit_entry, and the Friday-review surface
 * is gated only by having rateable subordinates.
 *
 * Side-effects handled here:
 *  - Gargi #57 had secondary_manager_id=17 (Anindita); now that Anindita is her
 *    PRIMARY manager that dotted line is redundant → null it (mirrors the Anaz
 *    handling in 2026_06_12_000001). The config/manager_ratings.php Gargi→Anindita
 *    rater override likewise becomes redundant and is removed in that file.
 *  - In-flight Friday review: this runs Mon 2026-06-15, OUTSIDE the Fri–Sun
 *    rating window, so there is no current-week handover. Historical
 *    manager_work_reviews rows stay with their original rater (KraScorecardService
 *    averages a subordinate's rows manager-agnostically). Clean handoff: NO
 *    secondary_manager_id dotted line for Sneha over the 4 support folks.
 */
return new class extends Migration
{
    private const SNEHA = 5;
    private const NANDHA = 3;
    private const DEEKSHA = 25;
    private const ANINDITA = 17;
    private const GARGI = 57;

    /** Language-support folks moving from Sneha to their Team Lead, Deeksha. */
    private const SUPPORT_TEAM = [26, 28, 48, 67]; // Gousia, Reshma, Anjali Bhatt, Smrithy

    public function up(): void
    {
        // Anindita: Nandha → Sneha
        User::where('id', self::ANINDITA)
            ->where('reporting_manager_id', self::NANDHA)
            ->update(['reporting_manager_id' => self::SNEHA]);

        // 4 language-support folks: Sneha → Deeksha
        User::whereIn('id', self::SUPPORT_TEAM)
            ->where('reporting_manager_id', self::SNEHA)
            ->update(['reporting_manager_id' => self::DEEKSHA]);

        // Gargi: Nandha → Anindita, then clear the now-redundant dotted line.
        User::where('id', self::GARGI)
            ->where('reporting_manager_id', self::NANDHA)
            ->update(['reporting_manager_id' => self::ANINDITA]);
        User::where('id', self::GARGI)
            ->where('secondary_manager_id', self::ANINDITA)
            ->update(['secondary_manager_id' => null]);
    }

    public function down(): void
    {
        // Revert only rows still pointing where up() left them, so a later manual
        // reassignment isn't clobbered by a rollback (mirrors the Iksha/Laxmi and
        // marketing-reorg migrations' guard style).
        User::where('id', self::GARGI)
            ->whereNull('secondary_manager_id')
            ->update(['secondary_manager_id' => self::ANINDITA]);
        User::where('id', self::GARGI)
            ->where('reporting_manager_id', self::ANINDITA)
            ->update(['reporting_manager_id' => self::NANDHA]);

        User::whereIn('id', self::SUPPORT_TEAM)
            ->where('reporting_manager_id', self::DEEKSHA)
            ->update(['reporting_manager_id' => self::SNEHA]);

        User::where('id', self::ANINDITA)
            ->where('reporting_manager_id', self::SNEHA)
            ->update(['reporting_manager_id' => self::NANDHA]);
    }
};
