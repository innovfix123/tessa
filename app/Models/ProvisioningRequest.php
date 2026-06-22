<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Account-provisioning ticket for an accepted candidate — the generated login
 * id plus the Tessa-login and Gmail/Slack done-tracking (Phase 4).
 */
class ProvisioningRequest extends Model
{
    protected $fillable = [
        'candidate_id',
        'generated_email',
        'email_strategy',
        'tessa_account_user_id',
        'tessa_done_at',
        'workspace_assignee_id',
        'workspace_done_at',
        'status',
    ];

    protected $casts = [
        'tessa_done_at' => 'datetime',
        'workspace_done_at' => 'datetime',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }
}
