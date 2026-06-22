<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGitHubConnected
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasGitHubConnection()) {
            return response()->json([
                'error'       => 'GitHub not connected',
                'connect_url' => '/api/github/connect',
            ], 403);
        }

        return $next($request);
    }
}
