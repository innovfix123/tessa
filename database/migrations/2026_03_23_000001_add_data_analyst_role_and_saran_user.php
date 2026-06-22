<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $dataAnalystRole = DB::table('roles')->where('slug', 'data_analyst')->first();
        if (! $dataAnalystRole) {
            $dataAnalystRoleId = DB::table('roles')->insertGetId([
                'name' => 'Data Analyst',
                'slug' => 'data_analyst',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $dataAnalystRoleId = $dataAnalystRole->id;
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
                ->where('role_id', $dataAnalystRoleId)
                ->where('permission', $permission)
                ->exists();

            if (! $exists) {
                DB::table('permissions')->insert([
                    'role_id' => $dataAnalystRoleId,
                    'permission' => $permission,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $yuvanesh = User::where('email', 'yuvanesh@innovfix.in')->first();

        $existingSaran = User::where('email', 'saran@innovfix.in')->first();
        if (! $existingSaran) {
            DB::table('users')->insert([
                'name' => 'Saran',
                'email' => 'saran@innovfix.in',
                'password_hash' => password_hash('12345678', PASSWORD_BCRYPT),
                'role_id' => $dataAnalystRoleId,
                'reporting_manager_id' => $yuvanesh?->id,
                'is_active' => true,
            ]);
        }
    }

    public function down(): void
    {
        User::where('email', 'saran@innovfix.in')->delete();

        $dataAnalystRole = Role::where('slug', 'data_analyst')->first();
        if ($dataAnalystRole) {
            DB::table('permissions')->where('role_id', $dataAnalystRole->id)->delete();
            DB::table('roles')->where('slug', 'data_analyst')->delete();
        }
    }
};
