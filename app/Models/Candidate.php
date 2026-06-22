<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A candidate in the Hiring/ATS pipeline — uploaded by a freelance recruiter
 * against a job description, advanced through `stage` by the panel + HR.
 */
class Candidate extends Model
{
    /**
     * Stages that count as "selected" for a recruiter — the candidate has been
     * approved IN the technical round (i.e. passed it → advanced to the HR round)
     * or gone further. Being merely shortlisted into `tech_round` does NOT count.
     * Drives the freelancer portal's "Selected" count + the HR Recruiters modal.
     */
    public const SELECTED_STAGES = ['hr_round', 'accepted', 'provisioning', 'offer', 'onboarding', 'hired'];

    protected $fillable = [
        'job_description_id',
        'uploaded_by',
        'resume_path',
        'resume_name',
        'resume_mime',
        'extracted_name',
        'extracted_email',
        'extracted_phone',
        'experience_years',
        'skills',
        'extraction_status',
        'stage',
        'approved_by',
        'rejected_by',
        'rejected_reason',
        'generated_email',
        'hired_user_id',
        'offer_accepted_at',
        'offer_accepted_via',
    ];

    protected $casts = [
        'experience_years' => 'decimal:1',
        'offer_accepted_at' => 'datetime',
    ];

    public function jobDescription(): BelongsTo
    {
        return $this->belongsTo(JobDescription::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(CandidateInterview::class);
    }

    public function provisioningRequest(): HasOne
    {
        return $this->hasOne(ProvisioningRequest::class);
    }

    /** Has this candidate cleared the panel round (counts as "selected")? */
    public function isSelected(): bool
    {
        return in_array($this->stage, self::SELECTED_STAGES, true);
    }
}
