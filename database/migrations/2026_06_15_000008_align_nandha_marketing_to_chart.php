<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/*
 * Pin every active member of Nandha's (#3, CMO) Performance-Marketing & Creative
 * org to public/shared/org.js / the org-chart screenshot — BOTH the reporting
 * line and the job-title (designation) the chart shows.
 *
 * Reporting lines were already brought to the chart by
 * 2026_06_12_000001_reorg_marketing_reporting_lines (creators→Kishore,
 * Swapna→Anirudh, Sooraj/Anaz→Krishnan, Kishore retitled) and
 * 2026_06_15_000005_set_jp_direct_reports (Nandha→JP). This migration RE-ASSERTS
 * those pointers (verified already matching on 2026-06-15, so they are no-ops)
 * so the whole subtree is pinned to the chart in one place, and additionally
 * syncs the four designations that still lagged the chart labels:
 *
 *   Anirudh #11       Performance Marketing  → Performance Marketing Lead
 *   Swapna M #55      Performance Marketer   → Junior Performance Marketer
 *   Krishnan #20      Content Lead           → Creative Head
 *   Sivaranjani N #58 Content Creator        → Content Lead — Only Care
 *
 * role_id is left unchanged for everyone (mirrors the Kishore #51 "Content Lead —
 * Hima" bump in the marketing reorg): the chart's labels are titles, not Tessa
 * roles, and changing role_id would flip unrelated surfaces (KRA weights,
 * roleRelation->name, permission sets). All these roles already carry the manager
 * features (feature.daily_reports / signoff / kpi / kpi.edit_entry), so the Friday
 * review + Daily Reports tab + leave/permission approval already flow correctly
 * via reporting_manager_id.
 *
 * No in-flight Friday-review handover: this runs Mon 2026-06-15 (outside the
 * Fri–Sun rating window) AND no reporting_manager_id actually changes, so
 * manager_work_reviews rows are untouched. Maanasi #22 reports to Krishnan in the
 * DB but is employee_status 'exited' since 2026-04-30 → correctly absent from the
 * chart, left as-is.
 *
 * NOTE: the users table has timestamps disabled (no updated_at column). Use the
 * Eloquent User model's ->update() (a query-builder mass update — also bypasses
 * model events), exactly as the prior reorg migrations do; never a raw
 * DB::table('users')->update([... 'updated_at' ...]).
 */
return new class extends Migration
{
    /** Chart truth: id => [reporting_manager_id, designation]. Active members only. */
    private const CHART = [
        11 => [3,  'Performance Marketing Lead'],   // Anirudh
        55 => [11, 'Junior Performance Marketer'],  // Swapna M       (→ Anirudh)
        20 => [3,  'Creative Head'],                // Krishnan
        51 => [20, 'Content Lead — Hima'],          // Kishore        (→ Krishnan)
        56 => [51, 'Content Creator'],              // Y Nehal        (→ Kishore)
        52 => [51, 'Content Creator'],              // Fathima K P
        21 => [51, 'Content Creator'],              // Tiyasa
        49 => [51, 'Content Creator'],              // Haripriya
        40 => [51, 'Content Creator'],              // Disha
        58 => [20, 'Content Lead — Only Care'],     // Sivaranjani N  (→ Krishnan)
        19 => [20, 'Graphic Designer'],             // Sooraj         (→ Krishnan)
        18 => [20, 'Video Editor'],                 // Anaz           (→ Krishnan)
    ];

    /** Designations to restore on rollback (only the four this migration changes). */
    private const PRIOR_DESIGNATIONS = [
        11 => 'Performance Marketing',
        55 => 'Performance Marketer',
        20 => 'Content Lead',
        58 => 'Content Creator',
    ];

    public function up(): void
    {
        foreach (self::CHART as $id => [$managerId, $designation]) {
            User::where('id', $id)->update([
                'reporting_manager_id' => $managerId,
                'designation'          => $designation,
            ]);
        }
    }

    public function down(): void
    {
        // Reporting lines were already at chart values before this migration, so only
        // the four retitled designations need reverting — guarded on the chart title so
        // a later manual retitle isn't clobbered (mirrors the existing reorg migrations).
        foreach (self::PRIOR_DESIGNATIONS as $id => $designation) {
            User::where('id', $id)
                ->where('designation', self::CHART[$id][1])
                ->update(['designation' => $designation]);
        }
    }
};
