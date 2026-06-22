<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScriptGeneration extends Model
{
    protected $fillable = [
        'user_id',
        'language',
        'category',
        'topic',
        'creative_brief',
        'requested_count',
        'scripts',
    ];

    protected function casts(): array
    {
        return [
            'scripts' => 'array',
            'requested_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function libraryItems(): HasMany
    {
        return $this->hasMany(ScriptLibraryItem::class);
    }
}
