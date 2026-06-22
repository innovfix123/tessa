<?php

namespace Tests\Feature\Mcp;

class OauthDiscoveryTest extends McpTestCase
{
    public function test_protected_resource_metadata_is_advertised(): void
    {
        $response = $this->getJson('/.well-known/oauth-protected-resource');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'resource',
            'authorization_servers',
            'scopes_supported',
            'bearer_methods_supported',
        ]);
        $response->assertJsonPath('resource', config('mcp.resource_url'));
    }

    public function test_authorization_server_metadata_advertises_pkce_s256_and_dcr(): void
    {
        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertStatus(200);
        $body = $response->json();

        $this->assertContains('S256', $body['code_challenge_methods_supported']);
        $this->assertContains('authorization_code', $body['grant_types_supported']);
        $this->assertContains('refresh_token', $body['grant_types_supported']);
        $this->assertNotEmpty($body['registration_endpoint']);
        $this->assertStringContainsString('/oauth/register', $body['registration_endpoint']);
    }

    public function test_unauthenticated_mcp_post_returns_401_with_resource_metadata_challenge(): void
    {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [],
        ]);

        $response->assertStatus(401);
        $this->assertStringContainsString(
            '.well-known/oauth-protected-resource',
            (string) $response->headers->get('WWW-Authenticate'),
        );
    }

    public function test_remote_endpoints_404_when_feature_disabled(): void
    {
        config(['mcp.remote_enabled' => false]);

        $this->getJson('/.well-known/oauth-protected-resource')->assertStatus(404);
        $this->getJson('/.well-known/oauth-authorization-server')->assertStatus(404);
        $this->postJson('/mcp', [])->assertStatus(404);
    }
}
