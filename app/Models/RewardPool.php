<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A team performance reward pool a manager (e.g. Krishnan) logs and sends to the
 * payer (Ayush). No assignee, no approval — direct creator → payer payout.
 */
class RewardPool extends Model
{
    protected $fillable = [
        'created_by', 'title', 'description', 'amount', 'status',
        'paid_at', 'paid_by', 'utr_number', 'admin_note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
