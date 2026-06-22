<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class KpiDefinition extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'group_name',
        'field_key',
        'field_label',
        'aggregation',
        'auto_sync',
        'input_type',
        'upload_accept',
        'upload_max_mb',
        'choices',
        'sort_order',
        'created_by',
        'effective_from',
    ];

    protected $casts = [
        'choices' => 'array',
    ];

    /**
     * Scope to definitions visible during a given week.
     * Must be used with withTrashed() so soft-deleted rows are candidates.
     */
    public function scopeVisibleForWeek(Builder $query, ?string $weekKey): Builder
    {
        if (! $weekKey) {
            return $query;
        }

        return $query
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $weekKey))
            ->where(fn (Builder $q) => $q->whereNull('deleted_at')->orWhereDate('deleted_at', '>=', $weekKey));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
