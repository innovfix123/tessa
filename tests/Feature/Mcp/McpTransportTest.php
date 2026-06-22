<?php

namespace Tests\Feature\Mcp;

use App\Models\Role;

class McpTransportTest extends McpTestCase
{
    public function test_initialize_returns_protocol_version_and_server_info(): void
    {
        $user = $this->makeUser(Role::SLUG_CEO);
        [$plain, $_] = $this->mintAccessTokenFor($user);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$plain}",
            'Origin' => 'https://claude.ai',
        ])->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('jsonrpc', '2.0');
        $response->assertJsonPath('id', 1);
        $response->assertJsonPath('result.serverInfo.name', 'tessa-mcp');
        $this->assertNotEmpty($response->json('result.protocolVersion'));
    }

    public function test_tools_list_returns_tools_filtered_for_user_role(): void
    {
        $user = $this->makeUser(Role::SLUG_FULL_STACK_DEVELOPER);
        [$plain, $_] = $this->mintAccessTokenFor($user);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$plain}",
            'Origin' => 'https://claude.ai',
        ])->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $response->assertStatus(200);
        $names = collect($response->json('result.tools'))->pluck('name')->all();
        $this->assertContains('whoami', $names);
        $this->assertContains('list_tasks', $names);
        // A full-stack developer is an IC: no admin tools, no letters.
        $this->assertNotContains('admin_tasks_overview', $names);
        $this->assertNotContains('list_letters', $names);
        $this->assertNotContains('tessa_request', $names);
    }

    public function test_tools_call_runs_whoami_and_returns_user_identity(): void
    {
        $user = $this->makeUser(Role::SLUG_HR);
        [$plain, $_] = $this->mintAccessTokenFor($user);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$plain}",
        ])->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'whoami', 'arguments' => []],
        ]);

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertFalse($body['result']['isError']);
        $this->assertSame($user->id, $body['result']['structuredContent']['id']);
        $this->assertSame(Role::SLUG_HR, $body['result']['structuredContent']['role']);
    }

    public function test_audience_mismatch_token_is_rejected(): void
    {
        $user = $this->makeUser(Role::SLUG_CEO);
        [$plain, $_] = $this->mintAccessTokenFor($user, audience: 'https://some-other-resource.example.com/mcp');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$plain}",
            'Origin' => 'https://claude.ai',
        ])->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
        ]);

        $response->assertStatus(401);
        $this->assertStringContainsString('audience', strtolower((string) $response->json('error_description')));
    }

    public function test_bad_origin_is_rejected(): void
    {
        $user = $this->makeUser(Role::SLUG_CEO);
        [$plain, $_] = $this->mintAccessTokenFor($user);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$plain}",
            'Origin' => 'https://evil.example.com',
        ])->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
        ]);

        $response->assertStatus(403);
    }

    public function test_revoked_token_is_rejected(): void
    {
        $user = $this->makeUser(Role::SLUG_CEO);
        [$plain, $tokenRow] = $this->mintAccessTokenFor($user);
        $tokenRow->forceFill(['revoked_at' => now()])->save();

        $this->withHeaders(['Authorization' => "Bearer {$plain}"])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
            ->assertStatus(401);
    }

    public function test_unknown_tool_returns_error_result_not_jsonrpc_error(): void
    {
        $user = $this->makeUser(Role::SLUG_CEO);
        [$plain, $_] = $this->mintAccessTokenFor($user);

        $response = $this->withHeaders(['Authorization' => "Bearer {$plain}"])
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'fictional_tool', 'arguments' => []],
            ]);
        // Per the MCP spec, tools/call returns a result with isError=true
        // rather than a JSON-RPC error envelope.
        $response->assertStatus(200);
        $this->assertTrue($response->json('result.isError'));
    }
}
