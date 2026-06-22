<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegionAdSpendCache extends Model
{
    protected $table = 'region_ad_spend_cache';

    protected $fillable = [
        'source',
        'project',
        'reporting_date',
        'language',
        'amount',
    ];

    protected $casts = [
        'reporting_date' => 'date',
        'amount' => 'decimal:2',
    ];
}
