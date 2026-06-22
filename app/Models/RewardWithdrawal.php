<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RewardWithdrawal extends Model
{
    protected $fillable = [
        'user_id', 'reward_task_id', 'amount', 'status',
        'requested_at', 'paid_at', 'paid_by',
        'utr_number', 'employee_note', 'admin_note', 'cancel_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'requested_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function rewardTask(): BelongsTo
    {
        return $this->belongsTo(RewardTask::class, 'reward_task_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['pending', 'paid']);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
