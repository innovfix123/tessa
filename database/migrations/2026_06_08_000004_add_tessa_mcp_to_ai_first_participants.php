<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_first_participants', function (Blueprint $t) {
            $t->timestamp('tessa_mcp_connected_at')->nullable()->after('claude_notes');
        });
    }

    public function down(): void
    {
        Schema::table('ai_first_participants', function (Blueprint $t) {
            $t->dropColumn('tessa_mcp_connected_at');
        });
    }
};
