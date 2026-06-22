<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ceoRole = DB::table('roles')->where('slug', 'ceo')->first();
        if (!$ceoRole) {
            return;
        }

        $exists = DB::table('permissions')
            ->where('role_id', $ceoRole->id)
            ->where('permission', 'feature.daily_reports')
            ->exists();

        if (!$exists) {
            DB::table('permissions')->insert([
                'role_id' => $ceoRole->id,
                'permission' => 'feature.daily_reports',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $ceoRole = DB::table('roles')->where('slug', 'ceo')->first();
        if ($ceoRole) {
            DB::table('permissions')
                ->where('role_id', $ceoRole->id)
                ->where('permission', 'feature.daily_reports')
                ->delete();
        }
    }
};
