<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/*
 * Make JP's (#1, CEO) direct reports exactly the 7 in public/shared/org.js's
 * top block (and the org-chart screenshot): Bala #2 (COO), Sneha Sunoj #5,
 * Nandha #3 (CMO), Ayush #4 (CFO), Yuvanesh #34, Fida Taneem #41, Akshara #61.
 *
 * reporting_manager_id alone drives Friday Work-Quality ratings, the manager
 * Daily Reports tab, leave approval/rejection + the pending queue/badge, and the
 * new KPI Report weekly-note filling — so these pointers route ALL of those to
 * JP for his seven reports.
 *
 *   Bala #2, Nandha #3, Ayush #4   ← were NULL (the leadership null-manager
 *                                     footgun) → now JP. Their leave/KPI duties
 *                                     route to JP naturally instead of via the
 *                                     special NULL→JP fallbacks.
 *   Sneha Sunoj #5, Yuvanesh #34, Fida #41, Akshara #61 — already JP (no-op,
 *                                     set explicitly so the migration is idempotent).
 *
 * "Any person other than these is removed from JP": at migration time the only
 * other user pointing at JP was Sneha Prathap #42. org.js already lists her
 * under Yuvanesh (engineeringRows, no redirect) and kpis.html says "Yuvanesh
 * manages … Sneha Prathap" — the DB was simply stale. So #42 → Yuvanesh #34,
 * bringing the DB in line with the canonical chart. Her 9 historical
 * manager_work_reviews (all by JP) stay put: KraScorecardService averages a
 * subordinate's review rows manager-agnostically, and we are NOT in a Fri–Sun
 * window (Mon 2026-06-15), so there is no in-flight week to hand over.
 *
 * NOT changed: leadership stays KRA-excluded (config/kra_exclusions [1,2,3,4])
 * and review-exempt (config/review.exempt_roles coo/cmo/cfo), so #2/#3/#4 still
 * never appear as ratees — even though they now report to JP. The KPI
 * filler_overrides [2,3,4 => 1] become redundant (reporting_manager_id already
 * yields JP) but are kept as a defensive fallback.
 */
return new class extends Migration
{
    private const JP = 1;
    private const YUVANESH = 34;

    /** JP's seven direct reports per org.js / the screenshot. */
    private const JP_REPORTS = [2, 3, 4, 5, 34, 41, 61];

    /** Only other user reporting to JP at migration time → moves to Yuvanesh. */
    private const SNEHA_PRATHAP = 42;

    public function up(): void
    {
        User::whereIn('id', self::JP_REPORTS)
            ->update(['reporting_manager_id' => self::JP]);

        // Remove the only non-listed person from JP, onto her org.js manager.
        User::where('id', self::SNEHA_PRATHAP)
            ->where('reporting_manager_id', self::JP)
            ->update(['reporting_manager_id' => self::YUVANESH]);
    }

    public function down(): void
    {
        // Restore the leadership trio to NULL (their pre-migration state).
        User::whereIn('id', [2, 3, 4])->update(['reporting_manager_id' => null]);
        // Sneha Prathap back under JP.
        User::where('id', self::SNEHA_PRATHAP)
            ->where('reporting_manager_id', self::YUVANESH)
            ->update(['reporting_manager_id' => self::JP]);
        // #5/#34/#41/#61 were already JP before this migration — leave them.
    }
};
