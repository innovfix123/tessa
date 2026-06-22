<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('meetings', 'meeting_date')) {
            Schema::table('meetings', function (Blueprint $table) {
                // Actual calendar date for one-time ('none') meetings. NULL for
                // recurring meetings, which key off day_of_week/recurrence instead.
                $table->date('meeting_date')->nullable()->after('day_of_week');
            });
        }

        // Backfill existing one-time meetings from their creation date so the
        // scheduler stops treating them as a meeting that recurs on every
        // same-weekday forever. created_at is the best available signal for
        // when these one-offs actually happened.
        DB::table('meetings')
            ->where('recurrence', 'none')
            ->whereNull('meeting_date')
            ->update(['meeting_date' => DB::raw('DATE(created_at)')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('meetings', 'meeting_date')) {
            Schema::table('meetings', function (Blueprint $table) {
                $table->dropColumn('meeting_date');
            });
        }
    }
};
