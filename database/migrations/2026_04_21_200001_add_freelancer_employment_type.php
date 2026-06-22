<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY employment_type ENUM('full_time','internship','freelancer') NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY employment_type ENUM('full_time','internship') NULL");
    }
};
