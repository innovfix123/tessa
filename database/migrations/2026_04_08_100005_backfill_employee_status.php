<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Active full-time employees -> 'active'
        DB::table('users')
            ->where('is_active', true)
            ->where('employment_type', 'full_time')
            ->update(['employee_status' => 'active']);

        // Active interns -> 'intern'
        DB::table('users')
            ->where('is_active', true)
            ->where('employment_type', 'internship')
            ->update(['employee_status' => 'intern']);

        // Inactive users -> 'resigned'
        DB::table('users')
            ->where('is_active', false)
            ->update(['employee_status' => 'resigned']);

        // Users with no employment_type but active -> 'active'
        DB::table('users')
            ->where('is_active', true)
            ->whereNull('employment_type')
            ->update(['employee_status' => 'active']);
    }

    public function down(): void
    {
        // No rollback needed - original is_active still intact
    }
};
