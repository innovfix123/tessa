<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Remote Tessa MCP connector — top-level routes (/.well-known/*,
            // /oauth/*, /mcp). All gated behind the 'mcp.enabled' feature flag
            // middleware so they 404 cleanly when MCP_REMOTE_ENABLED=false.
            \Illuminate\Support\Facades\Route::middleware([])
                ->group(__DIR__.'/../routes/mcp.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // /api/* + /mcp + /oauth/token + /oauth/register + /oauth/revoke
        // are all stateless JSON APIs and don't need CSRF cookies. 'r/*' is the
        // freelance-recruiter open portal — sessionless, authed by the unguessable
        // URL token, so CSRF (which protects browser sessions) doesn't apply.
        $middleware->validateCsrfTokens(except: ['api/*', 'mcp', 'oauth/token', 'oauth/register', 'oauth/revoke', 'r/*']);
        $middleware->web(append: [
            \App\Http\Middleware\AutoLoginMiddleware::class,
        ]);
        $middleware->alias([
            'role'             => \App\Http\Middleware\RoleMiddleware::class,
            'user.allowlist'   => \App\Http\Middleware\UserAllowlistMiddleware::class,
            'slack.connected'  => \App\Http\Middleware\EnsureSlackConnected::class,
            'github.connected' => \App\Http\Middleware\EnsureGitHubConnected::class,
            'google.connected' => \App\Http\Middleware\EnsureGoogleConnected::class,
            'mcp.token'        => \App\Http\Middleware\McpTokenMiddleware::class,
            'mcp.enabled'      => \App\Http\Middleware\McpFeatureEnabled::class,
            'mcp.access-token' => \App\Http\Middleware\McpAccessTokenMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
