<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RewardTask extends Model
{
    protected $fillable = [
        'assigned_to_id', 'assigned_by_id',
        'title', 'description', 'amount', 'deadline',
        'status',
        'submission_note', 'submission_evidence_url', 'submitted_at',
        'final_amount', 'reviewed_at', 'reviewed_by_id', 'review_note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'deadline' => 'date',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(RewardTaskUpdate::class)->orderBy('created_at');
    }

    public function withdrawal(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(RewardWithdrawal::class, 'reward_task_id');
    }

    public function scopeAssigned($q)
    {
        return $q->where('status', 'assigned');
    }

    public function scopeSubmitted($q)
    {
        return $q->where('status', 'submitted');
    }

    public function scopeApproved($q)
    {
        return $q->where('status', 'approved');
    }

    public function scopeForAssignee($q, int $userId)
    {
        return $q->where('assigned_to_id', $userId);
    }

    public function isLocked(): bool
    {
        return in_array($this->status, ['approved', 'rejected'], true);
    }

    public function isOverdue(): bool
    {
        if (! $this->deadline) {
            return false;
        }
        if (in_array($this->status, ['approved', 'rejected'], true)) {
            return false;
        }
        return $this->deadline->isPast() && ! $this->deadline->isToday();
    }
}
