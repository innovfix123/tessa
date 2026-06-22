<?php

namespace Tests\Feature\Mcp;

use App\Models\OauthAccessToken;
use App\Models\OauthClient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class McpTestCase extends TestCase
{
    // The Tessa codebase has 100+ legacy migrations with raw SQL +
    // production assumptions that don't run against SQLite. Instead of
    // chasing those, this base test case creates only the tables the
    // MCP/OAuth flow touches. Each test starts with a fresh in-memory
    // SQLite DB.
    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalSchema();
        $this->seedRoles();
    }

    protected function buildMinimalSchema(): void
    {
        // ─── users + roles + permissions ─────────────────────────
        // Mirror the columns User::$fillable touches in the codepaths
        // exercised by the MCP flow. Anything not relevant is skipped.
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->timestamps();
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('role_id');
            $t->string('permission');
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->unique();
            $t->string('password_hash')->nullable();
            $t->unsignedBigInteger('role_id')->nullable();
            $t->unsignedBigInteger('reporting_manager_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('remember_token', 100)->nullable();
        });

        // ─── OAuth tables (same shape as the real migrations) ────
        Schema::create('oauth_clients', function (Blueprint $t) {
            $t->id();
            $t->string('client_id', 64)->unique();
            $t->string('secret_hash', 64)->nullable();
            $t->string('client_name', 200);
            $t->json('redirect_uris');
            $t->string('token_endpoint_auth_method', 32)->default('none');
            $t->string('grant_types', 200)->default('authorization_code refresh_token');
            $t->string('response_types', 100)->default('code');
            $t->string('scope', 500)->default('mcp');
            $t->string('software_id', 200)->nullable();
            $t->string('software_version', 100)->nullable();
            $t->string('contacts', 500)->nullable();
            $t->string('created_via', 16)->default('dcr');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('oauth_authorization_codes', function (Blueprint $t) {
            $t->id();
            $t->string('code_hash', 64)->unique();
            $t->foreignId('client_internal_id');
            $t->unsignedBigInteger('user_id');
            $t->string('redirect_uri', 500);
            $t->string('scope', 500);
            $t->string('audience', 200);
            $t->string('code_challenge', 200);
            $t->string('code_challenge_method', 16)->default('S256');
            $t->timestamp('expires_at');
            $t->timestamp('redeemed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('oauth_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->string('token_hash', 64)->unique();
            $t->foreignId('client_internal_id');
            $t->unsignedBigInteger('user_id');
            $t->string('scope', 500);
            $t->string('audience', 200);
            $t->timestamp('expires_at');
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('oauth_refresh_tokens', function (Blueprint $t) {
            $t->id();
            $t->string('token_hash', 64)->unique();
            $t->foreignId('access_token_id')->nullable();
            $t->foreignId('client_internal_id');
            $t->unsignedBigInteger('user_id');
            $t->string('scope', 500);
            $t->string('audience', 200);
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('mcp_call_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->foreignId('client_internal_id')->nullable();
            $t->foreignId('access_token_id')->nullable();
            $t->string('jsonrpc_method', 64);
            $t->string('tool_name', 100)->nullable();
            $t->string('args_fingerprint', 32)->nullable();
            $t->unsignedSmallInteger('status_code');
            $t->unsignedInteger('duration_ms');
            $t->ipAddress('ip_address')->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->text('error_message')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    protected function seedRoles(): void
    {
        $slugs = [
            Role::SLUG_CEO => 'CEO',
            Role::SLUG_COO => 'COO',
            Role::SLUG_CMO => 'CMO',
            Role::SLUG_CFO => 'CFO',
            Role::SLUG_ADMIN => 'Admin',
            Role::SLUG_HR => 'HR',
            Role::SLUG_HR_OPERATIONS => 'HR Operations',
            Role::SLUG_BUSINESS_ANALYST => 'BA',
            Role::SLUG_FULL_STACK_DEVELOPER => 'Full Stack Developer',
            Role::SLUG_FREELANCE_RECRUITER => 'Freelance Recruiter',
        ];
        foreach ($slugs as $slug => $name) {
            Role::firstOrCreate(['slug' => $slug], ['name' => $name]);
        }
    }

    protected function makeUser(string $roleSlug, array $overrides = []): User
    {
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $email = $overrides['email'] ?? Str::lower($roleSlug).'-'.uniqid().'@test.local';
        DB::table('users')->insert(array_merge([
            'name' => $overrides['name'] ?? ucfirst($roleSlug),
            'email' => $email,
            'password_hash' => password_hash('test1234', PASSWORD_BCRYPT),
            'role_id' => $role->id,
            'is_active' => 1,
        ], $overrides));
        return User::where('email', $email)->firstOrFail();
    }

    protected function makeClient(array $overrides = []): OauthClient
    {
        return OauthClient::create(array_merge([
            'client_id' => (string) Str::uuid(),
            'secret_hash' => null,
            'client_name' => 'Test Claude Client',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            'token_endpoint_auth_method' => 'none',
            'grant_types' => 'authorization_code refresh_token',
            'response_types' => 'code',
            'scope' => 'mcp',
            'created_via' => 'dcr',
        ], $overrides));
    }

    /**
     * @return array{0:string,1:OauthAccessToken}
     */
    protected function mintAccessTokenFor(User $user, ?OauthClient $client = null, ?string $audience = null): array
    {
        $client ??= $this->makeClient();
        $plain = Str::random(64);
        $token = OauthAccessToken::create([
            'token_hash' => OauthAccessToken::hashToken($plain),
            'client_internal_id' => $client->id,
            'user_id' => $user->id,
            'scope' => 'mcp',
            'audience' => $audience ?? config('mcp.resource_url'),
            'expires_at' => now()->addHour(),
        ]);
        return [$plain, $token];
    }

    protected function grantPermission(string $roleSlug, string $permission): void
    {
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        Permission::firstOrCreate(['role_id' => $role->id, 'permission' => $permission]);
    }

    protected function pkceChallenge(string $verifier): string
    {
        $hash = hash('sha256', $verifier, true);
        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }
}
