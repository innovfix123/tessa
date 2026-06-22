<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RevenuePayout extends Model
{
    protected $fillable = [
        'date',
        'revenue',
        'paying_users',
        'transactions',
        'by_language',
        'payout_paid',
        'payout_paid_count',
        'payout_rejected',
        'payout_rejected_count',
        'payout_pending',
        'payout_pending_count',
        'audio_duration_sec',
        'video_duration_sec',
        'audio_minutes',
        'video_minutes',
        'agora_audio_cost_usd',
        'agora_video_cost_usd',
        'agora_total_cost_usd',
        'agora_total_cost_inr',
        'usd_inr_rate',
    ];

    protected $casts = [
        'date' => 'date',
        'by_language' => 'array',
    ];
}
