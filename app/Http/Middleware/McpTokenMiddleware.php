<?php

namespace App\Http\Middleware;

use App\Models\McpToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class McpTokenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');
        if (! is_string($header) || ! str_starts_with($header, 'Bearer ')) {
            return response()->json(['error' => 'Missing MCP bearer token'], 401);
        }

        $plain = trim(substr($header, 7));
        if ($plain === '') {
            return response()->json(['error' => 'Empty MCP bearer token'], 401);
        }

        $token = McpToken::active()
            ->where('token_hash', McpToken::hashToken($plain))
            ->with('user')
            ->first();

        if (! $token || ! $token->user || ! $token->user->is_active) {
            return response()->json(['error' => 'Invalid MCP token'], 401);
        }

        Auth::login($token->user);
        $token->forceFill(['last_used_at' => now()])->save();

        // Auto-inject portal=<user.role> for endpoints that require it
        // (e.g. meetings). The web app passes this from whichever dashboard
        // page the user is on; MCP callers don't have that context.
        if (! $request->query->has('portal') && $token->user->role) {
            $request->query->set('portal', $token->user->role);
        }

        return $next($request);
    }
}
