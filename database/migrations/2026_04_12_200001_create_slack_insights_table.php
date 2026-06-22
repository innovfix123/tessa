<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slack_insights', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->enum('type', ['action_item', 'reminder', 'follow_up', 'decision', 'important'])->index();
            $table->string('title', 255);
            $table->text('summary')->nullable();
            $table->string('source_channel', 100)->nullable();
            $table->string('source_channel_name', 255)->nullable();
            $table->string('source_message_ts', 50)->nullable();
            $table->string('mentioned_by', 255)->nullable();
            $table->date('due_date')->nullable();
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->enum('status', ['new', 'seen', 'actioned', 'dismissed'])->default('new');
            $table->foreignId('task_id')->nullable()->constrained('tessa_tasks')->nullOnDelete();
            $table->date('scanned_date')->index();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'scanned_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slack_insights');
    }
};
