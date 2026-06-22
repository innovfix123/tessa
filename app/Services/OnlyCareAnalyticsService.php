<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OnlyCareAnalyticsService
{
    private string $baseUrl;

    private ?string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.only_care.base_url', 'https://onlycare.in'), '/');
        $this->token = config('services.only_care.token');
    }

    /**
     * Lifetime total revenue for OnlyCare.
     *
     * Cached for 5 minutes since the mission dashboard hits this on every
     * refresh and the upstream is rate-limited (300 req/min/IP). Failures
     * are not cached so a transient blip recovers on the next request.
     *
     * @return array{total_revenue:int,transactions_count:int,last_transaction_at:?string,as_of:?string}|null
     */
    public function getTotalRevenue(): ?array
    {
        if (! $this->token) {
            return null;
        }

        $cached = Cache::get('onlycare:total_revenue');
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->token,
                'Accept' => 'application/json',
            ])->timeout(8)->get("{$this->baseUrl}/api/internal/total-revenue");

            if (! $response->successful()) {
                Log::warning('OnlyCareAnalyticsService: total-revenue failed', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $json = $response->json();
            if (! ($json['success'] ?? false)) {
                return null;
            }

            $data = [
                'total_revenue' => (int) ($json['total_revenue'] ?? 0),
                'transactions_count' => (int) ($json['transactions_count'] ?? 0),
                'last_transaction_at' => $json['last_transaction_at'] ?? null,
                'as_of' => $json['as_of'] ?? null,
            ];
            Cache::put('onlycare:total_revenue', $data, 300);

            return $data;
        } catch (\Throwable $e) {
            Log::error('OnlyCareAnalyticsService: total-revenue exception', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Per-day revenue between $from and $to (inclusive, YYYY-MM-DD).
     *
     * @return array{total_revenue:int,transactions_count:int,days:array<int,array{date:string,revenue:int,count:int}>,as_of:?string}|null
     */
    public function getDailyRevenue(string $from, string $to): ?array
    {
        if (! $this->token) {
            return null;
        }

        $lastGoodKey = "onlycare:daily_revenue:last_good:{$from}:{$to}";
        $response = null;
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$this->token,
                    'Accept' => 'application/json',
                ])->timeout(8)->get("{$this->baseUrl}/api/internal/daily-revenue", [
                    'from' => $from,
                    'to' => $to,
                ]);
            } catch (\Throwable $e) {
                Log::warning('OnlyCareAnalyticsService: daily-revenue exception', [
                    'attempt' => $attempt, 'error' => $e->getMessage(), 'from' => $from, 'to' => $to,
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
            Log::warning('OnlyCareAnalyticsService: daily-revenue failed', [
                'status' => $response?->status(),
                'from' => $from,
                'to' => $to,
                'attempts' => $maxAttempts,
            ]);
            $lastGood = Cache::get($lastGoodKey);
            return is_array($lastGood) ? $lastGood : null;
        }

        $json = $response->json();
        if (! ($json['success'] ?? false)) {
            $lastGood = Cache::get($lastGoodKey);
            return is_array($lastGood) ? $lastGood : null;
        }

        $days = array_map(fn ($d) => [
            'date' => (string) ($d['date'] ?? ''),
            'revenue' => (int) ($d['revenue'] ?? 0),
            'count' => (int) ($d['count'] ?? 0),
        ], (array) ($json['days'] ?? []));

        $data = [
            'total_revenue' => (int) ($json['total_revenue'] ?? 0),
            'transactions_count' => (int) ($json['transactions_count'] ?? 0),
            'days' => $days,
            'as_of' => $json['as_of'] ?? null,
        ];
        Cache::put($lastGoodKey, $data, 86400);
        return $data;
    }
}
