(function () {
    'use strict';

    var S = {
        wrap: 'padding:24px;max-width:980px;margin:0 auto;',
        title: 'font-size:22px;font-weight:700;margin:0;color:#fafafa;',
        sub: 'margin:4px 0 0;color:#a1a1aa;font-size:13px;',
        empty: 'color:#94a3b8;font-size:14px;padding:40px 24px;text-align:center;line-height:1.6;',
        guide: 'border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:18px 22px;margin-bottom:14px;background:rgba(255,255,255,.04);',
        prompt: 'color:#a1a1aa;font-size:14px;line-height:1.7;text-align:center;',
        note: 'color:#94a3b8;font-size:13px;text-align:center;padding:8px 4px;',
        search: 'padding:7px 12px;border:1px solid #374151;border-radius:8px;font-size:13px;min-width:180px;background:#1a2433;color:#e2e8f0;',
        select: 'padding:7px 10px;border:1px solid #374151;border-radius:8px;font-size:13px;background:#1a2433;color:#e2e8f0;',
        filterbar: 'display:none;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px;',
        card: 'border:1px solid #1e293b;border-radius:12px;padding:14px 16px;margin-bottom:10px;background:#0f1923;',
        cardToday: 'border-left:3px solid #3b82f6;',
        head: 'display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px;',
        dateLabel: 'font-weight:600;color:#94a3b8;font-size:12px;',
        who: 'color:#3b82f6;font-weight:600;font-size:13px;',
        right: 'margin-left:auto;display:flex;align-items:center;gap:8px;',
        time: 'color:#475569;font-size:12px;',
        reset: 'font-size:10px;color:#ef4444;background:none;border:1px solid rgba(239,68,68,.3);border-radius:5px;padding:2px 7px;cursor:pointer;',
        summary: 'color:#d1d5db;font-size:13px;line-height:1.55;white-space:pre-wrap;word-break:break-word;',
        chip: 'background:#1e293b;color:#64748b;border-radius:999px;padding:2px 9px;font-size:11px;',
    };

    var _all = [];
    var _roster = [];            // full active roster (overview only) — so never-used people show
    var _loggedIds = {};         // uid -> 1 for everyone who has EVER logged (beats the row cap)
    var _today = '';
    var _filter = '';
    var _filterUser = '';
    var _filterDate = '';
    var _bound = false;
    var _viewMode = 'stats';     // 'stats' | 'history' | 'mine'
    var _expandedUid = null;

    function cfg() { return window.__PORTAL_CONFIG || {}; }
    function canSeeAll() { return !!cfg().claudeContextOverview; }
    function root() { return document.getElementById('claude_contextView'); }

    function escapeHtml(v) {
        if (window.MeetingModule && MeetingModule.escapeHtml) return MeetingModule.escapeHtml(v);
        var d = document.createElement('div');
        d.textContent = v == null ? '' : String(v);
        return d.innerHTML;
    }

    function requestJson(url, options) {
        if (window.MeetingModule && MeetingModule.requestJson) return MeetingModule.requestJson(url, options);
        return fetch(url, Object.assign({
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }, options || {})).then(function (r) {
            return r.json().then(function (body) {
                if (!r.ok) { var e = new Error(body.message || body.error || 'Request failed'); e.status = r.status; throw e; }
                return body;
            });
        });
    }

    function showToast(msg, type) {
        var t = document.createElement('div');
        t.className = 'task-toast task-toast-' + (type || 'success');
        t.textContent = msg;
        document.body.appendChild(t);
        requestAnimationFrame(function () { t.classList.add('task-toast-show'); });
        setTimeout(function () { t.classList.remove('task-toast-show'); setTimeout(function () { t.remove(); }, 300); }, 3200);
    }

    function fmtDate(ymd) {
        var parts = String(ymd || '').split('-');
        var label = ymd || '';
        if (parts.length === 3) {
            var d = new Date(Date.UTC(+parts[0], +parts[1] - 1, +parts[2]));
            label = d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
        }
        return (_today && ymd === _today) ? 'Today · ' + label : label;
    }

    function fmtTime(iso) {
        if (!iso) return '';
        return new Date(iso).toLocaleString('en-US', { timeZone: 'Asia/Kolkata', hour: 'numeric', minute: '2-digit', hour12: true });
    }

    // ── Stats computation ──────────────────────────────────────────────────────

    // Score = number of distinct Claude-usage categories logged.
    // Intentionally ignores text length — a concise entry with 5 focused
    // categories reflects better utilisation than a 1000-word dump with 1.
    function entryScore(e) {
        return e ? (e.categories || []).length : 0;
    }

    function prevWorkday(dateStr) {
        var d = new Date(dateStr + 'T00:00:00Z');
        do { d.setUTCDate(d.getUTCDate() - 1); } while (d.getUTCDay() === 0 || d.getUTCDay() === 6);
        return d.toISOString().slice(0, 10);
    }

    function weekMondayDates(todayStr) {
        var t = new Date(todayStr + 'T00:00:00Z');
        var dow = t.getUTCDay();
        var mon = new Date(t);
        mon.setUTCDate(t.getUTCDate() - (dow === 0 ? 6 : dow - 1));
        var out = [];
        for (var i = 0; i < 5; i++) {
            var d = new Date(mon);
            d.setUTCDate(mon.getUTCDate() + i);
            out.push(d.toISOString().slice(0, 10));
        }
        return out;
    }

    function computeStreak(ebd, todayStr) {
        var d = new Date(todayStr + 'T00:00:00Z');
        if (!ebd[todayStr]) d.setUTCDate(d.getUTCDate() - 1);
        var n = 0;
        for (var i = 0; i < 60; i++) {
            var ds = d.toISOString().slice(0, 10), dow = d.getUTCDay();
            if (dow === 0 || dow === 6) { d.setUTCDate(d.getUTCDate() - 1); continue; }
            if (!ebd[ds]) break;
            n++;
            d.setUTCDate(d.getUTCDate() - 1);
        }
        return n;
    }

    // Tier ranking for sort: today first, then this-week, then lapsed, then never.
    var TIER_RANK = { today: 0, week: 1, lapsed: 2, never: 3 };

    function computeAllStats() {
        var wk = weekMondayDates(_today);
        var weekSet = {};
        wk.forEach(function (d) { weekSet[d] = 1; });

        // Seed every active employee first (overview only) so people who have
        // never logged still get a row, then fold in whatever entries exist.
        var byUser = {};
        (_roster || []).forEach(function (emp) {
            byUser[String(emp.id)] = { uid: String(emp.id), name: emp.name, entries: [] };
        });
        _all.forEach(function (e) {
            var uid = e.user_id != null ? String(e.user_id) : '__self';
            if (!byUser[uid]) byUser[uid] = { uid: uid, name: e.user_name || 'Me', entries: [] };
            byUser[uid].entries.push(e);
        });

        var stats = [];
        Object.keys(byUser).forEach(function (uid) {
            var u = byUser[uid];
            var ebd = {};
            u.entries.forEach(function (e) { ebd[e.date] = e; });
            var te = ebd[_today], ye = ebd[prevWorkday(_today)];
            var ts = entryScore(te), ys = entryScore(ye);
            var trend = !te ? 'missed' : !ye ? 'new' : ts > ys ? 'up' : ts < ys ? 'down' : 'same';
            var scores = u.entries.map(entryScore).filter(Boolean);
            var sortedEntries = u.entries.slice().sort(function (a, b) { return b.date.localeCompare(a.date); });

            var todayLogged = !!te;
            var usedThisWeek = u.entries.some(function (e) { return weekSet[e.date]; });
            // loggedIds covers history older than the row cap, so "never" is real.
            var everLogged = u.entries.length > 0 || !!_loggedIds[uid];
            var tier = todayLogged ? 'today' : usedThisWeek ? 'week' : everLogged ? 'lapsed' : 'never';

            stats.push({
                uid: uid,
                name: u.name,
                tier: tier,
                todayLogged: todayLogged,
                usedThisWeek: usedThisWeek,
                everLogged: everLogged,
                todayScore: ts,
                trend: trend,
                streak: computeStreak(ebd, _today),
                avgScore: scores.length ? Math.round(scores.reduce(function (a, b) { return a + b; }, 0) / scores.length) : 0,
                lastDate: sortedEntries.length ? sortedEntries[0].date : '',
                weekDates: wk,
                weekDots: wk.map(function (d) { return !!ebd[d]; }),
                entries: sortedEntries,
            });
        });

        return stats.sort(function (a, b) {
            if (a.tier !== b.tier) return TIER_RANK[a.tier] - TIER_RANK[b.tier];
            if (b.streak !== a.streak) return b.streak - a.streak;
            if (b.avgScore !== a.avgScore) return b.avgScore - a.avgScore;
            return String(a.name).localeCompare(String(b.name));
        });
    }

    // ── Visual helpers ─────────────────────────────────────────────────────────

    var ACOLORS = ['#3b82f6','#8b5cf6','#10b981','#f59e0b','#ef4444','#06b6d4','#ec4899','#0ea5e9'];

    function initials(name) {
        return String(name || '?').split(' ').slice(0, 2).map(function (p) { return p[0] || ''; }).join('').toUpperCase();
    }

    function avatarColor(name) {
        var h = 0;
        for (var i = 0; i < (name || '').length; i++) h = (h * 31 + name.charCodeAt(i)) | 0;
        return ACOLORS[Math.abs(h) % ACOLORS.length];
    }

    function chipsHtml(cats) {
        if (!cats || !cats.length) return '';
        return '<div style="display:flex;gap:5px;flex-wrap:wrap;margin-top:6px;">' +
            cats.map(function (c) { return '<span style="' + S.chip + '">' + escapeHtml(c) + '</span>'; }).join('') +
        '</div>';
    }

    // ── Stats card ─────────────────────────────────────────────────────────────

    function entryItemHtml(e, showReset) {
        var isToday = e.date === _today;
        return '<div style="padding:10px 0;border-bottom:1px solid rgba(255,255,255,.05);' + (isToday ? 'border-left:2px solid #3b82f6;padding-left:10px;' : '') + '">' +
            '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;">' +
                '<span style="font-size:11px;font-weight:600;color:#64748b;">' + escapeHtml(fmtDate(e.date)) + '</span>' +
                (showReset ? '<button class="cc-reset" data-id="' + escapeHtml(e.id) + '" style="' + S.reset + '">Reset</button>' : '') +
            '</div>' +
            '<div style="' + S.summary + '">' + escapeHtml(e.summary || '') + '</div>' +
            chipsHtml(e.categories) +
        '</div>';
    }

    // ── Leaderboard styles (injected once) ──────────────────────────────────────

    var _stylesInjected = false;
    function ensureStyles() {
        if (_stylesInjected || document.getElementById('cc-board-styles')) { _stylesInjected = true; return; }
        _stylesInjected = true;
        var css = [
            '.cc-grid{display:grid;grid-template-columns:minmax(150px,1fr) 148px 70px 118px 14px;gap:14px;align-items:center;}',
            '.cc-head{padding:4px 14px 9px;border:1px solid transparent;color:#64748b;font-size:10px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;}',
            '.cc-row{border:1px solid rgba(255,255,255,.05);background:rgba(255,255,255,.022);border-radius:10px;margin-bottom:4px;transition:background .12s,border-color .12s;}',
            '.cc-row.clk{cursor:pointer;}',
            '.cc-row.clk:hover{background:rgba(255,255,255,.055);border-color:rgba(255,255,255,.12);}',
            '.cc-row.exp{background:rgba(255,255,255,.05);border-color:rgba(255,255,255,.14);}',
            '.cc-row.muted{opacity:.5;}',
            '.cc-rowmain{padding:9px 14px;}',
            '.cc-member{display:flex;align-items:center;gap:10px;min-width:0;}',
            '.cc-av{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;flex-shrink:0;}',
            '.cc-name{font-weight:600;color:#e5e7eb;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
            '.cc-streak{font-size:10px;color:#f59e0b;font-weight:700;flex-shrink:0;white-space:nowrap;}',
            '.cc-week{display:grid;grid-template-columns:repeat(5,1fr);align-items:center;}',
            '.cc-week>span{display:flex;justify-content:center;}',
            '.cc-wklab{display:grid;grid-template-columns:repeat(5,1fr);}',
            '.cc-wklab>span{text-align:center;}',
            '.cc-dot{width:9px;height:9px;border-radius:50%;}',
            '.cc-avg{font-weight:700;font-size:13px;}',
            '.cc-status{font-size:11px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
            '.cc-chev{color:#475569;font-size:10px;text-align:center;}',
            '.cc-rowexp{padding:0 14px 11px;}',
            '.cc-tier{display:flex;align-items:center;gap:8px;margin:20px 0 8px;padding:0 2px;}',
            '.cc-tier-dot{width:7px;height:7px;border-radius:50%;}',
            '.cc-tier-lab{font-size:11px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#9aa6b2;}',
            '.cc-tier-n{font-size:11px;color:#52606d;font-weight:600;}',
        ].join('');
        var el = document.createElement('style');
        el.id = 'cc-board-styles';
        el.textContent = css;
        document.head.appendChild(el);
    }

    // ── Leaderboard row ─────────────────────────────────────────────────────────

    function fmtShort(ymd) {
        var p = String(ymd || '').split('-');
        if (p.length !== 3) return '—';
        var d = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2]));
        return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' });
    }

    // Mon–Fri presence dots for the row (aligned under the M T W T F header).
    function weekDotsCells(dots, dates) {
        return dots.map(function (on, i) {
            var future = dates[i] > _today, isToday = dates[i] === _today;
            var bg = future ? 'rgba(255,255,255,.05)' : on ? '#10b981' : '#27323f';
            var ring = isToday ? ';box-shadow:0 0 0 2px rgba(59,130,246,.55)' : '';
            return '<span><i class="cc-dot" style="background:' + bg + ring + '"></i></span>';
        }).join('');
    }

    function avgColor(v) { return v >= 5 ? '#34d399' : v >= 3 ? '#93c5fd' : '#cbd5e1'; }

    // The at-a-glance status cell, tuned per tier.
    function statusCell(s) {
        if (s.tier === 'never')  return '<span class="cc-status" style="color:#64748b;">Never used</span>';
        if (s.tier === 'lapsed') return '<span class="cc-status" style="color:#94a3b8;">Last ' + escapeHtml(fmtShort(s.lastDate)) + '</span>';
        if (s.tier === 'week')   return '<span class="cc-status" style="color:#f59e0b;">No entry today</span>';
        // today tier — compare with previous logged day
        if (s.trend === 'up')   return '<span class="cc-status" style="color:#34d399;">↑ More than last</span>';
        if (s.trend === 'down') return '<span class="cc-status" style="color:#f87171;">↓ Fewer than last</span>';
        if (s.trend === 'same') return '<span class="cc-status" style="color:#94a3b8;">→ Same as last</span>';
        return '<span class="cc-status" style="color:#94a3b8;">First entry</span>';
    }

    function rowHtml(s) {
        var expanded = _expandedUid === s.uid;
        var clickable = s.entries.length > 0;
        var cls = 'cc-row' + (clickable ? ' clk' : '') + (expanded ? ' exp' : '') + (s.tier === 'never' ? ' muted' : '');

        var avg = s.tier === 'never'
            ? '<span class="cc-avg" style="color:#475569;">—</span>'
            : '<span class="cc-avg" style="color:' + avgColor(s.avgScore) + ';">' + s.avgScore + '</span>';

        var streakBadge = s.streak >= 3 ? '<span class="cc-streak">🔥' + s.streak + '</span>' : '';

        var exp = '';
        if (expanded && clickable) {
            exp = '<div class="cc-rowexp">' +
                s.entries.map(function (e) { return entryItemHtml(e, canSeeAll()); }).join('') +
            '</div>';
        }

        return '<div class="' + cls + '" ' + (clickable ? 'data-uid="' + escapeHtml(s.uid) + '"' : '') + '>' +
            '<div class="cc-grid cc-rowmain">' +
                '<div class="cc-member">' +
                    '<span class="cc-av" style="background:' + avatarColor(s.name) + ';">' + escapeHtml(initials(s.name)) + '</span>' +
                    '<span class="cc-name">' + escapeHtml(s.name) + '</span>' + streakBadge +
                '</div>' +
                '<div class="cc-week">' + weekDotsCells(s.weekDots, s.weekDates) + '</div>' +
                avg +
                statusCell(s) +
                '<span class="cc-chev">' + (clickable ? (expanded ? '▾' : '▸') : '') + '</span>' +
            '</div>' +
            exp +
        '</div>';
    }

    // ── Stats (leaderboard) view ────────────────────────────────────────────────

    var TIER_META = {
        today:  { label: 'Using today',          dot: '#10b981' },
        week:   { label: 'This week, not today', dot: '#3b82f6' },
        lapsed: { label: 'Not used this week',   dot: '#f59e0b' },
        never:  { label: 'Never used Claude',    dot: '#64748b' },
    };

    function tierSectionHtml(tier, rows) {
        if (!rows.length) return '';
        var m = TIER_META[tier];
        return '<div class="cc-tier">' +
                '<span class="cc-tier-dot" style="background:' + m.dot + ';"></span>' +
                '<span class="cc-tier-lab">' + m.label + '</span>' +
                '<span class="cc-tier-n">' + rows.length + '</span>' +
            '</div>' +
            rows.map(rowHtml).join('');
    }

    function columnHeaderHtml(weekDates) {
        var days = ['M', 'T', 'W', 'T', 'F'];
        var labels = days.map(function (d, i) {
            var isToday = weekDates[i] === _today;
            return '<span style="font-size:10px;font-weight:700;color:' + (isToday ? '#93c5fd' : '#64748b') + ';">' + d + '</span>';
        }).join('');
        return '<div class="cc-grid cc-head">' +
            '<span>Member</span>' +
            '<span class="cc-wklab">' + labels + '</span>' +
            '<span>Avg</span>' +
            '<span>Status</span>' +
            '<span></span>' +
        '</div>';
    }

    function heroStat(label, val, color) {
        return '<div style="display:flex;align-items:baseline;gap:6px;">' +
            '<span style="font-size:20px;font-weight:700;color:' + color + ';line-height:1;">' + val + '</span>' +
            '<span style="font-size:11px;color:#6b7280;">' + escapeHtml(label) + '</span></div>';
    }

    function adoptionHero(stats) {
        var total = stats.length;
        var usedWeek = stats.filter(function (s) { return s.todayLogged || s.usedThisWeek; }).length;
        var todayN = stats.filter(function (s) { return s.todayLogged; }).length;
        var neverN = stats.filter(function (s) { return s.tier === 'never'; }).length;
        var pct = total ? Math.round((usedWeek / total) * 100) : 0;
        var barColor = pct >= 70 ? '#10b981' : pct >= 40 ? '#3b82f6' : '#f59e0b';

        return '<div style="background:rgba(255,255,255,.035);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:18px 20px;margin-bottom:6px;">' +
            '<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:10px;flex-wrap:wrap;gap:6px;">' +
                '<span style="font-size:11px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#9aa6b2;">Adoption this week</span>' +
                '<span style="font-size:13px;color:#cbd5e1;"><b style="color:#fafafa;font-size:15px;">' + usedWeek + '</b> of ' + total + ' used Claude · <span style="color:' + barColor + ';font-weight:700;">' + pct + '%</span></span>' +
            '</div>' +
            '<div style="background:rgba(255,255,255,.07);border-radius:999px;height:8px;overflow:hidden;">' +
                '<div style="background:' + barColor + ';height:8px;width:' + pct + '%;border-radius:999px;transition:width .35s;"></div>' +
            '</div>' +
            '<div style="display:flex;gap:26px;margin-top:14px;flex-wrap:wrap;">' +
                heroStat('logged today', todayN, '#34d399') +
                heroStat('active this week', usedWeek, '#93c5fd') +
                heroStat('never used', neverN, neverN ? '#f87171' : '#64748b') +
            '</div>' +
        '</div>';
    }

    function renderStats() {
        var body = document.getElementById('ccList');
        if (!body) return;
        ensureStyles();
        var stats = computeAllStats();
        if (!stats.length) {
            body.innerHTML = '<div style="' + S.empty + '">No employees to show yet.</div>';
            return;
        }
        var grouped = { today: [], week: [], lapsed: [], never: [] };
        stats.forEach(function (s) { grouped[s.tier].push(s); });
        var weekDates = stats[0].weekDates;

        body.innerHTML =
            adoptionHero(stats) +
            columnHeaderHtml(weekDates) +
            tierSectionHtml('today', grouped.today) +
            tierSectionHtml('week', grouped.week) +
            tierSectionHtml('lapsed', grouped.lapsed) +
            tierSectionHtml('never', grouped.never);
    }

    // ── History view ───────────────────────────────────────────────────────────

    function matches(e) {
        if (_filterUser && String(e.user_id) !== String(_filterUser)) return false;
        if (_filterDate && e.date !== _filterDate) return false;
        if (_filter) {
            var hay = ((e.user_name || '') + ' ' + (e.summary || '')).toLowerCase();
            if (hay.indexOf(_filter) === -1) return false;
        }
        return true;
    }

    function legacyCardHtml(e, all) {
        var whoHtml = all ? '<span style="' + S.who + '">' + escapeHtml(e.user_name || ('User #' + e.user_id)) + '</span>' : '';
        var resetBtn = all ? '<button class="cc-reset" data-id="' + escapeHtml(e.id) + '" style="' + S.reset + '">Reset</button>' : '';
        var isToday = e.date === _today;
        return '<div style="' + S.card + (isToday ? S.cardToday : '') + '">' +
            '<div style="' + S.head + '">' +
                '<span style="' + S.dateLabel + '">' + escapeHtml(fmtDate(e.date)) + '</span>' +
                whoHtml +
                '<span style="' + S.right + '">' +
                    '<span style="' + S.time + '">' + escapeHtml(fmtTime(e.created_at)) + '</span>' +
                    resetBtn +
                '</span>' +
            '</div>' +
            '<div style="' + S.summary + '">' + escapeHtml(e.summary || '') + '</div>' +
            chipsHtml(e.categories) +
        '</div>';
    }

    function populateUserFilter() {
        var sel = document.getElementById('ccUser');
        if (!sel) return;
        var seen = {}, users = [];
        _all.forEach(function (e) {
            if (e.user_id && !seen[e.user_id]) {
                seen[e.user_id] = 1;
                users.push({ id: e.user_id, name: e.user_name || ('User #' + e.user_id) });
            }
        });
        users.sort(function (a, b) { return String(a.name).localeCompare(String(b.name)); });
        var cur = sel.value;
        sel.innerHTML = '<option value="">All employees</option>' + users.map(function (u) {
            return '<option value="' + escapeHtml(u.id) + '">' + escapeHtml(u.name) + '</option>';
        }).join('');
        sel.value = cur;
    }

    // The personal "own daily summary" view — guidance card + today's entry.
    // Shared by the everyone-else History path and the overview "My Context"
    // tab. myId='' means no user filter (the entries are already only mine).
    function personalCardHtml(myId) {
        var guide = '<div style="' + S.guide + '"><div style="' + S.prompt + '">Open a <b>new</b> Claude chat each day and work in it. At sign-off, say:<br><i>"Summarize my context for today and send it to Tessa."</i></div></div>';
        var todayEntry = null, past = [];
        for (var i = 0; i < _all.length; i++) {
            var e = _all[i];
            if (myId && String(e.user_id) !== myId) continue;   // overview "My Context" tab filters to self
            if (e.date === _today) { if (!todayEntry) todayEntry = e; }
            else { past.push(e); }                               // _all is already newest-first
        }
        var html = guide + (todayEntry
            ? legacyCardHtml(todayEntry, false)
            : '<div style="' + S.note + '">Nothing logged yet for today.</div>');
        if (past.length) {
            html += '<div style="font-size:11px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#9aa6b2;margin:22px 2px 10px;">Past summaries · ' + past.length + '</div>'
                  + past.map(function (e) { return legacyCardHtml(e, false); }).join('');
        }
        return html;
    }

    // Overview users (JP, Fida) keep their own personal view as a 3rd tab,
    // filtered to themselves out of the team-wide dataset.
    function renderMine() {
        var body = document.getElementById('ccList');
        if (!body) return;
        body.innerHTML = personalCardHtml(String(cfg().userId || ''));
    }

    function renderHistory() {
        var body = document.getElementById('ccList');
        if (!body) return;
        if (!canSeeAll()) {
            body.innerHTML = personalCardHtml('');
            return;
        }
        var rows = _all.filter(matches);
        if (!rows.length) {
            body.innerHTML = '<div style="' + S.empty + '">' + (_filter || _filterUser || _filterDate ? 'No contexts match.' : 'No Claude contexts yet.') + '</div>';
            return;
        }
        body.innerHTML = rows.map(function (e) { return legacyCardHtml(e, true); }).join('');
    }

    // ── Main render ────────────────────────────────────────────────────────────

    function syncTabs() {
        var ACT = 'background:rgba(59,130,246,.2);color:#93c5fd;border-color:rgba(59,130,246,.3);';
        var IN = 'background:rgba(255,255,255,.04);color:#6b7280;border-color:rgba(255,255,255,.1);';
        var mi = document.getElementById('ccTabMine');
        var st = document.getElementById('ccTabStats'), hi = document.getElementById('ccTabHistory');
        if (mi) mi.setAttribute('style', TAB_BASE + (_viewMode === 'mine' ? ACT : IN));
        if (st) st.setAttribute('style', TAB_BASE + (_viewMode === 'stats' ? ACT : IN));
        if (hi) hi.setAttribute('style', TAB_BASE + (_viewMode === 'history' ? ACT : IN));
        var fb = document.getElementById('ccFilterBar');
        if (fb) fb.style.display = (_viewMode === 'history') ? 'flex' : 'none';
    }

    function renderMain() {
        syncTabs();
        if (!canSeeAll()) { renderHistory(); return; }
        if (_viewMode === 'history') renderHistory();
        else if (_viewMode === 'mine') renderMine();
        else renderStats();
    }

    // ── Shell ──────────────────────────────────────────────────────────────────

    var TAB_BASE = 'font-size:12px;font-weight:600;border:1px solid;border-radius:8px;padding:5px 14px;cursor:pointer;transition:background .15s,color .15s;';

    function ensureShell() {
        var r = root();
        if (!r) return null;
        if (r.getAttribute('data-cc-built') === '1') return r;
        r.setAttribute('data-cc-built', '1');
        var all = canSeeAll();
        var tabsHtml = all
            ? '<div style="display:flex;gap:6px;margin-bottom:16px;">' +
                '<button id="ccTabMine" style="' + TAB_BASE + '">My Context</button>' +
                '<button id="ccTabStats" style="' + TAB_BASE + '">Stats</button>' +
                '<button id="ccTabHistory" style="' + TAB_BASE + '">History</button>' +
              '</div>'
            : '';
        var filterBar = all
            ? '<div id="ccFilterBar" style="' + S.filterbar + '">' +
                '<select id="ccUser" style="' + S.select + '"><option value="">All employees</option></select>' +
                '<input type="date" id="ccDate" style="' + S.select + '">' +
                '<input type="search" id="ccSearch" placeholder="Search text…" style="' + S.search + '">' +
              '</div>'
            : '';
        r.innerHTML =
            '<div style="' + S.wrap + '">' +
                '<div style="margin-bottom:16px;">' +
                    '<h1 style="' + S.title + '">Claude Context</h1>' +
                    '<p style="' + S.sub + '">' + (all
                        ? 'Track how the team uses Claude — one entry per person per day.'
                        : 'Your daily end-of-day summary, written by Claude.') + '</p>' +
                '</div>' +
                tabsHtml + filterBar +
                '<div id="ccList"><div style="' + S.empty + '">Loading…</div></div>' +
            '</div>';
        return r;
    }

    // ── Event binding ──────────────────────────────────────────────────────────

    function bind() {
        if (_bound) return;
        var r = root();
        if (!r) return;
        _bound = true;

        r.addEventListener('click', function (ev) {
            var t = ev.target;
            // Tab buttons
            if (t && t.id === 'ccTabMine') {
                _viewMode = 'mine'; _expandedUid = null; renderMain(); return;
            }
            if (t && t.id === 'ccTabStats') {
                _viewMode = 'stats'; _expandedUid = null; renderMain(); return;
            }
            if (t && t.id === 'ccTabHistory') {
                _viewMode = 'history'; _expandedUid = null; renderMain(); return;
            }
            // Reset button
            var resetBtn = t && t.closest ? t.closest('.cc-reset') : null;
            if (resetBtn) {
                ev.stopPropagation();
                var id = resetBtn.getAttribute('data-id');
                if (!id || !window.confirm('Reset this day\'s Claude context? The employee can re-push a new summary.')) return;
                resetBtn.disabled = true;
                requestJson('/api/claude-context/' + id, { method: 'DELETE' }).then(function () {
                    _all = _all.filter(function (x) { return String(x.id) !== String(id); });
                    renderMain();
                    showToast('Context reset.', 'success');
                }).catch(function () {
                    resetBtn.disabled = false;
                    showToast('Could not reset.', 'error');
                });
                return;
            }
            // Leaderboard row expand / collapse (only rows with entries have data-uid)
            var row = t && t.closest ? t.closest('.cc-row') : null;
            if (row && row.getAttribute('data-uid')) {
                var uid = row.getAttribute('data-uid');
                _expandedUid = (_expandedUid === uid) ? null : uid;
                renderMain();
            }
        });

        r.addEventListener('input', function (ev) {
            if (ev.target && ev.target.id === 'ccSearch') {
                _filter = (ev.target.value || '').trim().toLowerCase();
                renderMain();
            }
        });
        r.addEventListener('change', function (ev) {
            if (!ev.target) return;
            if (ev.target.id === 'ccUser') { _filterUser = ev.target.value; renderMain(); }
            else if (ev.target.id === 'ccDate') { _filterDate = ev.target.value; renderMain(); }
        });
    }

    function load() {
        return requestJson('/api/claude-context').then(function (data) {
            _all = data.entries || [];
            _roster = data.employees || [];
            _loggedIds = {};
            (data.logged_user_ids || []).forEach(function (id) { _loggedIds[String(id)] = 1; });
            _today = data.today || '';
            populateUserFilter();
            renderMain();
        }).catch(function () {
            var body = document.getElementById('ccList');
            if (body) body.innerHTML = '<div style="' + S.empty + '">Failed to load.</div>';
        });
    }

    function render() {
        if (!ensureShell()) return;
        bind();
        load();
    }

    window.ClaudeContextModule = { render: render };
})();
