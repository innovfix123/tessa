<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_payments', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->date('week_start');
            $table->date('week_end');
            $table->decimal('total_overtime_hours', 6, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->enum('status', ['pending', 'paid'])->default('pending');
            $table->string('utr_number', 60)->nullable();
            $table->string('payment_screenshot_path')->nullable();
            $table->integer('paid_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'week_start']);
            $table->index(['week_start', 'status']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('paid_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_payments');
    }
};
