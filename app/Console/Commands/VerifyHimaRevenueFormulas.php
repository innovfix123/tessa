<?php

namespace App\Console\Commands;

use App\Services\HimaRevenueFormula;
use Illuminate\Console\Command;

class VerifyHimaRevenueFormulas extends Command
{
    protected $signature = 'hima-revenue:verify-formulas';

    protected $description = 'Assert HimaRevenueFormula matches the live Google Sheet for known May-26 fixtures (within ₹1).';

    /**
     * Fixtures pulled from the May-26 tab of the Hima Revenue sheet:
     * https://docs.google.com/spreadsheets/d/1V1SWkJ0RXtmr2b-_Gu-Y1yTXOpa6i4IzDJJpW6bAXsc
     *
     * Each entry has the manual inputs and the expected computed values
     * read directly from the published CSV. We allow ±₹1 tolerance to
     * absorb rounding differences between the sheet and our formula.
     */
    private const FIXTURES = [
        [
            'date' => '2026-05-01',
            'manual' => [
                'collection' => 2373805.00,
                'zocket_meta_ads_without_gst' => 505380.29,
                'hima_creator' => 10126.21,
                'g_ads_1_without_gst' => 95838.57,
                'g_ads_2_without_gst' => 0,
                'payout' => 904516,
                'day0_revenue' => 0,
            ],
            'expected' => [
                'actual_collection' => 2344607.20,
                'collection_without_gst' => 2011699.15,
                'zocket_meta_ads_with_gst' => 596348.74,
                'main_meta_ads_with_gst' => 11948.93,
                'g_ads_1_with_gst' => 113089.51,
                'g_ads_2_with_gst' => 0,
                'total_meta_ads_spend_with_gst' => 608298,
                'total_g_ads_spend_with_gst' => 113090,
                'total_ads_spend_with_gst' => 721387,
                'total_meta_ads_spend_without_gst' => 515506.50,
                'total_g_ads_spend_without_gst' => 95838.57,
                'total_ads_spend_without_gst' => 611345.07,
                'profit' => 718704.39,
                'agora_charges' => 82061,
                'gst_collected' => 362106,
                'claim_tax_gst' => 110042,
                'gst_payable' => 252064,
                'real_profit' => 384579.65,
            ],
        ],
        [
            'date' => '2026-05-02',
            'manual' => [
                'collection' => 2297251.00,
                'zocket_meta_ads_without_gst' => 541278.02,
                'hima_creator' => 26874.98,
                'g_ads_1_without_gst' => 101439.94,
                'g_ads_2_without_gst' => 0,
                'payout' => 841276,
                'day0_revenue' => 0,
            ],
            'expected' => [
                'actual_collection' => 2268994.81,
                'collection_without_gst' => 1946822.88,
                'zocket_meta_ads_with_gst' => 638708.06,
                'main_meta_ads_with_gst' => 31712.48,
                'g_ads_1_with_gst' => 119699.13,
                'total_meta_ads_spend_with_gst' => 670421,
                'total_g_ads_spend_with_gst' => 119699,
                'total_ads_spend_with_gst' => 790120,
                'profit' => 637599.29,
                'agora_charges' => 79415,
                'gst_collected' => 350428,
                'claim_tax_gst' => 120527,
                'gst_payable' => 229901,
                'real_profit' => 328282.90,
            ],
        ],
        [
            'date' => '2026-05-03',
            'manual' => [
                'collection' => 2393342.00,
                'zocket_meta_ads_without_gst' => 599282.62,
                'hima_creator' => 20864.54,
                'g_ads_1_without_gst' => 108990.02,
                'g_ads_2_without_gst' => 0,
                'payout' => 832242,
                'day0_revenue' => 0,
            ],
            'expected' => [
                'actual_collection' => 2363903.89,
                'collection_without_gst' => 2028255.93,
                'total_meta_ads_spend_with_gst' => 731774,
                'total_g_ads_spend_with_gst' => 128608,
                'total_ads_spend_with_gst' => 860382,
                'profit' => 671279.58,
                'agora_charges' => 82737,
                'gst_collected' => 365086,
                'gst_payable' => 233841,
                'real_profit' => 354701.21,
            ],
        ],
        [
            'date' => '2026-05-04',
            'manual' => [
                'collection' => 2138706.00,
                'zocket_meta_ads_without_gst' => 325748.17,
                'hima_creator' => 0,
                'g_ads_1_without_gst' => 82890.82,
                'g_ads_2_without_gst' => 0,
                'payout' => 812103,
                'day0_revenue' => 0,
            ],
            'expected' => [
                'actual_collection' => 2112399.92,
                'collection_without_gst' => 1812462.71,
                'total_meta_ads_spend_with_gst' => 384383,
                'total_g_ads_spend_with_gst' => 97811,
                'total_ads_spend_with_gst' => 482194,
                'profit' => 818102.55,
                'agora_charges' => 73934,
                'gst_collected' => 326243,
                'gst_payable' => 252688,
                'real_profit' => 491480.28,
            ],
        ],
    ];

    public function handle(): int
    {
        $tolerance = 1.0; // ₹1
        $totalChecks = 0;
        $failures = [];

        foreach (self::FIXTURES as $fix) {
            $computed = HimaRevenueFormula::compute($fix['manual']);
            foreach ($fix['expected'] as $key => $expected) {
                $totalChecks++;
                $actual = $computed[$key] ?? null;
                if ($actual === null) {
                    $failures[] = sprintf('[%s] %s missing in computed output', $fix['date'], $key);

                    continue;
                }
                if (abs((float) $actual - (float) $expected) > $tolerance) {
                    $failures[] = sprintf(
                        '[%s] %s: expected %s, got %s (diff %.2f)',
                        $fix['date'],
                        $key,
                        number_format((float) $expected, 2),
                        number_format((float) $actual, 2),
                        abs((float) $actual - (float) $expected)
                    );
                }
            }
        }

        if (! empty($failures)) {
            $this->error(sprintf('%d/%d checks FAILED:', count($failures), $totalChecks));
            foreach ($failures as $f) {
                $this->line('  '.$f);
            }

            return self::FAILURE;
        }

        $this->info(sprintf('All %d formula checks passed across %d fixtures (tolerance ₹%.2f).',
            $totalChecks, count(self::FIXTURES), $tolerance));

        return self::SUCCESS;
    }
}
