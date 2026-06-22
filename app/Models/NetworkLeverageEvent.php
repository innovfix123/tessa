<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkLeverageEvent extends Model
{
    protected $fillable = [
        'user_id',
        'week_key',
        'event_date',
        'event_name',
        'co_attendees',
        'attendee_count',
        'contacts',
        'linkedin_urls',
    ];

    protected $casts = [
        'event_date' => 'date',
        'attendee_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
