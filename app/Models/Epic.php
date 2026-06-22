<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Epic extends Model
{
    const STATUS_OPEN = 'open';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_DONE = 'done';
    const STATUS_CANCELLED = 'cancelled';

    const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_DONE,
        self::STATUS_CANCELLED,
    ];

    const PRIORITIES = ['low', 'medium', 'high', 'critical'];

    protected $fillable = [
        'title',
        'description',
        'project_id',
        'squad_id',
        'status',
        'priority',
        'owner_id',
        'target_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
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

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stories(): HasMany
    {
        return $this->hasMany(Story::class);
    }

    public function bugs(): HasMany
    {
        return $this->hasMany(Bug::class);
    }

    public function labels(): MorphToMany
    {
        return $this->morphToMany(Label::class, 'labelable', 'agile_labelables');
    }

    public function getProgressAttribute(): int
    {
        $total = $this->stories()->count();
        if ($total === 0) {
            return 0;
        }
        $done = $this->stories()->where('status', Story::STATUS_DONE)->count();
        return (int) round(($done / $total) * 100);
    }
}
