<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/*
 * Reconcile Bala's (#2, COO) roster to the org chart in public/shared/org.js /
 * the screenshot: Product Operations there is exactly Suwetha S #50, Rachita
 * #64, Dhanalakshmi #65, Nitha Sheri #66 and Dhanush #13 — all already report
 * to Bala. The DB had a 6th, non-charted person under Bala: Tamil Arasan #12,
 * whom org.js lists as "Product Designer" under Yuvanesh's engineering dept
 * (engineeringRows, dept head "Yuvanesh — Tech Lead"). Move him to Yuvanesh #34
 * so the DB matches the chart — same handling as Sneha Prathap #42 in
 * 2026_06_15_000005_set_jp_direct_reports.
 *
 * reporting_manager_id alone drives leave/permission approval + the pending
 * queue/badge, the manager Daily Reports tab, and Friday Work-Quality ratings,
 * so this one flip routes all of those to Yuvanesh. No role/permission change is
 * needed: Yuvanesh already manages an engineering team, so the manager surfaces
 * (relationship-gated) are already active for him.
 *
 * Runs Mon 2026-06-15, OUTSIDE the Fri–Sun rating window, so there is no
 * current-week review to hand over; Tamil Arasan's historical manager_work_reviews
 * rows stay with Bala (KraScorecardService averages a subordinate's rows
 * manager-agnostically). Dhanush #13's secondary_manager_id=5 (Sneha) is a
 * deliberate dual-manager arrangement (2026_06_02_150000) the chart doesn't
 * contradict — left untouched.
 */
return new class extends Migration
{
    private const TAMIL_ARASAN = 12;
    private const BALA = 2;
    private const YUVANESH = 34;

    public function up(): void
    {
        User::where('id', self::TAMIL_ARASAN)
            ->where('reporting_manager_id', self::BALA)
            ->update(['reporting_manager_id' => self::YUVANESH]);
    }

    public function down(): void
    {
        // Revert only if still pointing where up() left him, so a later manual
        // reassignment isn't clobbered by a rollback.
        User::where('id', self::TAMIL_ARASAN)
            ->where('reporting_manager_id', self::YUVANESH)
            ->update(['reporting_manager_id' => self::BALA]);
    }
};
