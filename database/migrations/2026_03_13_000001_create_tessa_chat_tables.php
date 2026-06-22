<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tessa_chats', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('tessa_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tessa_chat_id')->constrained('tessa_chats')->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant']);
            $table->longText('content');
            $table->timestamp('created_at')->useCurrent();
            $table->index('tessa_chat_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tessa_messages');
        Schema::dropIfExists('tessa_chats');
    }
};
