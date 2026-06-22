<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('tickets');

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('category', 32);
            $table->string('priority', 16)->default('medium');
            $table->string('status', 16)->default('open');
            $table->integer('reporter_id');
            $table->foreign('reporter_id')->references('id')->on('users')->cascadeOnDelete();
            $table->integer('assignee_id');
            $table->foreign('assignee_id')->references('id')->on('users')->cascadeOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('category');
            $table->index('assignee_id');
            $table->index('reporter_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
