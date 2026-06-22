<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('slack_user_id', 50)->nullable()->unique()->after('college_id_path');
            $table->text('slack_access_token')->nullable()->after('slack_user_id');
            $table->text('slack_refresh_token')->nullable()->after('slack_access_token');
            $table->string('slack_team_id', 50)->nullable()->index()->after('slack_refresh_token');
            $table->string('slack_team_name', 255)->nullable()->after('slack_team_id');
            $table->text('slack_scopes')->nullable()->after('slack_team_name');
            $table->timestamp('slack_connected_at')->nullable()->after('slack_scopes');
            $table->timestamp('slack_token_expires_at')->nullable()->after('slack_connected_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['slack_user_id']);
            $table->dropIndex(['slack_team_id']);
            $table->dropColumn([
                'slack_user_id',
                'slack_access_token',
                'slack_refresh_token',
                'slack_team_id',
                'slack_team_name',
                'slack_scopes',
                'slack_connected_at',
                'slack_token_expires_at',
            ]);
        });
    }
};
