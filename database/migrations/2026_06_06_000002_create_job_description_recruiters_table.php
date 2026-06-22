<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * JD ↔ freelance-recruiter assignment (stage 2). HR assigns a job description
 * to one or both freelancers; each row is one assignment. `notified_at` is
 * stamped when the WhatsApp ping was attempted (see RecruiterNotifier).
 *
 * User FKs are signed `integer` to match `users.id`; the JD FK is
 * `unsignedBigInteger` to match `job_descriptions.id` ($table->id()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_description_recruiters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('job_description_id');    // matches job_descriptions.id
            $table->integer('recruiter_user_id');                // a freelance_recruiter user
            $table->integer('assigned_by')->nullable();          // the HR user who assigned
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('notified_at')->nullable();        // WhatsApp ping attempted
            $table->timestamps();

            $table->foreign('job_description_id')->references('id')->on('job_descriptions')->cascadeOnDelete();
            $table->foreign('recruiter_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['job_description_id', 'recruiter_user_id'], 'jd_recruiter_unique');
            $table->index('recruiter_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_description_recruiters');
    }
};
