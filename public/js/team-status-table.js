/**
 * TeamStatusTable — self-contained widget that renders the unified per-employee
 * Day/Week status table by calling /api/admin/employee-overview.
 *
 * Used by JP's CEO Portal (Team Status tab). The admin console at /admin keeps
 * its own copy of this rendering inside admin.js — see admin.js:811
 * (loadEmployeeDashboard) for the source this widget mirrors.
 *
 * Mount with: TeamStatusTable.mount({ rootEl, initialMode = 'week' });
 *
 * The widget injects its own controls row and table markup into rootEl, so
 * callers only need to provide an empty container.
 */
(function () {
    'use strict';

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    async function requestJson(url, options) {
        var opts = Object.assign({ credentials: 'same-origin' }, options || {});
        opts.headers = Object.assign({
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }, opts.headers || {});
        var res = await fetch(url, opts);
        if (!res.ok) {
            var errData = {};
            try { errData = await res.json(); } catch (_) {}
            throw new Error(errData.error || res.statusText || 'Request failed');
        }
        return res.json();
    }

    function formatDateStr(date) {
        var y = date.getFullYear();
        var m = date.getMonth() + 1;
        var d = date.getDate();
        return y + '-' + (m < 10 ? '0' : '') + m + '-' + (d < 10 ? '0' : '') + d;
    }

    function formatTimeFromIso(iso) {
        if (!iso) return '—';
        try {
            var d = new Date(iso);
            if (isNaN(d.getTime())) return '—';
            return d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true, timeZone: 'Asia/Kolkata' });
        } catch (e) {
            return '—';
        }
    }

    function fmtDateShort(iso) {
        if (!iso) return '—';
        try {
            var d = new Date(iso);
            if (isNaN(d.getTime())) return '—';
            return d.toLocaleDateString('en-IN', { month: 'short', day: 'numeric', timeZone: 'Asia/Kolkata' });
        } catch (e) { return '—'; }
    }

    function fmtDateRange(startIso, endIso) {
        if (!startIso) return '';
        try {
            var s = new Date(startIso + 'T00:00:00');
            var e = endIso ? new Date(endIso + 'T00:00:00') : null;
            var opts = { month: 'short', day: 'numeric', timeZone: 'Asia/Kolkata' };
            if (!e || startIso === endIso) return s.toLocaleDateString('en-IN', opts);
            return s.toLocaleDateString('en-IN', opts) + ' – ' + e.toLocaleDateString('en-IN', opts);
        } catch (_) { return ''; }
    }

    function priorityBadge(priority) {
        var p = (priority || '').toLowerCase();
        var cls = 'admin-pri-' + (p || 'none');
        var label = p ? p.charAt(0).toUpperCase() + p.slice(1) : '—';
        return '<span class="admin-pri-badge ' + cls + '">' + escapeHtml(label) + '</span>';
    }

    /* ── Cell renderers ─────────────────────────────────────────────────── */

    function reportCellHtml(dr) {
        var s = dr.status;
        if (s === 'na') return '<span class="emp-muted">— no KPIs</span>';
        var fraction = dr.filled + '/' + dr.total;
        if (s === 'submitted') return '<span class="emp-dot emp-dot-ok"></span><span class="emp-frac">' + fraction + '</span><span class="emp-tag emp-tag-ok">Submitted</span>';
        if (s === 'partial')   return '<span class="emp-dot emp-dot-warn"></span><span class="emp-frac">' + fraction + '</span><span class="emp-tag emp-tag-warn">Partial</span>';
        return '<span class="emp-dot emp-dot-danger"></span><span class="emp-frac">' + fraction + '</span><span class="emp-tag emp-tag-danger">Missing</span>';
    }

    function signinCellHtml(si) {
        var dotMap = {
            green: 'emp-dot-ok',
            yellow: 'emp-dot-warn',
            red: 'emp-dot-danger',
            gray: 'emp-dot-gray',
            outline: 'emp-dot-outline'
        };
        var dotCls = dotMap[si.indicator] || 'emp-dot-outline';
        var dot = '<span class="emp-dot ' + dotCls + '"></span>';
        var delayedTag = si.delayed ? 'Delayed' : '';
        if (si.signedOff) {
            return '<div>' + dot + ' In ' + formatTimeFromIso(si.signedInAt) + '</div>' +
                '<div class="emp-sub">' + (delayedTag ? delayedTag + ' · ' : '') + 'Off ' + formatTimeFromIso(si.signedOffAt) + '</div>';
        }
        if (si.signedIn) {
            return '<div>' + dot + ' In ' + formatTimeFromIso(si.signedInAt) + '</div>' +
                '<div class="emp-sub">' + (delayedTag || 'Still online') + '</div>';
        }
        return '<div>' + dot + ' Offline</div>' +
            '<div class="emp-sub">—</div>';
    }

    function taskStatusBadge(status) {
        var s = (status || '').toLowerCase();
        var label, tone;
        switch (s) {
            case 'in_progress': label = 'In Progress'; tone = 'emp-status-progress'; break;
            case 'on_track':    label = 'On Track';    tone = 'emp-status-progress'; break;
            case 'at_risk':     label = 'At Risk';     tone = 'emp-status-hold'; break;
            case 'off_track':   label = 'Off Track';   tone = 'emp-status-muted'; break;
            case 'pending':     label = 'Pending';     tone = 'emp-status-pending'; break;
            case 'on_hold':     label = 'On Hold';     tone = 'emp-status-hold'; break;
            case 'completed':   label = 'Completed';   tone = 'emp-status-done'; break;
            case 'cancelled':   label = 'Cancelled';   tone = 'emp-status-muted'; break;
            default:            label = s || '—';      tone = 'emp-status-muted';
        }
        return '<span class="emp-task-status ' + tone + '">' + escapeHtml(label) + '</span>';
    }

    function updateFreshnessHtml(t) {
        if (!t.lastCheckinAt) {
            return '<span class="emp-update-missing">No check-in yet</span>';
        }
        if (t.hasUpdateToday) {
            return '<span class="emp-update-ok">Updated today</span>';
        }
        var days = Math.max(1, Number(t.daysSinceUpdate) || 1);
        var label = days === 1 ? 'Updated yesterday' : 'Last update ' + days + ' days ago';
        var cls = days >= 3 ? 'emp-update-stale' : 'emp-update-warn';
        return '<span class="' + cls + '">' + escapeHtml(label) + '</span>';
    }

    function taskItemHtml(t) {
        var due = t.deadline ? ' · due ' + escapeHtml(fmtDateShort(t.deadline)) : '';
        var overdue = t.isOverdue
            ? ' <span class="admin-overdue">OVERDUE' + (t.daysLate ? ' ' + t.daysLate + 'd' : '') + '</span>'
            : '';
        var noteSnippet = t.statusNote
            ? ' <span class="emp-task-note-inline">“' + escapeHtml(t.statusNote) + '”</span>'
            : '';

        return '<li class="emp-task-item' + (t.isOverdue ? ' emp-overdue-row' : '') + '">' +
            '<div class="emp-task-title-line">' +
                '<span class="emp-item-title">' + escapeHtml(t.title) + '</span>' +
                '<span class="emp-task-meta-text">' + due + '</span>' +
                taskStatusBadge(t.status) +
                overdue +
            '</div>' +
            '<div class="emp-task-update-line">' +
                updateFreshnessHtml(t) +
                noteSnippet +
            '</div>' +
        '</li>';
    }

    function pendingWorkHtml(emp) {
        var parts = [];

        if (emp.tasks.items && emp.tasks.items.length) {
            var taskLines = emp.tasks.items.map(taskItemHtml).join('');
            var taskHeader = 'Tasks (' + emp.tasks.open + ' open' +
                (emp.tasks.overdue > 0 ? ', <span class="admin-overdue">' + emp.tasks.overdue + ' overdue</span>' : '') + ')';
            var taskFooter = emp.tasks.truncated
                ? '<li class="emp-more">+' + (emp.tasks.open - emp.tasks.items.length) + ' more</li>'
                : '';
            parts.push('<div class="emp-pending-block"><div class="emp-pending-head">' + taskHeader + '</div><ul class="emp-pending-list emp-pending-tasks">' + taskLines + taskFooter + '</ul></div>');
        }

        if (emp.tickets.items && emp.tickets.items.length) {
            var ticketLines = emp.tickets.items.map(function (t) {
                return '<li>' + priorityBadge(t.priority) +
                    '<span class="emp-item-title">#' + t.id + ' ' + escapeHtml(t.title) + '</span>' +
                    '<span class="emp-item-meta">' + escapeHtml(t.status) + '</span></li>';
            }).join('');
            var ticketFooter = emp.tickets.truncated
                ? '<li class="emp-more">+' + (emp.tickets.open - emp.tickets.items.length) + ' more</li>' : '';
            parts.push('<div class="emp-pending-block"><div class="emp-pending-head">Tickets (' + emp.tickets.open + ' open · ' + emp.tickets.resolved + ' solved)</div><ul class="emp-pending-list">' + ticketLines + ticketFooter + '</ul></div>');
        }

        if (emp.bugs.items && emp.bugs.items.length) {
            var bugLines = emp.bugs.items.map(function (b) {
                return '<li>' + priorityBadge(b.priority) +
                    '<span class="emp-item-title">#' + b.id + ' ' + escapeHtml(b.title) + '</span>' +
                    '<span class="emp-item-meta">' + escapeHtml(b.status) + '</span></li>';
            }).join('');
            var bugFooter = emp.bugs.truncated
                ? '<li class="emp-more">+' + (emp.bugs.open - emp.bugs.items.length) + ' more</li>' : '';
            parts.push('<div class="emp-pending-block"><div class="emp-pending-head">Bugs (' + emp.bugs.open + ' open · ' + emp.bugs.resolved + ' fixed)</div><ul class="emp-pending-list">' + bugLines + bugFooter + '</ul></div>');
        }

        if (!parts.length) {
            var resolved = [];
            if (emp.tickets.resolved > 0) resolved.push(emp.tickets.resolved + ' ticket' + (emp.tickets.resolved > 1 ? 's' : '') + ' solved');
            if (emp.bugs.resolved > 0) resolved.push(emp.bugs.resolved + ' bug' + (emp.bugs.resolved > 1 ? 's' : '') + ' fixed');
            return resolved.length
                ? '<span class="emp-muted">No pending work · ' + resolved.join(' · ') + '</span>'
                : '<span class="emp-muted">No pending work</span>';
        }

        return parts.join('');
    }

    function momStatusLine(filled, expected, label) {
        if (expected === 0) return '<div class="emp-muted">' + escapeHtml(label) + ': —</div>';
        var tone = filled >= expected ? 'emp-tag-ok'
                 : filled === 0 ? 'emp-tag-danger'
                 : 'emp-tag-warn';
        return '<div class="emp-mom-line">' +
            '<span class="emp-mom-label">' + escapeHtml(label) + '</span>' +
            '<span class="emp-frac">' + filled + '/' + expected + '</span>' +
            '<span class="emp-tag ' + tone + '">' + (filled >= expected ? '✓' : (filled === 0 ? 'missing' : 'partial')) + '</span>' +
            '</div>';
    }

    function momCellHtml(emp) {
        var m = emp.mom || {};
        if ((m.notesExpected || 0) === 0 && (m.agendaExpected || 0) === 0) {
            return '<span class="emp-muted">Not a meeting owner</span>';
        }
        return momStatusLine(m.agendaFilled || 0, m.agendaExpected || 0, 'Agenda') +
               momStatusLine(m.notesFilled || 0, m.notesExpected || 0, 'Minutes');
    }

    function dayStripHtml(days, field) {
        return '<div class="emp-day-strip">' + days.map(function (d) {
            var cls = 'emp-day-dot ';
            var title = d.weekday + ' ' + d.date;
            if (field === 'reportStatus') {
                if (d.reportStatus === 'submitted') cls += 'emp-day-ok';
                else if (d.reportStatus === 'partial') cls += 'emp-day-warn';
                else if (d.reportStatus === 'missing') cls += 'emp-day-danger';
                else cls += 'emp-day-muted';
                title += ' · ' + d.reportStatus;
            } else if (field === 'signedIn') {
                cls += d.signedIn ? 'emp-day-ok' : 'emp-day-danger';
                title += ' · ' + (d.signedIn ? 'In' : 'No sign-in');
            } else if (field === 'signedOff') {
                cls += d.signedOff ? 'emp-day-ok' : 'emp-day-muted';
                title += ' · ' + (d.signedOff ? 'Signed off' : 'No sign-off');
            }
            return '<span class="' + cls + '" title="' + escapeHtml(title) + '">' +
                '<span class="emp-day-label">' + escapeHtml(d.weekday.slice(0, 1)) + '</span>' +
            '</span>';
        }).join('') + '</div>';
    }

    function weeklyReportCellHtml(emp) {
        if (!emp.period) return '<span class="emp-muted">—</span>';
        var p = emp.period;
        var denom = p.totalDays - p.reportNaDays;
        var headline;
        if (denom === 0) {
            headline = '<div class="emp-muted">No KPIs</div>';
        } else if (p.reportSubmittedDays === denom) {
            headline = '<div><span class="emp-frac">' + p.reportSubmittedDays + '/' + denom + '</span><span class="emp-tag emp-tag-ok">Submitted</span></div>';
        } else if (p.reportSubmittedDays > 0) {
            headline = '<div><span class="emp-frac">' + p.reportSubmittedDays + '/' + denom + '</span><span class="emp-tag emp-tag-warn">Partial</span></div>';
        } else {
            headline = '<div><span class="emp-frac">' + p.reportSubmittedDays + '/' + denom + '</span><span class="emp-tag emp-tag-danger">Missing</span></div>';
        }
        var sub2 = p.reportMissingDays > 0
            ? '<div class="emp-sub"><span class="admin-overdue">' + p.reportMissingDays + ' missed</span>' + (p.reportPartialDays > 0 ? ' · ' + p.reportPartialDays + ' partial' : '') + '</div>'
            : (p.reportPartialDays > 0 ? '<div class="emp-sub">' + p.reportPartialDays + ' partial</div>' : '');
        return headline + sub2 + dayStripHtml(p.days, 'reportStatus');
    }

    function weeklySignInCellHtml(emp) {
        if (!emp.period) return '<span class="emp-muted">—</span>';
        var p = emp.period;
        var pct = p.totalDays ? Math.round((p.signInDays / p.totalDays) * 100) : 0;
        var tone = p.signInDays === p.totalDays ? 'emp-tag-ok'
                 : p.signInDays === 0 ? 'emp-tag-danger'
                 : 'emp-tag-warn';
        return '<div><span class="emp-frac">' + p.signInDays + '/' + p.totalDays + '</span><span class="emp-tag ' + tone + '">' + pct + '%</span></div>' +
            (p.signOffDays > 0 ? '<div class="emp-sub">' + p.signOffDays + ' signed off</div>' : '') +
            dayStripHtml(p.days, 'signedIn');
    }

    function weeklyActivityCellHtml(emp) {
        if (!emp.period) return '<span class="emp-muted">—</span>';
        var p = emp.period;
        var lines = [];
        if (p.ticketsResolved > 0) lines.push('<div><span class="emp-tag emp-tag-ok">🎫 ' + p.ticketsResolved + ' ticket' + (p.ticketsResolved > 1 ? 's' : '') + ' solved</span></div>');
        if (p.bugsResolved > 0)    lines.push('<div><span class="emp-tag emp-tag-ok">🐛 ' + p.bugsResolved + ' bug' + (p.bugsResolved > 1 ? 's' : '') + ' fixed</span></div>');
        if (p.meetingsInWeek > 0) {
            lines.push('<div class="emp-sub">◷ ' + p.meetingsInWeek + ' meeting occurrence' + (p.meetingsInWeek > 1 ? 's' : '') + ' this week</div>');
        }
        var tracked = Number(p.meetingsTrackedInWeek || 0);
        if (tracked > 0) {
            var attended = Number(p.meetingsAttendedInWeek || 0);
            var pct = Math.round((attended / tracked) * 100);
            var tone = attended >= tracked ? 'emp-tag-ok'
                     : attended === 0 ? 'emp-tag-danger'
                     : 'emp-tag-warn';
            lines.push('<div class="emp-sub">Attended <span class="emp-frac">' + attended + '/' + tracked + '</span><span class="emp-tag ' + tone + '">' + pct + '%</span></div>');
        }
        return lines.length ? lines.join('') : '<span class="emp-muted">No completed work this week</span>';
    }

    function kraCellHtml(emp) {
        var k = emp.kra;
        if (!k || k.composite === null || k.composite === undefined) {
            return '<span class="emp-muted">No data</span>';
        }
        var score = parseFloat(k.composite);
        var colorCls = score >= 4.0 ? 'kra-score-high' : (score >= 3.0 ? 'kra-score-mid' : 'kra-score-low');

        var breakdown = '';
        if (k.kras) {
            var labels = { discipline: 'Disc', deliverables: 'Deliv', manager_review: 'Mgr' };
            var items = [];
            for (var key in labels) {
                var v = k.kras[key];
                items.push('<span>' + labels[key] + ' ' + (v !== null && v !== undefined ? parseFloat(v).toFixed(1) : '—') + '</span>');
            }
            breakdown = '<div class="kra-breakdown">' + items.join('') + '</div>';
        }

        // Label the actual week the KRA covers (last completed Mon–Sun).
        var weekLabel = '';
        if (k.weekStart && k.weekEnd) {
            var ws = new Date(k.weekStart + 'T00:00:00');
            var we = new Date(k.weekEnd + 'T00:00:00');
            var range = ws.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', month: 'short', day: 'numeric' }) +
                ' – ' + we.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', month: 'short', day: 'numeric' });
            weekLabel = '<div class="kra-valid">' + range + '</div>';
        }

        return '<div class="kra-composite ' + colorCls + '">' + score.toFixed(1) + '<span class="kra-max">/5</span></div>' +
            breakdown + weekLabel;
    }

    function employeeRowHtml(emp) {
        return '<tr class="emp-row emp-row-' + emp.health + '" data-emp-id="' + emp.id + '">' +
            '<td class="emp-cell-name">' +
                '<div class="emp-name">' + escapeHtml(emp.name) + '</div>' +
                '<div class="emp-sub">' + escapeHtml(emp.role || '—') + '</div>' +
            '</td>' +
            '<td class="emp-cell-status">' + signinCellHtml(emp.signIn) + '</td>' +
            '<td class="emp-cell-report">' + reportCellHtml(emp.dailyReport) + '</td>' +
            '<td class="emp-cell-pending">' + pendingWorkHtml(emp) + '</td>' +
            '<td class="emp-cell-meet">' + (emp.meetings.today > 0 ? emp.meetings.today : '<span class="emp-muted">—</span>') + '</td>' +
            '<td class="emp-cell-mom">' + momCellHtml(emp) + '</td>' +
            '<td class="emp-cell-kra">' + kraCellHtml(emp) + '</td>' +
            '</tr>';
    }

    function employeeRowWeekHtml(emp) {
        return '<tr class="emp-row emp-row-' + emp.health + '" data-emp-id="' + emp.id + '">' +
            '<td class="emp-cell-name">' +
                '<div class="emp-name">' + escapeHtml(emp.name) + '</div>' +
                '<div class="emp-sub">' + escapeHtml(emp.role || '—') + '</div>' +
            '</td>' +
            '<td class="emp-cell-week-signin">' + weeklySignInCellHtml(emp) + '</td>' +
            '<td class="emp-cell-week-report">' + weeklyReportCellHtml(emp) + '</td>' +
            '<td class="emp-cell-pending">' + pendingWorkHtml(emp) + '</td>' +
            '<td class="emp-cell-week-resolved">' + weeklyActivityCellHtml(emp) + '</td>' +
            '<td class="emp-cell-mom">' + momCellHtml(emp) + '</td>' +
            '<td class="emp-cell-kra">' + kraCellHtml(emp) + '</td>' +
            '</tr>';
    }

    /* ── Widget instance ────────────────────────────────────────────────── */

    function mount(opts) {
        opts = opts || {};
        var rootEl = opts.rootEl;
        if (!rootEl) {
            console.error('TeamStatusTable.mount: missing rootEl');
            return;
        }

        var instance = {
            data: null,
            search: '',
            mode: opts.initialMode || 'week',
            date: opts.initialDate || formatDateStr(new Date())
        };

        // Inject scaffold markup. IDs are scoped via querySelector inside rootEl
        // so multiple mounts on a page would still work.
        rootEl.innerHTML =
            '<div class="ts-wrap" style="padding:24px;">' +
                '<div class="admin-controls-row">' +
                    '<div class="admin-controls">' +
                        '<div class="admin-mode-toggle ts-mode-toggle">' +
                            '<button type="button" class="admin-mode-btn" data-mode="day">Day</button>' +
                            '<button type="button" class="admin-mode-btn active" data-mode="week">Week</button>' +
                        '</div>' +
                        '<label class="admin-control-label ts-date-label">Week of</label>' +
                        '<input type="date" class="admin-input ts-date-input">' +
                        '<span class="admin-period-range ts-period-range"></span>' +
                        '<button type="button" class="admin-btn ts-refresh-btn">Refresh</button>' +
                    '</div>' +
                    '<div class="admin-controls">' +
                        '<input type="search" class="admin-input admin-search ts-search-input" placeholder="Search employee or role…">' +
                    '</div>' +
                '</div>' +
                '<div class="emp-table-wrap admin-card">' +
                    '<table class="emp-table ts-table">' +
                        '<thead><tr></tr></thead>' +
                        '<tbody class="ts-tbody"><tr><td class="emp-empty">Loading…</td></tr></tbody>' +
                    '</table>' +
                '</div>' +
            '</div>';

        var modeToggle = rootEl.querySelector('.ts-mode-toggle');
        var dateInput  = rootEl.querySelector('.ts-date-input');
        var dateLabel  = rootEl.querySelector('.ts-date-label');
        var rangeEl    = rootEl.querySelector('.ts-period-range');
        var refreshBtn = rootEl.querySelector('.ts-refresh-btn');
        var searchEl   = rootEl.querySelector('.ts-search-input');
        var tableEl    = rootEl.querySelector('.ts-table');
        var headRow    = rootEl.querySelector('.ts-table thead tr');
        var tbody      = rootEl.querySelector('.ts-tbody');

        dateInput.value = instance.date;

        // Sync the visual active state on the mode buttons to instance.mode.
        Array.prototype.forEach.call(modeToggle.querySelectorAll('.admin-mode-btn'), function (b) {
            b.classList.toggle('active', b.getAttribute('data-mode') === instance.mode);
        });

        function matchesSearch(emp, q) {
            if (!q) return true;
            var h = q.toLowerCase();
            return (emp.name || '').toLowerCase().indexOf(h) >= 0
                || (emp.role || '').toLowerCase().indexOf(h) >= 0;
        }

        function renderTableHeader() {
            if (instance.mode === 'week') {
                headRow.innerHTML =
                    '<th class="emp-th-name">Employee</th>' +
                    '<th class="emp-th-week-signin">Sign-In (This Week)</th>' +
                    '<th class="emp-th-week-report">Daily Reports (This Week)</th>' +
                    '<th class="emp-th-pending">Pending Work</th>' +
                    '<th class="emp-th-week-resolved">Week Activity</th>' +
                    '<th class="emp-th-mom">Agenda / Minutes</th>' +
                    '<th class="emp-th-kra">KRA (Last Week)</th>';
            } else {
                headRow.innerHTML =
                    '<th class="emp-th-name">Employee</th>' +
                    '<th class="emp-th-status">Sign-In</th>' +
                    '<th class="emp-th-report">Daily Report</th>' +
                    '<th class="emp-th-pending">Pending Work</th>' +
                    '<th class="emp-th-meet">Meetings</th>' +
                    '<th class="emp-th-mom">Agenda / Minutes</th>' +
                    '<th class="emp-th-kra">KRA (Last Week)</th>';
            }
        }

        function renderRows() {
            if (!instance.data) return;
            var filtered = instance.data.employees
                .filter(function (e) { return matchesSearch(e, instance.search); });

            if (!filtered.length) {
                var colspan = instance.mode === 'week' ? 8 : 8;
                tbody.innerHTML = '<tr><td colspan="' + colspan + '" class="emp-empty">No employees match the current search.</td></tr>';
                return;
            }

            filtered.sort(function (a, b) {
                return (a.name || '').localeCompare(b.name || '');
            });

            var rowFn = instance.mode === 'week' ? employeeRowWeekHtml : employeeRowHtml;
            tbody.innerHTML = filtered.map(rowFn).join('');
        }

        function updateDateLabel() {
            dateLabel.textContent = instance.mode === 'week' ? 'Week of' : 'Date';
        }

        async function load() {
            if (rangeEl) rangeEl.textContent = '';
            tableEl.classList.toggle('emp-table-week', instance.mode === 'week');
            renderTableHeader();

            var colspan = instance.mode === 'week' ? 8 : 8;
            tbody.innerHTML = '<tr><td colspan="' + colspan + '" class="emp-empty">Loading…</td></tr>';

            try {
                instance.date = dateInput.value || formatDateStr(new Date());
                var url = '/api/admin/employee-overview?date=' + encodeURIComponent(instance.date) + '&mode=' + instance.mode;
                var data = await requestJson(url);
                if (!data.ok) throw new Error('Bad response');
                instance.data = data;
                if (instance.mode === 'week' && data.period && rangeEl) {
                    rangeEl.textContent = 'Week: ' + fmtDateRange(data.period.start, data.period.end) + ' · ' + data.period.totalDays + ' weekdays';
                }
                renderRows();
            } catch (err) {
                console.error('TeamStatusTable load failed', err);
                var errColspan = instance.mode === 'week' ? 8 : 8;
                tbody.innerHTML = '<tr><td colspan="' + errColspan + '" class="emp-empty" style="color:#ca6c6c">Error: ' + escapeHtml(err.message) + '</td></tr>';
            }
        }

        // ── Wire controls ───────────────────────────────────────────────
        dateInput.addEventListener('change', load);
        refreshBtn.addEventListener('click', load);
        searchEl.addEventListener('input', function () {
            instance.search = searchEl.value || '';
            renderRows();
        });
        modeToggle.addEventListener('click', function (e) {
            var btn = e.target.closest('.admin-mode-btn');
            if (!btn) return;
            var mode = btn.getAttribute('data-mode');
            if (!mode || mode === instance.mode) return;
            instance.mode = mode;
            Array.prototype.forEach.call(modeToggle.querySelectorAll('.admin-mode-btn'), function (b) {
                b.classList.toggle('active', b.getAttribute('data-mode') === mode);
            });
            updateDateLabel();
            load();
        });

        updateDateLabel();
        load();

        return {
            refresh: load
        };
    }

    window.TeamStatusTable = { mount: mount };
})();
