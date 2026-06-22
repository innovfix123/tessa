<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('joined_as', ['intern', 'fresher', 'experienced'])->nullable()->after('experienced');
        });

        // Seed defaults from existing employment_type / experienced so docs filter
        // sensibly out of the box. Users can change their pick on My Profile.
        DB::statement("UPDATE users SET joined_as = CASE
            WHEN employment_type = 'internship' THEN 'intern'
            WHEN employment_type = 'full_time' AND experienced = 1 THEN 'experienced'
            WHEN employment_type = 'full_time' AND (experienced = 0 OR experienced IS NULL) THEN 'fresher'
            ELSE NULL
        END");
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('joined_as');
        });
    }
};
