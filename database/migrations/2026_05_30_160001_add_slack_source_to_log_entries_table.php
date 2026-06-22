<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extend the source enum to allow 'slack'. Done via raw SQL so it works
        // without doctrine/dbal and matches the enum style of the create migration.
        DB::statement("ALTER TABLE log_entries MODIFY source ENUM('text','voice','slack') NOT NULL DEFAULT 'text'");

        Schema::table('log_entries', function (Blueprint $table) {
            // Slack message timestamp (e.g. "1717000000.000300"); null for manual entries.
            $table->string('slack_ts', 32)->nullable()->after('source');
            $table->string('slack_permalink', 1024)->nullable()->after('slack_ts');
            // Prevent the same Slack message being logged twice (NULLs do not collide in MySQL).
            $table->unique(['user_id', 'slack_ts']);
        });
    }

    public function down(): void
    {
        Schema::table('log_entries', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'slack_ts']);
            $table->dropColumn(['slack_ts', 'slack_permalink']);
        });

        DB::statement("ALTER TABLE log_entries MODIFY source ENUM('text','voice') NOT NULL DEFAULT 'text'");
    }
};
