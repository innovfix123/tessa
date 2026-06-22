<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Announcements were broadcast-only (every employee sees every card). Add an
 * optional per-user target so an announcement can be PERSONAL — e.g. the
 * "your travel expense is paid" card, which only its recipient should see.
 *
 *  - target_user_id NULL  → broadcast (unchanged: new-joiner cards, etc.)
 *  - target_user_id = N   → personal, shown only to user N
 *
 * Filtering lives in AnnouncementController::index().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->integer('target_user_id')->nullable()->index()->after('subject_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex(['target_user_id']);
            $table->dropColumn('target_user_id');
        });
    }
};
