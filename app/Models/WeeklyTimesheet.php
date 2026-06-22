<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyTimesheet extends Model
{
    protected $fillable = [
        'user_id', 'week_start', 'week_end',
        'regular_hours', 'regular_summary',
        'overtime_hours', 'overtime_summary',
        'overtime_saturday', 'overtime_sunday',
        'total_hours', 'status', 'submitted_at', 'updated_by',
    ];

    protected $casts = [
        'week_start' => 'date',
        'week_end' => 'date',
        'regular_hours' => 'decimal:2',
        'overtime_hours' => 'decimal:2',
        'total_hours' => 'decimal:2',
        'overtime_saturday' => 'boolean',
        'overtime_sunday' => 'boolean',
        'submitted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForWeek($query, $weekStart)
    {
        return $query->where('week_start', $weekStart);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * The Monday (IST) of the week containing the given date.
     */
    public static function mondayOf(string|Carbon|null $date = null): Carbon
    {
        $c = $date instanceof Carbon
            ? $date->copy()
            : Carbon::parse($date ?: 'now', 'Asia/Kolkata');

        return $c->startOfWeek(Carbon::MONDAY)->startOfDay();
    }
}
