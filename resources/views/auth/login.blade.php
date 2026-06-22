<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>InnovFix - Portal Login</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('img/tessa-logo.svg') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('img/favicon-32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('img/favicon-16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('img/apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/login.css') }}">
</head>
<body>
    <main class="login-page">
        <section class="login-card">
            <div class="brand"><img src="{{ asset('img/tessa-logo.svg') }}" alt="Tessa" class="login-logo">Tessa</div>
            <h1>Welcome Back</h1>
            <p class="subtitle">Sign in to your dashboard — tasks, meetings, reports & more.</p>

            <a href="/api/auth/google" class="google-login-btn">
                <svg width="20" height="20" viewBox="0 0 24 24"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
                Log in with Google
            </a>

            <div class="divider"><span>OR</span></div>

            <form id="loginForm" class="login-form" method="POST" action="{{ route('login') }}" autocomplete="off">
                @csrf
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" placeholder="you@innovfix.in" autocomplete="off" required>

                <label for="password">Password</label>
                {{-- autocomplete="new-password" is the only reliable cross-browser
                     signal that stops Chrome/Firefox auto-filling a *saved* login
                     password; "off" alone is ignored for credential fields. --}}
                <input id="password" name="password" type="password" placeholder="Enter password" autocomplete="new-password" required>

                <button type="submit" id="submitBtn">Sign In</button>
                <p id="statusText" class="status">@error('email'){{ $message }}@enderror</p>
            </form>
        </section>
    </main>
    <script src="{{ asset('js/login.js') }}"></script>
</body>
</html>
