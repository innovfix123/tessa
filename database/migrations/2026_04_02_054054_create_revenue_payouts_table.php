<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('revenue_payouts', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedBigInteger('revenue')->default(0);
            $table->unsignedInteger('paying_users')->default(0);
            $table->unsignedInteger('transactions')->default(0);
            $table->json('by_language')->nullable();
            $table->decimal('payout_paid', 12, 2)->default(0);
            $table->unsignedInteger('payout_paid_count')->default(0);
            $table->decimal('payout_rejected', 12, 2)->default(0);
            $table->unsignedInteger('payout_rejected_count')->default(0);
            $table->decimal('payout_pending', 12, 2)->default(0);
            $table->unsignedInteger('payout_pending_count')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('revenue_payouts');
    }
};
