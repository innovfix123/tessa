(function () {
    'use strict';

    var config = window.__ADMIN_CONFIG || {};

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
        if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(opts.body);
        }
        var res = await fetch(url, opts);
        if (!res.ok) {
            var errData = {};
            try { errData = await res.json(); } catch (_) {}
            throw new Error(errData.error || res.statusText || 'Request failed');
        }
        return res.json();
    }

    function startOfWeek(date) {
        var d = new Date(date);
        var day = d.getDay();
        var diff = d.getDate() - day + (day === 0 ? -6 : 1);
        d.setDate(diff);
        return d;
    }

    function formatDateStr(date) {
        var y = date.getFullYear();
        var m = date.getMonth() + 1;
        var d = date.getDate();
        return y + '-' + (m < 10 ? '0' : '') + m + '-' + (d < 10 ? '0' : '') + d;
    }

    function setCurrentDate() {
        var el = document.getElementById('currentDate');
        if (el) {
            el.textContent = new Date().toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
        }
    }

    function getMeetingsDate() {
        var input = document.getElementById('meetingsDate');
        if (!input || !input.value) {
            return formatDateStr(new Date());
        }
        return input.value;
    }

    function setMeetingsDate(value) {
        var input = document.getElementById('meetingsDate');
        if (input) input.value = value;
    }

    function getDailyReportDate() {
        var input = document.getElementById('dailyReportDate');
        if (!input || !input.value) {
            return formatDateStr(new Date());
        }
        return input.value;
    }

    function setDailyReportDate(value) {
        var input = document.getElementById('dailyReportDate');
        if (input) input.value = value;
    }

    function getSigninDate() {
        var input = document.getElementById('signinDate');
        if (!input || !input.value) {
            return formatDateStr(new Date());
        }
        return input.value;
    }

    function setSigninDate(value) {
        var input = document.getElementById('signinDate');
        if (input) input.value = value;
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

    function signinStatusClass(status) {
        if (status === 'signed_off') return 'admin-status-submitted';
        if (status === 'signed_in') return 'admin-status-partial';
        return 'admin-status-missing';
    }

    function signinStatusLabel(status) {
        if (status === 'signed_off') return 'Signed Off';
        if (status === 'signed_in') return 'Signed In';
        return 'Not Signed In';
    }

    function tasksStatusClass(status) {
        if (status === 'clear') return 'admin-status-submitted';
        if (status === 'pending') return 'admin-status-partial';
        return 'admin-status-missing'; // overdue
    }

    function tasksStatusLabel(status) {
        if (status === 'clear') return 'All Clear';
        if (status === 'pending') return 'Pending';
        return 'Overdue';
    }

    function agendaStatusClass(status) {
        if (status === 'filled') return 'admin-status-filled';
        if (status === 'partial') return 'admin-status-partial';
        return 'admin-status-empty';
    }

    function agendaStatusLabel(status) {
        if (status === 'filled') return 'Filled';
        if (status === 'partial') return 'Partial';
        return 'Empty';
    }

    function dailyStatusClass(status) {
        if (status === 'submitted') return 'admin-status-submitted';
        if (status === 'partial') return 'admin-status-partial';
        if (status === 'missing') return 'admin-status-missing';
        return 'admin-status-n/a';
    }

    function dailyStatusLabel(status) {
        if (status === 'submitted') return 'Submitted';
        if (status === 'partial') return 'Partial';
        if (status === 'missing') return 'Missing';
        return 'N/A';
    }

    async function loadMeetingsOverview() {
        var tbody = document.getElementById('meetingsTableBody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="8" class="admin-loading">Loading...</td></tr>';

        try {
            var date = getMeetingsDate();
            var data = await requestJson('/api/admin/meetings-overview?date=' + encodeURIComponent(date));
            if (!data.ok || !data.items) {
                tbody.innerHTML = '<tr><td colspan="8" class="admin-loading">No data</td></tr>';
                return;
            }

            if (data.items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="admin-loading">No meetings for this date</td></tr>';
                return;
            }

            var html = data.items.map(function (m) {
                var rowCls = 'dr-data-row admin-row-' + (m.rowColor || 'yellow');
                var statusCls = agendaStatusClass(m.agendaStatus);
                var statusLabel = agendaStatusLabel(m.agendaStatus);
                var statusExtra = m.agendaTotal > 0 ? ' (' + m.agendaFilled + '/' + m.agendaTotal + ')' : '';
                var attendees = (m.attendees || []).join(', ') || '—';
                var notesCls = m.notesStatus === 'written' ? 'admin-status-filled' : 'admin-status-empty';
                var notesLabel = m.notesStatus === 'written' ? 'Written' : 'Empty';
                return '<tr class="' + rowCls + '">' +
                    '<td class="dr-metric-cell">' + escapeHtml(m.title) + '</td>' +
                    '<td class="dr-cell dr-cell-locked">' + escapeHtml(m.owner) + '</td>' +
                    '<td class="dr-cell dr-cell-locked">' + escapeHtml(m.time) + '</td>' +
                    '<td class="dr-cell dr-cell-locked">' + escapeHtml(m.recurrence) + '</td>' +
                    '<td class="dr-cell dr-cell-locked">' + escapeHtml(m.portal) + '</td>' +
                    '<td class="dr-cell dr-cell-locked">' + escapeHtml(attendees) + '</td>' +
                    '<td class="dr-cell dr-cell-locked"><span class="esc-tag ' + statusCls + '">' + escapeHtml(statusLabel) + statusExtra + '</span></td>' +
                    '<td class="dr-cell dr-cell-locked"><span class="esc-tag ' + notesCls + '">' + escapeHtml(notesLabel) + '</span></td>' +
                    '</tr>';
            }).join('');
            tbody.innerHTML = html;
        } catch (err) {
            console.error('loadMeetingsOverview failed', err);
            tbody.innerHTML = '<tr><td colspan="8" class="admin-loading" style="color:#f87171">Error: ' + escapeHtml(err.message) + '</td></tr>';
        }
    }

    async function loadDailyReportsOverview() {
        var tbody = document.getElementById('dailyTableBody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="5" class="admin-loading">Loading...</td></tr>';

        try {
            var reportDate = getDailyReportDate();
            var data = await requestJson('/api/admin/daily-reports-overview?report_date=' + encodeURIComponent(reportDate));
            if (!data.ok || !data.items) {
                tbody.innerHTML = '<tr><td colspan="5" class="admin-loading">No data</td></tr>';
                return;
            }

            if (data.items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="admin-loading">No users with daily report access</td></tr>';
                return;
            }

            var html = data.items.map(function (u) {
                var statusCls = dailyStatusClass(u.status);
                var statusLabel = dailyStatusLabel(u.status);
                return '<tr class="dr-data-row">' +
                    '<td class="dr-metric-cell">' + escapeHtml(u.userName) + '</td>' +
                    '<td class="dr-cell dr-cell-locked">' + escapeHtml(u.role) + '</td>' +
                    '<td class="dr-cell dr-cell-locked">' + u.filledCount + '</td>' +
                    '<td class="dr-cell dr-cell-locked">' + u.totalFields + '</td>' +
                    '<td class="dr-cell dr-cell-locked"><span class="esc-tag ' + statusCls + '">' + escapeHtml(statusLabel) + '</span></td>' +
                    '</tr>';
            }).join('');
            tbody.innerHTML = html;
        } catch (err) {
            console.error('loadDailyReportsOverview failed', err);
            tbody.innerHTML = '<tr><td colspan="5" class="admin-loading" style="color:#f87171">Error: ' + escapeHtml(err.message) + '</td></tr>';
        }
    }

    async function loadSignInOverview() {
        var tbody = document.getElementById('signinTableBody');
        var summaryEl = document.getElementById('signinSummary');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="5" class="admin-loading">Loading...</td></tr>';
        if (summaryEl) summaryEl.textContent = '';

        try {
            var date = getSigninDate();
            var data = await requestJson('/api/admin/signin-overview?date=' + encodeURIComponent(date));
            if (!data.ok || !data.items) {
                tbody.innerHTML = '<tr><td colspan="5" class="admin-loading">No data</td></tr>';
                return;
            }

            if (summaryEl && data.summary) {
                summaryEl.textContent = data.summary.signedInCount + ' signed in · ' +
                    data.summary.signedOffCount + ' signed off · ' +
                    data.summary.totalCount + ' total';
            }

            if (data.items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="admin-loading">No active users</td></tr>';
                return;
            }

            var html = data.items.map(function (u) {
                var statusCls = signinStatusClass(u.status);
                var statusLabel = signinStatusLabel(u.status);
                var signinCell = u.signedIn
                    ? '<span class="esc-tag admin-status-submitted">' + escapeHtml(formatTimeFromIso(u.signedInAt)) + '</span>'
                    : '<span class="esc-tag admin-status-missing">—</span>';
                var signoffCell = u.signedOff
                    ? '<span class="esc-tag admin-status-submitted">' + escapeHtml(formatTimeFromIso(u.signedOffAt)) + '</span>'
                    : '<span class="esc-tag admin-status-missing">—</span>';
                return '<tr class="dr-data-row">' +
                    '<td class="dr-metric-cell">' + escapeHtml(u.userName) + '</td>' +
                    '<td class="dr-cell dr-cell-locked">' + escapeHtml(u.role) + '</td>' +
                    '<td class="dr-cell dr-cell-locked">' + signinCell + '</td>' +
                    '<td class="dr-cell dr-cell-locked">' + signoffCell + '</td>' +
                    '<td class="dr-cell dr-cell-locked"><span class="esc-tag ' + statusCls + '">' + escapeHtml(statusLabel) + '</span></td>' +
                    '</tr>';
            }).join('');
            tbody.innerHTML = html;
        } catch (err) {
            console.error('loadSignInOverview failed', err);
            tbody.innerHTML = '<tr><td colspan="5" class="admin-loading" style="color:#f87171">Error: ' + escapeHtml(err.message) + '</td></tr>';
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

    function priorityBadge(priority) {
        var p = (priority || '').toLowerCase();
        var cls = 'admin-pri-' + (p || 'none');
        var label = p ? p.charAt(0).toUpperCase() + p.slice(1) : '—';
        return '<span class="admin-pri-badge ' + cls + '">' + escapeHtml(label) + '</span>';
    }

    function buildPendingDetailsHtml(u) {
        var sections = [];

        if (u.tasks.items && u.tasks.items.length) {
            var taskRows = u.tasks.items.map(function (t) {
                var deadline = fmtDateShort(t.deadline);
                var overdueTag = t.isOverdue ? ' <span class="admin-overdue">Overdue' + (t.daysLate ? ' ' + t.daysLate + 'd' : '') + '</span>' : '';
                var progress = (t.progress != null && t.progress !== '') ? ' · ' + t.progress + '%' : '';
                var note = t.statusNote ? ' <em class="admin-detail-note">' + escapeHtml(t.statusNote) + '</em>' : '';
                return '<li class="' + (t.isOverdue ? 'admin-row-overdue' : '') + '">' +
                    priorityBadge(t.priority) + ' ' +
                    '<strong>' + escapeHtml(t.title) + '</strong>' +
                    ' <span class="admin-detail-meta">· ' + escapeHtml(t.status) + ' · due ' + escapeHtml(deadline) + overdueTag + progress + '</span>' +
                    note +
                    '</li>';
            }).join('');
            sections.push(
                '<div class="admin-detail-block"><h4>Tasks (' + u.tasks.open + ' open' +
                (u.tasks.overdue > 0 ? ', <span class="admin-overdue">' + u.tasks.overdue + ' overdue</span>' : '') +
                ')' + (u.tasks.truncated ? ' <span class="admin-detail-meta">showing top ' + u.tasks.items.length + '</span>' : '') + '</h4>' +
                '<ul class="admin-detail-list">' + taskRows + '</ul></div>'
            );
        }

        if (u.tickets.items && u.tickets.items.length) {
            var ticketRows = u.tickets.items.map(function (t) {
                return '<li>' + priorityBadge(t.priority) + ' ' +
                    '<strong>#' + t.id + ' ' + escapeHtml(t.title) + '</strong>' +
                    ' <span class="admin-detail-meta">· ' + escapeHtml(t.status) +
                    (t.category ? ' · ' + escapeHtml(t.category) : '') +
                    ' · opened ' + escapeHtml(fmtDateShort(t.createdAt)) + '</span>' +
                    '</li>';
            }).join('');
            sections.push(
                '<div class="admin-detail-block"><h4>Tickets (' + u.tickets.open + ' open)' +
                (u.tickets.truncated ? ' <span class="admin-detail-meta">showing top ' + u.tickets.items.length + '</span>' : '') + '</h4>' +
                '<ul class="admin-detail-list">' + ticketRows + '</ul></div>'
            );
        }

        if (u.bugs.items && u.bugs.items.length) {
            var bugRows = u.bugs.items.map(function (b) {
                return '<li>' + priorityBadge(b.priority) + ' ' +
                    '<strong>#' + b.id + ' ' + escapeHtml(b.title) + '</strong>' +
                    ' <span class="admin-detail-meta">· ' + escapeHtml(b.status) +
                    (b.severity ? ' · sev ' + escapeHtml(b.severity) : '') +
                    ' · opened ' + escapeHtml(fmtDateShort(b.createdAt)) + '</span>' +
                    '</li>';
            }).join('');
            sections.push(
                '<div class="admin-detail-block"><h4>Bugs (' + u.bugs.open + ' open)' +
                (u.bugs.truncated ? ' <span class="admin-detail-meta">showing top ' + u.bugs.items.length + '</span>' : '') + '</h4>' +
                '<ul class="admin-detail-list">' + bugRows + '</ul></div>'
            );
        }

        if (!sections.length) return '<em class="admin-detail-meta">No pending items.</em>';
        return sections.join('');
    }

    async function loadTasksOverview() {
        var tbody = document.getElementById('tasksTableBody');
        var summaryEl = document.getElementById('tasksSummary');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="6" class="admin-loading">Loading...</td></tr>';
        if (summaryEl) summaryEl.textContent = '';

        try {
            var data = await requestJson('/api/admin/tasks-overview');
            if (!data.ok || !data.items) {
                tbody.innerHTML = '<tr><td colspan="6" class="admin-loading">No data</td></tr>';
                return;
            }

            if (summaryEl && data.summary) {
                summaryEl.textContent =
                    data.summary.tasksOpen + ' tasks open' +
                    (data.summary.tasksOverdue > 0 ? ' (' + data.summary.tasksOverdue + ' overdue)' : '') +
                    ' · ' + data.summary.ticketsOpen + ' tickets open' +
                    ' · ' + data.summary.bugsOpen + ' bugs open' +
                    ' · ' + data.summary.totalUsers + ' users';
            }

            if (data.items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="admin-loading">No active users</td></tr>';
                return;
            }

            var html = data.items.map(function (u, idx) {
                var statusCls = tasksStatusClass(u.status);
                var statusLabel = tasksStatusLabel(u.status);
                var hasDetails = (u.tasks.items && u.tasks.items.length)
                    || (u.tickets.items && u.tickets.items.length)
                    || (u.bugs.items && u.bugs.items.length);
                var tasksCell = u.tasks.open === 0 && u.tasks.overdue === 0
                    ? '<span class="dr-zero">—</span>'
                    : (u.tasks.open + ' / ' +
                        (u.tasks.overdue > 0
                            ? '<span class="admin-overdue">' + u.tasks.overdue + '</span>'
                            : '0'));
                var ticketsCell = u.tickets.open === 0 && u.tickets.resolved === 0
                    ? '<span class="dr-zero">—</span>'
                    : (u.tickets.open + ' / ' + u.tickets.resolved);
                var bugsCell = u.bugs.open === 0 && u.bugs.resolved === 0
                    ? '<span class="dr-zero">—</span>'
                    : (u.bugs.open + ' / ' + u.bugs.resolved);

                var nameCell = hasDetails
                    ? '<span class="admin-expand-toggle" data-row="' + idx + '">▸</span> ' + escapeHtml(u.userName)
                    : escapeHtml(u.userName);

                var mainRow = '<tr class="dr-data-row admin-tasks-row' + (hasDetails ? ' admin-row-expandable' : '') + '" data-row="' + idx + '">' +
                    '<td class="dr-metric-cell">' + nameCell + '</td>' +
                    '<td class="dr-cell dr-cell-locked">' + escapeHtml(u.role) + '</td>' +
                    '<td class="dr-cell dr-cell-locked">' + tasksCell + '</td>' +
                    '<td class="dr-cell dr-cell-locked">' + ticketsCell + '</td>' +
                    '<td class="dr-cell dr-cell-locked">' + bugsCell + '</td>' +
                    '<td class="dr-cell dr-cell-locked"><span class="esc-tag ' + statusCls + '">' + escapeHtml(statusLabel) + '</span></td>' +
                    '</tr>';

                var detailRow = hasDetails
                    ? '<tr class="admin-detail-row hidden" data-detail-row="' + idx + '">' +
                        '<td colspan="6" class="admin-detail-cell">' + buildPendingDetailsHtml(u) + '</td>' +
                      '</tr>'
                    : '';

                return mainRow + detailRow;
            }).join('');
            tbody.innerHTML = html;

            // Wire expand/collapse
            tbody.querySelectorAll('.admin-row-expandable').forEach(function (row) {
                row.addEventListener('click', function () {
                    var idx = row.getAttribute('data-row');
                    var detail = tbody.querySelector('.admin-detail-row[data-detail-row="' + idx + '"]');
                    var toggle = row.querySelector('.admin-expand-toggle');
                    if (!detail) return;
                    var expanded = !detail.classList.contains('hidden');
                    if (expanded) {
                        detail.classList.add('hidden');
                        if (toggle) toggle.textContent = '▸';
                    } else {
                        detail.classList.remove('hidden');
                        if (toggle) toggle.textContent = '▾';
                    }
                });
            });
        } catch (err) {
            console.error('loadTasksOverview failed', err);
            tbody.innerHTML = '<tr><td colspan="6" class="admin-loading" style="color:#f87171">Error: ' + escapeHtml(err.message) + '</td></tr>';
        }
    }

    /* ────────────────────────────────────────────────────────────
     * Dashboard (home) — unified employee view
     * ──────────────────────────────────────────────────────────── */

    var homeState = {
        data: null,
        search: '',
        mode: 'week'
    };

    function getHomeDate() {
        var input = document.getElementById('homeDate');
        if (!input || !input.value) return formatDateStr(new Date());
        return input.value;
    }
    function setHomeDate(value) {
        var input = document.getElementById('homeDate');
        if (input) input.value = value;
    }

    function matchesSearch(emp, q) {
        if (!q) return true;
        var h = q.toLowerCase();
        return (emp.name || '').toLowerCase().indexOf(h) >= 0
            || (emp.role || '').toLowerCase().indexOf(h) >= 0;
    }

    function reportCellHtml(dr) {
        var s = dr.status;
        if (s === 'na') return '<span class="emp-muted">— no KPIs</span>';
        var fraction = dr.filled + '/' + dr.total;
        if (s === 'submitted') return '<span class="emp-dot emp-dot-ok"></span><span class="emp-frac">' + fraction + '</span><span class="emp-tag emp-tag-ok">Submitted</span>';
        if (s === 'partial')   return '<span class="emp-dot emp-dot-warn"></span><span class="emp-frac">' + fraction + '</span><span class="emp-tag emp-tag-warn">Partial</span>';
        return '<span class="emp-dot emp-dot-danger"></span><span class="emp-frac">' + fraction + '</span><span class="emp-tag emp-tag-danger">Missing</span>';
    }

    function signinCellHtml(si) {
        if (si.signedOff) {
            return '<div><span class="emp-dot emp-dot-ok"></span> In ' + formatTimeFromIso(si.signedInAt) + '</div>' +
                '<div class="emp-sub">Off ' + formatTimeFromIso(si.signedOffAt) + '</div>';
        }
        if (si.signedIn) {
            return '<div><span class="emp-dot emp-dot-warn"></span> In ' + formatTimeFromIso(si.signedInAt) + '</div>' +
                '<div class="emp-sub">Still online</div>';
        }
        return '<div><span class="emp-dot emp-dot-danger"></span> Offline</div>' +
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
        // If we got here hasUpdateToday is false; treat a stray 0 as "yesterday" to avoid
        // the nonsensical "0 days ago" phrasing when date-boundary math rounds down.
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

        // Tasks block (always show first if any)
        if (emp.tasks.items && emp.tasks.items.length) {
            var taskLines = emp.tasks.items.map(taskItemHtml).join('');
            var taskHeader = 'Tasks (' + emp.tasks.open + ' open' +
                (emp.tasks.overdue > 0 ? ', <span class="admin-overdue">' + emp.tasks.overdue + ' overdue</span>' : '') + ')';
            var taskFooter = emp.tasks.truncated
                ? '<li class="emp-more">+' + (emp.tasks.open - emp.tasks.items.length) + ' more</li>'
                : '';
            parts.push('<div class="emp-pending-block"><div class="emp-pending-head">' + taskHeader + '</div><ul class="emp-pending-list emp-pending-tasks">' + taskLines + taskFooter + '</ul></div>');
        }

        // Tickets block
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

        // Bugs block
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
            // Nothing pending — but mention resolved totals if any
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
            '</tr>';
    }

    function dayStripHtml(days, field) {
        // field: 'signedIn' | 'signedOff' | 'reportStatus'
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
        if (p.meetingsInWeek > 0)  lines.push('<div class="emp-sub">◷ ' + p.meetingsInWeek + ' meeting occurrence' + (p.meetingsInWeek > 1 ? 's' : '') + ' this week</div>');
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

        var validUntil = '';
        if (k.weekEnd) {
            var we = new Date(k.weekEnd + 'T00:00:00');
            var nextMon = new Date(we);
            nextMon.setDate(nextMon.getDate() + 1);
            while (nextMon.getDay() !== 1) nextMon.setDate(nextMon.getDate() + 1);
            validUntil = '<div class="kra-valid">Until ' + nextMon.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', month: 'short', day: 'numeric' }) + '</div>';
        }

        return '<div class="kra-composite ' + colorCls + '">' + score.toFixed(1) + '<span class="kra-max">/5</span></div>' +
            breakdown + validUntil;
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

    function renderTableHeader() {
        var head = document.querySelector('#homeView .emp-table thead tr');
        if (!head) return;
        if (homeState.mode === 'week') {
            head.innerHTML =
                '<th class="emp-th-name">Employee</th>' +
                '<th class="emp-th-week-signin">Sign-In (This Week)</th>' +
                '<th class="emp-th-week-report">Daily Reports (This Week)</th>' +
                '<th class="emp-th-pending">Pending Work</th>' +
                '<th class="emp-th-week-resolved">Week Activity</th>' +
                '<th class="emp-th-mom">Agenda / Minutes</th>' +
                '<th class="emp-th-kra">KRA (Week)</th>';
        } else {
            head.innerHTML =
                '<th class="emp-th-name">Employee</th>' +
                '<th class="emp-th-status">Sign-In</th>' +
                '<th class="emp-th-report">Daily Report</th>' +
                '<th class="emp-th-pending">Pending Work</th>' +
                '<th class="emp-th-meet">Meetings</th>' +
                '<th class="emp-th-mom">Agenda / Minutes</th>';
        }
    }

    function renderHomeEmployees() {
        var tbody = document.getElementById('homeEmpBody');
        if (!tbody || !homeState.data) return;
        var filtered = homeState.data.employees
            .filter(function (e) { return matchesSearch(e, homeState.search); });

        if (!filtered.length) {
            var colspan = homeState.mode === 'week' ? 7 : 6;
            tbody.innerHTML = '<tr><td colspan="' + colspan + '" class="emp-empty">No employees match the current search.</td></tr>';
            return;
        }

        // Alphabetical by name — easiest to scan when looking for a specific person.
        // (Health is still visible via the left-edge row stripe.)
        filtered.sort(function (a, b) {
            return (a.name || '').localeCompare(b.name || '');
        });

        var rowFn = homeState.mode === 'week' ? employeeRowWeekHtml : employeeRowHtml;
        tbody.innerHTML = filtered.map(rowFn).join('');
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

    async function loadEmployeeDashboard() {
        var tbody = document.getElementById('homeEmpBody');
        var rangeEl = document.getElementById('homePeriodRange');
        if (rangeEl) rangeEl.textContent = '';

        // Header switches between day/week schemas
        var table = document.querySelector('#homeView .emp-table');
        if (table) {
            table.classList.toggle('emp-table-week', homeState.mode === 'week');
        }
        renderTableHeader();

        var colspan = homeState.mode === 'week' ? 7 : 6;
        if (tbody) tbody.innerHTML = '<tr><td colspan="' + colspan + '" class="emp-empty">Loading…</td></tr>';

        try {
            var date = getHomeDate();
            var url = '/api/admin/employee-overview?date=' + encodeURIComponent(date) + '&mode=' + homeState.mode;
            var data = await requestJson(url);
            if (!data.ok) throw new Error('Bad response');
            homeState.data = data;
            if (homeState.mode === 'week' && data.period && rangeEl) {
                rangeEl.textContent = 'Week: ' + fmtDateRange(data.period.start, data.period.end) + ' · ' + data.period.totalDays + ' weekdays';
            }
            renderHomeEmployees();
        } catch (err) {
            console.error('loadEmployeeDashboard failed', err);
            var errColspan = homeState.mode === 'week' ? 7 : 6;
            if (tbody) tbody.innerHTML = '<tr><td colspan="' + errColspan + '" class="emp-empty" style="color:#f87171">Error: ' + escapeHtml(err.message) + '</td></tr>';
        }
    }

    /* ────────────────────────────────────────────────────────────
     * View switcher + nav wiring
     * ──────────────────────────────────────────────────────────── */

    var loadedViews = { home: false, meetings: false, daily: false, signin: false, tasks: false };

    var viewTitles = {
        home:     { title: 'Dashboard',           sub: 'Team overview at a glance' },
        signin:   { title: 'Sign-In / Sign-Off',  sub: 'Presence & end-of-day status per employee' },
        daily:    { title: 'Daily Reports',       sub: 'KPI submission tracking' },
        meetings: { title: 'Meetings',            sub: 'Agendas, notes & attendance for the day' },
        tasks:    { title: 'Tasks & Tickets',     sub: 'Pending work, overdue items, bug progress' }
    };

    function switchView(view) {
        var sections = {
            home:     document.getElementById('homeView'),
            meetings: document.getElementById('meetingsView'),
            daily:    document.getElementById('dailyView'),
            signin:   document.getElementById('signinView'),
            tasks:    document.getElementById('tasksView')
        };
        Object.keys(sections).forEach(function (key) {
            var el = sections[key];
            if (!el) return;
            el.classList.toggle('hidden', key !== view);
        });
        document.querySelectorAll('.admin-sidenav-link').forEach(function (a) {
            a.classList.toggle('active', a.getAttribute('data-view') === view);
        });
        var titleEl = document.getElementById('adminViewTitle');
        var subEl = document.getElementById('adminViewSub');
        var meta = viewTitles[view];
        if (titleEl && meta) titleEl.textContent = meta.title;
        if (subEl && meta) subEl.textContent = meta.sub;

        // Lazy load on first visit
        if (!loadedViews[view]) {
            loadedViews[view] = true;
            if (view === 'home')     loadEmployeeDashboard();
            if (view === 'meetings') loadMeetingsOverview();
            if (view === 'daily')    loadDailyReportsOverview();
            if (view === 'signin')   loadSignInOverview();
            if (view === 'tasks')    loadTasksOverview();
        }
    }

    function updateDateLabel() {
        var lbl = document.getElementById('homeDateLabel');
        if (!lbl) return;
        lbl.textContent = homeState.mode === 'week' ? 'Week of' : 'Date';
    }

    function wireHomeControls() {
        var dateEl = document.getElementById('homeDate');
        var refreshBtn = document.getElementById('homeRefreshBtn');
        var searchEl = document.getElementById('homeSearch');
        var modeToggle = document.getElementById('homeModeToggle');

        if (dateEl) dateEl.addEventListener('change', loadEmployeeDashboard);
        if (refreshBtn) refreshBtn.addEventListener('click', loadEmployeeDashboard);
        if (searchEl) {
            searchEl.addEventListener('input', function () {
                homeState.search = searchEl.value || '';
                renderHomeEmployees();
            });
        }
        if (modeToggle) {
            modeToggle.addEventListener('click', function (e) {
                var btn = e.target.closest('.admin-mode-btn');
                if (!btn) return;
                var mode = btn.getAttribute('data-mode');
                if (!mode || mode === homeState.mode) return;
                homeState.mode = mode;
                modeToggle.querySelectorAll('.admin-mode-btn').forEach(function (b) {
                    b.classList.toggle('active', b.getAttribute('data-mode') === mode);
                });
                updateDateLabel();
                loadEmployeeDashboard();
            });
        }
        updateDateLabel();
    }

    function init() {
        setCurrentDate();
        setHomeDate(formatDateStr(new Date()));
        setMeetingsDate(formatDateStr(new Date()));
        // Daily reports are filed for the previous day's performance — default that picker to yesterday.
        var yesterday = new Date();
        yesterday.setDate(yesterday.getDate() - 1);
        setDailyReportDate(formatDateStr(yesterday));
        setSigninDate(formatDateStr(new Date()));

        wireHomeControls();

        // Non-home tabs: wire controls but don't fetch until visited (lazy)
        var el;
        el = document.getElementById('meetingsRefreshBtn'); if (el) el.addEventListener('click', loadMeetingsOverview);
        el = document.getElementById('meetingsDate'); if (el) el.addEventListener('change', loadMeetingsOverview);
        el = document.getElementById('dailyRefreshBtn'); if (el) el.addEventListener('click', loadDailyReportsOverview);
        el = document.getElementById('dailyReportDate'); if (el) el.addEventListener('change', loadDailyReportsOverview);
        el = document.getElementById('signinRefreshBtn'); if (el) el.addEventListener('click', loadSignInOverview);
        el = document.getElementById('signinDate'); if (el) el.addEventListener('change', loadSignInOverview);
        el = document.getElementById('tasksRefreshBtn'); if (el) el.addEventListener('click', loadTasksOverview);

        document.querySelectorAll('.admin-sidenav-link').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                var view = a.getAttribute('data-view');
                if (view) switchView(view);
            });
        });

        // Load the default (home) view
        switchView('home');
    }

    init();
})();
