<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailySignoff extends Model
{
    protected $fillable = [
        'user_id',
        'signoff_date',
        'signed_off_at',
        'pending_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'signoff_date' => 'date',
            'signed_off_at' => 'datetime',
            'pending_snapshot' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
