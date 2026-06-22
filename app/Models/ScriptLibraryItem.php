<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScriptLibraryItem extends Model
{
    protected $fillable = [
        'user_id',
        'script_generation_id',
        'body',
        'language',
        'category',
        'topic',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scriptGeneration(): BelongsTo
    {
        return $this->belongsTo(ScriptGeneration::class);
    }
}
