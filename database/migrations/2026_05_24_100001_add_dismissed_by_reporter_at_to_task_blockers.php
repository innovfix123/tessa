<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_blockers', function (Blueprint $table) {
            $table->timestamp('dismissed_by_reporter_at')->nullable()->after('created_at');
            $table->index('dismissed_by_reporter_at');
        });
    }

    public function down(): void
    {
        Schema::table('task_blockers', function (Blueprint $table) {
            $table->dropIndex(['dismissed_by_reporter_at']);
            $table->dropColumn('dismissed_by_reporter_at');
        });
    }
};
