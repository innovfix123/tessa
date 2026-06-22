<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_submissions', function (Blueprint $table) {
            $table->unsignedBigInteger('matched_transaction_id')->nullable()->after('reviewed_at');
            $table->string('ai_extracted_vendor')->nullable()->after('matched_transaction_id');
            $table->decimal('ai_extracted_amount', 12, 2)->nullable()->after('ai_extracted_vendor');
            $table->date('ai_extracted_date')->nullable()->after('ai_extracted_amount');
            $table->decimal('match_confidence', 5, 2)->nullable()->after('ai_extracted_date')->comment('0-100 AI confidence score');
            $table->enum('verification_status', ['pending', 'verified', 'mismatch', 'no_match'])->default('pending')->after('match_confidence');

            $table->foreign('matched_transaction_id')->references('id')->on('bank_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_submissions', function (Blueprint $table) {
            $table->dropForeign(['matched_transaction_id']);
            $table->dropColumn([
                'matched_transaction_id',
                'ai_extracted_vendor',
                'ai_extracted_amount',
                'ai_extracted_date',
                'match_confidence',
                'verification_status',
            ]);
        });
    }
};
