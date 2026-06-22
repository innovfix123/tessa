<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tessa_chats', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false)->after('title');
            $table->boolean('is_archived')->default(false)->after('is_pinned');
        });
    }

    public function down(): void
    {
        Schema::table('tessa_chats', function (Blueprint $table) {
            $table->dropColumn(['is_pinned', 'is_archived']);
        });
    }
};
