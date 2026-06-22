<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_recurrences', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->integer('assigned_to');
            $table->integer('assigned_by');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->enum('recurrence_type', ['daily', 'weekly', 'monthly']);
            $table->unsignedTinyInteger('recurrence_day')->nullable();
            $table->timestamp('next_run_at');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('deadline_offset_hours')->default(24);
            $table->timestamps();

            $table->foreign('assigned_to')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['is_active', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_recurrences');
    }
};
