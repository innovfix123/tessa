<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stories')) {
            return;
        }
        Schema::create('stories', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('acceptance_criteria')->nullable();
            $table->foreignId('epic_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sprint_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('assignee_id')->nullable();
            $table->integer('reporter_id');
            $table->string('status', 16)->default('backlog');
            $table->string('priority', 16)->default('medium');
            $table->unsignedTinyInteger('story_points')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->integer('created_by');
            $table->timestamps();

            $table->foreign('assignee_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reporter_id')->references('id')->on('users');
            $table->foreign('created_by')->references('id')->on('users');
            $table->index(['sprint_id', 'status']);
            $table->index('epic_id');
            $table->index('assignee_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stories');
    }
};
