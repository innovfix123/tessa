<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssuedLetter extends Model
{
    protected $fillable = [
        'letter_type',
        'employee_category',
        'status',
        'recipient_user_id',
        'recipient_name',
        'recipient_email',
        'recipient_phone',
        'role_title',
        'department',
        'start_date',
        'letter_date',
        'payload',
        'body_html',
        'body_overridden',
        'pdf_path',
        'issued_by_user_id',
        'issued_at',
        'share_token',
    ];

    protected $casts = [
        'payload' => 'array',
        'body_overridden' => 'boolean',
        'start_date' => 'date',
        'letter_date' => 'date',
        'issued_at' => 'datetime',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ISSUED = 'issued';

    public const TYPE_OFFER = 'offer';
    public const TYPE_APPOINTMENT = 'appointment';
    public const TYPE_PROBATION = 'probation';
    // Offboarding letters for departing employees — category-agnostic.
    public const TYPE_RELIEVING = 'relieving';
    public const TYPE_EXPERIENCE = 'experience';

    public const CAT_FREELANCER = 'freelancer';
    public const CAT_INTERN = 'intern';
    public const CAT_FULLTIME = 'fulltime';

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }
}
