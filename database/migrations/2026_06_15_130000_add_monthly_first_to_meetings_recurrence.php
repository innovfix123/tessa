<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE meetings MODIFY COLUMN recurrence ENUM('daily_weekdays','weekly','none','tue_to_fri','mon_thu','mon_wed_fri','monthly_first') NOT NULL DEFAULT 'none'");
    }

    public function down(): void
    {
        // Demote monthly meetings before shrinking the enum to avoid truncation.
        DB::statement("UPDATE meetings SET recurrence='weekly' WHERE recurrence = 'monthly_first'");
        DB::statement("ALTER TABLE meetings MODIFY COLUMN recurrence ENUM('daily_weekdays','weekly','none','tue_to_fri','mon_thu','mon_wed_fri') NOT NULL DEFAULT 'none'");
    }
};
