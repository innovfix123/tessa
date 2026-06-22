<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Deactivate duplicate seeder users that cause name-based lookup failures.
     * IDs 14, 15, 29, 30, 31 are role-specific duplicates (coo@, cmo@, ceo@, cfo@, ops@).
     */
    public function up(): void
    {
        DB::table('users')
            ->whereIn('id', [14, 15, 29, 30, 31])
            ->update(['is_active' => false]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('users')
            ->whereIn('id', [14, 15, 29, 30, 31])
            ->update(['is_active' => true]);
    }
};
