<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkforcePayment extends Model
{
    protected $fillable = [
        'user_id', 'week_start', 'week_end',
        'total_overtime_hours', 'total_amount', 'status',
        'utr_number', 'payment_screenshot_path',
        'paid_by', 'paid_at', 'admin_note',
    ];

    protected $casts = [
        'week_start' => 'date',
        'week_end' => 'date',
        'paid_at' => 'datetime',
        'total_overtime_hours' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForWeek($query, $weekStart)
    {
        return $query->where('week_start', $weekStart);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
