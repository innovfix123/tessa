<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $baRole = DB::table('roles')->where('slug', 'business_analyst')->first();
        if (! $baRole) {
            $baRoleId = DB::table('roles')->insertGetId([
                'name' => 'Business Analyst',
                'slug' => 'business_analyst',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $baRoleId = $baRole->id;
        }

        $permissions = [
            'feature.daily_reports',
            'feature.kpi',
            'feature.dashboard',
            'feature.meetings',
            'feature.calendar',
            'feature.org',
            'feature.templates',
            'daily_report.edit',
            'kpi.edit_entry',
            'template.manage',
            'meeting.access',
            'org.view',
        ];

        foreach ($permissions as $permission) {
            $exists = DB::table('permissions')
                ->where('role_id', $baRoleId)
                ->where('permission', $permission)
                ->exists();

            if (! $exists) {
                DB::table('permissions')->insert([
                    'role_id' => $baRoleId,
                    'permission' => $permission,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $sneha = User::where('email', 'sneha@innovfix.in')->first();

        $existing = User::where('email', 'meghana@innovfix.in')->first();
        if (! $existing) {
            DB::table('users')->insert([
                'name' => 'Meghana',
                'email' => 'meghana@innovfix.in',
                'password_hash' => password_hash('12345678', PASSWORD_BCRYPT),
                'role_id' => $baRoleId,
                'reporting_manager_id' => $sneha?->id,
                'is_active' => true,
            ]);
        }
    }

    public function down(): void
    {
        User::where('email', 'meghana@innovfix.in')->delete();

        $baRole = Role::where('slug', 'business_analyst')->first();
        if ($baRole) {
            DB::table('permissions')->where('role_id', $baRole->id)->delete();
            DB::table('roles')->where('slug', 'business_analyst')->delete();
        }
    }
};
