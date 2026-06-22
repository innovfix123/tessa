<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Bug extends Model
{
    const STATUS_OPEN = 'open';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_FIXED = 'fixed';
    const STATUS_VERIFIED = 'verified';
    const STATUS_CLOSED = 'closed';
    const STATUS_WONT_FIX = 'wont_fix';

    const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_FIXED,
        self::STATUS_VERIFIED,
        self::STATUS_CLOSED,
        self::STATUS_WONT_FIX,
    ];

    const SEVERITIES = ['low', 'medium', 'high'];
    const PRIORITIES = ['minor', 'major', 'critical', 'blocker'];
    const ENVIRONMENTS = ['dev', 'staging', 'production'];

    protected $fillable = [
        'title',
        'description',
        'steps_to_reproduce',
        'project_id',
        'epic_id',
        'story_id',
        'sprint_id',
        'assignee_id',
        'reporter_id',
        'status',
        'severity',
        'priority',
        'story_points',
        'environment',
        'screenshot_path',
        'sort_order',
        'created_by',
        'resolved_at',
        'duplicate_group_id',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function epic(): BelongsTo
    {
        return $this->belongsTo(Epic::class);
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function labels(): MorphToMany
    {
        return $this->morphToMany(Label::class, 'labelable', 'agile_labelables');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(BugAttachment::class)->orderBy('id');
    }
}
