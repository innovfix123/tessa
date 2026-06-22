<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!$request->user()) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            return redirect()->route('login');
        }

        $userRole = $request->user()->role;
        if (!in_array($userRole, $roles, true)) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
            return redirect()->to($this->homeForRole($userRole));
        }

        return $next($request);
    }

    public static function homeForRole(string $role): string
    {
        if ($role === \App\Models\Role::SLUG_ADMIN) {
            return '/admin';
        }

        return '/';
    }
}
