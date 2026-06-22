<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiMonthlySummary extends Model
{
    protected $fillable = [
        'user_id',
        'kpi_item_id',
        'month_key',
        'summary_text',
        'percentage_met',
        'status',
        'generated_at',
        'model',
    ];

    protected function casts(): array
    {
        return [
            'percentage_met' => 'int',
            'generated_at'   => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(KpiScorecardItem::class, 'kpi_item_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
