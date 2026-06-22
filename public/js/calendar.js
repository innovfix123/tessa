/*
 * Personal Calendar — a month-grid view backed by the user's own Google
 * Calendar. Add all-day "notes" (real all-day GCal events), edit/delete them,
 * and see timed events read-only. Also fills the dashboard "Calendar" card.
 *
 * Gated server-side: this script only loads for users in
 * config/calendar_access.php → viewer_user_ids. Exposes window.TessaCalendar.
 * Degrades to a "connect Google" prompt when the user hasn't connected.
 */
(function () {
    'use strict';

    var LOOKAHEAD = 7; // dashboard card window (today inclusive) — matches config default
    var WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];

    // Per-product chip colours for the recurring product meetings (Hima / Only
    // Care / Unman / BLR Connect). Matched by title keyword (first match wins);
    // fg = text colour for contrast against bg. Unmatched events keep the
    // default note/event styling.
    var PRODUCT_COLORS = [
        { re: /\bhima\b/i,                    bg: '#ec4899', fg: '#ffffff' }, // pink
        { re: /only\s*care/i,                 bg: '#a855f7', fg: '#ffffff' }, // purple
        { re: /\bunman\b/i,                   bg: '#16a34a', fg: '#ffffff' }, // green
        { re: /(?:blr|bangalore)\s*connect|\bbc\b/i, bg: '#ffffff', fg: '#18181b' }  // white (incl. "BC" label)
    ];
    function eventColor(title) {
        var t = String(title || '');
        for (var i = 0; i < PRODUCT_COLORS.length; i++) {
            if (PRODUCT_COLORS[i].re.test(t)) return PRODUCT_COLORS[i];
        }
        return null;
    }

    var state = { year: null, month: null, events: [], byId: {}, rootEl: null, busy: false };

    // ── helpers ──────────────────────────────────────────────────
    function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.getAttribute('content') : ''; }
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function ymd(y, m, d) { return y + '-' + pad(m) + '-' + pad(d); } // m is 1-based
    function todayYmd() { var d = new Date(); return ymd(d.getFullYear(), d.getMonth() + 1, d.getDate()); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function fmtTime(mins) {
        if (mins == null) return '';
        var h = Math.floor(mins / 60), m = mins % 60, ap = h < 12 ? 'AM' : 'PM', hh = h % 12;
        if (hh === 0) hh = 12;
        return hh + (m ? ':' + pad(m) : '') + ' ' + ap;
    }
    function prettyDate(ds) {
        var t = todayYmd();
        var d = new Date(ds + 'T00:00:00');
        var tomorrow = new Date(); tomorrow.setDate(tomorrow.getDate() + 1);
        if (ds === t) return 'Today';
        if (ds === ymd(tomorrow.getFullYear(), tomorrow.getMonth() + 1, tomorrow.getDate())) return 'Tomorrow';
        return WEEKDAYS[d.getDay()] + ', ' + d.getDate() + ' ' + MONTHS[d.getMonth()].slice(0, 3);
    }

    function api(method, url, body) {
        var opts = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
        if (method !== 'GET') opts.headers['X-CSRF-TOKEN'] = csrf();
        if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
        return fetch(url, opts).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                return { status: r.status, ok: r.ok, body: j };
            });
        });
    }
    function notConnected(res) {
        return res.status === 401 || res.status === 403 || (res.body && (res.body.reconnect || res.body.connect_url));
    }

    // ── styles (injected once) ───────────────────────────────────
    function ensureStyles() {
        if (document.getElementById('tcalStyles')) return;
        var s = document.createElement('style');
        s.id = 'tcalStyles';
        s.textContent = [
            '.tcal-wrap{padding:24px;max-width:1100px;margin:0 auto;color:#e4e4e7}',
            '.tcal-head{display:flex;align-items:center;gap:14px;margin-bottom:16px;flex-wrap:wrap}',
            '.tcal-title{font-size:20px;font-weight:700;min-width:190px}',
            '.tcal-nav-btn{background:#27272a;border:1px solid #3f3f46;color:#e4e4e7;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:16px;line-height:1}',
            '.tcal-nav-btn:hover{background:#3f3f46}',
            '.tcal-today-btn{background:#27272a;border:1px solid #3f3f46;color:#e4e4e7;padding:6px 12px;border-radius:8px;cursor:pointer;font-size:13px}',
            '.tcal-today-btn:hover{background:#3f3f46}',
            '.tcal-add-btn{margin-left:auto;background:#3b82f6;border:0;color:#fff;padding:8px 14px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600}',
            '.tcal-add-btn:hover{background:#2563eb}',
            '.tcal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:1px;background:#27272a;border:1px solid #27272a;border-radius:10px;overflow:hidden}',
            '.tcal-dow{background:#18181b;padding:8px 6px;text-align:center;font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.04em}',
            '.tcal-cell{background:#0f0f11;min-height:104px;padding:6px;position:relative;display:flex;flex-direction:column}',
            '.tcal-empty{background:#141416}',
            '.tcal-today{background:#101a2e;box-shadow:inset 0 0 0 1px #3b82f6}',
            '.tcal-daynum{font-size:12px;color:#9ca3af;margin-bottom:4px;font-weight:600}',
            '.tcal-today .tcal-daynum{color:#60a5fa}',
            '.tcal-chips{display:flex;flex-direction:column;gap:3px;flex:1;overflow:hidden}',
            '.tcal-chip{text-align:left;border:0;border-radius:5px;padding:3px 6px;font-size:11px;cursor:pointer;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;width:100%;line-height:1.3}',
            '.tcal-note{background:#2563eb}',
            '.tcal-note:hover{background:#1d4ed8}',
            '.tcal-event{background:#3f3f46;color:#d4d4d8}',
            '.tcal-event:hover{background:#52525b}',
            '.tcal-chip-time{opacity:.8;font-weight:600;margin-right:2px}',
            '.tcal-cell-add{position:absolute;top:4px;right:4px;width:20px;height:20px;border-radius:5px;border:0;background:transparent;color:#52525b;font-size:16px;line-height:18px;cursor:pointer;opacity:0;transition:opacity .12s}',
            '.tcal-cell:hover .tcal-cell-add{opacity:1}',
            '.tcal-cell-add:hover{background:#27272a;color:#e4e4e7}',
            '.tcal-state{padding:48px 24px;text-align:center;color:#9ca3af}',
            '.tcal-connect-btn{margin-top:12px;background:#3b82f6;border:0;color:#fff;padding:8px 16px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600}',
            /* modal */
            '.tcal-modal-ov{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9000;display:flex;align-items:center;justify-content:center;padding:16px}',
            '.tcal-modal{background:#18181b;border:1px solid #3f3f46;border-radius:14px;width:100%;max-width:420px;padding:20px}',
            '.tcal-modal h3{margin:0 0 14px;font-size:16px;color:#e4e4e7}',
            '.tcal-field{margin-bottom:12px}',
            '.tcal-field label{display:block;font-size:12px;color:#9ca3af;margin-bottom:4px}',
            '.tcal-field input,.tcal-field textarea{width:100%;background:#0f0f11;border:1px solid #3f3f46;border-radius:8px;color:#e4e4e7;padding:8px 10px;font-size:13px;font-family:inherit;box-sizing:border-box}',
            '.tcal-field textarea{resize:vertical;min-height:60px}',
            '.tcal-modal-actions{display:flex;gap:8px;margin-top:16px;align-items:center}',
            '.tcal-btn{padding:8px 14px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;border:1px solid #3f3f46;background:#27272a;color:#e4e4e7}',
            '.tcal-btn:hover{background:#3f3f46}',
            '.tcal-btn-primary{background:#3b82f6;border-color:#3b82f6;color:#fff}',
            '.tcal-btn-primary:hover{background:#2563eb}',
            '.tcal-btn-primary:disabled{opacity:.6;cursor:default}',
            '.tcal-btn-danger{color:#f87171;border-color:#7f1d1d;background:transparent}',
            '.tcal-btn-danger:hover{background:#7f1d1d;color:#fff}',
            '.tcal-modal-err{color:#f87171;font-size:12px;margin-top:8px;min-height:14px}',
            /* dashboard card */
            '.tcal-dash-group{margin-bottom:12px}',
            '.tcal-dash-date{font-size:11px;font-weight:700;color:#60a5fa;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}',
            '.tcal-dash-item{display:flex;gap:8px;align-items:baseline;padding:5px 8px;border-radius:6px;background:#0f0f11;border:1px solid #27272a;margin-bottom:4px}',
            '.tcal-dash-item .t{font-size:11px;color:#9ca3af;min-width:60px;font-weight:600}',
            '.tcal-dash-item .n{font-size:13px;color:#e4e4e7}',
            '.tcal-dash-dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:4px}'
        ].join('\n');
        document.head.appendChild(s);
    }

    // ── data ─────────────────────────────────────────────────────
    function eventsByDate() {
        var map = {};
        state.byId = {};
        state.events.forEach(function (e) {
            if (e.id) state.byId[e.id] = e;
            if (!e.date) return;
            (map[e.date] = map[e.date] || []).push(e);
        });
        Object.keys(map).forEach(function (k) {
            map[k].sort(function (a, b) {
                if (a.all_day && !b.all_day) return -1;
                if (!a.all_day && b.all_day) return 1;
                return (a.start_minutes || 0) - (b.start_minutes || 0);
            });
        });
        return map;
    }

    // ── full Calendar view ───────────────────────────────────────
    function headerHtml() {
        return '<div class="tcal-head">' +
            '<button type="button" class="tcal-nav-btn" data-nav="prev" aria-label="Previous month">&lsaquo;</button>' +
            '<div class="tcal-title" id="tcalTitle">' + esc(MONTHS[state.month - 1] + ' ' + state.year) + '</div>' +
            '<button type="button" class="tcal-nav-btn" data-nav="next" aria-label="Next month">&rsaquo;</button>' +
            '<button type="button" class="tcal-today-btn" data-nav="today">Today</button>' +
            '<button type="button" class="tcal-add-btn" id="tcalAddBtn">+ Add note</button>' +
            '</div>';
    }
    function gridHtml() {
        var y = state.year, m = state.month;
        var startW = new Date(y, m - 1, 1).getDay();
        var dim = new Date(y, m, 0).getDate();
        var byDate = eventsByDate();
        var today = todayYmd();
        var html = '<div class="tcal-grid">';
        WEEKDAYS.forEach(function (w) { html += '<div class="tcal-dow">' + w + '</div>'; });
        for (var i = 0; i < startW; i++) html += '<div class="tcal-cell tcal-empty"></div>';
        for (var d = 1; d <= dim; d++) {
            var ds = ymd(y, m, d);
            var evs = byDate[ds] || [];
            html += '<div class="tcal-cell' + (ds === today ? ' tcal-today' : '') + '" data-date="' + ds + '">' +
                '<div class="tcal-daynum">' + d + '</div>' +
                '<button type="button" class="tcal-cell-add" data-add="' + ds + '" aria-label="Add note">+</button>' +
                '<div class="tcal-chips">';
            evs.forEach(function (e) {
                var c = eventColor(e.title);
                var st = c ? ' style="background:' + c.bg + ';color:' + c.fg + '"' : '';
                if (e.all_day) {
                    html += '<button type="button" class="tcal-chip tcal-note" data-id="' + esc(e.id) + '"' + st + ' title="' + esc(e.title) + '">' + esc(e.title) + '</button>';
                } else {
                    html += '<button type="button" class="tcal-chip tcal-event" data-id="' + esc(e.id) + '"' + st + ' title="' + esc(e.title) + '">' +
                        '<span class="tcal-chip-time">' + esc(fmtTime(e.start_minutes)) + '</span>' + esc(e.title) + '</button>';
                }
            });
            html += '</div></div>';
        }
        html += '</div>';
        return html;
    }
    function connectPromptHtml() {
        return '<div class="tcal-state">Connect your Google account to use the Calendar.' +
            '<br><button type="button" class="tcal-connect-btn" id="tcalConnect">Go to My Profile</button></div>';
    }
    function errorHtml(msg) {
        return '<div class="tcal-state">Couldn\'t load your calendar.<br><span style="font-size:12px;color:#71717a">' + esc(msg || '') + '</span></div>';
    }

    function render(rootEl) {
        if (!rootEl) return;
        ensureStyles();
        state.rootEl = rootEl;
        if (state.year == null) { var t = new Date(); state.year = t.getFullYear(); state.month = t.getMonth() + 1; }
        rootEl.innerHTML = '<div class="tcal-wrap">' + headerHtml() + '<div id="tcalBody"><div class="tcal-state">Loading…</div></div></div>';
        bindHeader(rootEl);
        load(rootEl);
    }

    function load(rootEl) {
        var body = rootEl.querySelector('#tcalBody');
        var title = rootEl.querySelector('#tcalTitle');
        if (title) title.textContent = MONTHS[state.month - 1] + ' ' + state.year;
        api('GET', '/api/google/calendar/month?year=' + state.year + '&month=' + state.month).then(function (res) {
            if (notConnected(res)) { body.innerHTML = connectPromptHtml(); bindConnect(body); return; }
            if (!res.ok) { body.innerHTML = errorHtml(res.body && res.body.error); return; }
            state.events = (res.body && res.body.data) || [];
            body.innerHTML = gridHtml();
            bindGrid(rootEl);
        }).catch(function () { body.innerHTML = errorHtml('Network error'); });
    }

    function bindHeader(rootEl) {
        rootEl.querySelectorAll('[data-nav]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var nav = btn.getAttribute('data-nav');
                if (nav === 'today') { var t = new Date(); state.year = t.getFullYear(); state.month = t.getMonth() + 1; }
                else if (nav === 'prev') { state.month--; if (state.month < 1) { state.month = 12; state.year--; } }
                else if (nav === 'next') { state.month++; if (state.month > 12) { state.month = 1; state.year++; } }
                load(rootEl);
            });
        });
        var addBtn = rootEl.querySelector('#tcalAddBtn');
        if (addBtn) addBtn.addEventListener('click', function () { openModal(todayYmd(), null); });
    }
    function bindConnect(scope) {
        var b = scope.querySelector('#tcalConnect');
        if (b) b.addEventListener('click', function () {
            if (window.MeetingModule && window.MeetingModule.switchView) window.MeetingModule.switchView('profile');
        });
    }
    function bindGrid(rootEl) {
        rootEl.querySelectorAll('.tcal-cell-add').forEach(function (b) {
            b.addEventListener('click', function (e) { e.stopPropagation(); openModal(b.getAttribute('data-add'), null); });
        });
        rootEl.querySelectorAll('.tcal-chip').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                var ev = state.byId[b.getAttribute('data-id')];
                if (!ev) return;
                if (ev.all_day) openModal(ev.date, ev);           // editable note
                else if (ev.html_link) window.open(ev.html_link, '_blank'); // timed event → open in Google
            });
        });
    }

    // ── add / edit modal ─────────────────────────────────────────
    function openModal(dateStr, note) {
        ensureStyles();
        closeModal();
        var editing = !!note;
        var ov = document.createElement('div');
        ov.className = 'tcal-modal-ov';
        ov.id = 'tcalModalOv';
        ov.innerHTML = '<div class="tcal-modal" role="dialog" aria-modal="true">' +
            '<h3>' + (editing ? 'Edit note' : 'Add note') + '</h3>' +
            '<div class="tcal-field"><label>Note</label><input type="text" id="tcalTitle2" maxlength="255" placeholder="What\'s happening?" value="' + esc(editing ? note.title : '') + '"></div>' +
            '<div class="tcal-field"><label>Date</label><input type="date" id="tcalDate" value="' + esc(dateStr) + '"></div>' +
            '<div class="tcal-field"><label>Details (optional)</label><textarea id="tcalDesc" maxlength="2000" placeholder="Add details…">' + esc(editing ? (note.description || '') : '') + '</textarea></div>' +
            '<div class="tcal-modal-err" id="tcalErr"></div>' +
            '<div class="tcal-modal-actions">' +
                (editing ? '<button type="button" class="tcal-btn tcal-btn-danger" id="tcalDelete">Delete</button>' : '') +
                '<button type="button" class="tcal-btn" id="tcalCancel" style="margin-left:auto">Cancel</button>' +
                '<button type="button" class="tcal-btn tcal-btn-primary" id="tcalSave">' + (editing ? 'Save' : 'Add note') + '</button>' +
            '</div>' +
        '</div>';
        document.body.appendChild(ov);
        var titleEl = ov.querySelector('#tcalTitle2');
        if (titleEl) titleEl.focus();

        ov.addEventListener('click', function (e) { if (e.target === ov) closeModal(); });
        ov.querySelector('#tcalCancel').addEventListener('click', closeModal);
        ov.querySelector('#tcalSave').addEventListener('click', function () { saveModal(note); });
        var del = ov.querySelector('#tcalDelete');
        if (del) del.addEventListener('click', function () { deleteFromModal(note); });
        titleEl.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); saveModal(note); } });
    }
    function closeModal() { var ov = document.getElementById('tcalModalOv'); if (ov) ov.remove(); }
    function setModalErr(msg) { var e = document.getElementById('tcalErr'); if (e) e.textContent = msg || ''; }

    function saveModal(note) {
        if (state.busy) return;
        var title = (document.getElementById('tcalTitle2').value || '').trim();
        var date = document.getElementById('tcalDate').value;
        var desc = (document.getElementById('tcalDesc').value || '').trim();
        if (!title) { setModalErr('Please enter a note.'); return; }
        if (!date) { setModalErr('Please pick a date.'); return; }
        state.busy = true;
        var saveBtn = document.getElementById('tcalSave');
        if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving…'; }
        setModalErr('');
        var req = note
            ? api('PATCH', '/api/google/calendar/notes/' + encodeURIComponent(note.id), { title: title, date: date, description: desc })
            : api('POST', '/api/google/calendar/notes', { title: title, date: date, description: desc });
        req.then(function (res) {
            state.busy = false;
            if (notConnected(res)) { setModalErr('Google disconnected — reconnect in My Profile.'); restoreSave(note); return; }
            if (!res.ok) { setModalErr((res.body && res.body.error) || 'Could not save.'); restoreSave(note); return; }
            closeModal();
            if (state.rootEl) load(state.rootEl);
            refreshDashboardCard();
        }).catch(function () { state.busy = false; setModalErr('Network error.'); restoreSave(note); });
    }
    function restoreSave(note) { var b = document.getElementById('tcalSave'); if (b) { b.disabled = false; b.textContent = note ? 'Save' : 'Add note'; } }

    function deleteFromModal(note) {
        if (state.busy || !note) return;
        if (!window.confirm('Delete this note?')) return;
        state.busy = true;
        setModalErr('');
        api('DELETE', '/api/google/calendar/notes/' + encodeURIComponent(note.id)).then(function (res) {
            state.busy = false;
            if (!res.ok && !notConnected(res)) { setModalErr((res.body && res.body.error) || 'Could not delete.'); return; }
            closeModal();
            if (state.rootEl) load(state.rootEl);
            refreshDashboardCard();
        }).catch(function () { state.busy = false; setModalErr('Network error.'); });
    }

    // ── dashboard "Calendar" card ────────────────────────────────
    function updateBadge(n) {
        var btn = document.querySelector('.dash-tab[data-dashtab="calendar"]');
        if (!btn) return;
        var badge = btn.querySelector('.dash-tab-badge');
        if (n > 0) {
            if (!badge) { badge = document.createElement('span'); badge.className = 'dash-tab-badge'; btn.appendChild(badge); }
            badge.textContent = n;
        } else if (badge) { badge.remove(); }
    }
    function dashListHtml(evs) {
        var byDate = {};
        evs.forEach(function (e) { if (e.date) (byDate[e.date] = byDate[e.date] || []).push(e); });
        var dates = Object.keys(byDate).sort();
        var html = '';
        dates.forEach(function (ds) {
            var items = byDate[ds].sort(function (a, b) {
                if (a.all_day && !b.all_day) return -1;
                if (!a.all_day && b.all_day) return 1;
                return (a.start_minutes || 0) - (b.start_minutes || 0);
            });
            html += '<div class="tcal-dash-group"><div class="tcal-dash-date">' + esc(prettyDate(ds)) + '</div>';
            items.forEach(function (e) {
                var c = eventColor(e.title);
                var dotColor = c ? c.bg : (e.all_day ? '#2563eb' : '#71717a');
                var dot = '<span class="tcal-dash-dot" style="background:' + dotColor + '"></span>';
                html += '<div class="tcal-dash-item">' +
                    '<span class="t">' + (e.all_day ? 'All day' : esc(fmtTime(e.start_minutes))) + '</span>' +
                    '<span class="n">' + dot + esc(e.title) + '</span></div>';
            });
            html += '</div>';
        });
        return html;
    }
    function fillDashboardUpcoming(panelEl) {
        if (!panelEl) return;
        ensureStyles();
        api('GET', '/api/google/calendar/upcoming?days=' + LOOKAHEAD).then(function (res) {
            if (notConnected(res)) {
                panelEl.innerHTML = '<div class="dash-tab-empty">Connect Google (My Profile → Integrations) to see your calendar here.</div>';
                updateBadge(0); return;
            }
            if (!res.ok) { panelEl.innerHTML = '<div class="dash-tab-empty">Couldn\'t load your calendar.</div>'; return; }
            var evs = ((res.body && res.body.data) || []).filter(function (e) { return e.date; });
            var todayCount = evs.filter(function (e) { return e.date === todayYmd(); }).length;
            updateBadge(todayCount);
            panelEl.innerHTML = evs.length
                ? dashListHtml(evs)
                : '<div class="dash-tab-empty">Nothing on your calendar in the next ' + LOOKAHEAD + ' days.</div>';
        }).catch(function () { panelEl.innerHTML = '<div class="dash-tab-empty">Couldn\'t load your calendar.</div>'; });
    }
    function refreshDashboardCard() {
        var panel = document.querySelector('.dash-tab-panel[data-dashpanel="calendar"]');
        if (panel) fillDashboardUpcoming(panel);
    }

    window.TessaCalendar = { render: render, fillDashboardUpcoming: fillDashboardUpcoming };
})();
