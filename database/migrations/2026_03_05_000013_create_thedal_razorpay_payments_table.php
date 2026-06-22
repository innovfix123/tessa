<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('thedal_razorpay_payments')) {
            return;
        }
        Schema::create('thedal_razorpay_payments', function (Blueprint $table) {
            $table->string('payment_id', 50)->primary();
            $table->integer('amount_paise');
            $table->string('status', 20);
            $table->string('contact', 100)->nullable();
            $table->string('email', 100)->nullable();
            $table->integer('created_at');
            $table->timestamp('synced_at')->useCurrent();
            $table->index('created_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thedal_razorpay_payments');
    }
};
