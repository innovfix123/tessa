<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One interview round (technical or hr) for a candidate in the Hiring/ATS
 * pipeline — schedule, AI-drafted invite, recording link, and outcome.
 */
class CandidateInterview extends Model
{
    protected $fillable = [
        'candidate_id',
        'round',
        'scheduled_at',
        'meet_link',
        'email_subject',
        'email_body',
        'email_status',
        'recording_link',
        'outcome',
        'feedback',
        'agenda',
        'conducted_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function conductor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conducted_by');
    }
}
