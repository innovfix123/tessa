<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Story extends Model
{
    const STATUS_BACKLOG = 'backlog';
    const STATUS_TODO = 'todo';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_CODE_REVIEW = 'code_review';
    const STATUS_QA = 'qa';
    const STATUS_DONE = 'done';

    const STATUSES = [
        self::STATUS_BACKLOG,
        self::STATUS_TODO,
        self::STATUS_IN_PROGRESS,
        self::STATUS_CODE_REVIEW,
        self::STATUS_QA,
        self::STATUS_DONE,
    ];

    const BOARD_COLUMNS = [
        self::STATUS_TODO,
        self::STATUS_IN_PROGRESS,
        self::STATUS_CODE_REVIEW,
        self::STATUS_QA,
        self::STATUS_DONE,
    ];

    const PRIORITIES = ['low', 'medium', 'high', 'critical'];
    const MOSCOW = ['must', 'should', 'could', 'wont'];
    const BUSINESS_VALUES = ['low', 'medium', 'high'];

    protected $fillable = [
        'title',
        'description',
        'acceptance_criteria',
        'technical_notes',
        'project_id',
        'epic_id',
        'sprint_id',
        'assignee_id',
        'reporter_id',
        'status',
        'priority',
        'moscow',
        'business_value',
        'story_points',
        'sort_order',
        'created_by',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function epic(): BelongsTo
    {
        return $this->belongsTo(Epic::class);
    }

    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(AgileTask::class);
    }

    public function bugs(): HasMany
    {
        return $this->hasMany(Bug::class);
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

    public function dependencies(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Story::class, 'story_dependencies', 'story_id', 'depends_on_story_id')
            ->withPivot('created_at');
    }

    public function dependents(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Story::class, 'story_dependencies', 'depends_on_story_id', 'story_id')
            ->withPivot('created_at');
    }
}
