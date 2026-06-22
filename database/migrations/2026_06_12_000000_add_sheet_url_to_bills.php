<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Travel Allowance switches from "upload screenshots + amount" to "paste a link
 * to a monthly travel-expenses Google Sheet". So a travel `bill` row now carries
 * a `sheet_url` and NO attachment, and the ₹ amount is left 0 until the admin
 * (Shoyab/Ayush) enters it at mark-paid time.
 *
 *  - `sheet_url`         : the pasted Google Sheet / Excel link (travel only).
 *  - `file_path`/`file_name` become nullable so a link-only row is valid.
 *  - `paid_announced_at` : set when an admin posts the per-user "travel paid"
 *                          in-portal announcement, so it can't be sent twice.
 *
 * Bills + Reimbursements are unchanged (they still require an invoice + amount).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->string('sheet_url', 1000)->nullable()->after('files');
            $table->timestamp('paid_announced_at')->nullable()->after('reviewed_at');

            // Link-only travel rows have no uploaded invoice/receipt.
            $table->string('file_path', 500)->nullable()->change();
            $table->string('file_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn(['sheet_url', 'paid_announced_at']);
            // Re-tighten — only safe once any link-only rows are removed.
            $table->string('file_path', 500)->nullable(false)->change();
            $table->string('file_name')->nullable(false)->change();
        });
    }
};
