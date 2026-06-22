<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UserAllowlistMiddleware
{
    public function handle(Request $request, Closure $next, string $configKey): Response
    {
        $allowed = (array) config($configKey, []);
        $userId = optional($request->user())->id;

        if (! $userId || ! in_array($userId, $allowed, true)) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
            return redirect()->to(RoleMiddleware::homeForRole($request->user()->role ?? ''));
        }

        return $next($request);
    }
}
