<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->integer('user_id');
            $table->text('content');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('task_id')->references('id')->on('tessa_tasks')->onDelete('cascade');
            $table->index(['task_id', 'created_at']);
        });

        Schema::create('task_participants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->integer('user_id');
            $table->enum('role', ['assigner', 'assignee', 'invited'])->default('invited');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('task_id')->references('id')->on('tessa_tasks')->onDelete('cascade');
            $table->unique(['task_id', 'user_id']);
        });

        Schema::table('tessa_tasks', function (Blueprint $table) {
            $table->string('ai_summary', 255)->nullable()->after('status_note');
        });
    }

    public function down(): void
    {
        Schema::table('tessa_tasks', function (Blueprint $table) {
            $table->dropColumn('ai_summary');
        });
        Schema::dropIfExists('task_participants');
        Schema::dropIfExists('task_messages');
    }
};
