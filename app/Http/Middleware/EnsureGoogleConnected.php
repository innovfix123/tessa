<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGoogleConnected
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasGoogleConnection()) {
            return response()->json([
                'error'       => 'Google not connected',
                'connect_url' => '/api/google/connect',
            ], 403);
        }

        return $next($request);
    }
}
