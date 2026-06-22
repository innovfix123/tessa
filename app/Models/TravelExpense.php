<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One logged commute behind the Travel Allowance tab (date, route, amount,
 * payment screenshots). Trips for a calendar month roll up into one pending
 * `travel` Bill (`bill_id`) for the existing Pay Queue. The Drive/Sheet columns
 * are dormant-ready sync state. See TravelExpenseService + TravelExpenseSyncService.
 */
class TravelExpense extends Model
{
    protected $fillable = [
        'user_id', 'trip_date', 'month_key',
        'from_label', 'to_label', 'amount', 'note',
        'screenshots',
        'screenshot_path', 'screenshot_name',
        'drive_file_id', 'drive_link', 'sheet_synced_at', 'bill_id',
    ];

    protected $casts = [
        'trip_date'       => 'date',
        'amount'          => 'decimal:2',
        'sheet_synced_at' => 'datetime',
        'screenshots'     => 'array',
    ];

    /** Public URL to the first payment screenshot (Drive copy preferred, then local). */
    public function getScreenshotUrlAttribute(): ?string
    {
        $screenshots = $this->screenshots;
        if (! empty($screenshots)) {
            $first = $screenshots[0];
            $url = $first['drive_link'] ?? null;
            if (! $url && ! empty($first['path'])) {
                $url = asset('storage/' . $first['path']);
            }

            return $url ?: null;
        }
        // Legacy fallback for rows predating the screenshots column.
        return $this->screenshot_path ? asset('storage/' . $this->screenshot_path) : null;
    }

    /** All payment screenshots as [{url, name, synced}] — used by the trip list UI. */
    public function getScreenshotUrlsAttribute(): array
    {
        $screenshots = $this->screenshots;
        if (! empty($screenshots)) {
            $result = [];
            foreach ($screenshots as $i => $s) {
                $url = $s['drive_link'] ?? null;
                if (! $url && ! empty($s['path'])) {
                    $url = asset('storage/' . $s['path']);
                }
                if (! $url) {
                    continue;
                }
                $result[] = [
                    'url'    => $url,
                    'name'   => $s['name'] ?? ('Screenshot ' . ($i + 1)),
                    'synced' => ! empty($s['drive_file_id']),
                ];
            }

            return $result;
        }
        // Legacy fallback.
        $url = $this->screenshot_path ? asset('storage/' . $this->screenshot_path) : null;
        if (! $url) {
            return [];
        }

        return [['url' => $url, 'name' => 'Screenshot', 'synced' => ! empty($this->drive_file_id)]];
    }

    /** 'Home → Office' display label. */
    public function getRouteLabelAttribute(): string
    {
        return trim((string) $this->from_label) . ' → ' . trim((string) $this->to_label);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'bill_id');
    }

    public function scopeForMonth($query, int $userId, string $monthKey)
    {
        return $query->where('user_id', $userId)->where('month_key', $monthKey);
    }
}
