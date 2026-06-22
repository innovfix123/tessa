(function () {
    'use strict';

    var config = window.__PORTAL_CONFIG || {};

    function escapeHtml(v) {
        if (v === null || v === undefined) return '';
        return String(v).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    function getInitials(name) {
        if (!name) return '?';
        var parts = String(name).trim().split(/\s+/);
        if (parts.length >= 2) return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
        return name.substring(0, 2).toUpperCase();
    }

    function showToast(msg, type) {
        if (window.TasksModule && window.TasksModule.showTaskToast) {
            window.TasksModule.showTaskToast(msg, type);
        } else {
            console.warn('[Tessa popovers]', msg);
        }
    }

    function dismissAllPopovers() {
        document.querySelectorAll('.cu-pop').forEach(function (el) { el.remove(); });
    }

    // Position a popover next to an anchor element, keeping it inside the viewport.
    function positionPopover(pop, anchor) {
        document.body.appendChild(pop);
        var ar = anchor.getBoundingClientRect();
        var pw = pop.offsetWidth;
        var ph = pop.offsetHeight;
        var vw = window.innerWidth;
        var vh = window.innerHeight;
        var left = ar.left;
        var top = ar.bottom + 4;
        if (left + pw > vw - 8) left = Math.max(8, vw - pw - 8);
        if (top + ph > vh - 8) top = Math.max(8, ar.top - ph - 4);
        pop.style.left = left + 'px';
        pop.style.top = top + 'px';
    }

    function attachAutoDismiss(pop, anchor) {
        setTimeout(function () {
            function onDocClick(e) {
                if (!pop.contains(e.target) && e.target !== anchor && !anchor.contains(e.target)) {
                    pop.remove();
                    document.removeEventListener('mousedown', onDocClick, true);
                    document.removeEventListener('keydown', onKey, true);
                }
            }
            function onKey(e) {
                if (e.key === 'Escape') {
                    pop.remove();
                    document.removeEventListener('mousedown', onDocClick, true);
                    document.removeEventListener('keydown', onKey, true);
                }
            }
            document.addEventListener('mousedown', onDocClick, true);
            document.addEventListener('keydown', onKey, true);
            pop._cleanup = function () {
                document.removeEventListener('mousedown', onDocClick, true);
                document.removeEventListener('keydown', onKey, true);
            };
        }, 0);
    }

    function persistField(taskId, payload) {
        return fetch('/api/tessa/tasks/' + taskId, {
            method: 'PUT',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(payload)
        }).then(function (r) {
            return r.json().then(function (b) { return { ok: r.ok && b && b.ok, body: b }; });
        });
    }

    // ── Priority ─────────────────────────────────────────────────────
    var PRIORITIES = [
        { value: 'urgent', label: 'Urgent', color: '#ef4444' },
        { value: 'high',   label: 'High',   color: '#f97316' },
        { value: 'medium', label: 'Normal', color: '#3b82f6' },
        { value: 'low',    label: 'Low',    color: '#a1a1aa' }
    ];

    function openPriorityPopover(anchor, task, onChange) {
        dismissAllPopovers();
        var pop = document.createElement('div');
        pop.className = 'cu-pop cu-pop-priority';
        pop.innerHTML = '<div class="cu-pop-title">Priority</div>' +
            PRIORITIES.map(function (p) {
                var active = (task.priority || 'medium') === p.value ? ' cu-pop-row-active' : '';
                return '<button type="button" class="cu-pop-row' + active + '" data-value="' + p.value + '">' +
                    '<span class="cu-flag" style="color:' + p.color + '">⚑</span>' +
                    '<span class="cu-pop-row-label">' + p.label + '</span>' +
                    '</button>';
            }).join('') +
            (task.priority ? '<button type="button" class="cu-pop-row cu-pop-row-clear" data-value="">Clear</button>' : '');
        positionPopover(pop, anchor);
        attachAutoDismiss(pop, anchor);

        pop.querySelectorAll('[data-value]').forEach(function (btn) {
            btn.onclick = function () {
                var val = btn.getAttribute('data-value') || 'medium';
                pop.remove();
                if (pop._cleanup) pop._cleanup();
                persistField(task.id, { priority: val }).then(function (res) {
                    if (res.ok && res.body.task) {
                        if (onChange) onChange(res.body.task);
                    } else {
                        showToast((res.body && res.body.error) || 'Failed to update priority');
                    }
                }).catch(function () { showToast('Failed to update priority'); });
            };
        });
    }

    // ── Assignee ─────────────────────────────────────────────────────
    function openAssigneePopover(anchor, task, onChange) {
        dismissAllPopovers();
        var people = config.MODAL_PEOPLE || [];
        var currentId = task.assigned_to && task.assigned_to.id ? task.assigned_to.id : null;

        var pop = document.createElement('div');
        pop.className = 'cu-pop cu-pop-assignee';
        pop.innerHTML =
            '<div class="cu-pop-title">Assignee</div>' +
            '<div class="cu-pop-search"><input type="text" placeholder="Search people..." class="cu-pop-search-input"></div>' +
            '<div class="cu-pop-list">' +
                people.map(function (p) {
                    var active = currentId === p.id ? ' cu-pop-row-active' : '';
                    return '<button type="button" class="cu-pop-row' + active + '" data-uid="' + p.id + '" data-name="' + escapeHtml(p.name) + '">' +
                        '<span class="cu-avatar">' + getInitials(p.name) + '</span>' +
                        '<span class="cu-pop-row-label">' + escapeHtml(p.name) + '</span>' +
                        '</button>';
                }).join('') +
            '</div>';

        positionPopover(pop, anchor);
        attachAutoDismiss(pop, anchor);

        var search = pop.querySelector('.cu-pop-search-input');
        if (search) {
            search.focus();
            search.oninput = function () {
                var q = search.value.toLowerCase();
                pop.querySelectorAll('.cu-pop-list .cu-pop-row').forEach(function (row) {
                    var name = (row.getAttribute('data-name') || '').toLowerCase();
                    row.style.display = name.indexOf(q) >= 0 ? '' : 'none';
                });
            };
        }

        pop.querySelectorAll('[data-uid]').forEach(function (btn) {
            btn.onclick = function () {
                var uid = parseInt(btn.getAttribute('data-uid'), 10);
                pop.remove();
                if (pop._cleanup) pop._cleanup();
                persistField(task.id, { assigned_to: uid }).then(function (res) {
                    if (res.ok && res.body.task) {
                        if (onChange) onChange(res.body.task);
                    } else {
                        showToast((res.body && res.body.error) || 'Failed to reassign');
                    }
                }).catch(function () { showToast('Failed to reassign'); });
            };
        });
    }

    // ── Date / Mini calendar ──────────────────────────────────────────
    function pad2(n) { return String(n).padStart(2, '0'); }
    function ymd(d) { return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); }
    function startOfDay(d) { var nd = new Date(d); nd.setHours(0, 0, 0, 0); return nd; }
    function addDays(d, n) { var nd = new Date(d); nd.setDate(nd.getDate() + n); return nd; }
    function isSameDay(a, b) { return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate(); }

    var MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var DOW = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

    function buildCalendarHtml(viewMonth, selected, today) {
        var first = new Date(viewMonth.getFullYear(), viewMonth.getMonth(), 1);
        var startWeekday = first.getDay();
        var gridStart = addDays(first, -startWeekday);
        var html = '<div class="cu-cal-head">' +
            '<div class="cu-cal-title">' + escapeHtml(MONTH_NAMES[viewMonth.getMonth()]) + ' ' + viewMonth.getFullYear() + '</div>' +
            '<div class="cu-cal-nav">' +
                '<button type="button" class="cu-cal-today" data-cal-today>Today</button>' +
                '<button type="button" class="cu-cal-arrow" data-cal-prev aria-label="Previous month">‹</button>' +
                '<button type="button" class="cu-cal-arrow" data-cal-next aria-label="Next month">›</button>' +
            '</div>' +
        '</div>';
        html += '<div class="cu-cal-dow">' + DOW.map(function (d) { return '<span>' + d + '</span>'; }).join('') + '</div>';
        html += '<div class="cu-cal-grid">';
        for (var i = 0; i < 42; i++) {
            var d = addDays(gridStart, i);
            var inMonth = d.getMonth() === viewMonth.getMonth();
            var isToday = isSameDay(d, today);
            var isSelected = selected && isSameDay(d, selected);
            var cls = 'cu-cal-cell' +
                (inMonth ? '' : ' cu-cal-cell-out') +
                (isToday ? ' cu-cal-cell-today' : '') +
                (isSelected ? ' cu-cal-cell-selected' : '');
            html += '<button type="button" class="' + cls + '" data-cal-date="' + ymd(d) + '">' + d.getDate() + '</button>';
        }
        html += '</div>';
        return html;
    }

    function openDatePopover(anchor, task, onChange) {
        dismissAllPopovers();
        var today = startOfDay(new Date());
        var current = task.deadline ? new Date(task.deadline) : null;
        var selected = current ? startOfDay(current) : null;
        var viewMonth = new Date((selected || today).getFullYear(), (selected || today).getMonth(), 1);

        function presetDate(name) {
            var d = new Date();
            d.setHours(18, 0, 0, 0);
            var dow = d.getDay();
            switch (name) {
                case 'today': return d;
                case 'later': d.setHours(d.getHours() + 3); return d;
                case 'tomorrow': d.setDate(d.getDate() + 1); d.setHours(18, 0, 0, 0); return d;
                case 'this_weekend': {
                    var toSat = (6 - dow + 7) % 7;
                    if (toSat === 0 && d.getHours() >= 18) toSat = 7;
                    d.setDate(d.getDate() + toSat); d.setHours(18, 0, 0, 0);
                    return d;
                }
                case 'next_week': {
                    var toMon = ((1 - dow + 7) % 7) || 7;
                    d.setDate(d.getDate() + toMon); d.setHours(9, 0, 0, 0);
                    return d;
                }
                case 'next_weekend': {
                    var toSat2 = (6 - dow + 7) % 7;
                    if (toSat2 === 0) toSat2 = 7;
                    d.setDate(d.getDate() + toSat2 + 7); d.setHours(18, 0, 0, 0);
                    return d;
                }
                case '2_weeks': d.setDate(d.getDate() + 14); d.setHours(18, 0, 0, 0); return d;
                case '4_weeks': d.setDate(d.getDate() + 28); d.setHours(18, 0, 0, 0); return d;
            }
            return d;
        }
        function shortDate(d) {
            return d.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: 'numeric', month: 'short' });
        }
        function shortWeekday(d) {
            return d.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', weekday: 'short' });
        }

        var pop = document.createElement('div');
        pop.className = 'cu-pop cu-pop-date';
        function renderShell() {
            var sd = (function () { var t = new Date(); return shortWeekday(t); })();
            var presets = [
                ['today', 'Today', shortWeekday(presetDate('today'))],
                ['later', 'Later', '6:00 pm'],
                ['tomorrow', 'Tomorrow', shortWeekday(presetDate('tomorrow'))],
                ['this_weekend', 'This weekend', shortWeekday(presetDate('this_weekend'))],
                ['next_week', 'Next week', shortWeekday(presetDate('next_week'))],
                ['next_weekend', 'Next weekend', shortDate(presetDate('next_weekend'))],
                ['2_weeks', '2 weeks', shortDate(presetDate('2_weeks'))],
                ['4_weeks', '4 weeks', shortDate(presetDate('4_weeks'))]
            ];
            var startVal = '';
            var endVal = current ? shortDate(current) : '';
            pop.innerHTML =
                '<div class="cu-pop-date-head">' +
                    '<div class="cu-date-input-row">' +
                        '<span class="cu-date-input-icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="12" height="11" rx="2"/><path d="M2 7h12M5 1v4M11 1v4"/></svg></span>' +
                        '<input type="text" class="cu-date-input" placeholder="Start date" id="cuDateStart" value="' + escapeHtml(startVal) + '" disabled>' +
                    '</div>' +
                    '<div class="cu-date-input-row">' +
                        '<span class="cu-date-input-icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="12" height="11" rx="2"/><path d="M2 7h12M5 1v4M11 1v4"/></svg></span>' +
                        '<input type="text" class="cu-date-input" id="cuDateEnd" value="' + escapeHtml(endVal) + '">' +
                        (endVal ? '<button type="button" class="cu-date-clear" id="cuDateClear" aria-label="Clear">×</button>' : '') +
                    '</div>' +
                '</div>' +
                '<div class="cu-pop-date-body">' +
                    '<div class="cu-pop-presets">' +
                        presets.map(function (p) {
                            return '<button type="button" class="cu-preset-row" data-preset="' + p[0] + '">' +
                                '<span class="cu-preset-label">' + escapeHtml(p[1]) + '</span>' +
                                '<span class="cu-preset-hint">' + escapeHtml(p[2]) + '</span>' +
                                '</button>';
                        }).join('') +
                        '<button type="button" class="cu-preset-row cu-preset-recurring" data-preset="recurring">' +
                            '<span class="cu-preset-label">Set Recurring</span>' +
                            '<span class="cu-preset-hint">›</span>' +
                        '</button>' +
                    '</div>' +
                    '<div class="cu-pop-cal" id="cuPopCal">' + buildCalendarHtml(viewMonth, selected, today) + '</div>' +
                '</div>';

            bindBody();
        }

        function bindBody() {
            pop.querySelectorAll('[data-preset]').forEach(function (btn) {
                btn.onclick = function () {
                    var name = btn.getAttribute('data-preset');
                    if (name === 'recurring') {
                        showToast('Open the task and use Convert to Recurring.', 'info');
                        return;
                    }
                    var d = presetDate(name);
                    saveDate(d);
                };
            });
            pop.querySelectorAll('[data-cal-date]').forEach(function (cell) {
                cell.onclick = function () {
                    var ymdStr = cell.getAttribute('data-cal-date');
                    var parts = ymdStr.split('-').map(Number);
                    var d = new Date(parts[0], parts[1] - 1, parts[2], 18, 0, 0);
                    saveDate(d);
                };
            });
            var prev = pop.querySelector('[data-cal-prev]');
            var next = pop.querySelector('[data-cal-next]');
            var todayBtn = pop.querySelector('[data-cal-today]');
            if (prev) prev.onclick = function () { viewMonth.setMonth(viewMonth.getMonth() - 1); refreshCal(); };
            if (next) next.onclick = function () { viewMonth.setMonth(viewMonth.getMonth() + 1); refreshCal(); };
            if (todayBtn) todayBtn.onclick = function () {
                viewMonth = new Date(today.getFullYear(), today.getMonth(), 1);
                refreshCal();
            };
            var clearBtn = pop.querySelector('#cuDateClear');
            if (clearBtn) clearBtn.onclick = function () { saveDate(null); };
        }

        function refreshCal() {
            var calBox = pop.querySelector('#cuPopCal');
            if (calBox) {
                calBox.innerHTML = buildCalendarHtml(viewMonth, selected, today);
                bindBody();
            }
        }

        function saveDate(d) {
            var payload = {};
            if (d) {
                var iso = d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()) +
                    'T' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
                payload.deadline = iso;
            } else {
                payload.deadline = null;
            }
            pop.remove();
            if (pop._cleanup) pop._cleanup();
            persistField(task.id, payload).then(function (res) {
                if (res.ok && res.body.task) {
                    if (onChange) onChange(res.body.task);
                } else {
                    showToast((res.body && res.body.error) || 'Failed to update due date');
                }
            }).catch(function () { showToast('Failed to update due date'); });
        }

        renderShell();
        positionPopover(pop, anchor);
        attachAutoDismiss(pop, anchor);
    }

    // ── Status ───────────────────────────────────────────────────────
    // Grouped like ClickUp: Not started / Active / Closed. Each status has
    // an inline icon (svg) plus a color used to tint the icon and the row chip.
    // Active substates (on_track / at_risk / off_track) sit alongside in_progress.
    function statusIcon(value, color) {
        switch (value) {
            case 'pending': // dashed circle
                return '<svg class="cu-status-icon" viewBox="0 0 16 16"><circle cx="8" cy="8" r="6" fill="none" stroke="' + color + '" stroke-width="1.6" stroke-dasharray="2 2"/></svg>';
            case 'in_progress': // half-filled circle
                return '<svg class="cu-status-icon" viewBox="0 0 16 16"><circle cx="8" cy="8" r="6" fill="none" stroke="' + color + '" stroke-width="1.6"/><path d="M8 2 A6 6 0 0 1 8 14 Z" fill="' + color + '"/></svg>';
            case 'on_track': // filled badge with checkmark
                return '<svg class="cu-status-icon" viewBox="0 0 16 16"><circle cx="8" cy="8" r="6" fill="' + color + '"/><path d="M5 8.2l2 2 4-4" stroke="#fff" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'at_risk': // warning triangle with !
                return '<svg class="cu-status-icon" viewBox="0 0 16 16"><path d="M8 2 L14 13 L2 13 Z" fill="' + color + '"/><path d="M8 6v3" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/><circle cx="8" cy="11.3" r="0.9" fill="#fff"/></svg>';
            case 'off_track': // shield with X
                return '<svg class="cu-status-icon" viewBox="0 0 16 16"><path d="M8 1.5 L13.5 3.5 V8 C13.5 11 11 13.5 8 14.5 C5 13.5 2.5 11 2.5 8 V3.5 Z" fill="' + color + '"/><path d="M6 6l4 4M10 6l-4 4" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/></svg>';
            case 'on_hold': // pause
                return '<svg class="cu-status-icon" viewBox="0 0 16 16"><circle cx="8" cy="8" r="6" fill="' + color + '"/><rect x="5.5" y="5" width="1.8" height="6" fill="#fff"/><rect x="8.7" y="5" width="1.8" height="6" fill="#fff"/></svg>';
            case 'completed': // green check circle
                return '<svg class="cu-status-icon" viewBox="0 0 16 16"><circle cx="8" cy="8" r="6" fill="' + color + '"/><path d="M5 8.2l2 2 4-4" stroke="#fff" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'cancelled': // X circle
                return '<svg class="cu-status-icon" viewBox="0 0 16 16"><circle cx="8" cy="8" r="6" fill="' + color + '"/><path d="M5.5 5.5l5 5M10.5 5.5l-5 5" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/></svg>';
            case 'closed': // dark check circle
                return '<svg class="cu-status-icon" viewBox="0 0 16 16"><circle cx="8" cy="8" r="6" fill="' + color + '"/><path d="M5 8.2l2 2 4-4" stroke="#fff" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            default:
                return '<span class="cu-status-dot" style="background:' + color + '"></span>';
        }
    }

    // Health rows (on_track / at_risk / blocked) live in the same picker for
    // discoverability, but `isHealth: true` makes the click handler update
    // `blocker_status` rather than `status` — so the task stays in its kanban
    // column instead of disappearing into a health-named column.
    var STATUSES = [
        { value: 'pending',     label: 'NOT STARTED', color: '#a1a1aa', group: 'Not started' },
        { value: 'in_progress', label: 'IN PROGRESS', color: '#60a5fa', group: 'Active' },
        { value: 'on_track',    label: 'ON TRACK',    color: '#4ade80', group: 'Active', isHealth: true },
        { value: 'at_risk',     label: 'AT RISK',     color: '#f59e0b', group: 'Active', isHealth: true },
        { value: 'blocked',     label: 'BLOCKED',     color: '#ef4444', group: 'Active', isHealth: true },
        { value: 'on_hold',     label: 'ON HOLD',     color: '#eab308', group: 'Active' },
        // 'closed' is set by the reporter's Verify flow; not user-settable via this popover.
        { value: 'completed',   label: 'COMPLETED',   color: '#22c55e', group: 'Closed' },
        { value: 'cancelled',   label: 'CANCELLED',   color: '#6b7280', group: 'Closed' }
    ];

    function openStatusPopover(anchor, task, onChange) {
        dismissAllPopovers();
        var pop = document.createElement('div');
        pop.className = 'cu-pop cu-pop-status';

        // A row is "active" if it matches either the current status (real
        // workflow state) or the current blocker_status (health). Health rows
        // and status rows live in the same picker but write to different
        // columns.
        var currentStatus = task.status || 'pending';
        var currentHealth = task.blocker_status || '';
        var groups = ['Not started', 'Active', 'Closed'];
        var bodyHtml = '<div class="cu-pop-search"><input type="text" class="cu-pop-search-input" placeholder="Search..."></div>' +
            '<div class="cu-pop-list cu-pop-status-list">' +
            groups.map(function (g) {
                var rows = STATUSES.filter(function (s) { return s.group === g; });
                if (!rows.length) return '';
                return '<div class="cu-pop-section-head">' + g + '</div>' +
                    rows.map(function (s) {
                        var isActive = s.isHealth ? (currentHealth === s.value) : (currentStatus === s.value);
                        var active = isActive ? ' cu-pop-row-active' : '';
                        return '<button type="button" class="cu-pop-row cu-pop-status-row' + active + '" data-value="' + s.value + '" data-label="' + s.label + '" data-is-health="' + (s.isHealth ? '1' : '0') + '">' +
                            statusIcon(s.value, s.color) +
                            '<span class="cu-pop-row-label cu-status-chip" style="background:' + s.color + '1f;color:' + s.color + '">' + s.label + '</span>' +
                            (isActive ? '<span class="cu-status-check">✓</span>' : '') +
                            '</button>';
                    }).join('');
            }).join('') +
            '</div>';
        pop.innerHTML = bodyHtml;

        positionPopover(pop, anchor);
        attachAutoDismiss(pop, anchor);

        var searchInput = pop.querySelector('.cu-pop-search-input');
        if (searchInput) {
            searchInput.focus();
            searchInput.oninput = function () {
                var q = (searchInput.value || '').trim().toLowerCase();
                pop.querySelectorAll('.cu-pop-status-row').forEach(function (row) {
                    var label = (row.getAttribute('data-label') || '').toLowerCase();
                    row.style.display = (!q || label.indexOf(q) !== -1) ? '' : 'none';
                });
                pop.querySelectorAll('.cu-pop-section-head').forEach(function (head) {
                    var next = head.nextElementSibling;
                    var anyVisible = false;
                    while (next && next.classList && next.classList.contains('cu-pop-status-row')) {
                        if (next.style.display !== 'none') { anyVisible = true; break; }
                        next = next.nextElementSibling;
                    }
                    head.style.display = anyVisible ? '' : 'none';
                });
            };
        }

        pop.querySelectorAll('[data-value]').forEach(function (btn) {
            btn.onclick = function () {
                var val = btn.getAttribute('data-value');
                var isHealth = btn.getAttribute('data-is-health') === '1';
                pop.remove();
                if (pop._cleanup) pop._cleanup();
                // Health rows update blocker_status (and never touch status —
                // so the task stays in its kanban column). Real status rows
                // update status and clear the stale health flag.
                var payload = isHealth
                    ? { blocker_status: val }
                    : { status: val, blocker_status: null };
                persistField(task.id, payload).then(function (res) {
                    if (res.ok && res.body.task) {
                        if (onChange) onChange(res.body.task);
                    } else {
                        showToast((res.body && res.body.error) || 'Failed to update');
                    }
                }).catch(function () { showToast('Failed to update'); });
            };
        });
    }

    // ── Public formatters ────────────────────────────────────────────
    function formatRelativeDeadline(iso) {
        if (!iso) return '';
        var d = new Date(iso);
        var today = startOfDay(new Date());
        var dDay = startOfDay(d);
        var diff = Math.round((dDay - today) / 86400000);
        if (diff === 0) return 'Today';
        if (diff === 1) return 'Tomorrow';
        if (diff === -1) return 'Yesterday';
        if (diff > 1 && diff < 7) return d.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', weekday: 'short' });
        return d.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: 'numeric', month: 'short' });
    }

    function formatRelativeCreated(iso) {
        if (!iso) return '';
        var d = new Date(iso);
        var today = startOfDay(new Date());
        var dDay = startOfDay(d);
        var diff = Math.round((today - dDay) / 86400000);
        if (diff === 0) return 'Today';
        if (diff === 1) return 'Yesterday';
        if (diff < 7) return diff + ' days ago';
        return d.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: 'numeric', month: 'short' });
    }

    function priorityLabel(value) {
        var p = PRIORITIES.find(function (x) { return x.value === value; });
        return p ? p.label : 'Normal';
    }
    function priorityColor(value) {
        var p = PRIORITIES.find(function (x) { return x.value === value; });
        return p ? p.color : '#3b82f6';
    }
    function statusLabel(value) {
        var s = STATUSES.find(function (x) { return x.value === value; });
        if (s) return s.label;
        if (value === 'closed') return 'CLOSED';
        return (value || 'pending').toUpperCase();
    }
    function statusColor(value) {
        var s = STATUSES.find(function (x) { return x.value === value; });
        if (s) return s.color;
        if (value === 'closed') return '#22c55e';
        return '#a1a1aa';
    }

    // ── Health (blocker_status) ──────────────────────────────────────
    // Distinct from `status`: this never moves the task between kanban
    // columns. It just paints the health dot and feeds the blocker_status
    // column used by check-ins.
    var HEALTHS = [
        { value: 'on_track', label: 'On Track', color: '#4ade80' },
        { value: 'at_risk',  label: 'At Risk',  color: '#f59e0b' },
        { value: 'blocked',  label: 'Blocked',  color: '#ef4444' }
    ];

    function openHealthPopover(anchor, task, onChange) {
        dismissAllPopovers();
        var pop = document.createElement('div');
        pop.className = 'cu-pop cu-pop-health';
        var current = task.blocker_status || '';
        pop.innerHTML = '<div class="cu-pop-title">Health</div>' +
            HEALTHS.map(function (h) {
                var active = current === h.value ? ' cu-pop-row-active' : '';
                return '<button type="button" class="cu-pop-row' + active + '" data-value="' + h.value + '">' +
                    '<span class="cu-pop-row-dot" style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + h.color + ';margin-right:8px;vertical-align:middle"></span>' +
                    '<span class="cu-pop-row-label">' + h.label + '</span>' +
                    '</button>';
            }).join('') +
            (current ? '<button type="button" class="cu-pop-row cu-pop-row-clear" data-value="">Clear</button>' : '');
        positionPopover(pop, anchor);
        attachAutoDismiss(pop, anchor);

        pop.querySelectorAll('[data-value]').forEach(function (btn) {
            btn.onclick = function () {
                var val = btn.getAttribute('data-value');
                pop.remove();
                if (pop._cleanup) pop._cleanup();
                persistField(task.id, { blocker_status: val || null }).then(function (res) {
                    if (res.ok && res.body.task) {
                        if (onChange) onChange(res.body.task);
                    } else {
                        showToast((res.body && res.body.error) || 'Failed to update health');
                    }
                }).catch(function () { showToast('Failed to update health'); });
            };
        });
    }

    function healthLabel(value) {
        var h = HEALTHS.find(function (x) { return x.value === value; });
        return h ? h.label : 'Health';
    }
    function healthColor(value) {
        var h = HEALTHS.find(function (x) { return x.value === value; });
        return h ? h.color : '#a1a1aa';
    }

    window.TasksPopovers = {
        openPriority: openPriorityPopover,
        openAssignee: openAssigneePopover,
        openDate: openDatePopover,
        openStatus: openStatusPopover,
        openHealth: openHealthPopover,
        healthLabel: healthLabel,
        healthColor: healthColor,
        dismissAll: dismissAllPopovers,
        formatRelativeDeadline: formatRelativeDeadline,
        formatRelativeCreated: formatRelativeCreated,
        priorityLabel: priorityLabel,
        priorityColor: priorityColor,
        statusLabel: statusLabel,
        statusColor: statusColor,
        getInitials: getInitials,
        escapeHtml: escapeHtml,
        PRIORITIES: PRIORITIES,
        STATUSES: STATUSES
    };
})();
