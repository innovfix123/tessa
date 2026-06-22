<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tessa_tasks', function (Blueprint $table) {
            $table->timestamp('original_deadline')->nullable()->after('deadline');
            $table->tinyInteger('deadline_extension_count')->unsigned()->default(0)->after('original_deadline');
            $table->tinyInteger('pending_extension_days')->unsigned()->nullable()->after('deadline_extension_count');
        });
    }

    public function down(): void
    {
        Schema::table('tessa_tasks', function (Blueprint $table) {
            $table->dropColumn(['original_deadline', 'deadline_extension_count', 'pending_extension_days']);
        });
    }
};
