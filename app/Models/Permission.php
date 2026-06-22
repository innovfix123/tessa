<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Permission extends Model
{
    protected $fillable = ['role_id', 'permission'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
