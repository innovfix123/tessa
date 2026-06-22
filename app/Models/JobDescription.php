<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A job description in the Hiring/ATS pipeline. Authored by a "panel member"
 * (HR/management or a configured hiring manager) and assigned to freelance
 * recruiters who source candidates against it.
 */
class JobDescription extends Model
{
    protected $fillable = [
        'created_by',
        'title',
        'description',
        'required_skills',
        'experience_level',
        'salary_range',
        'source_type',
        'jd_file_path',
        'jd_file_name',
        'status',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Freelance recruiters this JD is assigned to. */
    public function recruiters(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'job_description_recruiters',
            'job_description_id',
            'recruiter_user_id'
        )->withPivot(['assigned_by', 'assigned_at', 'notified_at'])->withTimestamps();
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class);
    }

    /**
     * HR/management (config roles) can manage any JD; the author can manage
     * their own. Mirrors the per-action re-check in HiringController.
     */
    public function canBeManagedBy(User $user): bool
    {
        return in_array($user->role, (array) config('hiring_access.roles', []), true)
            || (int) $this->created_by === (int) $user->id;
    }
}
