<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SlackInsight extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'title',
        'summary',
        'source_channel',
        'source_channel_name',
        'source_message_ts',
        'meeting_id',
        'meeting_title',
        'meeting_date',
        'meeting_kind',
        'suggested_assignee_id',
        'assigned_by_user_id',
        'audience',
        'audience_user_ids',
        'meeting_attendee_ids',
        'source_note_hash',
        'source_action_item',
        'mentioned_by',
        'due_date',
        'priority',
        'confidence_score',
        'status',
        'snooze_until',
        'task_id',
        'scanned_date',
    ];

    protected function casts(): array
    {
        return [
            // Date-only fields are serialized as YYYY-MM-DD (without the ISO
            // time/microsecond suffix Laravel's default `date` cast emits)
            // so the frontend can display them directly.
            'due_date'          => 'date:Y-m-d',
            'meeting_date'      => 'date:Y-m-d',
            'scanned_date'      => 'date:Y-m-d',
            'snooze_until'      => 'datetime',
            'audience_user_ids' => 'array',
            'meeting_attendee_ids' => 'array',
            'confidence_score'  => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(TessaTask::class, 'task_id');
    }

    public function suggestedAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suggested_assignee_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'meeting_id', 'meeting_key');
    }

    public function userStates(): HasMany
    {
        return $this->hasMany(SlackInsightUserState::class, 'insight_id');
    }
}
