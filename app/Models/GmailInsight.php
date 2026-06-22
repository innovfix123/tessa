<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GmailInsight extends Model
{
    protected $fillable = [
        'user_id',
        'gmail_message_id',
        'gmail_thread_id',
        'subject',
        'sender',
        'summary',
        'snippet',
        'category',
        'priority',
        'received_at',
        'confidence_score',
        'status',
        'snooze_until',
        'task_id',
        'scanned_date',
    ];

    protected function casts(): array
    {
        return [
            'received_at'      => 'datetime',
            'snooze_until'     => 'datetime',
            'scanned_date'     => 'date:Y-m-d',
            'confidence_score' => 'decimal:2',
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
}
