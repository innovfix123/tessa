<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Models\OauthAccessToken;
use App\Models\OauthAuthorizationCode;
use App\Models\OauthClient;
use App\Models\OauthRefreshToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TokenController extends Controller
{
    // POST /oauth/token
    //
    // Issues access + refresh tokens. Supports two grants:
    //   - authorization_code: exchange the one-time code (with PKCE verifier)
    //     for tokens. The bound user_id rides on the access token.
    //   - refresh_token: rotate the access token. Old refresh+access pair
    //     are revoked atomically; client gets a fresh pair.
    //
    // Audience (RFC 8707) is hard-coded to config('mcp.resource_url') so
    // the resource server can reject tokens issued for another resource.
    public function issue(Request $request): JsonResponse
    {
        $grantType = (string) $request->input('grant_type', '');
        $client = $this->authenticateClient($request);
        if ($client instanceof JsonResponse) {
            return $client;
        }

        if ($grantType === 'authorization_code') {
            return $this->handleAuthorizationCode($request, $client);
        }
        if ($grantType === 'refresh_token') {
            return $this->handleRefreshToken($request, $client);
        }
        return $this->oauthError('unsupported_grant_type', "grant_type '{$grantType}' is not supported.", 400);
    }

    private function handleAuthorizationCode(Request $request, OauthClient $client): JsonResponse
    {
        $code = (string) $request->input('code', '');
        $redirectUri = (string) $request->input('redirect_uri', '');
        $codeVerifier = (string) $request->input('code_verifier', '');

        if ($code === '' || $redirectUri === '' || $codeVerifier === '') {
            return $this->oauthError('invalid_request', 'code, redirect_uri, and code_verifier are required.', 400);
        }

        $row = OauthAuthorizationCode::where('code_hash', OauthAuthorizationCode::hashCode($code))
            ->where('client_internal_id', $client->id)
            ->first();

        if (! $row) {
            return $this->oauthError('invalid_grant', 'Authorization code not found.', 400);
        }
        if ($row->isRedeemed()) {
            // Replay attempt — invalidate every token derived from this code.
            // RFC 6749 section 4.1.2: "If an authorization code is used more
            // than once, the authorization server MUST attempt to revoke all
            // tokens previously issued based on that authorization code."
            $this->revokeAllForCode($row);
            return $this->oauthError('invalid_grant', 'Authorization code already used.', 400);
        }
        if ($row->isExpired()) {
            return $this->oauthError('invalid_grant', 'Authorization code has expired.', 400);
        }
        if ($row->redirect_uri !== $redirectUri) {
            return $this->oauthError('invalid_grant', 'redirect_uri does not match the value used in /oauth/authorize.', 400);
        }

        if (! $this->verifyPkce($row->code_challenge, $codeVerifier)) {
            return $this->oauthError('invalid_grant', 'PKCE code_verifier failed.', 400);
        }

        $plainAccess = Str::random(64);
        $plainRefresh = Str::random(64);

        $accessToken = null;
        $refreshToken = null;
        DB::transaction(function () use ($row, $client, $plainAccess, $plainRefresh, &$accessToken, &$refreshToken) {
            $row->forceFill(['redeemed_at' => now()])->save();

            $accessToken = OauthAccessToken::create([
                'token_hash' => OauthAccessToken::hashToken($plainAccess),
                'client_internal_id' => $client->id,
                'user_id' => $row->user_id,
                'scope' => $row->scope,
                'audience' => $row->audience,
                'expires_at' => now()->addSeconds((int) config('mcp.access_token_ttl_seconds')),
            ]);

            $refreshToken = OauthRefreshToken::create([
                'token_hash' => OauthRefreshToken::hashToken($plainRefresh),
                'access_token_id' => $accessToken->id,
                'client_internal_id' => $client->id,
                'user_id' => $row->user_id,
                'scope' => $row->scope,
                'audience' => $row->audience,
                'expires_at' => now()->addSeconds((int) config('mcp.refresh_token_ttl_seconds')),
            ]);
        });

        return response()->json([
            'access_token' => $plainAccess,
            'token_type' => 'Bearer',
            'expires_in' => (int) config('mcp.access_token_ttl_seconds'),
            'refresh_token' => $plainRefresh,
            'scope' => $accessToken->scope,
        ]);
    }

    private function handleRefreshToken(Request $request, OauthClient $client): JsonResponse
    {
        $plain = (string) $request->input('refresh_token', '');
        if ($plain === '') {
            return $this->oauthError('invalid_request', 'refresh_token is required.', 400);
        }

        $row = OauthRefreshToken::where('token_hash', OauthRefreshToken::hashToken($plain))
            ->where('client_internal_id', $client->id)
            ->first();

        if (! $row) {
            return $this->oauthError('invalid_grant', 'Refresh token not found.', 400);
        }
        if ($row->isRevoked() || $row->isExpired()) {
            return $this->oauthError('invalid_grant', 'Refresh token is expired or revoked.', 400);
        }

        $plainAccess = Str::random(64);
        $plainRefresh = Str::random(64);

        $newAccess = null;
        DB::transaction(function () use ($row, $plainAccess, $plainRefresh, &$newAccess) {
            // Revoke the old pair atomically so this refresh token cannot
            // be reused (refresh-token rotation).
            $row->forceFill(['revoked_at' => now()])->save();
            if ($row->accessToken) {
                $row->accessToken->forceFill(['revoked_at' => now()])->save();
            }

            $newAccess = OauthAccessToken::create([
                'token_hash' => OauthAccessToken::hashToken($plainAccess),
                'client_internal_id' => $row->client_internal_id,
                'user_id' => $row->user_id,
                'scope' => $row->scope,
                'audience' => $row->audience,
                'expires_at' => now()->addSeconds((int) config('mcp.access_token_ttl_seconds')),
            ]);

            OauthRefreshToken::create([
                'token_hash' => OauthRefreshToken::hashToken($plainRefresh),
                'access_token_id' => $newAccess->id,
                'client_internal_id' => $row->client_internal_id,
                'user_id' => $row->user_id,
                'scope' => $row->scope,
                'audience' => $row->audience,
                'expires_at' => now()->addSeconds((int) config('mcp.refresh_token_ttl_seconds')),
            ]);
        });

        return response()->json([
            'access_token' => $plainAccess,
            'token_type' => 'Bearer',
            'expires_in' => (int) config('mcp.access_token_ttl_seconds'),
            'refresh_token' => $plainRefresh,
            'scope' => $newAccess?->scope ?? $row->scope,
        ]);
    }

    /**
     * @return OauthClient|JsonResponse
     */
    private function authenticateClient(Request $request)
    {
        // Try HTTP Basic first (client_secret_basic), then form fields
        // (client_secret_post / public client_id-only).
        [$clientId, $clientSecret] = $this->extractClientCredentials($request);
        if ($clientId === '') {
            return $this->oauthError('invalid_client', 'client_id is required.', 401);
        }
        $client = OauthClient::active()->where('client_id', $clientId)->first();
        if (! $client) {
            return $this->oauthError('invalid_client', 'Unknown or revoked client.', 401);
        }
        if ($client->isPublic()) {
            // PKCE-only public client. No secret expected; ignore one if sent.
            return $client;
        }
        if ($clientSecret === '' || ! $client->checkSecret($clientSecret)) {
            return $this->oauthError('invalid_client', 'Client authentication failed.', 401);
        }
        return $client;
    }

    /** @return array{0:string,1:string} */
    private function extractClientCredentials(Request $request): array
    {
        $authorization = $request->header('Authorization', '');
        if (is_string($authorization) && str_starts_with(strtolower($authorization), 'basic ')) {
            $decoded = base64_decode(trim(substr($authorization, 6)), true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                [$id, $secret] = explode(':', $decoded, 2);
                return [rawurldecode($id), rawurldecode($secret)];
            }
        }
        return [(string) $request->input('client_id', ''), (string) $request->input('client_secret', '')];
    }

    private function verifyPkce(string $challenge, string $verifier): bool
    {
        $hash = hash('sha256', $verifier, true);
        $b64 = rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
        return hash_equals($challenge, $b64);
    }

    private function revokeAllForCode(OauthAuthorizationCode $code): void
    {
        DB::transaction(function () use ($code) {
            OauthAccessToken::where('user_id', $code->user_id)
                ->where('client_internal_id', $code->client_internal_id)
                ->where('created_at', '>=', $code->redeemed_at ?? $code->created_at)
                ->update(['revoked_at' => now()]);
            OauthRefreshToken::where('user_id', $code->user_id)
                ->where('client_internal_id', $code->client_internal_id)
                ->where('created_at', '>=', $code->redeemed_at ?? $code->created_at)
                ->update(['revoked_at' => now()]);
        });
    }

    private function oauthError(string $code, string $description, int $status): JsonResponse
    {
        return response()->json([
            'error' => $code,
            'error_description' => $description,
        ], $status);
    }
}
