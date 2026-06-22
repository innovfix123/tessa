<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Adds "HR Operations" to the roles table so it is selectable in the
        // "Add New Team Member" Role dropdown (which is live from
        // Role::orderBy('name') in Api/HR/EmployeeController::index).
        //
        // This role is a functional clone of the HR role (slug 'hr') — same
        // Tessa portal as Akshara. The portal is driven by two layers:
        //   1. permission rows  -> cloned below (and re-affirmed by PermissionSeeder)
        //   2. hardcoded SLUG_HR allowlists in the HR API controllers
        //      (DesignationController/EmployeeController/HRDashboardController/
        //      LetterController/DepartmentController), DashboardController,
        //      MeetingAttendanceController and NotifyProbationEnding -> those
        //      were updated in code to also accept 'hr_operations'.
        if (! DB::table('roles')->where('slug', 'hr_operations')->exists()) {
            DB::table('roles')->insert([
                'name' => 'HR Operations',
                'slug' => 'hr_operations',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Clone the HR role's CURRENT permission rows so a migrate-only run on
        // an existing database yields a working portal immediately (HR already
        // carries feature.signoff / feature.tickets etc. from earlier
        // migrations). On a fresh install HR has only a partial set at migrate
        // time — PermissionSeeder completes the mirror after seeding. Both
        // paths are idempotent (insert only the rows that are missing).
        $hr = DB::table('roles')->where('slug', 'hr')->first();
        $hrOps = DB::table('roles')->where('slug', 'hr_operations')->first();
        if ($hr && $hrOps) {
            $hrPermissions = DB::table('permissions')->where('role_id', $hr->id)->pluck('permission');
            $existing = DB::table('permissions')->where('role_id', $hrOps->id)->pluck('permission')->all();
            $now = now();
            $rows = [];
            foreach ($hrPermissions as $permission) {
                if (! in_array($permission, $existing, true)) {
                    $rows[] = [
                        'role_id' => $hrOps->id,
                        'permission' => $permission,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            if ($rows) {
                DB::table('permissions')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        $hrOps = DB::table('roles')->where('slug', 'hr_operations')->first();
        if ($hrOps) {
            DB::table('permissions')->where('role_id', $hrOps->id)->delete();
            DB::table('roles')->where('id', $hrOps->id)->delete();
        }
    }
};
