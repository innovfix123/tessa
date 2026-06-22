<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_user_id', 100)->nullable()->unique()->after('github_connected_at');
            $table->string('google_email', 255)->nullable()->after('google_user_id');
            $table->text('google_access_token')->nullable()->after('google_email');
            $table->text('google_refresh_token')->nullable()->after('google_access_token');
            $table->string('google_name', 255)->nullable()->after('google_refresh_token');
            $table->string('google_avatar_url', 500)->nullable()->after('google_name');
            $table->text('google_scopes')->nullable()->after('google_avatar_url');
            $table->timestamp('google_connected_at')->nullable()->after('google_scopes');
            $table->timestamp('google_token_expires_at')->nullable()->after('google_connected_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_user_id']);
            $table->dropColumn([
                'google_user_id',
                'google_email',
                'google_access_token',
                'google_refresh_token',
                'google_name',
                'google_avatar_url',
                'google_scopes',
                'google_connected_at',
                'google_token_expires_at',
            ]);
        });
    }
};
