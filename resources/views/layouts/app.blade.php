<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>InnovFix - @yield('title', 'Portal')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    @stack('styles')
</head>
<body>
    <header class="top-bar">
        <div class="logo">InnovFix</div>
        <div class="user" style="color:#a3a3a3;font-size:0.85rem;display:flex;align-items:center;gap:10px">
            <span>{{ now('Asia/Kolkata')->format('D, M j') }}</span>
            <span>{{ auth()->user()->name ?? '' }}</span>
            <span>{{ strtoupper(auth()->user()->role ?? '') }}</span>
            <form action="{{ route('logout') }}" method="POST" style="display:inline">
                @csrf
                <button type="submit" class="logout-btn" style="border:1px solid #2d2d2d;background:#151515;color:#f5f5f5;border-radius:8px;padding:8px 12px;cursor:pointer">Logout</button>
            </form>
        </div>
    </header>
    <main class="wrap">
        @yield('content')
    </main>
    @stack('scripts')
</body>
</html>
