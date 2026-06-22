<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_submissions', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->string('title', 200);
            $table->text('description');
            $table->string('evidence_url', 500)->nullable();
            $table->string('pillar', 40)->nullable();
            $table->enum('status', ['pending', 'verified', 'approved', 'rejected'])->default('pending');
            $table->decimal('amount', 10, 2)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->integer('verified_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->integer('approved_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->integer('rejected_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('reviewer_note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('status');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('verified_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('rejected_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_submissions');
    }
};
