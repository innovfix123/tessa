<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $managerSlugs = [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_TECH_LEAD];
        $now = now();

        foreach ($managerSlugs as $slug) {
            $role = DB::table('roles')->where('slug', $slug)->first();
            if (!$role) {
                continue;
            }

            $exists = DB::table('permissions')
                ->where('role_id', $role->id)
                ->where('permission', 'daily_report.edit')
                ->exists();

            if (!$exists) {
                DB::table('permissions')->insert([
                    'role_id' => $role->id,
                    'permission' => 'daily_report.edit',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $managerSlugs = [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_TECH_LEAD];
        $roleIds = DB::table('roles')->whereIn('slug', $managerSlugs)->pluck('id');

        DB::table('permissions')
            ->whereIn('role_id', $roleIds)
            ->where('permission', 'daily_report.edit')
            ->delete();
    }
};
