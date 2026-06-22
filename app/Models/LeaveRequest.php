<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    protected $fillable = [
        'user_id', 'leave_type_id', 'start_date', 'end_date', 'compensation_date',
        'total_days', 'hours',
        'from_time', 'to_time',
        'reason', 'status', 'approved_by', 'reviewed_at', 'reviewer_note', 'applied_via',
        'cancellation_requested_at', 'cancellation_reason',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'compensation_date' => 'date',
        'reviewed_at' => 'datetime',
        'cancellation_requested_at' => 'datetime',
        'total_days' => 'integer',
        'hours' => 'decimal:1',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /** Approved leave whose owner has requested cancellation, awaiting manager review. */
    public function hasPendingCancellation(): bool
    {
        return $this->status === 'approved' && $this->cancellation_requested_at !== null;
    }
}
