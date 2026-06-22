<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tessa_tasks', function (Blueprint $table) {
            $table->id();
            $table->integer('assigned_by');
            $table->integer('assigned_to');
            $table->foreign('assigned_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->timestamp('deadline')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->unsignedInteger('remind_count')->default(0);
            $table->text('status_note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['assigned_to', 'status']);
            $table->index(['assigned_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tessa_tasks');
    }
};
