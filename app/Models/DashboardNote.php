<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardNote extends Model
{
    protected $fillable = ['user_id', 'title', 'body', 'items', 'is_pinned', 'reminder_interval', 'reminder_at', 'reminder_day', 'monthly_reset_on', 'last_reminded_at'];

    protected $casts = [
        'items' => 'array',
        'is_pinned' => 'boolean',
        'reminder_at' => 'datetime',
        'reminder_day' => 'integer',
        'monthly_reset_on' => 'date',
        'last_reminded_at' => 'datetime',
    ];

    /**
     * Effective day-of-month for a monthly reminder in the given IST month,
     * clamped to the last day of short months (e.g. day 31 fires on Feb 28).
     */
    public function effectiveReminderDay(\Carbon\Carbon $istMonth): ?int
    {
        if (! $this->reminder_day) {
            return null;
        }

        return min($this->reminder_day, $istMonth->daysInMonth);
    }

    /**
     * Is this monthly reminder due on the given IST day?
     */
    public function isMonthlyDueOn(\Carbon\Carbon $istDay): bool
    {
        return $this->reminder_day && $istDay->day === $this->effectiveReminderDay($istDay);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasUncheckedItems(): bool
    {
        return collect($this->items)->contains(fn ($item) => ! ($item['checked'] ?? false));
    }

    public function allChecked(): bool
    {
        return ! $this->hasUncheckedItems();
    }
}
