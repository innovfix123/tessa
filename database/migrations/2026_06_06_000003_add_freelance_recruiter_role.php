<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the "Freelance Recruiter" role (slug 'freelance_recruiter') so the two
 * external recruiters (Yashasvi, Rohit) can be created as users and log into a
 * deliberately stripped portal that shows ONLY the Hiring tab.
 *
 * This is a BARE role — no permission rows. The Hiring sidebar tab is granted
 * by role/config in DashboardController::roleConfig() (mirroring the Bills
 * pattern), NOT by a feature.* permission, so nothing needs seeding here.
 * Because the role lacks feature.dashboard, the daily sign-in gate never
 * applies to it (the gate keys off the Dashboard tab). Access to /api/hiring is
 * re-checked in HiringController regardless.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('roles')->where('slug', 'freelance_recruiter')->exists()) {
            DB::table('roles')->insert([
                'name' => 'Freelance Recruiter',
                'slug' => 'freelance_recruiter',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $role = DB::table('roles')->where('slug', 'freelance_recruiter')->first();
        if ($role) {
            DB::table('permissions')->where('role_id', $role->id)->delete();
            DB::table('roles')->where('id', $role->id)->delete();
        }
    }
};
