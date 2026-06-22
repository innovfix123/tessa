<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscussionPoint extends Model
{
    protected $fillable = [
        'meeting_id',
        'week_key',
        'question',
        'answer',
        'sort_order',
        'section_id',
    ];

    protected function casts(): array
    {
        return [
            'week_key' => 'date',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'meeting_id', 'meeting_key');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(AgendaSection::class, 'section_id');
    }
}
