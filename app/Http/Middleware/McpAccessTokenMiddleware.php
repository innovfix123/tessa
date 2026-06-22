<?php

namespace App\Http\Middleware;

use App\Models\OauthAccessToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class McpAccessTokenMiddleware
{
    // OAuth 2.1 resource server middleware for the /mcp endpoint.
    //
    // Verifies the Bearer token, enforces the audience (RFC 8707), logs
    // the user in for the request, and stashes the OauthAccessToken on
    // the request so the controller can audit it. If anything's off we
    // return a 401 with the WWW-Authenticate resource_metadata header
    // Claude.ai needs to start the OAuth dance.
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');
        if (! is_string($header) || ! str_starts_with($header, 'Bearer ')) {
            return $this->challenge('No access token provided.');
        }

        $plain = trim(substr($header, 7));
        if ($plain === '') {
            return $this->challenge('Empty access token.');
        }

        $token = OauthAccessToken::with('user', 'client')
            ->where('token_hash', OauthAccessToken::hashToken($plain))
            ->first();

        if (! $token) {
            return $this->challenge('Unknown access token.');
        }
        if ($token->isRevoked()) {
            return $this->challenge('Access token has been revoked.');
        }
        if ($token->isExpired()) {
            return $this->challenge('Access token has expired.');
        }
        // Audience pinning prevents a token issued for some other MCP
        // resource (or any other Tessa-adjacent service we ever add)
        // from being replayed against /mcp. Cheap, important defence.
        if ($token->audience !== config('mcp.resource_url')) {
            return $this->challenge('Access token audience does not match this resource.');
        }
        if (! $token->user) {
            return $this->challenge('Access token user no longer exists.');
        }
        if (! $token->user->is_active) {
            return $this->challenge('Tessa user is inactive.');
        }
        if (! $token->client || $token->client->revoked_at !== null) {
            return $this->challenge('OAuth client has been revoked.');
        }

        Auth::setUser($token->user);
        // Stash on the request so McpController can attribute log rows
        // without re-loading the token.
        $request->attributes->set('mcp_access_token', $token);

        $response = $next($request);

        // Touch last_used_at after a successful response so we don't
        // mark a token "used" if the request fails on a downstream
        // middleware (e.g. rate limit).
        $token->forceFill(['last_used_at' => now()])->save();

        return $response;
    }

    private function challenge(string $description): JsonResponse
    {
        $metadataUrl = url('/.well-known/oauth-protected-resource');
        return response()->json([
            'error' => 'invalid_token',
            'error_description' => $description,
        ], 401)->withHeaders([
            'WWW-Authenticate' => sprintf(
                'Bearer realm="mcp", resource_metadata="%s", error="invalid_token", error_description="%s"',
                $metadataUrl,
                addcslashes($description, '"\\'),
            ),
        ]);
    }
}
