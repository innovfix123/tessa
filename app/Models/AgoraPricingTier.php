<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgoraPricingTier extends Model
{
    protected $fillable = [
        'type',
        'min_minutes',
        'max_minutes',
        'tier_label',
        'price_usd',
    ];

    /**
     * Get the flat rate (USD per 1000 min) for a given monthly volume.
     * Agora bills at a flat rate determined by total monthly usage bracket.
     */
    public static function getRateForVolume(int $monthlyMinutes, string $type = 'audio'): float
    {
        $tier = self::where('type', $type)
            ->where('min_minutes', '<=', $monthlyMinutes)
            ->where(function ($q) use ($monthlyMinutes) {
                $q->where('max_minutes', '>=', $monthlyMinutes)
                  ->orWhereNull('max_minutes');
            })
            ->first();

        return $tier ? (float) $tier->price_usd : 0.0;
    }

    /**
     * Calculate Agora cost for daily minutes using monthly volume for tier selection.
     *
     * @param  int    $dailyMinutes    Minutes used on this day
     * @param  int    $monthlyMinutes  Total monthly volume (to determine tier rate)
     * @param  string $type            'audio' or 'video_hd'
     * @return float  Cost in USD
     */
    public static function calculateDailyCost(int $dailyMinutes, int $monthlyMinutes, string $type = 'audio'): float
    {
        $rate = self::getRateForVolume($monthlyMinutes, $type);

        return round(($dailyMinutes / 1000) * $rate, 2);
    }
}
