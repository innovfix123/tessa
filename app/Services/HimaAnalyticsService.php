<?php

namespace App\Services;

use App\Models\DailyReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HimaAnalyticsService
{
    private string $baseUrl;
    private string $appUrl;
    private string $token;
    private string $internalToken;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.hima_analytics.base_url', ''), '/');
        $this->appUrl = rtrim(config('services.hima_analytics.app_url', 'https://himaapp.in'), '/');
        $this->token = (string) config('services.hima_analytics.token', '');
        $this->internalToken = (string) config('services.hima_analytics.internal_token', $this->token);
    }

    /**
     * Fetch conversion metrics for a given date.
     *
     * @return array|null Raw API response or null on failure
     */
    public function getConversion(string $date): ?array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => $this->token,
                'Accept' => 'application/json',
            ])->get("{$this->baseUrl}/api/conversion", [
                'date' => $date,
            ]);

            if (! $response->successful()) {
                Log::warning('HimaAnalyticsService: API request failed', [
                    'date' => $date,
                    'status' => $response->status(),
                ]);
                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::error('HimaAnalyticsService: exception', [
                'date' => $date,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Fetch daily call/duration stats for a given date.
     *
     * @return array|null Raw API response or null on failure
     */
    public function getDailyCalls(string $date): ?array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => $this->token,
                'Accept' => 'application/json',
            ])->get("{$this->baseUrl}/api/daily-calls", [
                'date' => $date,
            ]);

            if (! $response->successful()) {
                Log::warning('HimaAnalyticsService: daily-calls failed', [
                    'date' => $date,
                    'status' => $response->status(),
                ]);
                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::error('HimaAnalyticsService: daily-calls exception', [
                'date' => $date,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Fetch daily revenue & payout data for a given date.
     *
     * @return array|null Raw API response or null on failure
     */
    public function getDailyRevenuePayout(string $date): ?array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => $this->token,
                'Accept' => 'application/json',
            ])->get("{$this->baseUrl}/api/daily-revenue-payout", [
                'date' => $date,
            ]);

            if (! $response->successful()) {
                Log::warning('HimaAnalyticsService: daily-revenue-payout failed', [
                    'date' => $date,
                    'status' => $response->status(),
                ]);
                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::error('HimaAnalyticsService: daily-revenue-payout exception', [
                'date' => $date,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Fetch paid registered users by language for a given date.
     *
     * @return array|null Raw API response or null on failure
     */
    public function getPaidRegisteredByLanguage(string $date): ?array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => $this->token,
                'Accept' => 'application/json',
            ])->get("{$this->appUrl}/api/reports/paid-registered-by-language", [
                'from_date' => $date,
                'to_date' => $date,
            ]);

            if (! $response->successful()) {
                Log::warning('HimaAnalyticsService: paid-registered-by-language failed', [
                    'date' => $date,
                    'status' => $response->status(),
                ]);
                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::error('HimaAnalyticsService: paid-registered-by-language exception', [
                'date' => $date,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Fetch the Hima admin "user report" for one date from the endpoint configured
     * in config('cpa_master_sheet.sync.endpoint') — a full URL, or a path appended
     * to HIMA_APP_URL. Returns the decoded JSON (assoc) or null when the endpoint
     * is unset / the call fails. The response shape is mapped to CPA-sheet columns
     * by config('cpa_master_sheet.sync.field_map') (dot-notation), so this method
     * stays response-agnostic. See HimaCpaSheetSyncService.
     */
    public function getUserReport(string $date): ?array
    {
        $endpoint = trim((string) config('cpa_master_sheet.sync.endpoint', ''));
        if ($endpoint === '') {
            return null;
        }

        $url = str_starts_with($endpoint, 'http') ? $endpoint : $this->appUrl . '/' . ltrim($endpoint, '/');
        $params = config('cpa_master_sheet.sync.date_param_style', 'date') === 'from_to'
            ? ['from_date' => $date, 'to_date' => $date]
            : ['date' => $date];

        // This endpoint is token-authed with its OWN token (distinct from the
        // analytics token, which the old open endpoints ignored); fall back to the
        // analytics token for back-compat when no dedicated token is configured.
        $token = (string) (config('cpa_master_sheet.sync.token') ?: $this->token);

        try {
            $response = Http::withHeaders([
                'Authorization' => $token,
                'Accept' => 'application/json',
            ])->timeout(20)->get($url, $params);

            if (! $response->successful()) {
                Log::warning('HimaAnalyticsService: user-report failed', [
                    'date' => $date, 'url' => $url, 'status' => $response->status(),
                ]);
                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::error('HimaAnalyticsService: user-report exception', [
                'date' => $date, 'url' => $url, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // Upstream caps each request at 92 days; 90 keeps a small safety margin.
    private const DAILY_REVENUE_CHUNK_DAYS = 90;

    /**
     * Per-day revenue between $from and $to (inclusive, YYYY-MM-DD) from himaapp.in.
     *
     * @return array{total_revenue:int,total_paying_users:int,total_transactions:int,days:array<int,array{date:string,revenue:int,paying_users:int,transactions:int}>,as_of:?string}|null
     */
    public function getDailyRevenue(string $from, string $to): ?array
    {
        if ($this->internalToken === '') {
            return null;
        }

        try {
            $start = CarbonImmutable::parse($from)->startOfDay();
            $end = CarbonImmutable::parse($to)->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }

        if ($start->gt($end)) {
            return null;
        }

        $daysByDate = [];
        $cursor = $start;
        while ($cursor->lte($end)) {
            $chunkEnd = $cursor->addDays(self::DAILY_REVENUE_CHUNK_DAYS - 1);
            if ($chunkEnd->gt($end)) {
                $chunkEnd = $end;
            }

            $chunk = $this->fetchDailyRevenueChunk(
                $cursor->format('Y-m-d'),
                $chunkEnd->format('Y-m-d'),
            );
            if ($chunk === null) {
                return null;
            }

            foreach ($chunk['days'] as $row) {
                if (! isset($row['date'])) {
                    continue;
                }
                $daysByDate[$row['date']] = $row;
            }

            $cursor = $chunkEnd->addDay();
        }

        ksort($daysByDate);
        $days = array_values($daysByDate);

        $totalRevenue = 0;
        $totalTransactions = 0;
        foreach ($days as $row) {
            $totalRevenue += (int) ($row['revenue'] ?? 0);
            $totalTransactions += (int) ($row['transactions'] ?? 0);
        }

        return [
            'total_revenue' => $totalRevenue,
            'total_paying_users' => 0, // distinct across range — only upstream can compute; not used in dashboard
            'total_transactions' => $totalTransactions,
            'days' => $days,
            'as_of' => $days !== [] ? end($days)['date'] : null,
        ];
    }

    /**
     * Fetch one chunk (up to 92 days) of daily revenue from himaapp.in.
     *
     * @return array{days:array<int,array<string,mixed>>}|null
     */
    private function fetchDailyRevenueChunk(string $from, string $to): ?array
    {
        // Upstream is flaky (~70% 5xx in bursts). Retry; on total failure, serve the last good snapshot.
        $lastGoodKey = "hima:daily_revenue:last_good:{$from}:{$to}";
        $response = null;
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => $this->internalToken,
                    'Accept' => 'application/json',
                ])->timeout(30)->get("{$this->appUrl}/api/internal/daily-revenue", [
                    'from' => $from,
                    'to' => $to,
                ]);
            } catch (\Throwable $e) {
                Log::warning('HimaAnalyticsService: daily-revenue exception', [
                    'attempt' => $attempt, 'from' => $from, 'to' => $to, 'error' => $e->getMessage(),
                ]);
                if ($attempt < $maxAttempts) {
                    usleep(350_000);
                }
                continue;
            }

            if ($response->successful() || $response->clientError()) {
                break;
            }
            if ($attempt < $maxAttempts) {
                usleep(350_000);
            }
        }

        if (! $response || ! $response->successful()) {
            Log::warning('HimaAnalyticsService: daily-revenue failed', [
                'from' => $from,
                'to' => $to,
                'status' => $response?->status(),
                'attempts' => $maxAttempts,
            ]);
            $lastGood = Cache::get($lastGoodKey);
            return is_array($lastGood) ? $lastGood : null;
        }

        $json = $response->json();
        $days = array_map(fn ($d) => [
            'date' => (string) ($d['date'] ?? ''),
            'revenue' => (int) ($d['revenue'] ?? 0),
            'paying_users' => (int) ($d['paying_users'] ?? 0),
            'transactions' => (int) ($d['transactions'] ?? 0),
        ], (array) ($json['days'] ?? []));

        $data = ['days' => $days];
        Cache::put($lastGoodKey, $data, 86400);
        return $data;
    }

    /**
     * Map API response to KPI daily report fields, grouped by user ID.
     *
     * @return array<int, array<string, string>> user_id => [field_key => value]
     */
    public static function mapToKpiFields(array $data): array
    {
        $byUser = [];
        $langData = [];

        foreach ($data['languages'] ?? [] as $lang) {
            $langData[$lang['language'] ?? ''] = $lang;
        }

        // Sneha (user 5) — Tamil, Telugu, Kannada, Malayalam
        $snehaLanguages = ['Tamil' => 'tamil', 'Telugu' => 'telugu', 'Kannada' => 'kannada', 'Malayalam' => 'malayalam'];
        foreach ($snehaLanguages as $name => $prefix) {
            if (! isset($langData[$name])) {
                continue;
            }
            $lang = $langData[$name];
            $byUser[5]["{$prefix}_new_users_paying_conversion_pct"] = (string) ($lang['conversion_pct'] ?? '');
            $byUser[5]["{$prefix}_new_users_avg_paying_amount"] = (string) ($lang['avg_paying_amount'] ?? '');
        }

        // Anindita (user 17) — Bengali + Hindi
        if (isset($langData['Bengali'])) {
            $bengali = $langData['Bengali'];
            $byUser[17]['bengali_reg_to_purchase_pct'] = (string) ($bengali['conversion_pct'] ?? '');
            $byUser[17]['bengali_avg_paying_amount'] = (string) ($bengali['avg_paying_amount'] ?? '');
        }
        if (isset($langData['Hindi'])) {
            $hindi = $langData['Hindi'];
            $byUser[17]['hindi_reg_to_purchase_pct'] = (string) ($hindi['conversion_pct'] ?? '');
            $byUser[17]['hindi_avg_paying_amount'] = (string) ($hindi['avg_paying_amount'] ?? '');
        }

        return $byUser;
    }

    private const ANIRUDH_USER_ID = 11;

    private const CPA_MAP = [
        'tamil_daily_ad_spend' => ['cpa' => 'tamil_cpa', 'users' => 'tamil_total_paid_registered_users'],
        'telugu_daily_ad_spend' => ['cpa' => 'telugu_cpa', 'users' => 'telugu_total_paid_registered_users'],
        'kannada_daily_ad_spend' => ['cpa' => 'kannada_cpa', 'users' => 'kannada_total_paid_registered_users'],
        'malayalam_daily_ad_spend' => ['cpa' => 'malayalam_cpa', 'users' => 'malayalam_total_paid_registered_users'],
        'bengali_daily_ad_spend' => ['cpa' => 'bengali_cpa', 'users' => 'bengali_total_paid_registered_users'],
        'daily_spend' => ['cpa' => 'cpa', 'users' => 'hindi_total_paid_registered_users'],
    ];

    /**
     * Recalculate CPA for all of Anirudh's languages on a given date.
     * CPA = Daily Ad Spend / Total Paid Registered Users
     */
    public static function recalculateCpa(string $date, int $updatedBy = 1): int
    {
        $entries = DailyReport::where('user_id', self::ANIRUDH_USER_ID)
            ->where('report_date', $date)
            ->pluck('value', 'field_key')
            ->toArray();

        $saved = 0;
        foreach (self::CPA_MAP as $spendKey => $fields) {
            $spend = (float) preg_replace('/[^0-9.]/', '', $entries[$spendKey] ?? '');
            $users = (int) ($entries[$fields['users']] ?? 0);

            if ($spend <= 0 || $users <= 0) {
                continue;
            }

            $cpa = round($spend / $users, 2);

            DailyReport::updateOrCreate(
                [
                    'user_id' => self::ANIRUDH_USER_ID,
                    'report_date' => $date,
                    'field_key' => $fields['cpa'],
                ],
                [
                    'value' => (string) $cpa,
                    'updated_by' => $updatedBy,
                ]
            );
            $saved++;
        }

        return $saved;
    }

    /**
     * Check if a field key triggers CPA recalculation for Anirudh.
     */
    public static function isCpaTriggerField(int $userId, string $fieldKey): bool
    {
        if ($userId !== self::ANIRUDH_USER_ID) {
            return false;
        }

        return isset(self::CPA_MAP[$fieldKey]);
    }
}
