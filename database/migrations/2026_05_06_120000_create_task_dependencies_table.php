<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tessa_tasks')->cascadeOnDelete();
            $table->foreignId('depends_on_task_id')->constrained('tessa_tasks')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['task_id', 'depends_on_task_id'], 'task_deps_pair_unique');
            $table->index('depends_on_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_dependencies');
    }
};
