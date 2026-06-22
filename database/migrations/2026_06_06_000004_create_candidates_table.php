<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Candidates — stage 3+ of the Hiring/ATS pipeline. A freelance recruiter
 * uploads a résumé against a JD they're assigned to; AI extracts the basic
 * fields ONCE (reused in later rounds). `stage` is the candidate state machine.
 *
 * `job_description_id` is `unsignedBigInteger` to match `job_descriptions.id`
 * ($table->id()); all user FKs are signed `integer` to match `users.id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('job_description_id');
            $table->integer('uploaded_by');                      // the freelance recruiter

            // Résumé file (public disk).
            $table->string('resume_path', 500);
            $table->string('resume_name');
            $table->string('resume_mime', 100)->nullable();

            // AI-extracted basics (extract once at upload, reuse downstream).
            $table->string('extracted_name')->nullable();
            $table->string('extracted_email')->nullable();
            $table->string('extracted_phone', 40)->nullable();
            $table->decimal('experience_years', 4, 1)->nullable();
            $table->text('skills')->nullable();
            $table->enum('extraction_status', ['pending', 'done', 'failed'])->default('pending');

            // State machine. Forward-only except reject/withdraw.
            $table->enum('stage', [
                'sourced', 'panel_review', 'tech_round', 'hr_round', 'accepted',
                'provisioning', 'offer', 'onboarding', 'hired', 'rejected', 'withdrawn',
            ])->default('sourced');

            $table->integer('approved_by')->nullable();          // panel/HR who approved to tech round
            $table->integer('rejected_by')->nullable();
            $table->text('rejected_reason')->nullable();

            // Set in later phases (provisioning / onboarding).
            $table->string('generated_email')->nullable();
            $table->integer('hired_user_id')->nullable();

            $table->timestamps();

            $table->foreign('job_description_id')->references('id')->on('job_descriptions')->cascadeOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('rejected_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('hired_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['job_description_id', 'stage']);
            $table->index('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
