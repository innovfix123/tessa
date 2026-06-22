<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tessa_followups', function (Blueprint $table) {
            $table->id();
            $table->integer('requested_by');
            $table->integer('target_user_id');
            $table->unsignedBigInteger('tessa_chat_id')->nullable();
            $table->foreign('requested_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('target_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('tessa_chat_id')->references('id')->on('tessa_chats')->nullOnDelete();
            $table->string('type');
            $table->string('reference_key')->nullable();
            $table->string('reference_week_key')->nullable();
            $table->text('message');
            $table->timestamp('slack_sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['requested_by', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tessa_followups');
    }
};
