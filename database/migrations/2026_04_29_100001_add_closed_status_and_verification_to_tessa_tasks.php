<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tessa_tasks MODIFY COLUMN status ENUM('pending','in_progress','completed','closed','cancelled','on_hold') NOT NULL DEFAULT 'pending'");

        Schema::table('tessa_tasks', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('completed_at');
            $table->integer('closed_by')->nullable()->after('closed_at');
            $table->tinyInteger('reopen_count')->unsigned()->default(0)->after('closed_by');
            $table->text('reopen_reason')->nullable()->after('reopen_count');
            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement("UPDATE tessa_tasks SET status='closed', closed_at=COALESCE(completed_at, NOW()) WHERE status='completed'");
    }

    public function down(): void
    {
        DB::statement("UPDATE tessa_tasks SET status='completed' WHERE status='closed'");

        Schema::table('tessa_tasks', function (Blueprint $table) {
            $table->dropForeign(['closed_by']);
            $table->dropColumn(['closed_at', 'closed_by', 'reopen_count', 'reopen_reason']);
        });

        DB::statement("ALTER TABLE tessa_tasks MODIFY COLUMN status ENUM('pending','in_progress','completed','cancelled','on_hold') NOT NULL DEFAULT 'pending'");
    }
};
