<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reward_withdrawals', function (Blueprint $table) {
            $table->unsignedBigInteger('reward_task_id')->nullable()->after('user_id');
            $table->index('reward_task_id');
            $table->foreign('reward_task_id')->references('id')->on('reward_tasks')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('reward_withdrawals', function (Blueprint $table) {
            $table->dropForeign(['reward_task_id']);
            $table->dropIndex(['reward_task_id']);
            $table->dropColumn('reward_task_id');
        });
    }
};
