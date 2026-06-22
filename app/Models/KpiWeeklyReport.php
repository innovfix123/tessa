<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiWeeklyReport extends Model
{
    /** We only track updated_at + submitted_at — no created_at column. */
    const CREATED_AT = null;

    protected $fillable = [
        'kpi_item_id',
        'user_id',
        'manager_id',
        'week_key',
        'report_text',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'week_key'     => 'date',
            'submitted_at' => 'datetime',
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

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }
}
