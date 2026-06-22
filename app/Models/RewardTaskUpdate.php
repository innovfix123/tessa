<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RewardTaskUpdate extends Model
{
    protected $fillable = [
        'reward_task_id', 'user_id', 'note', 'evidence_url',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(RewardTask::class, 'reward_task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
