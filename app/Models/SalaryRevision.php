<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryRevision extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'effective_date',
        'previous_monthly_salary',
        'new_monthly_salary',
        'previous_annual_ctc',
        'new_annual_ctc',
        'revision_reason',
        'revised_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'previous_monthly_salary' => 'decimal:2',
            'new_monthly_salary' => 'decimal:2',
            'previous_annual_ctc' => 'decimal:2',
            'new_annual_ctc' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function revisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revised_by');
    }
}
