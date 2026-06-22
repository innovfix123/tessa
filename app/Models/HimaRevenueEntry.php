<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HimaRevenueEntry extends Model
{
    protected $fillable = [
        'date',
        'collection',
        'zocket_meta_ads_without_gst',
        'hima_creator',
        'g_ads_1_without_gst',
        'g_ads_2_without_gst',
        'payout',
        'day0_revenue',
        'notes',
        'updated_by',
    ];

    protected $casts = [
        'date' => 'date',
        'collection' => 'decimal:2',
        'zocket_meta_ads_without_gst' => 'decimal:2',
        'hima_creator' => 'decimal:2',
        'g_ads_1_without_gst' => 'decimal:2',
        'g_ads_2_without_gst' => 'decimal:2',
        'payout' => 'decimal:2',
        'day0_revenue' => 'decimal:2',
    ];

    public const MANUAL_FIELDS = [
        'collection',
        'zocket_meta_ads_without_gst',
        'hima_creator',
        'g_ads_1_without_gst',
        'g_ads_2_without_gst',
        'payout',
        'day0_revenue',
    ];
}
