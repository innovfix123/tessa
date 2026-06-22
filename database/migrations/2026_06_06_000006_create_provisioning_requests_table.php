<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account-provisioning ticket (stage 7). Created when HR clicks "Send to Tessa":
 * holds the auto-generated login id and the two manual provisioning tasks —
 * Fida creates the Tessa login (`tessa_*`), Yuvanesh creates the Gmail + Slack
 * accounts (`workspace_*`). HR ticks each as done; `status` rolls up. One row
 * per candidate.
 *
 * `candidate_id` is `unsignedBigInteger` to match `candidates.id`; the assignee
 * FKs are signed `integer` to match `users.id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('candidate_id');
            $table->string('generated_email');
            $table->enum('email_strategy', ['firstname', 'initials', 'custom'])->default('firstname');

            $table->integer('tessa_account_user_id')->nullable();   // Fida — Tessa login
            $table->timestamp('tessa_done_at')->nullable();
            $table->integer('workspace_assignee_id')->nullable();   // Yuvanesh — Gmail + Slack
            $table->timestamp('workspace_done_at')->nullable();

            $table->enum('status', ['pending', 'partial', 'done'])->default('pending');
            $table->timestamps();

            $table->foreign('candidate_id')->references('id')->on('candidates')->cascadeOnDelete();
            $table->foreign('tessa_account_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('workspace_assignee_id')->references('id')->on('users')->nullOnDelete();
            $table->unique('candidate_id', 'prov_req_candidate_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_requests');
    }
};
