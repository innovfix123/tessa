<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $viewRoles = ['ceo', 'coo', 'cfo', 'cmo', 'tech_lead'];
        $manageRoles = ['ceo', 'tech_lead'];
        $now = now();

        foreach ($viewRoles as $slug) {
            $role = DB::table('roles')->where('slug', $slug)->first();
            if (!$role) {
                continue;
            }
            $exists = DB::table('permissions')
                ->where('role_id', $role->id)
                ->where('permission', 'feature.releases')
                ->exists();
            if (!$exists) {
                DB::table('permissions')->insert([
                    'role_id' => $role->id,
                    'permission' => 'feature.releases',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        foreach ($manageRoles as $slug) {
            $role = DB::table('roles')->where('slug', $slug)->first();
            if (!$role) {
                continue;
            }
            $exists = DB::table('permissions')
                ->where('role_id', $role->id)
                ->where('permission', 'releases.manage')
                ->exists();
            if (!$exists) {
                DB::table('permissions')->insert([
                    'role_id' => $role->id,
                    'permission' => 'releases.manage',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->where('permission', 'feature.releases')->delete();
        DB::table('permissions')->where('permission', 'releases.manage')->delete();
    }
};
