<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $techLeadRole = DB::table('roles')->where('slug', 'tech_lead')->first();
        if (!$techLeadRole) {
            return;
        }

        $exists = DB::table('permissions')
            ->where('role_id', $techLeadRole->id)
            ->where('permission', 'feature.calendar')
            ->exists();

        if (!$exists) {
            DB::table('permissions')->insert([
                'role_id' => $techLeadRole->id,
                'permission' => 'feature.calendar',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $techLeadRole = DB::table('roles')->where('slug', 'tech_lead')->first();
        if ($techLeadRole) {
            DB::table('permissions')
                ->where('role_id', $techLeadRole->id)
                ->where('permission', 'feature.calendar')
                ->delete();
        }
    }
};
