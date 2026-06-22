<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class McpFeatureEnabled
{
    // Master kill-switch for the entire remote MCP surface. When
    // config('mcp.remote_enabled') is false (the default), every route
    // in routes/mcp.php returns 404 as if it didn't exist. Toggle via
    // MCP_REMOTE_ENABLED=true in .env once dogfooded.
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('mcp.remote_enabled', false)) {
            abort(404);
        }
        return $next($request);
    }
}
