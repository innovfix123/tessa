<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WellKnownController extends Controller
{
    // RFC 9728 Protected Resource Metadata.
    //
    // Claude.ai fetches this after our /mcp returns 401 with
    // WWW-Authenticate: Bearer resource_metadata=... It tells the client
    // which authorization server can issue tokens for this resource and
    // which bearer auth method we expect.
    public function protectedResource(Request $request): JsonResponse
    {
        return response()->json([
            'resource' => config('mcp.resource_url'),
            'authorization_servers' => [config('mcp.authorization_server')],
            'scopes_supported' => config('mcp.scopes_supported'),
            'bearer_methods_supported' => ['header'],
            'resource_documentation' => url('/settings/connect-claude'),
            'resource_name' => 'Tessa MCP',
        ]);
    }

    // RFC 8414 Authorization Server Metadata.
    //
    // Tells Claude.ai where /authorize, /token, /register live and which
    // PKCE methods we support. The "S256" entry is mandatory — we reject
    // 'plain' code challenges at the token endpoint.
    public function authorizationServer(Request $request): JsonResponse
    {
        $base = rtrim(config('mcp.authorization_server'), '/');

        return response()->json([
            'issuer' => $base,
            'authorization_endpoint' => $base.'/oauth/authorize',
            'token_endpoint' => $base.'/oauth/token',
            'registration_endpoint' => $base.'/oauth/register',
            'revocation_endpoint' => $base.'/oauth/revoke',
            'scopes_supported' => config('mcp.scopes_supported'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => [
                'none',
                'client_secret_basic',
                'client_secret_post',
            ],
            'service_documentation' => url('/settings/connect-claude'),
        ]);
    }
}
