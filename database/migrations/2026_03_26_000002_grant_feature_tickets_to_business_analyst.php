<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * business_analyst did not exist when 2026_03_24_000002_add_tickets_feature_permission ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = DB::table('roles')->where('slug', 'business_analyst')->first();
        if (! $role) {
            return;
        }

        $exists = DB::table('permissions')
            ->where('role_id', $role->id)
            ->where('permission', 'feature.tickets')
            ->exists();

        if (! $exists) {
            $now = now();
            DB::table('permissions')->insert([
                'role_id' => $role->id,
                'permission' => 'feature.tickets',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $role = DB::table('roles')->where('slug', 'business_analyst')->first();
        if (! $role) {
            return;
        }

        DB::table('permissions')
            ->where('role_id', $role->id)
            ->where('permission', 'feature.tickets')
            ->delete();
    }
};
