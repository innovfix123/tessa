<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_links', function (Blueprint $table) {
            $table->id();
            // Always stored with task_a_id < task_b_id so symmetric pairs can't duplicate.
            $table->foreignId('task_a_id')->constrained('tessa_tasks')->cascadeOnDelete();
            $table->foreignId('task_b_id')->constrained('tessa_tasks')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['task_a_id', 'task_b_id'], 'task_links_pair_unique');
            $table->index('task_b_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_links');
    }
};
