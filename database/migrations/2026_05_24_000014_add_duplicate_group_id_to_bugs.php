<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a `duplicate_group_id` (UUID string) on bugs. Bugs that share a
 * group id are flagged as semantic duplicates of each other by
 * BugDuplicateService (AI clustering via OpenRouter). Singleton groups are
 * intentionally stored as NULL — only true duplicates (group size >= 2)
 * get a non-null id, so the indicator pill simply checks NOT NULL.
 *
 * Index is plain (non-unique) since many bugs share the same group id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bugs', function (Blueprint $table) {
            $table->string('duplicate_group_id', 36)->nullable()->after('environment');
            $table->index('duplicate_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('bugs', function (Blueprint $table) {
            $table->dropIndex(['duplicate_group_id']);
            $table->dropColumn('duplicate_group_id');
        });
    }
};
