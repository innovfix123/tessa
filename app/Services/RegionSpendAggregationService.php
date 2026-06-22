<?php

namespace App\Services;

use App\Models\DailyReport;
use App\Models\RegionAdSpendCache;
use App\Services\HimaAnalyticsService;

class RegionSpendAggregationService
{
    const REGION_TO_LANGUAGE = [
        'Tamil Nadu'      => 'tamil',
        'Kerala'          => 'malayalam',
        'Karnataka'       => 'kannada',
        'Andhra Pradesh'  => 'telugu',
        'Telangana'       => 'telugu',
        'West Bengal'     => 'bengali',
        // Identity entries: Meta now resolves the language in the parser (from the
        // "Ad set name" column) and passes a slug straight through. Google still
        // passes state names above. Unknown keys fall back to 'hindi' (see line 61).
        'tamil'           => 'tamil',
        'telugu'          => 'telugu',
        'kannada'         => 'kannada',
        'malayalam'       => 'malayalam',
        'bengali'         => 'bengali',
        'hindi'           => 'hindi',
    ];

    // The six language names searched for inside a Meta "Ad set name" value. They
    // are mutually non-overlapping as substrings, so a plain stripos can't false-match.
    const LANGUAGE_NAMES = ['tamil', 'telugu', 'kannada', 'malayalam', 'bengali', 'hindi'];

    const LANGUAGE_FIELD_KEYS = [
        'tamil'     => 'tamil_daily_ad_spend',
        'telugu'    => 'telugu_daily_ad_spend',
        'kannada'   => 'kannada_daily_ad_spend',
        'malayalam' => 'malayalam_daily_ad_spend',
        'bengali'   => 'bengali_daily_ad_spend',
        'hindi'     => 'daily_spend',
    ];

    // Companion rows that hold the same spend WITHOUT the 18% GST. Auto-filled
    // alongside the GST-inclusive value above; read-only in the Ops table.
    const LANGUAGE_FIELD_KEYS_EXCL_GST = [
        'tamil'     => 'tamil_daily_ad_spend_excl_gst',
        'telugu'    => 'telugu_daily_ad_spend_excl_gst',
        'kannada'   => 'kannada_daily_ad_spend_excl_gst',
        'malayalam' => 'malayalam_daily_ad_spend_excl_gst',
        'bengali'   => 'bengali_daily_ad_spend_excl_gst',
        'hindi'     => 'hindi_daily_ad_spend_excl_gst',
    ];

    const ANIRUDH_USER_ID = 11;

    /**
     * Store per-source language totals in cache, then combine both sources
     * and write to Anirudh's DailyReport.
     *
     * @param  string $source        'meta' or 'google'
     * @param  string $date          YYYY-MM-DD
     * @param  array  $regionAmounts ['Tamil Nadu' => 197774.35, 'Kerala' => 141452.72, ...]
     * @param  string $project
     * @return array  ['totals' => [...], 'meta' => [...], 'google' => [...]]
     */
    public static function storeAndAggregate(
        string $source,
        string $date,
        array $regionAmounts,
        string $project = 'hima'
    ): array {
        $byLang = array_fill_keys(array_keys(self::LANGUAGE_FIELD_KEYS), 0.0);

        foreach ($regionAmounts as $region => $amount) {
            $lang = self::REGION_TO_LANGUAGE[$region] ?? 'hindi';
            $byLang[$lang] += (float) $amount;
        }

        foreach ($byLang as $language => $amount) {
            RegionAdSpendCache::updateOrCreate(
                [
                    'source'         => $source,
                    'project'        => $project,
                    'reporting_date' => $date,
                    'language'       => $language,
                ],
                ['amount' => round($amount, 2)]
            );
        }

        $allCached = RegionAdSpendCache::where('project', $project)
            ->where('reporting_date', $date)
            ->get();

        $metaTotals = [];
        $googleTotals = [];
        $combined = array_fill_keys(array_keys(self::LANGUAGE_FIELD_KEYS), 0.0);

        foreach ($allCached as $row) {
            $combined[$row->language] += (float) $row->amount;
            if ($row->source === 'meta') {
                $metaTotals[$row->language] = (float) $row->amount;
            } else {
                $googleTotals[$row->language] = (float) $row->amount;
            }
        }

        $updatedBy = auth()->id() ?? self::ANIRUDH_USER_ID;

        foreach ($combined as $language => $total) {
            $fieldKey = self::LANGUAGE_FIELD_KEYS[$language] ?? null;
            if (! $fieldKey) continue;

            $exclGst = round($total);
            $withGst = round($total * 1.18);
            $combined[$language] = $withGst;

            DailyReport::updateOrCreate(
                [
                    'user_id'    => self::ANIRUDH_USER_ID,
                    'report_date' => $date,
                    'field_key'  => $fieldKey,
                ],
                [
                    'value'      => (string) $withGst,
                    'updated_by' => $updatedBy,
                ]
            );

            // Same spend, GST stripped out — written to the companion row.
            $exclKey = self::LANGUAGE_FIELD_KEYS_EXCL_GST[$language] ?? null;
            if ($exclKey) {
                DailyReport::updateOrCreate(
                    [
                        'user_id'    => self::ANIRUDH_USER_ID,
                        'report_date' => $date,
                        'field_key'  => $exclKey,
                    ],
                    [
                        'value'      => (string) $exclGst,
                        'updated_by' => $updatedBy,
                    ]
                );
            }
        }

        HimaAnalyticsService::recalculateCpa($date, $updatedBy);

        return [
            'date'   => $date,
            'totals' => array_map(fn ($v) => round($v), $combined),
            'meta'   => $metaTotals,
            'google' => $googleTotals,
        ];
    }

    /**
     * Resolve a language slug from a Meta "Ad set name" value (e.g.
     * "ROI Both | Tamilnadu" -> "tamil"). Plain-text, case-insensitive substring
     * match against the six language names. Zero matches or more than one distinct
     * language match both fall back to 'hindi' (the catch-all daily_spend bucket).
     */
    public static function languageFromAdSetName(string $adSetName): string
    {
        $hits = [];
        foreach (self::LANGUAGE_NAMES as $lang) {
            if (stripos($adSetName, $lang) !== false) {
                $hits[$lang] = true;
            }
        }

        return count($hits) === 1 ? array_key_first($hits) : 'hindi';
    }

    /**
     * Re-aggregate from existing cache (no new data). Useful for
     * recalculating after formula changes (e.g. adding GST).
     */
    public static function reaggregate(string $date, string $project = 'hima'): array
    {
        $allCached = RegionAdSpendCache::where('project', $project)
            ->where('reporting_date', $date)
            ->get();

        if ($allCached->isEmpty()) {
            return [];
        }

        $metaTotals = [];
        $googleTotals = [];
        $combined = array_fill_keys(array_keys(self::LANGUAGE_FIELD_KEYS), 0.0);

        foreach ($allCached as $row) {
            $combined[$row->language] += (float) $row->amount;
            if ($row->source === 'meta') {
                $metaTotals[$row->language] = (float) $row->amount;
            } else {
                $googleTotals[$row->language] = (float) $row->amount;
            }
        }

        $updatedBy = auth()->id() ?? self::ANIRUDH_USER_ID;

        foreach ($combined as $language => $total) {
            $fieldKey = self::LANGUAGE_FIELD_KEYS[$language] ?? null;
            if (! $fieldKey) continue;

            $exclGst = round($total);
            $withGst = round($total * 1.18);
            $combined[$language] = $withGst;

            DailyReport::updateOrCreate(
                [
                    'user_id'    => self::ANIRUDH_USER_ID,
                    'report_date' => $date,
                    'field_key'  => $fieldKey,
                ],
                [
                    'value'      => (string) $withGst,
                    'updated_by' => $updatedBy,
                ]
            );

            // Same spend, GST stripped out — written to the companion row.
            $exclKey = self::LANGUAGE_FIELD_KEYS_EXCL_GST[$language] ?? null;
            if ($exclKey) {
                DailyReport::updateOrCreate(
                    [
                        'user_id'    => self::ANIRUDH_USER_ID,
                        'report_date' => $date,
                        'field_key'  => $exclKey,
                    ],
                    [
                        'value'      => (string) $exclGst,
                        'updated_by' => $updatedBy,
                    ]
                );
            }
        }

        HimaAnalyticsService::recalculateCpa($date, $updatedBy);

        return [
            'date'   => $date,
            'totals' => array_map(fn ($v) => round($v), $combined),
            'meta'   => $metaTotals,
            'google' => $googleTotals,
        ];
    }
}
