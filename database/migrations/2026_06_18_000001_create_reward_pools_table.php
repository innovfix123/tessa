<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reward Pools — a manager (e.g. Krishnan) runs a weekly team performance
 * reward whose winners are decided ORALLY (no per-member record in Tessa). In
 * Tessa he only logs the pool itself (title + description + amount) and sends it
 * straight to the payer's (Ayush's) queue. There is no assignee and no
 * submit/approve loop — it's a direct creator → payer payout request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_pools', function (Blueprint $table) {
            $table->id();
            $table->integer('created_by');                 // the manager who set the pool (Krishnan)
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['pending', 'paid'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->integer('paid_by')->nullable();         // the payer who settled it (Ayush)
            $table->string('utr_number', 60)->nullable();
            $table->text('admin_note')->nullable();         // payer's update/note at pay time
            $table->timestamps();

            $table->index('status');
            $table->index(['created_by', 'status']);
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('paid_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_pools');
    }
};
