<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>InnovFix - {{ $config['title'] }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) ?: time() }}">
</head>
<body class="admin-body">

<div class="admin-shell">
    <aside class="admin-sidebar">
        <div class="admin-brand">
            <div class="admin-brand-logo">InnovFix</div>
            <div class="admin-brand-sub">Admin Console</div>
        </div>
        <nav class="admin-sidenav">
            <a href="#" class="admin-sidenav-link active" data-view="home">
                <span class="admin-sidenav-icon">▦</span> Dashboard
            </a>
            <a href="#" class="admin-sidenav-link" data-view="signin">
                <span class="admin-sidenav-icon">●</span> Sign-In / Sign-Off
            </a>
            <a href="#" class="admin-sidenav-link" data-view="daily">
                <span class="admin-sidenav-icon">≡</span> Daily Reports
            </a>
            <a href="#" class="admin-sidenav-link" data-view="meetings">
                <span class="admin-sidenav-icon">◷</span> Meetings
            </a>
            @if (in_array($config['userId'] ?? null, config('timesheet_access.tracker_user_ids', []), true))
            <a href="#" class="admin-sidenav-link" data-view="timesheetTracker">
                <span class="admin-sidenav-icon">◴</span> Timesheets
            </a>
            @endif
            @if (in_array($config['userId'] ?? null, config('timesheet_access.self_log_user_ids', []), true))
            <a href="#" class="admin-sidenav-link" data-view="timesheetAssistant">
                <span class="admin-sidenav-icon">✺</span> Log via Tessa
            </a>
            @endif
        </nav>
        <div class="admin-sidenav-footer">
            <div class="admin-user-chip">
                <div class="admin-user-name">{{ $config['userName'] ?? '' }}</div>
                <div class="admin-user-role">{{ $config['roleName'] ?? 'ADMIN' }}</div>
            </div>
            <form method="POST" action="{{ route('logout') }}" class="admin-logout-form">
                @csrf
                <button type="submit" class="admin-logout-btn">Logout</button>
            </form>
        </div>
    </aside>

    <div class="admin-content">
        <header class="admin-topbar">
            <div class="admin-topbar-left">
                <h1 id="adminViewTitle" class="admin-page-title">Dashboard</h1>
                <span class="admin-page-sub" id="adminViewSub">Team overview at a glance</span>
            </div>
            <div class="admin-topbar-right">
                <span class="current-date" id="currentDate"></span>
            </div>
        </header>

        <div class="birthday-banner" id="adminBirthdayBanner" style="display:none"></div>
        <div class="bday-confetti" id="adminBdayConfetti" aria-hidden="true"></div>

        {{-- Dashboard Home (default view) --}}
        <section id="homeView" class="admin-view">
            <div class="admin-controls-row">
                <div class="admin-controls">
                    <div class="admin-mode-toggle" id="homeModeToggle">
                        <button type="button" class="admin-mode-btn" data-mode="day">Day</button>
                        <button type="button" class="admin-mode-btn active" data-mode="week">Week</button>
                    </div>
                    <label for="homeDate" class="admin-control-label" id="homeDateLabel">Date</label>
                    <input type="date" id="homeDate" class="admin-input">
                    <span class="admin-period-range" id="homePeriodRange"></span>
                    <button type="button" id="homeRefreshBtn" class="admin-btn">Refresh</button>
                </div>
                <div class="admin-controls">
                    <input type="search" id="homeSearch" class="admin-input admin-search" placeholder="Search employee or role…">
                </div>
            </div>

            <div class="emp-table-wrap admin-card">
                <table class="emp-table">
                    <thead>
                        <tr>
                            <th class="emp-th-name">Employee</th>
                            <th class="emp-th-status">Sign-In</th>
                            <th class="emp-th-report">Daily Report</th>
                            <th class="emp-th-pending">Pending Work</th>
                            <th class="emp-th-meet">Meetings</th>
                            <th class="emp-th-mom">Agenda / Minutes</th>
                        </tr>
                    </thead>
                    <tbody id="homeEmpBody">
                        <tr><td colspan="6" class="emp-empty">Loading employees…</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Sign-In / Sign-Off --}}
        <section id="signinView" class="admin-view hidden">
            <div class="admin-controls-row">
                <div class="admin-controls">
                    <span class="admin-summary" id="signinSummary"></span>
                </div>
                <div class="admin-controls">
                    <label for="signinDate" class="admin-control-label">Date</label>
                    <input type="date" id="signinDate" class="admin-input">
                    <button type="button" id="signinRefreshBtn" class="admin-btn">Refresh</button>
                </div>
            </div>
            <div class="dr-table-wrap admin-card">
                <table class="dr-table admin-table">
                    <thead>
                        <tr>
                            <th class="dr-metric-th">User</th>
                            <th class="dr-day-th">Role</th>
                            <th class="dr-day-th">Sign-In</th>
                            <th class="dr-day-th">Sign-Off</th>
                            <th class="dr-day-th">Status</th>
                        </tr>
                    </thead>
                    <tbody id="signinTableBody">
                        <tr><td colspan="5" class="admin-loading">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Daily Reports --}}
        <section id="dailyView" class="admin-view hidden">
            <div class="admin-controls-row">
                <div class="admin-controls">
                    <label for="dailyReportDate" class="admin-control-label">Report date</label>
                    <input type="date" id="dailyReportDate" class="admin-input">
                    <button type="button" id="dailyRefreshBtn" class="admin-btn">Refresh</button>
                </div>
            </div>
            <div class="dr-table-wrap admin-card">
                <table class="dr-table admin-table">
                    <thead>
                        <tr>
                            <th class="dr-metric-th">User</th>
                            <th class="dr-day-th">Role</th>
                            <th class="dr-day-th">Filled</th>
                            <th class="dr-day-th">Total</th>
                            <th class="dr-day-th">Status</th>
                        </tr>
                    </thead>
                    <tbody id="dailyTableBody">
                        <tr><td colspan="5" class="admin-loading">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Meetings --}}
        <section id="meetingsView" class="admin-view hidden">
            <div class="admin-controls-row">
                <div class="admin-controls">
                    <label for="meetingsDate" class="admin-control-label">Date</label>
                    <input type="date" id="meetingsDate" class="admin-input">
                    <button type="button" id="meetingsRefreshBtn" class="admin-btn">Refresh</button>
                </div>
            </div>
            <div class="dr-table-wrap admin-card">
                <table class="dr-table admin-table">
                    <thead>
                        <tr>
                            <th class="dr-metric-th">Meeting</th>
                            <th class="dr-day-th">Owner</th>
                            <th class="dr-day-th">Time</th>
                            <th class="dr-day-th">Recurrence</th>
                            <th class="dr-day-th">Portal</th>
                            <th class="dr-day-th">Attendees</th>
                            <th class="dr-day-th">Agenda</th>
                            <th class="dr-day-th">Notes</th>
                        </tr>
                    </thead>
                    <tbody id="meetingsTableBody">
                        <tr><td colspan="8" class="admin-loading">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Tasks & Tickets --}}
        <section id="tasksView" class="admin-view hidden">
            <div class="admin-controls-row">
                <div class="admin-controls">
                    <span class="admin-summary" id="tasksSummary"></span>
                </div>
                <div class="admin-controls">
                    <button type="button" id="tasksRefreshBtn" class="admin-btn">Refresh</button>
                </div>
            </div>
            <div class="dr-table-wrap admin-card">
                <table class="dr-table admin-table">
                    <thead>
                        <tr>
                            <th class="dr-metric-th">User</th>
                            <th class="dr-day-th">Role</th>
                            <th class="dr-day-th">Tasks (Open / Overdue)</th>
                            <th class="dr-day-th">Tickets (Open / Resolved)</th>
                            <th class="dr-day-th">Bugs (Open / Resolved)</th>
                            <th class="dr-day-th">Status</th>
                        </tr>
                    </thead>
                    <tbody id="tasksTableBody">
                        <tr><td colspan="6" class="admin-loading">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Workforce (payments) --}}
        <section id="workforceAdminView" class="admin-view hidden"></section>

        @if (in_array($config['userId'] ?? null, config('timesheet_access.tracker_user_ids', []), true))
        {{-- Timesheet Tracker --}}
        <section id="timesheetTrackerView" class="admin-view hidden"></section>
        @endif

        @if (in_array($config['userId'] ?? null, config('timesheet_access.self_log_user_ids', []), true))
        {{-- Timesheet AI Assistant --}}
        <section id="timesheetAssistantView" class="admin-view hidden"></section>
        @endif
    </div>
</div>

<script>window.__ADMIN_CONFIG = @json($config);</script>
<script src="{{ asset('js/admin.js') }}?v={{ @filemtime(public_path('js/admin.js')) ?: time() }}"></script>
<script src="{{ asset('js/workforce.js') }}?v={{ @filemtime(public_path('js/workforce.js')) ?: time() }}"></script>
@if (in_array($config['userId'] ?? null, config('timesheet_access.self_log_user_ids', []), true))
<script src="{{ asset('js/timesheet-assistant.js') }}?v={{ @filemtime(public_path('js/timesheet-assistant.js')) ?: time() }}"></script>
@endif
@if (in_array($config['userId'] ?? null, config('timesheet_access.tracker_user_ids', []), true))
<script src="{{ asset('js/timesheet-tracker.js') }}?v={{ @filemtime(public_path('js/timesheet-tracker.js')) ?: time() }}"></script>
@endif
<script>
// Wire the three new admin sidebar views. admin.js handles built-in views via data-view;
// these three are loaded by their own modules and triggered on first reveal.
(function () {
    function showAdminView(view) {
        document.querySelectorAll('.admin-view').forEach(el => el.classList.add('hidden'));
        document.querySelectorAll('.admin-sidenav-link').forEach(el => el.classList.remove('active'));
        const target = document.getElementById(view + 'View');
        if (target) target.classList.remove('hidden');
        const link = document.querySelector('.admin-sidenav-link[data-view="' + view + '"]');
        if (link) link.classList.add('active');
        const titleMap = {
            workforceAdmin: ['Weekly Summary', 'Team timesheets, hours and payments'],
            timesheetTracker: ['Timesheet Tracker', 'Daily / weekly / monthly compliance'],
            timesheetAssistant: ['Log via Tessa', 'Conversational timesheet entry'],
        };
        const t = titleMap[view];
        if (t) {
            const titleEl = document.getElementById('adminViewTitle');
            const subEl = document.getElementById('adminViewSub');
            if (titleEl) titleEl.textContent = t[0];
            if (subEl) subEl.textContent = t[1];
        }
    }

    function bindAdminTimesheetTabs() {
        document.querySelectorAll('.admin-sidenav-link').forEach(link => {
            const view = link.getAttribute('data-view');
            if (!view || !['workforceAdmin', 'timesheetTracker', 'timesheetAssistant'].includes(view)) return;
            link.addEventListener('click', function (e) {
                e.preventDefault();
                showAdminView(view);
                const container = document.getElementById(view + 'View');
                if (!container) return;
                if (view === 'workforceAdmin' && window.WorkforceAdmin?.render) {
                    window.WorkforceAdmin.render(container);
                } else if (view === 'timesheetTracker' && window.TimesheetTracker?.render) {
                    window.TimesheetTracker.render(container);
                } else if (view === 'timesheetAssistant' && window.TimesheetAssistant?.mount) {
                    window.TimesheetAssistant.mount(container);
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindAdminTimesheetTabs);
    } else {
        bindAdminTimesheetTabs();
    }
})();
</script>
<script>
// Birthday celebration on admin-console load (mirrors the portal). Reuses
// the .birthday-* / .bday-* styles already in app.css.
(function () {
    var cfg = window.__ADMIN_CONFIG || {};
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }
    function fireConfetti(force) {
        var box = document.getElementById('adminBdayConfetti');
        if (!box) return;
        box.innerHTML = '';
        var colors = ['#db2777', '#9333ea', '#f59e0b', '#10b981', '#3b82f6', '#ef4444', '#fde047'];
        var poppers = ['🎉', '🎊', '🥳', '🎂', '✨', '🎈'];
        for (var i = 0; i < 170; i++) {
            var p = document.createElement('span');
            if (i % 5 === 0) {
                p.className = 'bday-emoji';
                p.textContent = poppers[Math.floor(Math.random() * poppers.length)];
            } else {
                p.className = 'bday-piece';
                p.style.background = colors[Math.floor(Math.random() * colors.length)];
            }
            p.style.left = (Math.random() * 100) + 'vw';
            p.style.setProperty('--dx', (Math.random() * 30 - 15).toFixed(1) + 'vw');
            p.style.animationDelay = (Math.random() * 1.4).toFixed(2) + 's';
            p.style.animationDuration = (3 + Math.random() * 2.5).toFixed(2) + 's';
            box.appendChild(p);
        }
        var bl = document.createElement('span');
        bl.className = 'bday-blast bday-blast--l'; bl.textContent = '🎉';
        var br = document.createElement('span');
        br.className = 'bday-blast bday-blast--r'; br.textContent = '🎉';
        var cheer = document.createElement('span');
        cheer.className = 'bday-cheer'; cheer.textContent = '🥳 Happy Birthday! 🥳';
        box.appendChild(bl); box.appendChild(br); box.appendChild(cheer);

        box.classList.add('bday-confetti--on');
        if (force) box.style.setProperty('display', 'block', 'important');
        setTimeout(function () {
            box.classList.remove('bday-confetti--on');
            box.style.removeProperty('display');
            box.innerHTML = '';
        }, 8000);
    }
    // Manual replay from devtools (ignores session guard + reduced-motion).
    window.fireBirthday = function () { fireConfetti(true); };
    function renderBanner() {
        var el = document.getElementById('adminBirthdayBanner');
        if (!el) return;
        if (/[?#&]birthday=(demo|test|1)\b/i.test(location.href)) {
            el.className = 'birthday-banner birthday-banner--me';
            el.innerHTML = '<span class="bday-ico">🎉</span> Happy Birthday! 🎂 The whole team is wishing you a wonderful year ahead. <em>(demo)</em>';
            el.style.display = '';
            document.body.classList.add('bday-mode-self');
            fireConfetti(true);
            return;
        }
        var list = cfg.todaysBirthdays || [];
        var mine = !!(cfg.myBirthday && cfg.myBirthday.is);
        if (!list.length && !mine) { el.style.display = 'none'; return; }
        if (mine) {
            var first = ((cfg.myBirthday && cfg.myBirthday.name) || '').split(' ')[0];
            el.className = 'birthday-banner birthday-banner--me';
            el.innerHTML = '<span class="bday-ico">🎉</span> Happy Birthday, <strong>' + esc(first) +
                '</strong>! 🎂 The whole team is wishing you a wonderful year ahead.';
            document.body.classList.add('bday-mode-self');
            el.style.display = '';
            // Party-poppers ONLY for the birthday person, once per session.
            try {
                var key = 'bdayCelebrated:' + new Date().toISOString().slice(0, 10) + ':me:admin';
                if (!sessionStorage.getItem(key)) { sessionStorage.setItem(key, '1'); fireConfetti(); }
            } catch (e) { fireConfetti(); }
        } else {
            var names = list.map(function (b) { return esc(b.name); });
            var joined = names.length === 1 ? names[0]
                : names.slice(0, -1).join(', ') + ' & ' + names[names.length - 1];
            el.className = 'birthday-banner birthday-banner--notify';
            el.innerHTML = '<span class="bday-ico">🎂</span> It\'s <strong>' + joined +
                '</strong>\'s birthday today — don\'t forget to wish ' +
                (names.length === 1 ? 'them' : 'them all') + ' a happy birthday! 🎉';
            el.style.display = '';
            // No party-poppers for non-birthday people — just the reminder.
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderBanner);
    } else {
        renderBanner();
    }
})();
</script>
</body>
</html>
