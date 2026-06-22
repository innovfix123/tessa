<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Travel-Expense trips — the "log one commute" record behind the Travel Allowance
 * tab. Replaces the paste-a-sheet-link flow with a 10-second form (date, route,
 * amount, payment screenshot); Tessa keeps the screenshot inline on the `public`
 * disk and shows an in-portal ledger. Each month's trips roll up into ONE pending
 * `travel` bill (`bill_id`) so the existing Pay Queue / mark-paid / announce flow
 * settles them unchanged.
 *
 * The Drive/Sheets columns (`drive_file_id`, `drive_link`, `sheet_synced_at`) are
 * the dormant-ready sync state: null = not yet mirrored to Google. They stay null
 * until the service account is provisioned, then `travel:sync` (or the deferred
 * callback) backfills them — mirrors the HR-doc Drive sync (Features 5 & 6).
 *
 * `user_id` is signed `integer` to match `users.id` (signed int) — NOT bigInteger.
 * `bill_id` references `bills.id` (unsigned bigint from $table->id()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_expenses', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');                           // the employee who travelled
            $table->date('trip_date');
            $table->char('month_key', 7);                         // 'YYYY-MM' (IST), the rollup bucket
            $table->string('from_label', 120);
            $table->string('to_label', 120);
            $table->decimal('amount', 10, 2);
            $table->string('note', 300)->nullable();

            // Payment screenshot (public disk: travel-expenses/{YYYY-MM}/…).
            $table->string('screenshot_path', 500);
            $table->string('screenshot_name')->nullable();

            // Dormant-ready Google sync state (null = not yet mirrored).
            $table->string('drive_file_id', 64)->nullable();
            $table->string('drive_link', 1000)->nullable();
            $table->timestamp('sheet_synced_at')->nullable();

            // The monthly rollup `travel` bill this trip settles through.
            $table->unsignedBigInteger('bill_id')->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('bill_id')->references('id')->on('bills')->nullOnDelete();
            $table->index(['user_id', 'month_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_expenses');
    }
};
