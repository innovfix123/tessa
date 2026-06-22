<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Smrithy (#67) moves out of HR Operations and becomes a Malayalam Support team
 * member on the Only Care project, reporting to Bala (#2, COO) — a clone of the
 * other probation Only Care hires Rachita (#64) and Dhanalakshmi (#65).
 *
 * Three facts change; everything else follows automatically:
 *   1. role_id 31 (hr_operations) -> 30 (customer_support_executive). Role 30 is a
 *      bare/least-privilege IC role (in Role::IC_SLUGS) that keeps feature.daily_reports
 *      but drops feature.employees / feature.hr_dashboard, and every SLUG_HR controller
 *      allow-list keys off the hr_operations slug — so all HR access falls away with the
 *      role. No permission rows or AgileService pin needed (peers 64/65 have neither).
 *   2. reporting_manager_id 1 (JP) -> 2 (Bala). This alone routes leave approvals
 *      (LeaveService->reportingManager), the Daily Reports tab (Bala's
 *      getAllowedUserIdsForUser scope), and the Friday review to Bala.
 *   3. Only Care (project #5) pivot row so Daily Reports renders "Smrithy (Only Care)".
 *
 * Her two daily-report KPI definitions are re-grouped HR -> Support so they don't render
 * under an "HR" header in Bala's view (the fields themselves are generic and unchanged).
 *
 * Her free-text designation (the public-facing label shown in the sidebar via
 * DashboardController roleName = designation ?: role name) is set "HR Operations" ->
 * "Malayalam Support" — independent of the shared customer_support_executive role.
 *
 * project_assignments has UNIQUE(user_id, project_id); insertOrIgnore is idempotent.
 * user_id is a signed INT FK to users.id.
 */
return new class extends Migration
{
    private const SMRITHY = 67;
    private const BALA = 2;
    private const ONLY_CARE = 5;
    private const ROLE_CUSTOMER_SUPPORT = 30; // customer_support_executive
    private const ROLE_HR_OPERATIONS = 31;    // hr_operations
    private const JP = 1;

    public function up(): void
    {
        DB::table('users')->where('id', self::SMRITHY)->update([
            'role_id'              => self::ROLE_CUSTOMER_SUPPORT,
            'reporting_manager_id' => self::BALA,
            'designation'          => 'Malayalam Support',
        ]);

        DB::table('project_assignments')->insertOrIgnore([
            'user_id'    => self::SMRITHY,
            'project_id' => self::ONLY_CARE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('kpi_definitions')
            ->where('user_id', self::SMRITHY)
            ->where('group_name', 'HR')
            ->update(['group_name' => 'Support', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('users')->where('id', self::SMRITHY)->update([
            'role_id'              => self::ROLE_HR_OPERATIONS,
            'reporting_manager_id' => self::JP,
            'designation'          => 'HR Operations',
        ]);

        DB::table('project_assignments')
            ->where('user_id', self::SMRITHY)
            ->where('project_id', self::ONLY_CARE)
            ->delete();

        DB::table('kpi_definitions')
            ->where('user_id', self::SMRITHY)
            ->where('group_name', 'Support')
            ->update(['group_name' => 'HR', 'updated_at' => now()]);
    }
};
