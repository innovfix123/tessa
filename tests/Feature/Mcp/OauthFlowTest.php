<?php

namespace Tests\Feature\Mcp;

use App\Models\OauthAccessToken;
use App\Models\OauthAuthorizationCode;
use App\Models\OauthClient;
use App\Models\OauthRefreshToken;
use App\Models\Role;
use Illuminate\Support\Str;

class OauthFlowTest extends McpTestCase
{
    public function test_dynamic_client_registration_returns_client_id(): void
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Test Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            'token_endpoint_auth_method' => 'none',
        ]);

        $response->assertStatus(201);
        $body = $response->json();

        $this->assertNotEmpty($body['client_id']);
        $this->assertSame('none', $body['token_endpoint_auth_method']);
        $this->assertArrayNotHasKey('client_secret', $body); // public client
    }

    public function test_dcr_rejects_non_https_redirect_uri(): void
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Sketchy',
            'redirect_uris' => ['http://evil.example.com/cb'],
        ]);
        $response->assertStatus(400)
            ->assertJsonPath('error', 'invalid_redirect_uri');
    }

    public function test_authorization_code_exchange_with_pkce_issues_access_and_refresh_tokens(): void
    {
        $user = $this->makeUser(Role::SLUG_CEO);
        $client = $this->makeClient();

        $verifier = Str::random(64);
        $challenge = $this->pkceChallenge($verifier);

        // Skip the user-facing /oauth/authorize step in this test and
        // create the code row directly — that endpoint is covered by
        // a separate test focused on the consent screen.
        $plainCode = Str::random(64);
        OauthAuthorizationCode::create([
            'code_hash' => OauthAuthorizationCode::hashCode($plainCode),
            'client_internal_id' => $client->id,
            'user_id' => $user->id,
            'redirect_uri' => $client->redirect_uris[0],
            'scope' => 'mcp',
            'audience' => config('mcp.resource_url'),
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->client_id,
            'code' => $plainCode,
            'redirect_uri' => $client->redirect_uris[0],
            'code_verifier' => $verifier,
        ]);

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertNotEmpty($body['access_token']);
        $this->assertNotEmpty($body['refresh_token']);
        $this->assertSame('Bearer', $body['token_type']);
        $this->assertSame('mcp', $body['scope']);

        // The access token should be discoverable + bound to the user.
        $access = OauthAccessToken::where('token_hash', OauthAccessToken::hashToken($body['access_token']))->first();
        $this->assertNotNull($access);
        $this->assertSame($user->id, $access->user_id);
        $this->assertSame(config('mcp.resource_url'), $access->audience);
    }

    public function test_authorization_code_replay_revokes_issued_tokens(): void
    {
        $user = $this->makeUser(Role::SLUG_CEO);
        $client = $this->makeClient();
        $verifier = Str::random(64);

        $plainCode = Str::random(64);
        OauthAuthorizationCode::create([
            'code_hash' => OauthAuthorizationCode::hashCode($plainCode),
            'client_internal_id' => $client->id,
            'user_id' => $user->id,
            'redirect_uri' => $client->redirect_uris[0],
            'scope' => 'mcp',
            'audience' => config('mcp.resource_url'),
            'code_challenge' => $this->pkceChallenge($verifier),
            'code_challenge_method' => 'S256',
            'expires_at' => now()->addMinutes(10),
        ]);

        $params = [
            'grant_type' => 'authorization_code',
            'client_id' => $client->client_id,
            'code' => $plainCode,
            'redirect_uri' => $client->redirect_uris[0],
            'code_verifier' => $verifier,
        ];
        $this->postJson('/oauth/token', $params)->assertStatus(200);

        // Second call with same code is a replay → must fail.
        $this->postJson('/oauth/token', $params)
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_grant');
    }

    public function test_pkce_verifier_mismatch_is_rejected(): void
    {
        $user = $this->makeUser(Role::SLUG_CEO);
        $client = $this->makeClient();
        $plainCode = Str::random(64);
        OauthAuthorizationCode::create([
            'code_hash' => OauthAuthorizationCode::hashCode($plainCode),
            'client_internal_id' => $client->id,
            'user_id' => $user->id,
            'redirect_uri' => $client->redirect_uris[0],
            'scope' => 'mcp',
            'audience' => config('mcp.resource_url'),
            'code_challenge' => $this->pkceChallenge('the-real-verifier'),
            'code_challenge_method' => 'S256',
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->client_id,
            'code' => $plainCode,
            'redirect_uri' => $client->redirect_uris[0],
            'code_verifier' => 'wrong-verifier-here',
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_refresh_token_rotates_access_and_refresh_pair(): void
    {
        $user = $this->makeUser(Role::SLUG_CEO);
        $client = $this->makeClient();
        $plainRefresh = Str::random(64);
        OauthRefreshToken::create([
            'token_hash' => OauthRefreshToken::hashToken($plainRefresh),
            'access_token_id' => null,
            'client_internal_id' => $client->id,
            'user_id' => $user->id,
            'scope' => 'mcp',
            'audience' => config('mcp.resource_url'),
            'expires_at' => now()->addDays(30),
        ]);

        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->client_id,
            'refresh_token' => $plainRefresh,
        ]);
        $response->assertStatus(200);
        $newRefresh = $response->json('refresh_token');
        $this->assertNotSame($plainRefresh, $newRefresh);

        // Old refresh token is dead.
        $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->client_id,
            'refresh_token' => $plainRefresh,
        ])->assertStatus(400);

        // New refresh token works.
        $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->client_id,
            'refresh_token' => $newRefresh,
        ])->assertStatus(200);
    }
}
