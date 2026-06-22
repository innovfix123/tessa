<?php

namespace App\Models;

use App\Helpers\DateHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Sprint extends Model
{
    const STATUS_PLANNING = 'planning';
    const STATUS_ACTIVE = 'active';
    const STATUS_REVIEW = 'review';
    const STATUS_CLOSED = 'closed';

    const STATUSES = [
        self::STATUS_PLANNING,
        self::STATUS_ACTIVE,
        self::STATUS_REVIEW,
        self::STATUS_CLOSED,
    ];

    protected $fillable = [
        'name',
        'goal',
        'project_id',
        'squad_id',
        'status',
        'start_date',
        'end_date',
        'velocity',
        'capacity_hours',
        'review_notes',
        'retrospective_notes',
        'created_by',
        'meeting_id',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'retrospective_notes' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function squad(): BelongsTo
    {
        return $this->belongsTo(Squad::class);
    }

    public function stories(): HasMany
    {
        return $this->hasMany(Story::class);
    }

    public function bugs(): HasMany
    {
        return $this->hasMany(Bug::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function getTotalPointsAttribute(): int
    {
        return (int) $this->stories()->sum('story_points');
    }

    public function getCompletedPointsAttribute(): int
    {
        return (int) $this->stories()->where('status', Story::STATUS_DONE)->sum('story_points');
    }

    public function getDaysRemainingAttribute(): ?int
    {
        if ($this->status === self::STATUS_CLOSED) {
            return 0;
        }
        // Compare date-only (not date+time) so a sprint ending "May 10" with
        // today being "May 5" reads as 5 days, regardless of clock time.
        $today = DateHelper::today()->startOfDay();
        $end = $this->end_date->copy()->startOfDay();
        if ($end->lt($today)) {
            return 0;
        }
        return (int) $today->diffInDays($end, false);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForSquad(Builder $query, int $squadId): Builder
    {
        return $query->where('squad_id', $squadId);
    }
}
