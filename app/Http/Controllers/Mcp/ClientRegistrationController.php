<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Models\OauthClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ClientRegistrationController extends Controller
{
    // RFC 7591 Dynamic Client Registration.
    //
    // Claude.ai POSTs here unauthenticated when a user adds the connector.
    // We accept the redirect_uris + display metadata and hand back a fresh
    // client_id. PKCE-only public clients get token_endpoint_auth_method=none
    // and no secret; confidential clients get a one-time secret in the response.
    public function register(Request $request): JsonResponse
    {
        $payload = $request->all();
        $redirectUris = $payload['redirect_uris'] ?? [];
        if (! is_array($redirectUris) || empty($redirectUris)) {
            return $this->errorResponse('invalid_redirect_uri', 'redirect_uris is required and must be a non-empty array.');
        }

        foreach ($redirectUris as $uri) {
            if (! is_string($uri) || ! filter_var($uri, FILTER_VALIDATE_URL)) {
                return $this->errorResponse('invalid_redirect_uri', "Invalid redirect_uri: {$uri}");
            }
            // Only allow https://, with one carve-out for Claude Code's
            // loopback redirect on http://127.0.0.1. Reject anything else.
            if (! str_starts_with($uri, 'https://')
                && ! str_starts_with($uri, 'http://127.0.0.1')
                && ! str_starts_with($uri, 'http://localhost')) {
                return $this->errorResponse('invalid_redirect_uri', "redirect_uri must use https:// or loopback http://: {$uri}");
            }
        }

        $authMethod = (string) ($payload['token_endpoint_auth_method'] ?? 'none');
        if (! in_array($authMethod, ['none', 'client_secret_basic', 'client_secret_post'], true)) {
            return $this->errorResponse('invalid_client_metadata', 'token_endpoint_auth_method must be one of: none, client_secret_basic, client_secret_post.');
        }

        $grantTypes = $payload['grant_types'] ?? ['authorization_code', 'refresh_token'];
        if (is_array($grantTypes)) {
            $grantTypes = implode(' ', $grantTypes);
        }
        foreach (preg_split('/\s+/', (string) $grantTypes) as $grant) {
            if (! in_array($grant, ['authorization_code', 'refresh_token'], true)) {
                return $this->errorResponse('invalid_client_metadata', "Unsupported grant_type: {$grant}");
            }
        }

        $clientId = (string) Str::uuid();
        $plainSecret = null;
        $secretHash = null;
        if ($authMethod !== 'none') {
            $plainSecret = Str::random(64);
            $secretHash = OauthClient::hashSecret($plainSecret);
        }

        $client = OauthClient::create([
            'client_id' => $clientId,
            'secret_hash' => $secretHash,
            'client_name' => (string) ($payload['client_name'] ?? 'MCP Client'),
            'redirect_uris' => array_values($redirectUris),
            'token_endpoint_auth_method' => $authMethod,
            'grant_types' => $grantTypes,
            'response_types' => is_array($payload['response_types'] ?? null)
                ? implode(' ', $payload['response_types'])
                : (string) ($payload['response_types'] ?? 'code'),
            'scope' => (string) ($payload['scope'] ?? 'mcp'),
            'software_id' => isset($payload['software_id']) ? (string) $payload['software_id'] : null,
            'software_version' => isset($payload['software_version']) ? (string) $payload['software_version'] : null,
            'contacts' => isset($payload['contacts'])
                ? (is_array($payload['contacts']) ? implode(',', $payload['contacts']) : (string) $payload['contacts'])
                : null,
            'created_via' => 'dcr',
        ]);

        $response = [
            'client_id' => $client->client_id,
            'client_id_issued_at' => $client->created_at?->timestamp,
            'client_name' => $client->client_name,
            'redirect_uris' => $client->redirect_uris,
            'token_endpoint_auth_method' => $client->token_endpoint_auth_method,
            'grant_types' => preg_split('/\s+/', $client->grant_types),
            'response_types' => preg_split('/\s+/', $client->response_types),
            'scope' => $client->scope,
        ];
        // Per RFC 7591, the secret is returned exactly once at registration
        // and never again. Confidential clients (auth method != none) must
        // store it themselves.
        if ($plainSecret !== null) {
            $response['client_secret'] = $plainSecret;
            $response['client_secret_expires_at'] = 0; // never expires
        }

        return response()->json($response, 201);
    }

    private function errorResponse(string $error, string $description): JsonResponse
    {
        return response()->json([
            'error' => $error,
            'error_description' => $description,
        ], 400);
    }
}
