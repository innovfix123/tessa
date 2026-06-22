<?php

use App\Http\Controllers\Mcp\AuthorizationController;
use App\Http\Controllers\Mcp\ClientRegistrationController;
use App\Http\Controllers\Mcp\McpController;
use App\Http\Controllers\Mcp\RevocationController;
use App\Http\Controllers\Mcp\TokenController;
use App\Http\Controllers\Mcp\WellKnownController;
use Illuminate\Support\Facades\Route;

// Tessa remote MCP connector routes (Claude.ai custom connector flow).
// Gated by config('mcp.remote_enabled'); when false the routes are still
// registered but every endpoint returns 404 via the AbortIfDisabled middleware.

Route::middleware('mcp.enabled')->group(function () {
    // ─── RFC 9728 + RFC 8414 discovery ───────────────────────────
    // These MUST live at the well-known paths; they're how Claude.ai
    // bootstraps the OAuth dance.
    Route::get('/.well-known/oauth-protected-resource', [WellKnownController::class, 'protectedResource']);
    Route::get('/.well-known/oauth-authorization-server', [WellKnownController::class, 'authorizationServer']);

    // ─── OAuth 2.1 authorization server ──────────────────────────
    Route::post('/oauth/register', [ClientRegistrationController::class, 'register'])
        ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

    // Authorize endpoint reuses the existing web auth session so users
    // log in once with their Tessa credentials and the consent screen
    // sees them as authenticated.
    Route::middleware('web')->group(function () {
        Route::get('/oauth/authorize', [AuthorizationController::class, 'showConsent'])->name('oauth.authorize');
        Route::post('/oauth/authorize', [AuthorizationController::class, 'decide']);
    });

    // Token + revocation endpoints are JSON APIs — no CSRF, no web session.
    Route::post('/oauth/token', [TokenController::class, 'issue'])
        ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
    Route::post('/oauth/revoke', [RevocationController::class, 'revoke'])
        ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

    // User-facing revocation from /settings/connect-claude (web session).
    Route::middleware(['web', 'auth'])->group(function () {
        Route::delete('/oauth/access-tokens/{accessToken}', [RevocationController::class, 'userRevoke'])
            ->name('oauth.user-revoke');
        Route::delete('/oauth/clients/{oauthClient}', [RevocationController::class, 'userRevokeClient'])
            ->name('oauth.user-revoke-client');
    });

    // ─── MCP transport ───────────────────────────────────────────
    // Single Streamable HTTP endpoint. POST = JSON-RPC (requires Bearer),
    // GET = optional SSE (we return 405; the spec allows it).
    Route::get('/mcp', [McpController::class, 'rejectGet']);
    Route::match(['post', 'delete'], '/mcp', [McpController::class, 'handle'])
        ->middleware('mcp.access-token')
        ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
});
