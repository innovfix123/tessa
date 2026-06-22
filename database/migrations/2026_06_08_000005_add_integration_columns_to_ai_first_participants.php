<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_first_participants', function (Blueprint $t) {
            $t->timestamp('slack_connected_at')->nullable()->after('tessa_mcp_connected_at');
            $t->timestamp('google_drive_connected_at')->nullable()->after('slack_connected_at');
            $t->timestamp('google_calendar_connected_at')->nullable()->after('google_drive_connected_at');
            $t->timestamp('gmail_connected_at')->nullable()->after('google_calendar_connected_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_first_participants', function (Blueprint $t) {
            $t->dropColumn([
                'slack_connected_at',
                'google_drive_connected_at',
                'google_calendar_connected_at',
                'gmail_connected_at',
            ]);
        });
    }
};
