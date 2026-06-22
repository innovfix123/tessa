<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job Descriptions — stage 1 of the Hiring/ATS pipeline.
 *
 * A "panel member" (the JD author — any HR/management user or a configured
 * hiring manager) raises one row per role they want to hire for, either by
 * filling the template fields (title/description/skills/experience/salary) or
 * by uploading a JD PDF. HR then assigns it to freelance recruiter(s) via the
 * job_description_recruiters pivot. The uploaded PDF lives inline on the
 * `public` disk (`jd_file_path`).
 *
 * `created_by` is a signed `integer` to match `users.id` (signed int) — NOT
 * bigInteger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_descriptions', function (Blueprint $table) {
            $table->id();
            $table->integer('created_by');                       // author / panel member
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('required_skills')->nullable();
            $table->string('experience_level')->nullable();      // e.g. "3-5 years"
            $table->string('salary_range')->nullable();          // free text, like the Letters salary

            // form  = filled via the template; pdf = uploaded JD document.
            $table->enum('source_type', ['form', 'pdf'])->default('form');
            $table->string('jd_file_path', 500)->nullable();     // uploaded PDF (public disk)
            $table->string('jd_file_name')->nullable();

            // draft   – not yet visible to HR (reserved; v1 creates as open)
            // open    – created, awaiting recruiter assignment
            // assigned – at least one freelancer is sourcing for it
            // closed  – filled / cancelled
            $table->enum('status', ['draft', 'open', 'assigned', 'closed'])->default('open');

            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['created_by', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_descriptions');
    }
};
