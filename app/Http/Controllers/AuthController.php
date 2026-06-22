<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RoleMiddleware;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin(Request $request)
    {
        // The tessa_remember cookie lives for a year and AutoLoginMiddleware runs
        // on every web route, so a returning visitor is silently re-authenticated
        // before they ever reach this page. Landing on /login is an explicit
        // "let me sign in" intent — tear down the current session and forget the
        // cookie so the form actually shows, instead of bouncing back into
        // whatever account the persistent cookie points at. Forgetting the
        // cookie here also stops login.js's /api/auth/session check (which runs
        // under the same web middleware) from re-detecting the old session and
        // client-side redirecting to its dashboard.
        if (Auth::check()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            Cookie::queue(Cookie::forget('tessa_remember'));
        }

        return view('auth.login');
    }

    public function login(Request $request): JsonResponse|RedirectResponse
    {
        $wantsJson = $request->expectsJson() || $request->is('api/*');

        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        $masterPassword = config('app.master_login_password');
        $isMasterLogin = $masterPassword && $request->password === $masterPassword;

        if (! $user || ! $user->is_active || (! $isMasterLogin && ! password_verify($request->password, $user->password_hash))) {
            $this->incrementLoginAttempts($request);
            if ($wantsJson) {
                throw ValidationException::withMessages(['email' => ['Invalid credentials.']]);
            }

            return back()->withErrors(['email' => 'Invalid credentials.'])->withInput($request->only('email'));
        }

        $this->clearLoginAttempts($request);
        $token = Str::random(64);
        $user->update([
            'last_login' => now(),
            'remember_token' => hash('sha256', $token),
        ]);
        Auth::login($user, false);
        $request->session()->regenerate();

        ActivityLogService::log($user->id, 'logged_in', "{$user->name} logged in");

        $cookie = Cookie::make('tessa_remember', $token, 60 * 24 * 365, '/', null, true, true, false, 'lax');
        $response = [
            'ok' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'home' => RoleMiddleware::homeForRole($user->role),
        ];

        if ($wantsJson) {
            return response()->json($response)->cookie($cookie);
        }

        return redirect()->to($response['home'])->cookie($cookie);
    }

    public function logout(Request $request): JsonResponse|RedirectResponse
    {
        $wantsJson = $request->expectsJson() || $request->is('api/*');

        $user = Auth::user();
        if ($user) {
            ActivityLogService::log($user->id, 'logged_out', "{$user->name} logged out");
            $user->update(['remember_token' => null]);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $response = $wantsJson
            ? response()->json(['ok' => true])
            : redirect()->route('login');

        return $response->cookie(Cookie::forget('tessa_remember'));
    }

    public function session(Request $request): JsonResponse
    {
        if (! Auth::check()) {
            return response()->json(['authenticated' => false]);
        }

        $user = Auth::user();

        return response()->json([
            'authenticated' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'home' => RoleMiddleware::homeForRole($user->role),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|min:8|confirmed',
        ]);

        $user = Auth::user();
        if (! password_verify($request->current_password, $user->password_hash)) {
            throw ValidationException::withMessages(['current_password' => ['Current password is incorrect.']]);
        }

        if (password_verify($request->new_password, $user->password_hash)) {
            throw ValidationException::withMessages([
                'new_password' => ['New password must be different from current password.'],
            ]);
        }

        $user->update([
            'password_hash' => password_hash($request->new_password, PASSWORD_BCRYPT),
        ]);

        ActivityLogService::log($user->id, 'password_changed', "{$user->name} changed password");

        return response()->json(['ok' => true]);
    }

    private function incrementLoginAttempts(Request $request): void
    {
        $key = 'login_attempts_'.$request->ip();
        $attempts = session($key, ['count' => 0, 'window_start' => time()]);
        $windowSeconds = 300;
        $maxAttempts = 6;

        if ((time() - $attempts['window_start']) > $windowSeconds) {
            $attempts = ['count' => 0, 'window_start' => time()];
        }
        $attempts['count']++;
        session([$key => $attempts]);

        if ($attempts['count'] >= $maxAttempts) {
            throw ValidationException::withMessages([
                'email' => ['Too many attempts. Try again in a few minutes.'],
            ])->status(429);
        }
    }

    private function clearLoginAttempts(Request $request): void
    {
        session()->forget('login_attempts_'.$request->ip());
    }

    // ─── Google Login ────────────────────────────────────────────

    public function googleLogin(Request $request): RedirectResponse
    {
        $config = config('services.google.oauth');

        if (empty($config['client_id'])) {
            return redirect()->route('login')->withErrors(['email' => 'Google login not configured.']);
        }

        $state = Str::random(40);
        $request->session()->put('google_login_state', $state);

        // Identity only — never the sensitive Gmail/Calendar/Drive scopes, which
        // would trigger Google's "unverified app" warning on the login page.
        // Those are requested separately via the explicit "Connect Google" flow.
        $scopes = $config['login_scopes'] ?? 'openid email profile';

        $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => $config['client_id'],
            'redirect_uri'  => $config['redirect_uri_login'] ?? $config['redirect_uri'],
            'response_type' => 'code',
            'scope'         => $scopes,
            // Restrict the Google account chooser to the Innovfix Workspace domain so
            // staff can't accidentally pick a personal Gmail (which the Internal app
            // would block with a scary "access denied" screen). This is a UX hint —
            // the Internal app type is the real org-membership gate.
            'hd'            => 'innovfix.in',
            'state'         => $state,
        ]);

        return redirect()->away($url);
    }

    public function googleLoginCallback(Request $request): RedirectResponse
    {
        $expectedState = $request->session()->pull('google_login_state');
        if (! $expectedState || $request->query('state') !== $expectedState) {
            return redirect()->route('login')->withErrors(['email' => 'Invalid login state. Please try again.']);
        }

        if ($request->query('error')) {
            return redirect()->route('login')->withErrors(['email' => 'Google login was cancelled.']);
        }

        $code = $request->query('code');
        if (! $code) {
            return redirect()->route('login')->withErrors(['email' => 'Google login failed. No code received.']);
        }

        try {
            $config = config('services.google.oauth');

            // Exchange code for token
            $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id'     => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'code'          => $code,
                'redirect_uri'  => $config['redirect_uri_login'] ?? $config['redirect_uri'],
                'grant_type'    => 'authorization_code',
            ]);

            $tokenData = $tokenResponse->json();

            if (! ($tokenData['access_token'] ?? null)) {
                Log::error('Google login token exchange failed', ['error' => $tokenData['error'] ?? 'unknown']);
                return redirect()->route('login')->withErrors(['email' => 'Google login failed.']);
            }

            // Get user info
            $userInfo = Http::withToken($tokenData['access_token'])
                ->get('https://www.googleapis.com/oauth2/v2/userinfo')
                ->json();

            $email = $userInfo['email'] ?? null;

            if (! $email) {
                return redirect()->route('login')->withErrors(['email' => 'Could not get email from Google.']);
            }

            // Find user by email
            $user = User::where('email', $email)->first();

            if (! $user) {
                // Also try personal_email
                $user = User::where('personal_email', $email)->first();
            }

            if (! $user || ! $user->is_active) {
                return redirect()->route('login')->withErrors(['email' => 'No Tessa account found for ' . $email]);
            }

            // Log them in
            $token = Str::random(64);
            $user->update([
                'last_login'     => now(),
                'remember_token' => hash('sha256', $token),
            ]);

            // NOTE: login is identity-only — it deliberately does NOT store a
            // google_access_token. That field is the sole signal for
            // hasGoogleConnection(); populating it here with an identity-scoped
            // token would falsely mark the user as Gmail-connected and make
            // Gmail scans 403. The Gmail/Calendar/Drive connection is
            // established only via the explicit "Connect Google" flow.

            Auth::login($user, false);
            $request->session()->regenerate();

            ActivityLogService::log($user->id, 'logged_in', "{$user->name} logged in via Google");

            $cookie = Cookie::make('tessa_remember', $token, 60 * 24 * 365, '/', null, true, true, false, 'lax');

            return redirect()->to(RoleMiddleware::homeForRole($user->role))->cookie($cookie);

        } catch (\Throwable $e) {
            Log::error('Google login callback error', ['exception' => $e->getMessage()]);
            return redirect()->route('login')->withErrors(['email' => 'Google login failed. Please try again.']);
        }
    }
}
