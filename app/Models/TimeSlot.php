<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeSlot extends Model
{
    protected $fillable = [
        'timesheet_id', 'start_time', 'end_time',
        'duration_hours', 'type', 'description',
    ];

    protected $casts = [
        'duration_hours' => 'decimal:2',
    ];

    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class);
    }

    public function isOvertime(): bool
    {
        return $this->type === 'overtime';
    }
}
