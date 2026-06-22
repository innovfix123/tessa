<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_item_completions', function (Blueprint $table) {
            $table->timestamp('assigner_dismissed_at')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('checklist_item_completions', function (Blueprint $table) {
            $table->dropColumn('assigner_dismissed_at');
        });
    }
};
