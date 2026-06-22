<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaudeContext extends Model
{
    protected $table = 'claude_contexts';

    // Write-once record: created_at only, no updated_at.
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'context_date',
        'summary',
        'categories',
        'source',
        'created_at',
    ];

    protected $casts = [
        'context_date' => 'date',
        'categories' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
