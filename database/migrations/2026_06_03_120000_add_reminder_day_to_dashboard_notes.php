<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboard_notes', function (Blueprint $table) {
            // Day-of-month (1-31) for a monthly recurring reminder. When set,
            // the note fires on that day every month and is hidden otherwise.
            $table->unsignedTinyInteger('reminder_day')->nullable()->after('reminder_at');
            // IST date of the occurrence whose checklist we last reset to
            // unchecked, so each month starts fresh exactly once.
            $table->date('monthly_reset_on')->nullable()->after('reminder_day');
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_notes', function (Blueprint $table) {
            $table->dropColumn(['reminder_day', 'monthly_reset_on']);
        });
    }
};
