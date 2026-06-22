<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add 'travel' to the bills.type enum so travel-allowance claims (daily intern
 * commute reimbursements with a ₹3,000/month cap) get their own type + tab,
 * separate from general reimbursements. MySQL enum extension via raw ALTER —
 * Schema has no portable enum-modify; the table is small and the new value is
 * purely additive (existing rows unaffected).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE bills MODIFY COLUMN type ENUM('bill', 'reimbursement', 'travel') NOT NULL");
    }

    public function down(): void
    {
        // Revert any travel rows first so the narrowed enum doesn't truncate them.
        DB::table('bills')->where('type', 'travel')->update(['type' => 'reimbursement']);
        DB::statement("ALTER TABLE bills MODIFY COLUMN type ENUM('bill', 'reimbursement') NOT NULL");
    }
};
