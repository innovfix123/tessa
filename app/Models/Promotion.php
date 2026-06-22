<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Promotion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'effective_date',
        'old_designation',
        'new_designation',
        'old_role_id',
        'new_role_id',
        'old_department_id',
        'new_department_id',
        'salary_revision_id',
        'promotion_type',
        'notes',
        'promoted_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function promotedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'promoted_by');
    }

    public function salaryRevision(): BelongsTo
    {
        return $this->belongsTo(SalaryRevision::class);
    }

    public function oldRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'old_role_id');
    }

    public function newRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'new_role_id');
    }
}
