<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('github_user_id', 50)->nullable()->unique()->after('slack_token_expires_at');
            $table->string('github_username', 100)->nullable()->after('github_user_id');
            $table->text('github_access_token')->nullable()->after('github_username');
            $table->string('github_avatar_url', 500)->nullable()->after('github_access_token');
            $table->text('github_scopes')->nullable()->after('github_avatar_url');
            $table->timestamp('github_connected_at')->nullable()->after('github_scopes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['github_user_id']);
            $table->dropColumn([
                'github_user_id',
                'github_username',
                'github_access_token',
                'github_avatar_url',
                'github_scopes',
                'github_connected_at',
            ]);
        });
    }
};
