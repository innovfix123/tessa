<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/*
 * Pin the Ayush (#4, CFO), Fida Taneem (#41, Lead AI Engineer) and Yuvanesh
 * (#34, Tech Lead) subtrees to public/shared/org.js / the org-chart screenshot
 * (image copy 11.png) — both reporting line and job-title (designation). Same
 * mechanism as 2026_06_15_000008_align_nandha_marketing_to_chart.
 *
 * Verified on the live DB 2026-06-15: Ayush's and Fida's teams already match the
 * chart, and most of Yuvanesh's does too — so the only real changes are:
 *
 *   Reporting (chart nests them under Rishabh; Maari is even tagged "→ Rishabh"):
 *     Perumal #37   Yuvanesh #34 → Rishabh #35
 *     Maari   #38   Yuvanesh #34 → Rishabh #35
 *
 *   Titles (role_id left unchanged — chart labels are titles, not Tessa roles,
 *   mirroring the Kishore/Nandha bumps; changing role_id would flip KRA weights,
 *   roleRelation->name and permission sets):
 *     Yuvanesh #34      Tech Lead     → Tech Lead + Hima Strategist
 *     Rishabh  #35      Full Stack Dev → Team Lead
 *     Tamil Arasan #12  Product Manager → Product Designer  (keeps role_id 7)
 *
 * Everything else in the three teams is re-asserted to the chart (no-ops) so the
 * whole subtree is pinned in one place. No permission/KRA change is needed: the
 * managers holding/gaining reports already carry feature.daily_reports / signoff /
 * kpi / kpi.edit_entry (Rishabh role 22 already manages Barkha/Sumit, so adding
 * Perumal/Maari is free), and the Friday-review surface is gated only by having
 * subordinates.
 *
 * No in-flight Friday-review handover: this runs Mon 2026-06-15 (outside the
 * Fri–Sun rating window) and the two moved subordinates (#37, #38) have 0 rows for
 * the current week_key, so manager_work_reviews is untouched (their 9 historical
 * rows by Yuvanesh stay — KraScorecardService averages a subordinate's rows
 * manager-agnostically).
 *
 * Left as-is per the chart owner (active but not drawn on the chart): Karuna Behal
 * #54, Arun #90, Kruthi M Gowda #93 (the last has rm=NULL; her manager will be set
 * during her onboarding, separately). Ayush #4 / Fida #41 themselves are not in the
 * map — their → JP pointers are owned by 2026_06_15_000005_set_jp_direct_reports and
 * their titles already match; Yuvanesh #34 is in the map only because his title moves.
 *
 * NOTE: the users table has timestamps disabled (no updated_at column). Use the
 * Eloquent User model's ->update() (a query-builder mass update — also bypasses
 * model events), as prior reorg migrations do; never a raw
 * DB::table('users')->update([... 'updated_at' ...]).
 */
return new class extends Migration
{
    /** Chart truth: id => [reporting_manager_id, designation]. Active members only. */
    private const CHART = [
        // Ayush — CFO (Finance)
        32 => [4,  'Accountant'],                   // Shoyab            (→ Ayush)
        46 => [4,  "Founder's Office"],             // Irisha            (→ Ayush)

        // Fida Taneem — Lead AI Engineer (AI Platform & R&D)
        59 => [41, 'AI Intern'],                    // Bhuvan Prasad     (→ Fida)
        60 => [41, 'AI Intern'],                    // Bhoomika          (→ Fida)
        62 => [41, 'AI Intern'],                    // Soundarya Balaraddi (→ Fida)

        // Yuvanesh — Tech Lead + Hima Strategist (All App Development)
        34 => [1,  'Tech Lead + Hima Strategist'],  // Yuvanesh          (→ JP)   [TITLE]
        35 => [34, 'Team Lead'],                    // Rishabh           (→ Yuvanesh) [TITLE]
        37 => [35, 'Full Stack Dev'],               // Perumal           (→ Rishabh) [MOVE]
        39 => [35, 'Intern'],                       // Barkha Agarwal    (→ Rishabh)
        63 => [35, 'Full Stack Developer Intern'],  // Sumit             (→ Rishabh)
        38 => [35, 'Full Stack Dev'],               // Maari             (→ Rishabh) [MOVE]
        27 => [34, 'QA Lead'],                      // Ranjini           (→ Yuvanesh)
        23 => [27, 'QA Intern'],                    // Laxmi             (→ Ranjini)
        53 => [27, 'QA Intern'],                    // Iksha H S         (→ Ranjini)
        91 => [27, 'Content Moderator & QA'],       // Priyadharshini    (→ Ranjini)
        42 => [34, 'Gen AI Developer'],             // Sneha Prathap     (→ Yuvanesh)
        12 => [34, 'Product Designer'],             // Tamil Arasan      (→ Yuvanesh) [TITLE]
        44 => [34, 'Data Analyst'],                 // Saran             (→ Yuvanesh)
        87 => [44, 'Data Analyst Intern'],          // Prajwal           (→ Saran)
    ];

    /** Pre-migration reporting_manager_id for the rows this migration actually moves. */
    private const PRIOR_MANAGERS = [
        37 => 34, // Perumal: Rishabh → back to Yuvanesh
        38 => 34, // Maari:   Rishabh → back to Yuvanesh
    ];

    /** Pre-migration designation for the three titles this migration changes. */
    private const PRIOR_DESIGNATIONS = [
        34 => 'Tech Lead',
        35 => 'Full Stack Dev',
        12 => 'Product Manager',
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
        // Revert only what up() actually changed, each guarded on the value up() set
        // so a later manual edit isn't clobbered (mirrors the existing reorg migrations).
        foreach (self::PRIOR_MANAGERS as $id => $managerId) {
            User::where('id', $id)
                ->where('reporting_manager_id', self::CHART[$id][0])
                ->update(['reporting_manager_id' => $managerId]);
        }

        foreach (self::PRIOR_DESIGNATIONS as $id => $designation) {
            User::where('id', $id)
                ->where('designation', self::CHART[$id][1])
                ->update(['designation' => $designation]);
        }
    }
};
