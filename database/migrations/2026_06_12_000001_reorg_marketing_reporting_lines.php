<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Align the Performance-Marketing & Creative reporting lines (Nandha's org) to
 * the org chart in public/shared/org.js. reporting_manager_id alone drives
 * Friday Work-Quality ratings, the manager Daily Reports tab, leave/permission
 * approval + the pending queue/badge — so flipping these pointers moves all
 * those duties to the right managers. No role/permission change is needed:
 * Kishore (Content Creator role) and Anirudh (Performance Marketing role)
 * already carry feature.daily_reports / feature.signoff / feature.kpi, and the
 * Friday review surface is gated only by having rateable subordinates.
 *
 *   Anirudh #11   ← Swapna #55                       (was → Nandha #3)
 *   Kishore #51   ← Nehal #56, Fathima #52, Tiyasa #21,
 *                   Haripriya #49, Disha #40          (were → Krishnan #20)
 *   Krishnan #20  ← Sooraj #19, Anaz #18              (were → Nandha #3)
 *
 * Out of scope (left as-is, per the screenshot): Anindita #17 + Gargi #57 keep
 * reporting to Nandha; the config/manager_ratings.php Gargi→Anindita override
 * stays. Clean handoff: NO secondary_manager_id dotted-line for Krishnan over
 * the creators.
 *
 * Two side-effects handled here:
 *  - Anaz #18 had secondary_manager_id=20 (Krishnan); now that Krishnan is his
 *    PRIMARY manager that dotted line is redundant → null it.
 *  - Kishore #51 designation: "Content Creator" → "Content Lead — Hima" to match
 *    the chart and his new managerial role. Role (role_id 13) is left unchanged
 *    on purpose — it already has every manager feature and changing the role
 *    would flip unrelated surfaces (KRA weights, roleRelation->name, etc.).
 *  - In-flight Friday review: this migration runs inside the Fri/Sat/Sun rating
 *    window and Krishnan has ALREADY rated the 5 creators for week 2026-06-12.
 *    Reassign those 5 rows to Kishore so the current week hands over cleanly —
 *    otherwise Kishore's sign-off is blocked and the week double-counts in the
 *    creators' KRA (KraScorecardService averages all rows per subordinate,
 *    manager-agnostic). Past weeks stay with Krishnan (history preserved).
 */
return new class extends Migration
{
    private const NANDHA = 3;
    private const ANIRUDH = 11;
    private const KRISHNAN = 20;
    private const KISHORE = 51;

    private const CREATORS = [56, 52, 21, 49, 40]; // Nehal, Fathima, Tiyasa, Haripriya, Disha
    private const SWAPNA = 55;
    private const TO_KRISHNAN = [19, 18];           // Sooraj, Anaz
    private const ANAZ = 18;

    /** The in-flight Friday review week this migration is run in. */
    private const REVIEW_WEEK = '2026-06-12';

    public function up(): void
    {
        // 5 content creators: Krishnan → Kishore
        User::whereIn('id', self::CREATORS)
            ->where('reporting_manager_id', self::KRISHNAN)
            ->update(['reporting_manager_id' => self::KISHORE]);

        // Swapna: Nandha → Anirudh
        User::where('id', self::SWAPNA)
            ->where('reporting_manager_id', self::NANDHA)
            ->update(['reporting_manager_id' => self::ANIRUDH]);

        // Sooraj + Anaz: Nandha → Krishnan
        User::whereIn('id', self::TO_KRISHNAN)
            ->where('reporting_manager_id', self::NANDHA)
            ->update(['reporting_manager_id' => self::KRISHNAN]);

        // Anaz: clear now-redundant dotted line (was Krishnan, now his primary).
        User::where('id', self::ANAZ)
            ->where('secondary_manager_id', self::KRISHNAN)
            ->update(['secondary_manager_id' => null]);

        // Kishore: designation label only (keep role_id).
        User::where('id', self::KISHORE)
            ->where('designation', 'Content Creator')
            ->update(['designation' => 'Content Lead — Hima']);

        // Hand this week's already-submitted creator ratings to Kishore.
        DB::table('manager_work_reviews')
            ->where('week_key', self::REVIEW_WEEK)
            ->where('manager_id', self::KRISHNAN)
            ->whereIn('subordinate_id', self::CREATORS)
            ->update(['manager_id' => self::KISHORE]);
    }

    public function down(): void
    {
        // Revert only rows still pointing where up() left them, so a later manual
        // reassignment isn't clobbered by a rollback (mirrors the Iksha/Laxmi
        // migration's guard style).
        DB::table('manager_work_reviews')
            ->where('week_key', self::REVIEW_WEEK)
            ->where('manager_id', self::KISHORE)
            ->whereIn('subordinate_id', self::CREATORS)
            ->update(['manager_id' => self::KRISHNAN]);

        User::where('id', self::KISHORE)
            ->where('designation', 'Content Lead — Hima')
            ->update(['designation' => 'Content Creator']);

        User::where('id', self::ANAZ)
            ->whereNull('secondary_manager_id')
            ->update(['secondary_manager_id' => self::KRISHNAN]);

        User::whereIn('id', self::TO_KRISHNAN)
            ->where('reporting_manager_id', self::KRISHNAN)
            ->update(['reporting_manager_id' => self::NANDHA]);

        User::where('id', self::SWAPNA)
            ->where('reporting_manager_id', self::ANIRUDH)
            ->update(['reporting_manager_id' => self::NANDHA]);

        User::whereIn('id', self::CREATORS)
            ->where('reporting_manager_id', self::KISHORE)
            ->update(['reporting_manager_id' => self::KRISHNAN]);
    }
};
