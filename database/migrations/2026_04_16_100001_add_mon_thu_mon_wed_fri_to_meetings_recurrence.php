<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE meetings MODIFY COLUMN recurrence ENUM('daily_weekdays','weekly','none','tue_to_fri','mon_thu','mon_wed_fri') NOT NULL DEFAULT 'none'");
    }

    public function down(): void
    {
        DB::statement("UPDATE meetings SET recurrence='weekly' WHERE recurrence IN ('mon_thu','mon_wed_fri')");
        DB::statement("ALTER TABLE meetings MODIFY COLUMN recurrence ENUM('daily_weekdays','weekly','none','tue_to_fri') NOT NULL DEFAULT 'none'");
    }
};
