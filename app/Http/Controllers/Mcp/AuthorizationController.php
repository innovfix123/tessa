<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Mcp\McpToolRegistry;
use App\Models\OauthAuthorizationCode;
use App\Models\OauthClient;
use App\Models\Role;
use App\Services\ActivityLogService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuthorizationController extends Controller
{
    // GET /oauth/authorize
    //
    // The user-facing step of the OAuth 2.1 authorization code flow.
    // Claude.ai redirects the user's browser here with client_id +
    // redirect_uri + code_challenge + state. If the user isn't already
    // signed into Tessa, we bounce them through the existing /login
    // flow first and return them here afterward via the "return" param.
    public function showConsent(Request $request): View|RedirectResponse
    {
        $validation = $this->validateAuthorizationRequest($request);
        if ($validation['error']) {
            return $this->errorRedirect($request, $validation['error'], $validation['error_description']);
        }
        /** @var OauthClient $client */
        $client = $validation['client'];

        if (! Auth::check()) {
            // Stash the full /oauth/authorize URL so we land back here
            // after login. The session key matches what the login view
            // already supports (return param).
            $request->session()->put('url.intended', $request->fullUrl());
            return redirect()->route('login');
        }

        $registry = app(McpToolRegistry::class);
        $availableTools = $registry->toolsForUser(Auth::user());

        return view('mcp.consent', [
            'client' => $client,
            'state' => (string) $request->query('state', ''),
            'scope' => (string) $request->query('scope', $client->scope),
            'redirect_uri' => (string) $request->query('redirect_uri'),
            'code_challenge' => (string) $request->query('code_challenge'),
            'code_challenge_method' => (string) $request->query('code_challenge_method', 'S256'),
            'resource' => (string) $request->query('resource', config('mcp.resource_url')),
            'user' => Auth::user(),
            'available_tools' => $availableTools,
            'tools_count' => count($availableTools),
            'role_label' => $this->roleLabel(Auth::user()->role),
        ]);
    }

    // POST /oauth/authorize
    //
    // Final step of the consent screen — the user clicked Approve (or Deny).
    // We mint a short-lived authorization code bound to the user + client +
    // redirect_uri + code_challenge and redirect to the client with it.
    public function decide(Request $request): RedirectResponse
    {
        $validation = $this->validateAuthorizationRequest($request);
        if ($validation['error']) {
            return $this->errorRedirect($request, $validation['error'], $validation['error_description']);
        }
        if (! Auth::check()) {
            // Defense in depth: the GET handler already redirected guests,
            // but a stale POST could land here without a session.
            return redirect()->route('login');
        }
        /** @var OauthClient $client */
        $client = $validation['client'];

        $decision = (string) $request->input('decision', '');
        $redirectUri = (string) $request->input('redirect_uri', $request->query('redirect_uri'));
        $state = (string) $request->input('state', $request->query('state', ''));

        if ($decision !== 'approve') {
            return $this->errorRedirectTo($redirectUri, $state, 'access_denied', 'User denied the request.');
        }

        $plainCode = Str::random(64);
        OauthAuthorizationCode::create([
            'code_hash' => OauthAuthorizationCode::hashCode($plainCode),
            'client_internal_id' => $client->id,
            'user_id' => Auth::id(),
            'redirect_uri' => $redirectUri,
            'scope' => (string) $request->input('scope', $request->query('scope', $client->scope)),
            'audience' => config('mcp.resource_url'),
            'code_challenge' => (string) $request->input('code_challenge', $request->query('code_challenge')),
            'code_challenge_method' => (string) $request->input(
                'code_challenge_method',
                $request->query('code_challenge_method', 'S256')
            ),
            'expires_at' => now()->addSeconds((int) config('mcp.authorization_code_ttl_seconds', 600)),
        ]);

        ActivityLogService::log(
            Auth::id(),
            'mcp_consent_approved',
            "Approved MCP client \"{$client->client_name}\" ({$client->client_id})"
        );

        $params = http_build_query(array_filter([
            'code' => $plainCode,
            'state' => $state !== '' ? $state : null,
        ]));
        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return redirect()->away($redirectUri.$separator.$params);
    }

    /**
     * Validate the common OAuth 2.1 authorization request params.
     *
     * @return array{error: string|null, error_description: string|null, client: OauthClient|null}
     */
    private function validateAuthorizationRequest(Request $request): array
    {
        $params = $request->isMethod('post') ? array_merge($request->query->all(), $request->all()) : $request->query->all();

        $clientId = (string) ($params['client_id'] ?? '');
        if ($clientId === '') {
            return ['error' => 'invalid_request', 'error_description' => 'client_id is required.', 'client' => null];
        }
        $client = OauthClient::active()->where('client_id', $clientId)->first();
        if (! $client) {
            return ['error' => 'unauthorized_client', 'error_description' => 'Unknown or revoked client.', 'client' => null];
        }

        $responseType = (string) ($params['response_type'] ?? '');
        if ($responseType !== 'code') {
            return ['error' => 'unsupported_response_type', 'error_description' => "response_type must be 'code'.", 'client' => $client];
        }

        $redirectUri = (string) ($params['redirect_uri'] ?? '');
        if ($redirectUri === '' || ! $client->isValidRedirectUri($redirectUri)) {
            return ['error' => 'invalid_request', 'error_description' => 'redirect_uri does not match a registered URI.', 'client' => $client];
        }

        $codeChallenge = (string) ($params['code_challenge'] ?? '');
        if ($codeChallenge === '') {
            return ['error' => 'invalid_request', 'error_description' => 'PKCE code_challenge is required.', 'client' => $client];
        }
        $codeChallengeMethod = (string) ($params['code_challenge_method'] ?? 'S256');
        if ($codeChallengeMethod !== 'S256') {
            return ['error' => 'invalid_request', 'error_description' => "code_challenge_method must be 'S256'.", 'client' => $client];
        }

        return ['error' => null, 'error_description' => null, 'client' => $client];
    }

    private function errorRedirect(Request $request, string $error, ?string $description): RedirectResponse
    {
        // If we don't have a valid redirect_uri (e.g. unknown client), we
        // can't safely redirect — render a plain error view instead.
        $redirectUri = (string) $request->query('redirect_uri', '');
        if ($redirectUri === '') {
            abort(400, "OAuth error: {$error} — {$description}");
        }
        return $this->errorRedirectTo($redirectUri, (string) $request->query('state', ''), $error, $description);
    }

    private function errorRedirectTo(string $redirectUri, string $state, string $error, ?string $description): RedirectResponse
    {
        $params = http_build_query(array_filter([
            'error' => $error,
            'error_description' => $description,
            'state' => $state !== '' ? $state : null,
        ]));
        $separator = str_contains($redirectUri, '?') ? '&' : '?';
        return redirect()->away($redirectUri.$separator.$params);
    }

    private function roleLabel(?string $slug): string
    {
        if (! $slug) {
            return 'Tessa user';
        }
        return (string) (Role::where('slug', $slug)->value('name') ?? $slug);
    }
}
