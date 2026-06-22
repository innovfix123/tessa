<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnlyCareRevenueSnapshot extends Model
{
    protected $table = 'onlycare_revenue_snapshots';

    protected $fillable = [
        'snapshot_date',
        'total_revenue',
        'transactions_count',
        'last_transaction_at',
        'source_as_of',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'total_revenue' => 'integer',
        'transactions_count' => 'integer',
        'last_transaction_at' => 'datetime',
        'source_as_of' => 'datetime',
    ];
}
