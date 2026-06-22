<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    const SLUG_CASUAL = 'casual';
    const SLUG_SICK = 'sick';
    const SLUG_EMERGENCY = 'emergency';
    const SLUG_WFH = 'wfh';
    const SLUG_MENSTRUAL = 'menstrual';
    const SLUG_PERMISSION = 'permission';

    protected $fillable = [
        'name', 'slug', 'requires_approval', 'is_active', 'is_hourly', 'gender_restricted',
    ];

    protected $casts = [
        'requires_approval' => 'boolean',
        'is_active' => 'boolean',
        'is_hourly' => 'boolean',
    ];

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForGender($query, ?string $gender)
    {
        return $query->where(function ($q) use ($gender) {
            $q->whereNull('gender_restricted')
              ->orWhere('gender_restricted', $gender);
        });
    }
}
