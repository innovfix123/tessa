<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSlackConnected
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasSlackConnection()) {
            return response()->json([
                'error'       => 'Slack not connected',
                'connect_url' => '/api/slack/connect',
            ], 403);
        }

        return $next($request);
    }
}
