<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-request audit trail for the remote MCP endpoint. Captures who
        // (user + OAuth client), what (JSON-RPC method + tool name), and how
        // it went (status + duration). Used by ops to spot abuse and by users
        // to see their own activity on /settings/connect-claude.
        Schema::create('mcp_call_log', function (Blueprint $table) {
            $table->id();
            // users.id is signed INT in this DB; match exactly for the FK.
            $table->integer('user_id');
            $table->foreignId('client_internal_id')->nullable()
                ->constrained('oauth_clients')->nullOnDelete();
            $table->foreignId('access_token_id')->nullable()
                ->constrained('oauth_access_tokens')->nullOnDelete();
            $table->string('jsonrpc_method', 64);
            $table->string('tool_name', 100)->nullable();
            // Short fingerprint of the args payload (not the args themselves —
            // these could contain PII like salary numbers). Useful for grouping
            // identical calls in the audit log.
            $table->string('args_fingerprint', 32)->nullable();
            // HTTP status returned to the client (200, 400, 401, 403, 500…).
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('duration_ms');
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'created_at']);
            $table->index(['tool_name', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_call_log');
    }
};
