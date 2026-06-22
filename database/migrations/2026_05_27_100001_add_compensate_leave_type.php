<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the "Compensate" leave type — a weekday off swapped for a weekend
 * working day. Not a real leave: the original weekday is treated as week-off
 * for that user, the weekend `compensation_date` is treated as a working day.
 *
 *   leave_requests.start_date / end_date → weekday taken off (single day)
 *   leave_requests.compensation_date     → Sat/Sun the user will work
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->date('compensation_date')->nullable()->after('end_date');
        });

        DB::table('leave_types')->insertOrIgnore([
            'name' => 'Compensate',
            'slug' => 'compensate',
            'requires_approval' => true,
            'is_active' => true,
            'is_hourly' => false,
            'gender_restricted' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('leave_types')->where('slug', 'compensate')->delete();

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('compensation_date');
        });
    }
};
