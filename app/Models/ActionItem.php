<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionItem extends Model
{
    protected $fillable = [
        'meeting_id',
        'week_key',
        'task',
        'owner',
        'deadline',
        'status',
        'priority',
        'linked_kpi',
        'completed_at',
        'comment',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'week_key' => 'date',
            'deadline' => 'date',
            'completed_at' => 'date',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'meeting_id', 'meeting_key');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
