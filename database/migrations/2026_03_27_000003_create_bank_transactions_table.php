<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->integer('uploaded_by');
            $table->date('transaction_date');
            $table->string('description');
            $table->string('reference_number')->nullable();
            $table->decimal('amount', 12, 2);
            $table->enum('type', ['credit', 'debit'])->default('debit');
            $table->decimal('balance', 14, 2)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('statement_month')->nullable()->comment('YYYY-MM format');
            $table->string('source_file')->nullable();
            $table->enum('match_status', ['unmatched', 'matched', 'ignored'])->default('unmatched');
            $table->timestamps();

            $table->foreign('uploaded_by')->references('id')->on('users');
            $table->index(['transaction_date', 'match_status']);
            $table->index('statement_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
