<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Nitha Sheri (#66) was onboarded misconfigured and does not match her Only Care team
 * under Bala (#2, COO) — the other probation Only Care hires Suwetha S (#50, role 16),
 * Rachita (#64) and Dhanalakshmi (#65). This realigns her so her daily reports AND her
 * portal look like the team. Her reporting_manager_id (2, Bala) and department_id (4,
 * Operations) are already correct, and she is already in the org chart (public/shared/org.js).
 *
 * Three facts change:
 *   1. role_id 29 (team_lead_operations) -> 16 (technical_support). She was on a *lead*
 *      role; Suwetha is on 16. Roles 16/29/30 carry an identical 17 permission rows, so the
 *      sidebar feature set is unchanged — but the role drives (a) the displayed role label
 *      and (b) the KPI Report tab: config/kpi_report.php excludes 'technical_support' (16)
 *      except Deeksha #25, while 29/30 are not excluded. Moving to 16 drops the stray KPI
 *      Report tab she shouldn't have, matching Suwetha. No permission rows to copy (perms are
 *      role-scoped) and no hardcoded team_lead_operations allow-list to leave behind.
 *   2. Only Care (project #5) pivot row so Daily Reports renders "Nitha Sheri (Only Care)".
 *      She currently has no project_assignments row. UNIQUE(user_id, project_id) =>
 *      insertOrIgnore is idempotent. user_id is a signed INT FK to users.id.
 *   3. Daily-report KPI definitions: her bespoke 16-row set (3 numbered "Support" groups,
 *      created_by 5) is retired and replaced with the team's 2-row "Creators availability"
 *      template cloned from Suwetha #50 (_group_init + increase_active_creators, agg 'latest',
 *      created_by 2).
 *
 * kpi_definitions uses SoftDeletes + effective_from versioning, and the daily-report grid
 * always reads withTrashed()->visibleForWeek($weekKey) (a row shows for a week when
 * effective_from is null/<= weekKey AND deleted_at is null/>= weekKey). So the bespoke rows
 * are soft-deleted with deleted_at backdated to 2026-06-14 (the day before this week's
 * Monday, 2026-06-15): they vanish from the current week onward but stay visible in her
 * Jun 1 history week (she has daily_reports for Jun 1-3 against those field_keys). The new
 * metric gets effective_from 2026-06-15 so it appears now without polluting that history week.
 *
 * Designation is left as "Malayalam Technical Support" (accurate, descriptive). users has no
 * updated_at column — never set it on the users row.
 */
return new class extends Migration
{
    private const NITHA = 66;
    private const ROLE_TECH_SUPPORT = 16;    // technical_support (Suwetha)
    private const ROLE_TEAM_LEAD_OPS = 29;   // team_lead_operations (her current, stray)
    private const ONLY_CARE = 5;             // project
    private const TEAM_GROUP = 'Creators availability';
    private const RETIRE_AT = '2026-06-14 00:00:00'; // day before this week's Monday
    private const METRIC_EFFECTIVE_FROM = '2026-06-15'; // this week's Monday

    public function up(): void
    {
        // 1. Role: team_lead_operations -> technical_support (manager/dept already correct).
        DB::table('users')->where('id', self::NITHA)->update([
            'role_id' => self::ROLE_TECH_SUPPORT,
        ]);

        // 2. Only Care project assignment (idempotent).
        DB::table('project_assignments')->insertOrIgnore([
            'user_id'    => self::NITHA,
            'project_id' => self::ONLY_CARE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3a. Retire her bespoke KPI rows (everything except the team template) so they drop
        //     out of the current week but remain in her Jun-1 history week. Excluding the
        //     team group keeps this idempotent across re-runs.
        DB::table('kpi_definitions')
            ->where('user_id', self::NITHA)
            ->where('group_name', '!=', self::TEAM_GROUP)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => self::RETIRE_AT, 'updated_at' => now()]);

        // 3b. Add the team's 2-row "Creators availability" template (clone of Suwetha #50).
        $rows = [
            [
                'group_name'     => self::TEAM_GROUP,
                'field_key'      => '_group_init',
                'field_label'    => '',
                'aggregation'    => null,
                'input_type'     => 'text',
                'sort_order'     => 0,
                'effective_from' => null,
            ],
            [
                'group_name'     => self::TEAM_GROUP,
                'field_key'      => 'increase_active_creators',
                'field_label'    => 'Increase Active Creators',
                'aggregation'    => 'latest',
                'input_type'     => 'text',
                'sort_order'     => 1,
                'effective_from' => self::METRIC_EFFECTIVE_FROM,
            ],
        ];
        foreach ($rows as $row) {
            $exists = DB::table('kpi_definitions')
                ->where('user_id', self::NITHA)
                ->where('group_name', self::TEAM_GROUP)
                ->where('field_key', $row['field_key'])
                ->whereNull('deleted_at')
                ->exists();
            if (! $exists) {
                DB::table('kpi_definitions')->insert($row + [
                    'user_id'    => self::NITHA,
                    'created_by' => 2,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('users')->where('id', self::NITHA)->update([
            'role_id' => self::ROLE_TEAM_LEAD_OPS,
        ]);

        DB::table('project_assignments')
            ->where('user_id', self::NITHA)
            ->where('project_id', self::ONLY_CARE)
            ->delete();

        // Remove the inserted team rows, then restore her retired bespoke rows.
        DB::table('kpi_definitions')
            ->where('user_id', self::NITHA)
            ->where('group_name', self::TEAM_GROUP)
            ->delete();

        DB::table('kpi_definitions')
            ->where('user_id', self::NITHA)
            ->whereNotNull('deleted_at')
            ->update(['deleted_at' => null, 'updated_at' => now()]);
    }
};
