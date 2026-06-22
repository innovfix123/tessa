<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TessaChat extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'is_pinned',
        'is_archived',
    ];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'is_archived' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TessaMessage::class);
    }
}
