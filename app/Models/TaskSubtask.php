<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskSubtask extends Model
{
    public $timestamps = false;

    protected $fillable = ['task_id', 'title', 'is_completed', 'sort_order', 'created_at'];

    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(TessaTask::class, 'task_id');
    }
}
