<?php

namespace App\Services;

class HimaRevenueFormula
{
    public const PAYIN_COMMISSION_PCT = 1.23;

    public const GST_RATE = 0.18;

    public const AGORA_RATE = 0.035;

    /**
     * Apply every formula to a set of manual values. Returns a flat array
     * keyed by column name. Null inputs are treated as 0 for arithmetic
     * but preserved as null in the output where the column is purely a
     * pass-through manual field.
     *
     * @param  array<string,float|int|string|null>  $manual
     * @return array<string,float|int|null>
     */
    public static function compute(array $manual): array
    {
        $f = static fn ($k) => is_numeric($manual[$k] ?? null) ? (float) $manual[$k] : 0.0;

        $collection                 = $f('collection');
        $zocketWithoutGst           = $f('zocket_meta_ads_without_gst');
        $himaCreator                = $f('hima_creator');
        $g1WithoutGst               = $f('g_ads_1_without_gst');
        $g2WithoutGst               = $f('g_ads_2_without_gst');
        $payout                     = $f('payout');
        $day0Revenue                = $f('day0_revenue');

        $payinCommissionPct         = self::PAYIN_COMMISSION_PCT;
        $actualCollection           = $collection - ($payinCommissionPct / 100) * $collection;
        $collectionWithoutGst       = $collection / 1.18;

        $zocketWithGst              = round($zocketWithoutGst * 1.18, 2);
        $mainMetaWithGst            = round($himaCreator * 1.18, 2);
        $g1WithGst                  = round($g1WithoutGst * 1.18, 2);
        $g2WithGst                  = round($g2WithoutGst * 1.18, 2);

        $totalMetaWithGst           = $zocketWithGst + $mainMetaWithGst;
        $totalGAdsWithGst           = $g1WithGst + $g2WithGst;
        $totalAdsWithGst            = $totalMetaWithGst + $totalGAdsWithGst;

        $totalMetaWithoutGst        = $zocketWithoutGst + $himaCreator;
        $totalGAdsWithoutGst        = $g1WithoutGst + $g2WithoutGst;
        $totalAdsWithoutGst         = $totalMetaWithoutGst + $totalGAdsWithoutGst;

        $profit                     = $actualCollection - ($totalMetaWithGst + $totalGAdsWithGst + $payout);
        $roas                       = $totalAdsWithGst > 0 ? $collection / $totalAdsWithGst : null;
        $day0Roas                   = $totalAdsWithGst > 0 ? ($day0Revenue / $totalAdsWithGst) * 100 : null;

        $agoraCharges               = round(self::AGORA_RATE * $actualCollection);
        $gstCollected               = $collection * (self::GST_RATE / (1 + self::GST_RATE));
        $claimTaxGst                = ($zocketWithGst + $mainMetaWithGst + $g1WithGst + $g2WithGst)
                                      * (self::GST_RATE / (1 + self::GST_RATE));
        $gstPayable                 = $gstCollected - $claimTaxGst;

        $realProfit                 = $profit - $gstPayable - $agoraCharges;

        return [
            'payin_commission_pct'             => $payinCommissionPct,
            'actual_collection'                => $actualCollection,
            'collection_without_gst'           => $collectionWithoutGst,
            'zocket_meta_ads_with_gst'         => $zocketWithGst,
            'main_meta_ads_with_gst'           => $mainMetaWithGst,
            'g_ads_1_with_gst'                 => $g1WithGst,
            'g_ads_2_with_gst'                 => $g2WithGst,
            'total_meta_ads_spend_with_gst'    => $totalMetaWithGst,
            'total_g_ads_spend_with_gst'       => $totalGAdsWithGst,
            'total_ads_spend_with_gst'         => $totalAdsWithGst,
            'total_meta_ads_spend_without_gst' => $totalMetaWithoutGst,
            'total_g_ads_spend_without_gst'    => $totalGAdsWithoutGst,
            'total_ads_spend_without_gst'      => $totalAdsWithoutGst,
            'profit'                           => $profit,
            'roas'                             => $roas,
            'day0_roas'                        => $day0Roas,
            'agora_charges'                    => $agoraCharges,
            'gst_collected'                    => $gstCollected,
            'claim_tax_gst'                    => $claimTaxGst,
            'gst_payable'                      => $gstPayable,
            'real_profit'                      => $realProfit,
        ];
    }
}
