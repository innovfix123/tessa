<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Shared assigner" for task redirection. When an assignee (or an existing
 * shared assigner) passes a task on to someone else, the person who handed it
 * off is recorded here while `assigned_by` stays the original creator. Always
 * the LATEST delegator — overwritten on each redirect, cleared when the creator
 * reassigns from scratch, NULL for tasks that were never redirected.
 * integer (not bigint) — users.id is a signed int.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tessa_tasks', function (Blueprint $table) {
            $table->integer('shared_assigned_by')->nullable()->after('assigned_by');
            $table->foreign('shared_assigned_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tessa_tasks', function (Blueprint $table) {
            $table->dropForeign(['shared_assigned_by']);
            $table->dropColumn('shared_assigned_by');
        });
    }
};
