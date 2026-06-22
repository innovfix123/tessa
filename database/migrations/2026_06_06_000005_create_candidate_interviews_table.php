<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interview rounds (stages 5–6). One row per candidate per round (technical,
 * hr). Holds the schedule (date/time + manually-pasted Google Meet link), the
 * AI-drafted invite email (HR/panel sends it manually), the recording link,
 * and the outcome that advances the candidate's stage.
 *
 * `candidate_id` is `unsignedBigInteger` to match `candidates.id`; `conducted_by`
 * is a signed `integer` to match `users.id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_interviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('candidate_id');
            $table->enum('round', ['technical', 'hr']);
            $table->dateTime('scheduled_at')->nullable();
            $table->string('meet_link', 500)->nullable();         // manual paste
            $table->string('email_subject')->nullable();
            $table->text('email_body')->nullable();               // AI-drafted, editable
            $table->enum('email_status', ['draft', 'sent'])->default('draft');
            $table->string('recording_link', 500)->nullable();
            $table->enum('outcome', ['pending', 'passed', 'failed'])->default('pending');
            $table->integer('conducted_by')->nullable();
            $table->timestamps();

            $table->foreign('candidate_id')->references('id')->on('candidates')->cascadeOnDelete();
            $table->foreign('conducted_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['candidate_id', 'round'], 'cand_interview_round_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_interviews');
    }
};
