<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'description',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * activity_logs.created_at is stored as IST wall time (not UTC).
     */
    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value === null) {
                    return null;
                }
                if ($value instanceof Carbon) {
                    return $value->timezone('Asia/Kolkata');
                }

                return Carbon::parse($value, 'Asia/Kolkata');
            },
            set: function ($value) {
                if ($value === null) {
                    return null;
                }
                $carbon = $value instanceof Carbon
                    ? $value->copy()->timezone('Asia/Kolkata')
                    : Carbon::parse($value, 'Asia/Kolkata');

                return $carbon->format('Y-m-d H:i:s');
            },
        );
    }

    public static function boot(): void
    {
        parent::boot();
        static::creating(function (ActivityLog $model) {
            if (empty($model->created_at)) {
                $model->created_at = Carbon::now('Asia/Kolkata');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
