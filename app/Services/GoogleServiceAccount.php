<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Server-side Google auth via a service account (Features 5 & 6). Signs a JWT
 * with the service-account private key (RS256) and exchanges it for an OAuth
 * access token scoped to Sheets + Drive — no logged-in user required.
 *
 * Mirrors the raw-Http style of GoogleUserService (no google/apiclient dep).
 * DORMANT-safe: isConfigured() is false until the JSON key file exists, and all
 * callers must skip when it returns false. See config('services.google.service_account').
 */
class GoogleServiceAccount
{
    public const SCOPES = 'https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/drive';

    private ?array $key = null;

    public function __construct()
    {
        $path = config('services.google.service_account.json_path');
        if ($path && is_file($path)) {
            $json = json_decode((string) file_get_contents($path), true);
            if (is_array($json) && ! empty($json['client_email']) && ! empty($json['private_key'])) {
                $this->key = $json;
            } else {
                Log::warning('GoogleServiceAccount: key file present but missing client_email/private_key', ['path' => $path]);
            }
        }
    }

    public function isConfigured(): bool
    {
        return $this->key !== null;
    }

    public function clientEmail(): ?string
    {
        return $this->key['client_email'] ?? null;
    }

    /**
     * A bearer access token (cached ~50 min). Returns null when unconfigured.
     * Throws only on a genuine exchange failure (callers wrap in try/catch).
     */
    public function accessToken(): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $cacheKey = 'gsa_token_' . md5($this->key['client_email'] . self::SCOPES);

        return Cache::remember($cacheKey, 3000, function () {
            $now = time();
            $tokenUri = $this->key['token_uri'] ?? 'https://oauth2.googleapis.com/token';

            $segments = $this->b64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
                . '.' . $this->b64url((string) json_encode([
                    'iss' => $this->key['client_email'],
                    'scope' => self::SCOPES,
                    'aud' => $tokenUri,
                    'iat' => $now,
                    'exp' => $now + 3600,
                ]));

            $signature = '';
            if (! openssl_sign($segments, $signature, $this->key['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('GoogleServiceAccount: JWT signing failed (bad private_key?).');
            }
            $jwt = $segments . '.' . $this->b64url($signature);

            $resp = Http::asForm()->timeout(15)->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            $token = $resp->json('access_token');
            if (! $token) {
                Log::warning('GoogleServiceAccount: token exchange failed', ['status' => $resp->status(), 'body' => $resp->json()]);
                throw new RuntimeException('Google service-account token exchange failed.');
            }

            return $token;
        });
    }

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
