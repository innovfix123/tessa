<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskBlocker extends Model
{
    public $timestamps = false;

    protected $fillable = ['task_id', 'note', 'created_by', 'created_at', 'dismissed_by_reporter_at'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'dismissed_by_reporter_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(TessaTask::class, 'task_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
