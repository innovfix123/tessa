<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistItemCompletion extends Model
{
    protected $fillable = [
        'checklist_item_id',
        'user_id',
        'check_date',
        'checked_at',
        'note',
        'assigner_dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'check_date' => 'date',
            'checked_at' => 'datetime',
            'assigner_dismissed_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class, 'checklist_item_id');
    }
}
