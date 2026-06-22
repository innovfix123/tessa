<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlackInsightUserState extends Model
{
    protected $table = 'slack_insight_user_state';

    protected $fillable = [
        'insight_id',
        'user_id',
        'status',
        'snooze_until',
        'task_id',
    ];

    protected function casts(): array
    {
        return [
            'snooze_until' => 'datetime',
        ];
    }

    public function insight(): BelongsTo
    {
        return $this->belongsTo(SlackInsight::class, 'insight_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(TessaTask::class, 'task_id');
    }
}
