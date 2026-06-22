<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bills & Reimbursements — direct employee → admin (Ayush #4 / Shoyab #32)
 * payment flow.
 *
 * Each row is ONE request an employee raises from their portal: a company
 * `bill` (agency invoice, software subscription) or a personal
 * `reimbursement` (PG/room rent, travel). The uploaded invoice/receipt lives
 * inline on the `public` disk (`file_path`). It routes straight into the
 * admins' Pay Queue; an admin marks it paid with proof (`transaction_id`
 * and/or `proof_path`) or rejects it with a reason. Paid rows form the
 * read-only accounts ledger.
 *
 * `user_id` / `reviewed_by` are signed `integer` to match `users.id`
 * (signed int) — NOT bigInteger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');                          // submitter
            $table->enum('type', ['bill', 'reimbursement']);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category')->nullable();              // rent/travel/subscription/agency/other
            $table->decimal('amount', 12, 2);
            $table->string('currency', 8)->default('INR');
            $table->string('vendor_name')->nullable();           // bills: agency/vendor

            // Uploaded invoice / receipt (public disk).
            $table->string('file_path', 500);
            $table->string('file_name');
            $table->integer('file_size')->nullable();

            $table->enum('status', ['pending', 'paid', 'rejected'])->default('pending');

            // Admin action (paid or rejected).
            $table->integer('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            // Proof of payment.
            $table->string('transaction_id', 80)->nullable();    // UTR/UPI ref
            $table->string('proof_path', 500)->nullable();       // payment screenshot
            $table->string('proof_name')->nullable();
            $table->text('payment_note')->nullable();

            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['user_id', 'status']);
            $table->index(['status', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
