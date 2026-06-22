<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roles = DB::table('roles')->pluck('id');
        $now = now();

        foreach ($roles as $roleId) {
            $exists = DB::table('permissions')
                ->where('role_id', $roleId)
                ->where('permission', 'feature.signoff')
                ->exists();

            if (!$exists) {
                DB::table('permissions')->insert([
                    'role_id' => $roleId,
                    'permission' => 'feature.signoff',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->where('permission', 'feature.signoff')->delete();
    }
};
