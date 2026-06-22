<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmail_insights', function (Blueprint $table) {
            $table->id();
            // Inbox owner. Gmail insights are always personal — each email is
            // private to the account it came from, so (unlike Slack) there is no
            // shared/audience model and no per-user state table.
            $table->integer('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->string('gmail_message_id', 64);
            $table->string('gmail_thread_id', 64)->nullable();

            $table->string('subject', 255)->default('');
            $table->string('sender', 255)->nullable();
            $table->text('summary')->nullable();
            $table->text('snippet')->nullable();

            $table->string('category', 50)->nullable();
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');

            $table->dateTime('received_at')->nullable();
            $table->decimal('confidence_score', 3, 2)->nullable();

            $table->enum('status', ['new', 'seen', 'actioned', 'dismissed'])->default('new');
            $table->dateTime('snooze_until')->nullable();
            $table->foreignId('task_id')->nullable()->constrained('tessa_tasks')->nullOnDelete();

            $table->date('scanned_date')->index();
            $table->timestamps();

            // One row per message per inbox — dedup guard for re-runs.
            $table->unique(['user_id', 'gmail_message_id']);
            $table->index(['user_id', 'status']);
            $table->index('snooze_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmail_insights');
    }
};
