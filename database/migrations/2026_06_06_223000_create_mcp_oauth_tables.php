<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // OAuth clients registered against the Tessa MCP authorization server.
        // Most rows are created automatically via Dynamic Client Registration
        // (RFC 7591) by Claude.ai when a user adds the connector. Admins can
        // also pre-register a confidential client via php artisan oauth:list-clients.
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->id();
            $table->string('client_id', 64)->unique();
            // Hashed client_secret; null for public PKCE-only clients (Claude).
            $table->string('secret_hash', 64)->nullable();
            $table->string('client_name', 200);
            // JSON array of allowed redirect URIs (per RFC 7591).
            $table->json('redirect_uris');
            // 'none' (public, PKCE-only) or 'client_secret_basic|post'.
            $table->string('token_endpoint_auth_method', 32)->default('none');
            // Space-separated grant_types and response_types.
            $table->string('grant_types', 200)->default('authorization_code refresh_token');
            $table->string('response_types', 100)->default('code');
            $table->string('scope', 500)->default('mcp');
            $table->string('software_id', 200)->nullable();
            $table->string('software_version', 100)->nullable();
            // Optional contact URL or email from the RFC 7591 registration request.
            $table->string('contacts', 500)->nullable();
            // Provenance: 'dcr' (Dynamic Client Registration) or 'manual'.
            $table->string('created_via', 16)->default('dcr');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['revoked_at']);
        });

        // Short-lived authorization codes issued by /oauth/authorize and exchanged
        // at /oauth/token. PKCE S256 is mandatory — code_challenge stored here,
        // code_verifier supplied at exchange.
        Schema::create('oauth_authorization_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code_hash', 64)->unique();
            $table->foreignId('client_internal_id')->constrained('oauth_clients')->cascadeOnDelete();
            // users.id is signed INT in this DB (not bigint unsigned) — match
            // exactly so the foreign key below is accepted by MySQL.
            $table->integer('user_id');
            $table->string('redirect_uri', 500);
            $table->string('scope', 500);
            // Audience baked at issuance (RFC 8707). Always the MCP resource URL.
            $table->string('audience', 200);
            $table->string('code_challenge', 200);
            $table->string('code_challenge_method', 16)->default('S256');
            $table->timestamp('expires_at');
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['expires_at']);
        });

        // Bearer access tokens. Opaque random 64-byte strings; only their
        // sha256 hash is stored at rest. Audience claim is enforced by the
        // resource server (McpAccessTokenMiddleware).
        Schema::create('oauth_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('client_internal_id')->constrained('oauth_clients')->cascadeOnDelete();
            $table->integer('user_id');
            $table->string('scope', 500);
            $table->string('audience', 200);
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'revoked_at']);
            $table->index(['expires_at']);
        });

        Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            // The access token this refresh token pairs with. When the access
            // token is rotated via /oauth/token, both rows are replaced.
            $table->foreignId('access_token_id')->nullable()
                ->constrained('oauth_access_tokens')->nullOnDelete();
            $table->foreignId('client_internal_id')->constrained('oauth_clients')->cascadeOnDelete();
            $table->integer('user_id');
            $table->string('scope', 500);
            $table->string('audience', 200);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_refresh_tokens');
        Schema::dropIfExists('oauth_access_tokens');
        Schema::dropIfExists('oauth_authorization_codes');
        Schema::dropIfExists('oauth_clients');
    }
};
