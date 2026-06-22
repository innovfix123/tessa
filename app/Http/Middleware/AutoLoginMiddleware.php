<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class AutoLoginMiddleware
{
    private const COOKIE_NAME = 'tessa_remember';

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            // A logged-in user can become inactive mid-session (e.g. terminated
            // in HR). Invalidate the session so they fall back to the login page
            // on the next request and are blocked by the credential check there.
            if (! Auth::user()->is_active) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                Cookie::queue(Cookie::forget(self::COOKIE_NAME));

                return $next($request);
            }

            return $next($request);
        }

        $token = $request->cookie(self::COOKIE_NAME);

        if (!$token) {
            return $next($request);
        }

        $hashed = hash('sha256', $token);
        $user = User::where('remember_token', $hashed)->first();

        if (!$user || !$user->is_active) {
            Cookie::queue(Cookie::forget(self::COOKIE_NAME));
            return $next($request);
        }

        Auth::login($user, false);
        $request->session()->regenerate();

        return $next($request);
    }
}
