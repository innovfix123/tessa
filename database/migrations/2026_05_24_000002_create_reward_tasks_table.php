<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_tasks', function (Blueprint $table) {
            $table->id();
            $table->integer('assigned_to_id');
            $table->integer('assigned_by_id');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->decimal('amount', 10, 2);
            $table->date('deadline')->nullable();
            $table->enum('status', ['assigned', 'submitted', 'approved', 'rejected'])->default('assigned');
            $table->text('submission_note')->nullable();
            $table->string('submission_evidence_url', 500)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->decimal('final_amount', 10, 2)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->integer('reviewed_by_id')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['assigned_to_id', 'status']);
            $table->index('status');
            $table->index('deadline');
            $table->foreign('assigned_to_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('assigned_by_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('reviewed_by_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_tasks');
    }
};
