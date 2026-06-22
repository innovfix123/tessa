<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Smrithy (#67) moves out of Malayalam Support / Only Care under Bala (#2) into the
 * vacant Tamil Support slot under Sneha Sunoj (#5, Ops Manager) — the previous Tamil
 * Support, Nisha (#47), has resigned. This makes her record identical to the other
 * language-support peers (Deeksha #25 / Gousia #26 / Reshma #28 / Anjali #48), who are
 * all role 16 (technical_support), department 4 (Operations), reporting to #5, on Hima.
 *
 * Four user facts change:
 *   1. role_id 30 (customer_support_executive) -> 16 (technical_support). Both are bare
 *      IC roles with 17 permission rows, and neither is in config/kra_weights.php or the
 *      DashboardController title map, so this only aligns the displayed role name with
 *      peers — no permission rows to copy (peers are already on 16).
 *   2. reporting_manager_id 2 (Bala) -> 5 (Sneha Sunoj). Routes leave approvals
 *      (LeaveService->reportingManager), the Daily Reports tab scope and the Friday
 *      review to Sneha — same as every other language-support member.
 *   3. department_id 6 (HR) -> 4 (Operations). The 2026_06_11 migration never moved her
 *      out of HR; all support peers are dept 4.
 *   4. designation 'Malayalam Support' -> 'Tamil Support' (free-text sidebar label).
 *
 * Project pivot Only Care (#5) -> Hima (#1) so her Daily Reports project line matches the
 * active language-support peers. project_assignments has UNIQUE(user_id, project_id);
 * insertOrIgnore is idempotent; user_id is a signed INT FK to users.id.
 *
 * Her two generic daily-report KPI definitions (rows #433/#434: meetings_attended_today /
 * what_did_you_work_on_today, group 'Support', textareas) are re-pointed in place to the
 * language-support pattern cloned from Reshma #28: tickets_resolved (sum) +
 * avg_resolution_time_hrs (avg), group 'Tamil Support', text inputs, created_by 1.
 * Updating in place preserves the row ids and is exactly reversible.
 */
return new class extends Migration
{
    private const SMRITHY = 67;
    private const BALA = 2;
    private const SNEHA = 5;                  // Sneha Sunoj, Ops Manager
    private const ONLY_CARE = 5;              // project
    private const HIMA = 1;                   // project
    private const ROLE_TECH_SUPPORT = 16;     // technical_support (the peers)
    private const ROLE_CUSTOMER_SUPPORT = 30; // customer_support_executive
    private const DEPT_OPERATIONS = 4;
    private const DEPT_HR = 6;

    public function up(): void
    {
        DB::table('users')->where('id', self::SMRITHY)->update([
            'role_id'              => self::ROLE_TECH_SUPPORT,
            'reporting_manager_id' => self::SNEHA,
            'department_id'        => self::DEPT_OPERATIONS,
            'designation'          => 'Tamil Support',
        ]);

        // Project: Only Care -> Hima
        DB::table('project_assignments')
            ->where('user_id', self::SMRITHY)
            ->where('project_id', self::ONLY_CARE)
            ->delete();
        DB::table('project_assignments')->insertOrIgnore([
            'user_id'    => self::SMRITHY,
            'project_id' => self::HIMA,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // KPI defs: re-point the two generic rows to the language-support pattern.
        DB::table('kpi_definitions')
            ->where('user_id', self::SMRITHY)
            ->where('field_key', 'meetings_attended_today')
            ->update([
                'group_name'  => 'Tamil Support',
                'field_key'   => 'tickets_resolved',
                'field_label' => 'Tickets Resolved',
                'aggregation' => 'sum',
                'input_type'  => 'text',
                'created_by'  => 1,
                'updated_at'  => now(),
            ]);
        DB::table('kpi_definitions')
            ->where('user_id', self::SMRITHY)
            ->where('field_key', 'what_did_you_work_on_today')
            ->update([
                'group_name'  => 'Tamil Support',
                'field_key'   => 'avg_resolution_time_hrs',
                'field_label' => 'Avg Resolution Time (hrs)',
                'aggregation' => 'avg',
                'input_type'  => 'text',
                'created_by'  => 1,
                'updated_at'  => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('users')->where('id', self::SMRITHY)->update([
            'role_id'              => self::ROLE_CUSTOMER_SUPPORT,
            'reporting_manager_id' => self::BALA,
            'department_id'        => self::DEPT_HR,
            'designation'          => 'Malayalam Support',
        ]);

        DB::table('project_assignments')
            ->where('user_id', self::SMRITHY)
            ->where('project_id', self::HIMA)
            ->delete();
        DB::table('project_assignments')->insertOrIgnore([
            'user_id'    => self::SMRITHY,
            'project_id' => self::ONLY_CARE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('kpi_definitions')
            ->where('user_id', self::SMRITHY)
            ->where('field_key', 'tickets_resolved')
            ->update([
                'group_name'  => 'Support',
                'field_key'   => 'meetings_attended_today',
                'field_label' => 'Meetings attended today?',
                'aggregation' => null,
                'input_type'  => 'textarea',
                'created_by'  => null,
                'updated_at'  => now(),
            ]);
        DB::table('kpi_definitions')
            ->where('user_id', self::SMRITHY)
            ->where('field_key', 'avg_resolution_time_hrs')
            ->update([
                'group_name'  => 'Support',
                'field_key'   => 'what_did_you_work_on_today',
                'field_label' => 'What did u work on today?',
                'aggregation' => null,
                'input_type'  => 'textarea',
                'created_by'  => null,
                'updated_at'  => now(),
            ]);
    }
};
