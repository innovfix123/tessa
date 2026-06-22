<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->decimal('monthly_credit', 4, 1)->unsigned()->default(0)->after('default_days_per_year');
            $table->unsignedInteger('max_consecutive_days')->default(0)->after('monthly_credit');
            $table->unsignedInteger('carry_forward_cap')->default(0)->after('max_consecutive_days');
        });

        // Update Casual Leave: 1/month = 12/year, max 4 consecutive, carry forward up to 6
        DB::table('leave_types')->where('slug', 'casual')->update([
            'default_days_per_year' => 12,
            'monthly_credit' => 1,
            'max_consecutive_days' => 4,
            'carry_forward_cap' => 6,
        ]);

        // Update Sick Leave: 1/month = 12/year, max 4 consecutive, carry forward up to 6
        DB::table('leave_types')->where('slug', 'sick')->update([
            'default_days_per_year' => 12,
            'monthly_credit' => 1,
            'max_consecutive_days' => 4,
            'carry_forward_cap' => 6,
        ]);

        // Deactivate Emergency Leave (not in new policy)
        DB::table('leave_types')->where('slug', 'emergency')->update([
            'is_active' => false,
        ]);

        // Add Menstrual Leave: 1/month = 12/year, max 2 consecutive, carry forward up to 3
        DB::table('leave_types')->insertOrIgnore([
            'name' => 'Menstrual Leave',
            'slug' => 'menstrual',
            'default_days_per_year' => 12,
            'monthly_credit' => 1,
            'max_consecutive_days' => 2,
            'carry_forward_cap' => 3,
            'requires_approval' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Keep WFH as unlimited, no monthly credit
        DB::table('leave_types')->where('slug', 'wfh')->update([
            'monthly_credit' => 0,
            'max_consecutive_days' => 0,
            'carry_forward_cap' => 0,
        ]);
    }

    public function down(): void
    {
        // Restore Emergency Leave
        DB::table('leave_types')->where('slug', 'emergency')->update([
            'is_active' => true,
        ]);

        // Remove Menstrual Leave
        DB::table('leave_types')->where('slug', 'menstrual')->delete();

        // Restore old allocations
        DB::table('leave_types')->where('slug', 'casual')->update([
            'default_days_per_year' => 12,
        ]);
        DB::table('leave_types')->where('slug', 'sick')->update([
            'default_days_per_year' => 6,
        ]);

        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn(['monthly_credit', 'max_consecutive_days', 'carry_forward_cap']);
        });
    }
};
