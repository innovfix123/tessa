<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TessaMessage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tessa_chat_id',
        'role',
        'content',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(TessaChat::class, 'tessa_chat_id');
    }
}
