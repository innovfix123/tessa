<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tessa_tasks', function (Blueprint $table) {
            $table->enum('blocker_status', ['on_track', 'blocked', 'no_update'])->default('no_update')->after('ai_summary');
            $table->text('blocker_note')->nullable()->after('blocker_status');
            $table->timestamp('last_checkin_at')->nullable()->after('blocker_note');
            $table->timestamp('next_checkin_at')->nullable()->after('last_checkin_at');
        });
    }

    public function down(): void
    {
        Schema::table('tessa_tasks', function (Blueprint $table) {
            $table->dropColumn(['blocker_status', 'blocker_note', 'last_checkin_at', 'next_checkin_at']);
        });
    }
};
