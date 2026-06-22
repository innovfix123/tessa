<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_task_updates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reward_task_id');
            $table->integer('user_id');
            $table->text('note');
            $table->string('evidence_url', 500)->nullable();
            $table->timestamps();

            $table->index('reward_task_id');
            $table->foreign('reward_task_id')->references('id')->on('reward_tasks')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_task_updates');
    }
};
