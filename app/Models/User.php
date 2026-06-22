<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'parent_name',
        'email',
        'password_hash',
        'role_id',
        'reporting_manager_id',
        'secondary_manager_id',
        'is_active',
        'last_login',
        'remember_token',
        'recruiter_portal_token',
        'meow_sound_enabled',
        'personal_mobile',
        'personal_email',
        'employment_type',
        'designation',
        'gender',
        'blood_group',
        'marital_status',
        'qualification',
        'current_address',
        'permanent_address',
        'nominee_name',
        'nominee_age',
        'nominee_dob',
        'nominee_relation',
        'date_of_birth',
        'pf_applicable',
        'pf_uan',
        'insurance_applicable',
        'insurance_number',
        'emergency_contact_name',
        'emergency_contact_number',
        'bank_account_holder_name',
        'bank_account_number',
        'bank_ifsc_code',
        'bank_passbook_path',
        'experienced',
        'joined_as',
        'joining_date',
        'hourly_rate',
        // Employee lifecycle
        'employee_status',
        'onboarding_required',
        'exit_date',
        'exit_reason',
        'last_working_date',
        'resignation_date',
        'probation_start_date',
        'probation_end_date',
        'confirmed_date',
        'internship_start_date',
        'internship_end_date',
        'stipend_amount',
        'intern_conversion_status',
        'intern_conversion_date',
        'monthly_salary',
        'annual_ctc',
        'notice_period_days',
        'department_id',
        'designation_id',
        // Documents
        'aadhar_front_path',
        'aadhar_back_path',
        'pan_path',
        'passport_photo_path',
        'profile_photo_path',
        'tenth_marksheet_path',
        'twelfth_marksheet_path',
        'degree_certificate_path',
        'pg_certificate_path',
        'consolidated_marksheets',
        'prev_offer_letter_path',
        'experience_letters_path',
        'salary_slips_path',
        'signed_offer_letter_path',
        'nda_path',
        'form_11_path',
        'college_id_path',
        'resume_path',
        'esic_intern_decl_path',
        'insurance_policy_path',
        // Per-person Google Drive folder id (HR document mirroring)
        'google_drive_folder_id',
        // Slack OAuth
        'slack_user_id',
        'slack_access_token',
        'slack_refresh_token',
        'slack_team_id',
        'slack_team_name',
        'slack_scopes',
        'slack_connected_at',
        'slack_token_expires_at',
        'logs_slack_enabled',
        'logs_slack_cursor',
        'logs_slack_enabled_at',
        // GitHub OAuth
        'github_user_id',
        'github_username',
        'github_access_token',
        'github_avatar_url',
        'github_scopes',
        'github_connected_at',
        // Google OAuth
        'google_user_id',
        'google_email',
        'google_access_token',
        'google_refresh_token',
        'google_name',
        'google_avatar_url',
        'google_scopes',
        'google_connected_at',
        'google_token_expires_at',
    ];

    protected $hidden = [
        'password_hash',
        'slack_access_token',
        'slack_refresh_token',
        'github_access_token',
        'google_access_token',
        'google_refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'pf_applicable' => 'boolean',
            'insurance_applicable' => 'boolean',
            'nominee_dob' => 'date',
            'date_of_birth' => 'date',
            'last_login' => 'datetime',
            'joining_date' => 'date',
            'exit_date' => 'date',
            'last_working_date' => 'date',
            'resignation_date' => 'date',
            'probation_start_date' => 'date',
            'probation_end_date' => 'date',
            'confirmed_date' => 'date',
            'internship_start_date' => 'date',
            'internship_end_date' => 'date',
            'intern_conversion_date' => 'date',
            'stipend_amount' => 'decimal:2',
            'monthly_salary' => 'decimal:2',
            'annual_ctc' => 'decimal:2',
            'slack_access_token' => 'encrypted',
            'slack_refresh_token' => 'encrypted',
            'slack_connected_at' => 'datetime',
            'slack_token_expires_at' => 'datetime',
            'logs_slack_enabled' => 'boolean',
            'logs_slack_enabled_at' => 'datetime',
            'github_access_token' => 'encrypted',
            'github_connected_at' => 'datetime',
            'google_access_token' => 'encrypted',
            'google_refresh_token' => 'encrypted',
            'google_connected_at' => 'datetime',
            'google_token_expires_at' => 'datetime',
            'bank_account_number' => 'encrypted',
        ];
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function roleRelation(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /** Role slug for backward compatibility (ceo, coo, ops, etc.) */
    public function getRoleAttribute(): ?string
    {
        return $this->roleRelation?->slug;
    }

    /**
     * Display avatar URL: the user-editable profile photo, falling back to
     * the HR passport photo. Either source is only used when it is a real
     * image that exists on the public disk (skips broken '0' uploads and
     * any doc uploaded as a PDF) — callers treat null as "show initials".
     */
    public function getProfilePhotoUrlAttribute(): ?string
    {
        // Sentinel "-" on profile_photo_path means the user has explicitly opted
        // out of any avatar (including the passport fallback). Return null so
        // callers render initials. Never touch passport_photo_path itself.
        if ($this->profile_photo_path === '-') {
            return null;
        }

        foreach ([$this->profile_photo_path, $this->passport_photo_path] as $path) {
            if (! is_string($path) || $path === '' || $path === '0') {
                continue;
            }
            if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                continue;
            }
            if (Storage::disk('public')->exists($path)) {
                return asset('storage/' . $path);
            }
        }

        return null;
    }

    public function reportingManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporting_manager_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(User::class, 'reporting_manager_id');
    }

    public function scopeIsRatingManager($query)
    {
        $overrideManagerIds = array_values(array_unique(array_map('intval', (array) config('manager_ratings.rater_overrides', []))));

        return $query->where(function ($q) use ($overrideManagerIds) {
            $q->whereHas('subordinates', fn ($q2) => $q2->where('is_active', true));
            if ($overrideManagerIds) {
                $q->orWhereIn('id', $overrideManagerIds);
            }
        });
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_assignments')
            ->withTimestamps();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ProjectAssignment::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class, 'created_by');
    }

    public function actionItems(): HasMany
    {
        return $this->hasMany(ActionItem::class, 'created_by');
    }

    public function dailyReports(): HasMany
    {
        return $this->hasMany(DailyReport::class, 'updated_by');
    }

    public function dailySignoffs(): HasMany
    {
        return $this->hasMany(DailySignoff::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function designationRelation(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'designation_id');
    }

    public function salaryRevisions(): HasMany
    {
        return $this->hasMany(SalaryRevision::class);
    }

    /** Sync is_active based on employee_status. */
    public function syncIsActive(): void
    {
        $activeStatuses = ['active', 'probation', 'intern'];
        $this->is_active = in_array($this->employee_status, $activeStatuses, true);
    }

    // ─── Slack Helpers ───────────────────────────────────────────

    public function hasSlackConnection(): bool
    {
        return $this->slack_access_token !== null;
    }

    public function getSlackScopes(): array
    {
        return $this->slack_scopes ? explode(',', $this->slack_scopes) : [];
    }

    public function disconnectSlack(): void
    {
        $this->update([
            'slack_user_id'          => null,
            'slack_access_token'     => null,
            'slack_refresh_token'    => null,
            'slack_team_id'          => null,
            'slack_team_name'        => null,
            'slack_scopes'           => null,
            'slack_connected_at'     => null,
            'slack_token_expires_at' => null,
        ]);
    }

    // ─── GitHub Helpers ──────────────────────────────────────────

    public function hasGitHubConnection(): bool
    {
        return $this->github_access_token !== null;
    }

    public function disconnectGitHub(): void
    {
        $this->update([
            'github_user_id'      => null,
            'github_username'     => null,
            'github_access_token' => null,
            'github_avatar_url'   => null,
            'github_scopes'       => null,
            'github_connected_at' => null,
        ]);
    }

    // ─── Google Helpers ──────────────────────────────────────────

    public function hasGoogleConnection(): bool
    {
        return $this->google_access_token !== null;
    }

    public function disconnectGoogle(): void
    {
        $this->update([
            'google_user_id'          => null,
            'google_email'            => null,
            'google_access_token'     => null,
            'google_refresh_token'    => null,
            'google_name'             => null,
            'google_avatar_url'       => null,
            'google_scopes'           => null,
            'google_connected_at'     => null,
            'google_token_expires_at' => null,
        ]);
    }

    /** Daily sign-in roster — excludes freelancers (Rohit, Yashasvi, etc.). */
    public function scopeOnSigninRoster($query)
    {
        $excluded = (array) config('signin_status_access.excluded_employment_types', ['freelancer']);

        return $query->where(function ($q) use ($excluded) {
            $q->whereNull('employment_type')
                ->orWhereNotIn('employment_type', $excluded);
        });
    }
}
