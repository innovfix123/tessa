<?php

namespace App\Mcp;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Thin helper for tools that wrap an existing /api/mcp/* endpoint.
 *
 * Idea: instead of duplicating controller logic, each tool fires a
 * Laravel sub-request against the same route the web UI uses. The
 * request runs through the entire Laravel middleware stack with
 * Auth::user() already set, so RoleMiddleware, user.allowlist, etc.
 * enforce RBAC just like they do for the portal.
 */
class ApiSubRequest
{
    public static function get(string $path, array $query = [], ?User $actingAs = null): array
    {
        return self::dispatch('GET', $path, $query, null, $actingAs);
    }

    public static function post(string $path, array $body = [], ?User $actingAs = null, array $query = []): array
    {
        return self::dispatch('POST', $path, $query, $body, $actingAs);
    }

    public static function put(string $path, array $body = [], ?User $actingAs = null, array $query = []): array
    {
        return self::dispatch('PUT', $path, $query, $body, $actingAs);
    }

    public static function patch(string $path, array $body = [], ?User $actingAs = null, array $query = []): array
    {
        return self::dispatch('PATCH', $path, $query, $body, $actingAs);
    }

    public static function delete(string $path, array $query = [], ?User $actingAs = null): array
    {
        return self::dispatch('DELETE', $path, $query, null, $actingAs);
    }

    /**
     * Run a sub-request against /api/{path} as the given user.
     *
     * Returns the JSON-decoded response body. Non-2xx responses raise a
     * ToolException with the HTTP status — the caller maps that to the
     * appropriate JSON-RPC error code.
     */
    private static function dispatch(string $method, string $path, array $query, ?array $body, ?User $actingAs): array
    {
        $actingAs ??= Auth::user();
        if (! $actingAs) {
            throw new ToolException('Not authenticated.', 401);
        }

        $path = '/'.ltrim($path, '/');
        $url = "/api{$path}";

        // The mcp.token middleware reads ?portal=<role> when one isn't
        // already in the query. Some endpoints (meetings, dashboard
        // notes) require it, so set a sensible default the same way
        // McpTokenMiddleware does today.
        if (! isset($query['portal']) && $actingAs->role) {
            $query['portal'] = $actingAs->role;
        }

        $request = Request::create(
            $url,
            $method,
            array_merge($query, $body ?? []),
            cookies: [],
            files: [],
            server: ['HTTP_ACCEPT' => 'application/json'],
            content: $body !== null ? json_encode($body) : null,
        );
        if ($body !== null) {
            $request->headers->set('Content-Type', 'application/json');
        }
        $request->setUserResolver(fn () => $actingAs);

        // Make sure Auth::user() inside the controller resolves the same way.
        $previousUser = Auth::user();
        Auth::setUser($actingAs);
        try {
            /** @var Response|JsonResponse $response */
            $response = app()->handle($request);
        } finally {
            if ($previousUser) {
                Auth::setUser($previousUser);
            }
        }

        $status = $response->getStatusCode();
        $content = $response->getContent();
        $decoded = $content === '' || $content === false ? [] : json_decode((string) $content, true);
        if (! is_array($decoded)) {
            $decoded = ['raw' => $content];
        }

        if ($status >= 200 && $status < 300) {
            return $decoded;
        }

        $message = is_string($decoded['error'] ?? null)
            ? $decoded['error']
            : (is_string($decoded['message'] ?? null) ? $decoded['message'] : "Tessa API returned {$status}");
        throw new ToolException($message, $status, $decoded);
    }
}
