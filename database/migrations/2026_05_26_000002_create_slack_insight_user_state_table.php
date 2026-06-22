<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slack_insight_user_state', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('insight_id');
            $table->foreign('insight_id')->references('id')->on('slack_insights')->cascadeOnDelete();
            $table->integer('user_id');
            $table->enum('status', ['seen', 'snoozed', 'dismissed', 'actioned'])->default('seen');
            $table->dateTime('snooze_until')->nullable();
            $table->foreignId('task_id')->nullable()->constrained('tessa_tasks')->nullOnDelete();
            $table->timestamps();

            $table->unique(['insight_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->index('snooze_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slack_insight_user_state');
    }
};
