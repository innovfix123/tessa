<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgendaSection extends Model
{
    protected $fillable = [
        'meeting_id',
        'week_key',
        'title',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'week_key' => 'date',
        ];
    }

    public function discussionPoints(): HasMany
    {
        return $this->hasMany(DiscussionPoint::class, 'section_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
