<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Once a leave is APPROVED, the employee can no longer self-cancel it — they
 * must request cancellation and the manager approves/rejects. While that request
 * is pending, the leave deliberately stays status='approved' (the person is still
 * on leave until the manager agrees), so we track the request with a flag column
 * instead of a new status value — that also avoids altering the status enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->timestamp('cancellation_requested_at')->nullable()->after('reviewer_note');
            $table->text('cancellation_reason')->nullable()->after('cancellation_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['cancellation_requested_at', 'cancellation_reason']);
        });
    }
};
