<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Timesheet extends Model
{
    protected $fillable = [
        'user_id', 'work_date', 'week_start',
        'total_hours', 'regular_hours', 'overtime_hours', 'amount',
        'hourly_rate_snapshot', 'aggregate_description',
    ];

    protected $casts = [
        'work_date' => 'date',
        'week_start' => 'date',
        'total_hours' => 'decimal:2',
        'regular_hours' => 'decimal:2',
        'overtime_hours' => 'decimal:2',
        'amount' => 'decimal:2',
        'hourly_rate_snapshot' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function timeSlots(): HasMany
    {
        return $this->hasMany(TimeSlot::class);
    }

    public function scopeForWeek($query, $weekStart)
    {
        return $query->where('week_start', $weekStart);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function isLocked(): bool
    {
        return WorkforcePayment::where('user_id', $this->user_id)
            ->where('week_start', $this->week_start->format('Y-m-d'))
            ->where('status', 'paid')
            ->exists();
    }
}
