<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskParticipant extends Model
{
    public $timestamps = false;

    protected $fillable = ['task_id', 'user_id', 'role', 'created_at', 'last_read_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'last_read_at' => 'datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(TessaTask::class, 'task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
