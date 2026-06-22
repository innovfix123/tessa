<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('logs_slack_enabled')->default(false)->after('slack_token_expires_at');
            // Slack message ts (float-as-string) of the last message processed into Logs.
            $table->string('logs_slack_cursor', 32)->nullable()->after('logs_slack_enabled');
            $table->timestamp('logs_slack_enabled_at')->nullable()->after('logs_slack_cursor');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['logs_slack_enabled', 'logs_slack_cursor', 'logs_slack_enabled_at']);
        });
    }
};
