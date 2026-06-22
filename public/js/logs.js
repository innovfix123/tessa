(function () {
    'use strict';

    var TYPE_LABELS = {
        note: 'Note',
        decision: 'Decision',
        problem: 'Problem',
        idea: 'Idea',
        meeting: 'Meeting'
    };

    var SPARK = '<svg class="lg-pill-spark" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l1.6 5.4L19 9l-5.4 1.6L12 16l-1.6-5.4L5 9l5.4-1.6z"/></svg>';

    var _entries = [];
    var _bound = false;
    var _clockTimer = null;
    var _hintTimer = null;
    var _composerMode = 'log';
    var HINT_DEFAULT = 'Enter to save · Tessa fixes grammar & tags it · Shift+Enter for a new line';
    var LOG_PLACEHOLDER = 'What\'s happening right now?';
    var TASK_PLACEHOLDER = 'Assign a task — who, what, and when (e.g. "Ask Anindita to make the autopay video by Friday")';
    var LEAVE_REASON_PLACEHOLDER = 'Reason (optional)';
    var _leaveTypes = null;
    var _leaveTypesPromise = null;
    var _leaveSelectedSlug = '';
    var _leaveSelectedHourly = false;
    var MODE_CONFIG = {
        task: {
            label: 'Task',
            placeholder: TASK_PLACEHOLDER,
            chipClass: 'is-task',
            iconPaths: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>'
        },
        leave: {
            label: 'Leave',
            placeholder: LEAVE_REASON_PLACEHOLDER,
            chipClass: 'is-leave',
            iconPaths: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="m9 15 2 2 4-4"/>'
        }
    };

    function showHint(msg, isWarn) {
        var el = document.getElementById('lgHint');
        if (!el) return;
        el.textContent = msg;
        el.classList.toggle('lg-hint-warn', !!isWarn);
        if (_hintTimer) clearTimeout(_hintTimer);
        _hintTimer = setTimeout(function () {
            el.textContent = HINT_DEFAULT;
            el.classList.remove('lg-hint-warn');
        }, 3800);
    }

    function showToast(msg, type) {
        var toast = document.createElement('div');
        toast.className = 'task-toast task-toast-' + (type || 'success');
        toast.textContent = msg;
        document.body.appendChild(toast);
        requestAnimationFrame(function () { toast.classList.add('task-toast-show'); });
        setTimeout(function () {
            toast.classList.remove('task-toast-show');
            setTimeout(function () { toast.remove(); }, 300);
        }, 3600);
    }

    function escapeHtml(v) {
        if (window.MeetingModule && MeetingModule.escapeHtml) {
            return MeetingModule.escapeHtml(v);
        }
        var d = document.createElement('div');
        d.textContent = v == null ? '' : String(v);
        return d.innerHTML;
    }

    function requestJson(url, options) {
        if (window.MeetingModule && MeetingModule.requestJson) {
            return MeetingModule.requestJson(url, options);
        }
        return fetch(url, Object.assign({
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }, options || {})).then(function (r) {
            return r.json().then(function (body) {
                if (!r.ok) {
                    var err = new Error(body.message || body.error || 'Request failed');
                    err.status = r.status;
                    throw err;
                }
                return body;
            });
        });
    }

    function pad(n) {
        return n < 10 ? '0' + n : '' + n;
    }

    function formatTimeParts(iso) {
        var d = iso ? new Date(iso) : new Date();
        var parts = d.toLocaleString('en-US', {
            timeZone: 'Asia/Kolkata',
            hour: 'numeric',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        }).match(/^(\d{1,2}):(\d{2}):(\d{2})\s*(AM|PM)$/i);
        if (!parts) {
            return { main: '--:--', secs: '', ap: '' };
        }
        return { main: parts[1] + ':' + parts[2], secs: ':' + parts[3], ap: parts[4] };
    }

    function formatDayLabel(iso) {
        var d = new Date(iso);
        var today = new Date();
        var opts = { timeZone: 'Asia/Kolkata', weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' };
        var label = d.toLocaleDateString('en-US', opts);
        var todayStr = today.toLocaleDateString('en-US', { timeZone: 'Asia/Kolkata', year: 'numeric', month: 'short', day: 'numeric' });
        var entryStr = d.toLocaleDateString('en-US', { timeZone: 'Asia/Kolkata', year: 'numeric', month: 'short', day: 'numeric' });
        if (todayStr === entryStr) {
            return 'Today · ' + label;
        }
        var tomorrowKey = dayKey(new Date(Date.now() + 86400000).toISOString());
        if (dayKey(iso) === tomorrowKey) {
            return 'Tomorrow · ' + label;
        }
        return label;
    }

    function entrySortMs(entry) {
        return entry.created_at ? new Date(entry.created_at).getTime() : 0;
    }

    function isFutureEntry(entry) {
        if (entry.tense === 'overdue') {
            return false;
        }
        if (entry.tense === 'future' || entry.type === 'meeting_upcoming') {
            return true;
        }
        if (entry.type === 'task_due' && entry.tense === 'future') {
            return true;
        }
        return entrySortMs(entry) > Date.now();
    }

    function formatDeadlineLabel(iso) {
        if (!iso) return '';
        return new Date(iso).toLocaleString('en-US', {
            timeZone: 'Asia/Kolkata',
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        });
    }

    function taskDirectionLabel(entry) {
        if (entry.direction === 'out') return 'To ' + (entry.counterpart || '?');
        if (entry.direction === 'in') return 'From ' + (entry.counterpart || '?');
        return 'Self';
    }

    function dayKey(iso) {
        return new Date(iso).toLocaleDateString('en-CA', { timeZone: 'Asia/Kolkata' });
    }

    function setPillHtml(pill, category, classifying) {
        if (!pill) return;
        if (classifying) {
            pill.className = 'lg-type-pill classifying';
            pill.title = '';
            pill.innerHTML = '<span class="lg-mini-spin"></span>Reading…';
            return;
        }
        pill.className = 'lg-type-pill lg-type-pill-readonly';
        pill.title = 'Tagged by Tessa';
        pill.innerHTML = SPARK + '<span class="lg-pill-label">' + escapeHtml(TYPE_LABELS[category] || 'Note') + '</span>';
    }

    function groupSlug(group) {
        return String(group || 'activity').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'activity';
    }

    function buildNowDivider() {
        var t = formatTimeParts(new Date().toISOString());
        var div = document.createElement('div');
        div.className = 'lg-now-divider';
        div.setAttribute('role', 'separator');
        div.innerHTML =
            '<div class="lg-entry-time" aria-hidden="true"></div>' +
            '<div class="lg-entry-track lg-now-track">' +
                '<span class="lg-entry-dot lg-now-dot"></span>' +
                '<span class="lg-now-pill">NOW</span>' +
                '<span class="lg-now-time">' + escapeHtml(t.main + t.secs + ' ' + t.ap) + '</span>' +
            '</div>';
        return div;
    }

    function updateNowDividerTime() {
        var el = document.querySelector('.lg-now-time');
        if (!el) return;
        var t = formatTimeParts(new Date().toISOString());
        el.textContent = t.main + t.secs + ' ' + t.ap;
    }

    function scrollTimelineToNow() {
        var timeline = document.getElementById('lgTimeline');
        var inner = document.getElementById('lgTimelineInner');
        if (!timeline || !inner) return;
        var nowEl = inner.querySelector('.lg-now-divider');
        if (nowEl) {
            timeline.scrollTop = Math.max(0, nowEl.offsetTop - timeline.clientHeight + nowEl.offsetHeight + 24);
        } else {
            timeline.scrollTop = timeline.scrollHeight;
        }
    }

    function buildEntryRow(entry, opts) {
        opts = opts || {};
        var id = entry.id;
        if (entry.type === 'task_due') {
            var tDue = formatTimeParts(entry.deadline || entry.created_at);
            var isOverdue = entry.tense === 'overdue';
            var pri = (entry.priority || 'medium').toLowerCase();
            var taskEl = document.createElement('div');
            taskEl.className = 'lg-entry lg-activity lg-task' + (isOverdue ? ' lg-task-overdue' : ' lg-upcoming');
            taskEl.setAttribute('data-type', 'task_due');
            taskEl.setAttribute('data-group', 'task');
            taskEl.setAttribute('data-id', id);
            taskEl.innerHTML =
                '<div class="lg-entry-time">' + escapeHtml(tDue.main) + '<span class="lg-secs">' + escapeHtml(tDue.secs) + '</span><br>' + escapeHtml(tDue.ap) + '</div>' +
                '<div class="lg-entry-track"><span class="lg-entry-dot"></span>' +
                    '<div class="lg-entry-card">' +
                        '<div class="lg-entry-head">' +
                            '<span class="lg-activity-group">Task</span>' +
                            (isOverdue ? '<span class="lg-overdue-pill">Overdue</span>' : '') +
                            '<span class="lg-priority-pill lg-priority-' + escapeHtml(pri) + '">' + escapeHtml(pri) + '</span>' +
                        '</div>' +
                        '<div class="lg-entry-text">' + escapeHtml(entry.title || entry.content || '') + '</div>' +
                        '<div class="lg-task-meta">' +
                            '<span>' + escapeHtml(taskDirectionLabel(entry)) + '</span>' +
                            '<span class="lg-task-meta-sep">·</span>' +
                            '<span>Due ' + escapeHtml(formatDeadlineLabel(entry.deadline)) + '</span>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            return taskEl;
        }
        if (entry.type === 'meeting_upcoming') {
            var meetTime = entry.meeting_time || '';
            var tUp;
            if (meetTime) {
                var tm = meetTime.match(/^(\d{1,2}:\d{2})(?::\d{2})?\s*(AM|PM)?$/i);
                tUp = tm
                    ? { main: tm[1], secs: '', ap: tm[2] || '' }
                    : { main: meetTime, secs: '', ap: '' };
            } else {
                tUp = formatTimeParts(entry.created_at);
            }
            var upEl = document.createElement('div');
            upEl.className = 'lg-entry lg-activity lg-upcoming';
            upEl.setAttribute('data-type', 'meeting_upcoming');
            upEl.setAttribute('data-group', 'meeting');
            upEl.setAttribute('data-id', id);
            upEl.innerHTML =
                '<div class="lg-entry-time">' + escapeHtml(tUp.main) + '<span class="lg-secs">' + escapeHtml(tUp.secs) + '</span><br>' + escapeHtml(tUp.ap) + '</div>' +
                '<div class="lg-entry-track"><span class="lg-entry-dot"></span>' +
                    '<div class="lg-entry-card">' +
                        '<div class="lg-entry-head">' +
                            '<span class="lg-activity-group">Meeting</span>' +
                            '<span class="lg-upcoming-time">' + escapeHtml(meetTime) + '</span>' +
                        '</div>' +
                        '<div class="lg-entry-text">' + escapeHtml(entry.title || entry.content || '') + '</div>' +
                    '</div>' +
                '</div>';
            return upEl;
        }
        if (entry.type === 'activity') {
            var tAct = formatTimeParts(entry.created_at);
            var group = entry.group || 'Activity';
            var gSlug = groupSlug(group);
            var actEl = document.createElement('div');
            actEl.className = 'lg-entry lg-activity';
            actEl.setAttribute('data-type', 'activity');
            actEl.setAttribute('data-group', gSlug);
            actEl.setAttribute('data-id', id);
            actEl.innerHTML =
                '<div class="lg-entry-time">' + escapeHtml(tAct.main) + '<span class="lg-secs">' + escapeHtml(tAct.secs) + '</span><br>' + escapeHtml(tAct.ap) + '</div>' +
                '<div class="lg-entry-track"><span class="lg-entry-dot"></span>' +
                    '<div class="lg-entry-card">' +
                        '<div class="lg-entry-head">' +
                            '<span class="lg-activity-group">' + escapeHtml(group) + '</span>' +
                        '</div>' +
                        '<div class="lg-entry-text">' + escapeHtml(entry.content || '') + '</div>' +
                    '</div>' +
                '</div>';
            return actEl;
        }
        var category = entry.category || 'note';
        var pending = opts.pending || String(id).indexOf('temp_') === 0;
        var t = formatTimeParts(entry.created_at);
        var sourceBadge = '';
        if (entry.source === 'slack') {
            if (entry.slack_permalink) {
                sourceBadge = '<a class="lg-source-badge" href="' + escapeHtml(entry.slack_permalink) + '" target="_blank" rel="noopener" title="Open in Slack">Slack</a>';
            } else {
                sourceBadge = '<span class="lg-source-badge">Slack</span>';
            }
        }
        var el = document.createElement('div');
        el.className = 'lg-entry';
        el.setAttribute('data-type', category);
        el.setAttribute('data-id', id);
        el.innerHTML =
            '<div class="lg-entry-time">' + escapeHtml(t.main) + '<span class="lg-secs">' + escapeHtml(t.secs) + '</span><br>' + escapeHtml(t.ap) + '</div>' +
            '<div class="lg-entry-track"><span class="lg-entry-dot"></span>' +
                '<div class="lg-entry-card">' +
                    '<div class="lg-entry-head">' +
                        '<span class="lg-type-pill"></span>' +
                        sourceBadge +
                    '</div>' +
                    '<div class="lg-entry-text">' + escapeHtml(entry.content) + '</div>' +
                '</div>' +
            '</div>';
        var pill = el.querySelector('.lg-type-pill');
        setPillHtml(pill, category, pending);
        return el;
    }

    function renderTimeline() {
        var inner = document.getElementById('lgTimelineInner');
        if (!inner) return;
        inner.innerHTML = '';
        if (!_entries.length) {
            inner.innerHTML = '<div class="lg-empty">Nothing logged yet. Type below to record your first entry.</div>';
            scrollTimelineToNow();
            return;
        }
        var lastDay = '';
        var nowInserted = false;
        _entries.forEach(function (entry) {
            if (!nowInserted && isFutureEntry(entry)) {
                inner.appendChild(buildNowDivider());
                nowInserted = true;
            }
            var dk = dayKey(entry.created_at);
            if (dk !== lastDay) {
                lastDay = dk;
                var div = document.createElement('div');
                div.className = 'lg-day-divider';
                div.innerHTML =
                    '<div class="lg-entry-time" aria-hidden="true"></div>' +
                    '<div class="lg-day-divider-body"><span>' + escapeHtml(formatDayLabel(entry.created_at)) + '</span></div>';
                inner.appendChild(div);
            }
            inner.appendChild(buildEntryRow(entry));
        });
        if (!nowInserted) {
            inner.appendChild(buildNowDivider());
        }
        scrollTimelineToNow();
    }

    function loadEntries() {
        var inner = document.getElementById('lgTimelineInner');
        if (inner) inner.innerHTML = '<div class="lg-loading">Loading…</div>';
        return requestJson('/api/logs').then(function (data) {
            _entries = (data.entries || []).slice().sort(function (a, b) {
                return entrySortMs(a) - entrySortMs(b);
            });
            renderTimeline();
        }).catch(function () {
            if (inner) inner.innerHTML = '<div class="lg-empty">Failed to load entries.</div>';
        });
    }

    function sendEntry() {
        var input = document.getElementById('lgInput');
        var sendBtn = document.getElementById('lgSendBtn');
        if (!input || !sendBtn) return;
        if (_composerMode === 'task') {
            var taskText = (input.value || '').trim();
            if (!taskText) return;
            assignTask(taskText);
            return;
        }
        if (_composerMode === 'leave') {
            submitLeave();
            return;
        }
        var text = (input.value || '').trim();
        if (!text) return;
        input.value = '';
        autoResizeInput(input);
        refreshSendBtn();

        var tempId = 'temp_' + Date.now();
        _entries.push({
            id: tempId,
            type: 'entry',
            content: text,
            category: 'note',
            source: 'text',
            created_at: new Date().toISOString()
        });
        renderTimeline();

        sendBtn.disabled = true;
        requestJson('/api/logs', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ content: text, source: 'text' })
        }).then(function (data) {
            _entries = _entries.filter(function (e) { return String(e.id) !== tempId; });
            if (data && data.skipped) {
                showHint('Skipped — that looked like a greeting, so nothing was logged.', true);
            } else if (data && data.entry) {
                var saved = data.entry;
                saved.type = 'entry';
                _entries.push(saved);
            }
            renderTimeline();
        }).catch(function () {
            _entries = _entries.filter(function (e) { return String(e.id) !== tempId; });
            renderTimeline();
            showHint('Could not save. Please try again.', true);
        }).finally(function () {
            sendBtn.disabled = false;
            refreshSendBtn();
        });
    }

    function autoResizeInput(el) {
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 160) + 'px';
    }

    function todayIso() {
        return new Date().toISOString().split('T')[0];
    }

    function resetLeaveDates() {
        var t = todayIso();
        var start = document.getElementById('lgLeaveStart');
        var end = document.getElementById('lgLeaveEnd');
        var perm = document.getElementById('lgLeavePermDate');
        var from = document.getElementById('lgLeaveFrom');
        var to = document.getElementById('lgLeaveTo');
        if (start) start.value = t;
        if (end) end.value = t;
        if (perm) perm.value = t;
        if (from) from.value = '10:00';
        if (to) to.value = '12:00';
    }

    function closeLeaveTypeMenu() {
        var menu = document.getElementById('lgLeaveTypeMenu');
        var trigger = document.getElementById('lgLeaveTypeTrigger');
        if (menu) menu.hidden = true;
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
    }

    function toggleLeaveTypeMenu() {
        var menu = document.getElementById('lgLeaveTypeMenu');
        var trigger = document.getElementById('lgLeaveTypeTrigger');
        if (!menu || !trigger) return;
        var open = menu.hidden;
        if (open) {
            menu.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
        } else {
            closeLeaveTypeMenu();
        }
    }

    function selectLeaveType(slug, name, isHourly) {
        _leaveSelectedSlug = slug || '';
        _leaveSelectedHourly = !!isHourly;
        var hidden = document.getElementById('lgLeaveType');
        var label = document.getElementById('lgLeaveTypeLabel');
        if (hidden) hidden.value = _leaveSelectedSlug;
        if (label) label.textContent = name || 'Select type';
        var menu = document.getElementById('lgLeaveTypeMenu');
        if (menu) {
            menu.querySelectorAll('.lg-leave-type-item').forEach(function (btn) {
                btn.classList.toggle('is-selected', btn.getAttribute('data-slug') === _leaveSelectedSlug);
            });
        }
        closeLeaveTypeMenu();
        onLeaveTypeChange();
    }

    function populateLeaveTypeMenu(types) {
        var menu = document.getElementById('lgLeaveTypeMenu');
        var label = document.getElementById('lgLeaveTypeLabel');
        var hidden = document.getElementById('lgLeaveType');
        if (!menu) return;
        if (!types.length) {
            menu.innerHTML = '';
            _leaveSelectedSlug = '';
            _leaveSelectedHourly = false;
            if (hidden) hidden.value = '';
            if (label) label.textContent = 'No types';
            return;
        }
        menu.innerHTML = types.map(function (t) {
            var tag = !t.requires_approval ? ' · auto' : '';
            return '<button type="button" class="lg-leave-type-item" role="option" data-slug="' +
                escapeHtml(t.slug) + '" data-hourly="' + (t.is_hourly ? '1' : '0') + '" data-name="' +
                escapeHtml(t.name) + '">' + escapeHtml(t.name + tag) + '</button>';
        }).join('');
        var first = types[0];
        selectLeaveType(first.slug, first.name, first.is_hourly);
    }

    function onLeaveTypeChange() {
        var dateFields = document.getElementById('lgLeaveDateFields');
        var hourlyFields = document.getElementById('lgLeaveHourlyFields');
        if (!dateFields || !hourlyFields) return;
        dateFields.hidden = _leaveSelectedHourly;
        hourlyFields.hidden = !_leaveSelectedHourly;
        refreshSendBtn();
    }

    function isLeaveFormReady() {
        if (!_leaveSelectedSlug) return false;
        var isHourly = _leaveSelectedHourly;
        if (isHourly) {
            var perm = document.getElementById('lgLeavePermDate');
            var from = document.getElementById('lgLeaveFrom');
            var to = document.getElementById('lgLeaveTo');
            return !!(perm && perm.value && from && from.value && to && to.value);
        }
        var start = document.getElementById('lgLeaveStart');
        return !!(start && start.value);
    }

    function loadLeaveTypes() {
        var label = document.getElementById('lgLeaveTypeLabel');
        if (_leaveTypes) {
            populateLeaveTypeMenu(_leaveTypes);
            resetLeaveDates();
            refreshSendBtn();
            return Promise.resolve(_leaveTypes);
        }
        if (label) label.textContent = 'Loading…';
        if (!_leaveTypesPromise) {
            _leaveTypesPromise = requestJson('/api/leave/types').then(function (data) {
                _leaveTypes = (data.leave_types || []).filter(function (t) {
                    return t.slug !== 'compensate';
                });
                return _leaveTypes;
            }).catch(function () {
                _leaveTypesPromise = null;
                return [];
            });
        }
        return _leaveTypesPromise.then(function (types) {
            populateLeaveTypeMenu(types);
            resetLeaveDates();
            refreshSendBtn();
            if (!types.length) {
                showHint('Could not load leave types.', true);
            }
            return types;
        });
    }

    function refreshSendBtn() {
        var input = document.getElementById('lgInput');
        var sendBtn = document.getElementById('lgSendBtn');
        if (!sendBtn) return;
        var ready;
        if (_composerMode === 'leave') {
            ready = isLeaveFormReady();
        } else {
            ready = !!(input && (input.value || '').trim());
        }
        sendBtn.classList.toggle('ready', ready);
        sendBtn.disabled = !ready;
    }

    function tickClock() {
        var el = document.getElementById('lgClock');
        if (el) {
            var t = formatTimeParts(new Date().toISOString());
            el.textContent = t.main + t.secs + ' ' + t.ap;
        }
        updateNowDividerTime();
    }

    function applySlackUi(status) {
        var btn = document.getElementById('lgSlackToggle');
        var label = document.getElementById('lgSlackState');
        if (!btn) return;
        var connected = !!(status && status.connected);
        btn.setAttribute('data-connected', connected ? '1' : '0');
        // Connected = syncs silently, nothing shown. Only surface a button when
        // there's an action to take (connect Slack).
        if (connected) {
            btn.style.display = 'none';
            return;
        }
        btn.style.display = '';
        btn.classList.add('is-connect');
        btn.classList.remove('is-on');
        if (label) label.textContent = 'Connect Slack';
        btn.title = 'Connect Slack to sync your messages into Logs';
    }

    function loadSlackStatus() {
        requestJson('/api/logs/slack-status')
            .then(applySlackUi)
            .catch(function () { /* leave default */ });
    }

    function onSlackButton() {
        var btn = document.getElementById('lgSlackToggle');
        if (!btn) return;
        // Connected = always syncing; nothing to toggle.
        if (btn.getAttribute('data-connected') === '1') {
            showHint('Your Slack messages sync into Logs automatically.', false);
            return;
        }
        // Not connected — kick off the Slack OAuth connect flow.
        requestJson('/api/slack/connect').then(function (d) {
            var url = d && (d.url || (d.data && d.data.url));
            if (url) {
                window.location.href = url;
            } else {
                showHint('Could not start Slack connect. Open the Slack tab to connect.', true);
            }
        }).catch(function () {
            showHint('Could not start Slack connect. Open the Slack tab to connect.', true);
        });
    }

    function ensureShell() {
        var root = document.getElementById('logsView');
        if (!root || root.getAttribute('data-lg-built') === '2') return root;
        root.setAttribute('data-lg-built', '2');
        root.innerHTML =
            '<div class="lg-col">' +
                '<div class="lg-header">' +
                    '<div><h1 class="lg-title">Logs</h1><p class="lg-sub">Your day, recorded — time-stamped as you go.</p></div>' +
                    '<div class="lg-header-right">' +
                        '<button type="button" class="lg-slack-toggle is-connect" id="lgSlackToggle" data-connected="0" style="display:none" title="Connect Slack to sync your messages into Logs">' +
                            '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M9 12a2 2 0 1 1-2 2v-2h2zm1 0a2 2 0 0 1 4 0v5a2 2 0 1 1-4 0v-5zm2-7a2 2 0 1 1 2-2h-2v2zm0 1a2 2 0 0 1 0 4H7a2 2 0 1 1 0-4h5zm7 2a2 2 0 1 1 2 2h-2v-2zm-1 0a2 2 0 0 1-4 0V3a2 2 0 1 1 4 0v5zm-2 7a2 2 0 1 1-2 2h2v-2zm0-1a2 2 0 0 1 0-4h5a2 2 0 1 1 0 4h-5z"/></svg>' +
                            '<span id="lgSlackState">Connect Slack</span>' +
                        '</button>' +
                        '<div class="lg-live-clock"><span class="lg-live-dot"></span><span id="lgClock">--:--</span></div>' +
                    '</div>' +
                '</div>' +
                '<div class="lg-timeline" id="lgTimeline"><div class="lg-timeline-inner" id="lgTimelineInner"><div class="lg-loading">Loading…</div></div></div>' +
                '<div class="lg-composer-wrap">' +
                    '<div class="lg-composer-inner lg-composer-inner-relative">' +
                        '<div class="lg-plus-menu" id="lgPlusMenu" hidden role="menu" aria-label="Composer actions">' +
                            '<button type="button" class="lg-plus-menu-item" role="menuitem" data-action="task">' +
                                '<svg class="lg-plus-menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>' +
                                '<span>Task</span>' +
                            '</button>' +
                            '<button type="button" class="lg-plus-menu-item" role="menuitem" data-action="leave">' +
                                '<svg class="lg-plus-menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="m9 15 2 2 4-4"/></svg>' +
                                '<span>Leave</span>' +
                            '</button>' +
                            '<button type="button" class="lg-plus-menu-item is-disabled" role="menuitem" data-action="meetings" disabled>' +
                                '<svg class="lg-plus-menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>' +
                                '<span>Meetings</span><span class="lg-plus-soon">Soon</span>' +
                            '</button>' +
                            '<button type="button" class="lg-plus-menu-item is-disabled" role="menuitem" data-action="slack" disabled>' +
                                '<svg class="lg-plus-menu-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9 12a2 2 0 1 1-2 2v-2h2zm1 0a2 2 0 0 1 4 0v5a2 2 0 1 1-4 0v-5zm2-7a2 2 0 1 1 2-2h-2v2zm0 1a2 2 0 0 1 0 4H7a2 2 0 1 1 0-4h5zm7 2a2 2 0 1 1 2 2h-2v-2zm-1 0a2 2 0 0 1-4 0V3a2 2 0 1 1 4 0v5zm-2 7a2 2 0 1 1-2 2h2v-2zm0-1a2 2 0 0 1 0-4h5a2 2 0 1 1 0 4h-5z"/></svg>' +
                                '<span>Slack</span><span class="lg-plus-soon">Soon</span>' +
                            '</button>' +
                            '<button type="button" class="lg-plus-menu-item is-disabled" role="menuitem" data-action="gmail" disabled>' +
                                '<svg class="lg-plus-menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="m22 6-10 7L2 6"/></svg>' +
                                '<span>Gmail draft</span><span class="lg-plus-soon">Soon</span>' +
                            '</button>' +
                        '</div>' +
                        '<div class="lg-composer" id="lgComposer">' +
                            '<button type="button" class="lg-task-btn" id="lgTaskBtn" title="More actions" aria-label="More actions" aria-haspopup="menu" aria-expanded="false">' +
                                '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>' +
                            '</button>' +
                            '<span class="lg-mode-chip" id="lgModeChip" hidden>' +
                                '<svg class="lg-mode-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>' +
                                '<span class="lg-mode-chip-label">Task</span>' +
                                '<button type="button" class="lg-mode-chip-x" id="lgModeChipX" aria-label="Exit mode">&times;</button>' +
                            '</span>' +
                            '<textarea id="lgInput" rows="1" placeholder="What\'s happening right now?"></textarea>' +
                            '<button type="button" class="lg-send-btn" id="lgSendBtn" title="Save entry" disabled>' +
                                '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>' +
                            '</button>' +
                        '</div>' +
                        '<div class="lg-leave-controls" id="lgLeaveControls" hidden>' +
                            '<div class="lg-leave-pill lg-leave-type-pill">' +
                                '<svg class="lg-leave-pill-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>' +
                                '<button type="button" class="lg-leave-type-trigger" id="lgLeaveTypeTrigger" aria-haspopup="listbox" aria-expanded="false">' +
                                    '<span id="lgLeaveTypeLabel">Select type</span>' +
                                '</button>' +
                                '<svg class="lg-leave-pill-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>' +
                                '<input type="hidden" id="lgLeaveType" value="">' +
                                '<div class="lg-leave-type-menu" id="lgLeaveTypeMenu" hidden role="listbox" aria-label="Leave type"></div>' +
                            '</div>' +
                            '<div class="lg-leave-dates" id="lgLeaveDateFields">' +
                                '<div class="lg-leave-pill">' +
                                    '<svg class="lg-leave-pill-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>' +
                                    '<span class="lg-leave-pill-text">Start</span>' +
                                    '<input type="date" id="lgLeaveStart" class="lg-leave-pill-date" aria-label="Start date">' +
                                '</div>' +
                                '<div class="lg-leave-pill">' +
                                    '<svg class="lg-leave-pill-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>' +
                                    '<span class="lg-leave-pill-text">End</span>' +
                                    '<input type="date" id="lgLeaveEnd" class="lg-leave-pill-date" aria-label="End date">' +
                                '</div>' +
                            '</div>' +
                            '<div class="lg-leave-hourly" id="lgLeaveHourlyFields" hidden>' +
                                '<div class="lg-leave-pill">' +
                                    '<svg class="lg-leave-pill-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>' +
                                    '<span class="lg-leave-pill-text">Date</span>' +
                                    '<input type="date" id="lgLeavePermDate" class="lg-leave-pill-date" aria-label="Permission date">' +
                                '</div>' +
                                '<div class="lg-leave-pill">' +
                                    '<svg class="lg-leave-pill-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>' +
                                    '<span class="lg-leave-pill-text">From</span>' +
                                    '<input type="time" id="lgLeaveFrom" class="lg-leave-pill-time" value="10:00" aria-label="From time">' +
                                '</div>' +
                                '<div class="lg-leave-pill">' +
                                    '<svg class="lg-leave-pill-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>' +
                                    '<span class="lg-leave-pill-text">To</span>' +
                                    '<input type="time" id="lgLeaveTo" class="lg-leave-pill-time" value="12:00" aria-label="To time">' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                        '<div class="lg-composer-hint" id="lgHint">Enter to save · Tessa fixes grammar &amp; tags it · Shift+Enter for a new line</div>' +
                    '</div>' +
                '</div>' +
            '</div>';
        return root;
    }

    function closePlusMenu() {
        var menu = document.getElementById('lgPlusMenu');
        var btn = document.getElementById('lgTaskBtn');
        if (menu) menu.hidden = true;
        if (btn) {
            btn.classList.remove('is-active');
            btn.setAttribute('aria-expanded', 'false');
        }
    }

    function togglePlusMenu() {
        var menu = document.getElementById('lgPlusMenu');
        var btn = document.getElementById('lgTaskBtn');
        if (!menu || !btn) return;
        var open = menu.hidden;
        if (open) {
            menu.hidden = false;
            btn.classList.add('is-active');
            btn.setAttribute('aria-expanded', 'true');
        } else {
            closePlusMenu();
        }
    }

    function setComposerMode(mode) {
        if (mode !== 'task' && mode !== 'leave') mode = 'log';
        _composerMode = mode;
        var active = mode !== 'log';
        var cfg = MODE_CONFIG[mode] || null;
        var chip = document.getElementById('lgModeChip');
        var composer = document.getElementById('lgComposer');
        var input = document.getElementById('lgInput');
        var btn = document.getElementById('lgTaskBtn');
        var leaveControls = document.getElementById('lgLeaveControls');
        var hint = document.getElementById('lgHint');
        if (chip) {
            chip.hidden = !active;
            chip.classList.remove('is-task', 'is-leave');
            if (cfg) {
                chip.classList.add(cfg.chipClass);
                var label = chip.querySelector('.lg-mode-chip-label');
                var icon = chip.querySelector('.lg-mode-chip-icon');
                if (label) label.textContent = cfg.label;
                if (icon) icon.innerHTML = cfg.iconPaths;
            }
        }
        if (composer) composer.classList.toggle('is-task-mode', active);
        if (leaveControls) {
            leaveControls.hidden = mode !== 'leave';
        }
        if (input) {
            input.placeholder = mode === 'leave'
                ? LEAVE_REASON_PLACEHOLDER
                : (cfg ? cfg.placeholder : LOG_PLACEHOLDER);
        }
        if (hint) {
            hint.textContent = mode === 'leave'
                ? 'Enter to apply · Pick leave type and dates · Reason is optional'
                : HINT_DEFAULT;
            hint.classList.remove('lg-hint-warn');
        }
        if (btn) {
            btn.title = active ? 'More actions' : 'Add to log';
        }
        if (mode === 'leave') {
            resetLeaveDates();
            loadLeaveTypes();
        } else {
            closeLeaveTypeMenu();
        }
        closePlusMenu();
        refreshSendBtn();
    }

    function assignTask(text) {
        var input = document.getElementById('lgInput');
        var sendBtn = document.getElementById('lgSendBtn');
        if (sendBtn) {
            sendBtn.disabled = true;
            sendBtn.classList.add('is-loading');
        }
        showHint('Assigning task…', false);
        requestJson('/api/logs/assign-task', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text: text })
        }).then(function (data) {
            if (input) {
                input.value = '';
                autoResizeInput(input);
            }
            setComposerMode('log');
            var t = data && data.task;
            var name = (t && t.assignee_name) || 'them';
            var due = (t && t.deadline) ? formatDeadlineLabel(t.deadline + 'T12:00:00') : '';
            var msg = 'Task assigned to ' + name + (due ? ' — due ' + due : '');
            showToast(msg, 'success');
            showHint(msg + '.', false);
            loadEntries();
        }).catch(function (err) {
            if (input) {
                input.value = text;
                autoResizeInput(input);
            }
            showHint((err && err.message) || 'Could not assign task.', true);
        }).finally(function () {
            if (sendBtn) sendBtn.classList.remove('is-loading');
            refreshSendBtn();
        });
    }

    function requestLeave(text) {
        var input = document.getElementById('lgInput');
        var sendBtn = document.getElementById('lgSendBtn');
        if (sendBtn) {
            sendBtn.disabled = true;
            sendBtn.classList.add('is-loading');
        }
        showHint('Requesting leave…', false);
        requestJson('/api/logs/request-leave', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text: text })
        }).then(function (data) {
            if (input) {
                input.value = '';
                autoResizeInput(input);
            }
            setComposerMode('log');
            var msg = (data && data.message) || 'Leave request submitted.';
            showToast(msg, 'success');
            showHint(msg, false);
            loadEntries();
        }).catch(function (err) {
            if (input) {
                input.value = text;
                autoResizeInput(input);
            }
            showHint((err && err.message) || 'Could not request leave.', true);
        }).finally(function () {
            if (sendBtn) sendBtn.classList.remove('is-loading');
            refreshSendBtn();
        });
    }

    function submitLeave() {
        var input = document.getElementById('lgInput');
        var sendBtn = document.getElementById('lgSendBtn');
        if (!_leaveSelectedSlug) {
            showHint('Select a leave type.', true);
            return;
        }

        var selectedType = _leaveSelectedSlug;
        var isHourly = _leaveSelectedHourly;
        var payload = {
            leave_type: selectedType,
            reason: input ? ((input.value || '').trim() || null) : null
        };

        if (isHourly) {
            var permDate = document.getElementById('lgLeavePermDate');
            var fromEl = document.getElementById('lgLeaveFrom');
            var toEl = document.getElementById('lgLeaveTo');
            if (!permDate || !permDate.value) {
                showHint('Pick a date.', true);
                return;
            }
            if (!fromEl || !toEl || !fromEl.value || !toEl.value) {
                showHint('Pick from and to time.', true);
                return;
            }
            var fp = fromEl.value.split(':');
            var tp = toEl.value.split(':');
            var diff = (parseInt(tp[0], 10) * 60 + parseInt(tp[1], 10)) -
                (parseInt(fp[0], 10) * 60 + parseInt(fp[1], 10));
            if (diff <= 0) {
                showHint('To time must be after From time.', true);
                return;
            }
            payload.start_date = permDate.value;
            payload.from_time = fromEl.value;
            payload.to_time = toEl.value;
        } else {
            var startEl = document.getElementById('lgLeaveStart');
            var endEl = document.getElementById('lgLeaveEnd');
            if (!startEl || !startEl.value) {
                showHint('Pick a start date.', true);
                return;
            }
            payload.start_date = startEl.value;
            payload.end_date = (endEl && endEl.value) ? endEl.value : startEl.value;
        }

        if (sendBtn) {
            sendBtn.disabled = true;
            sendBtn.classList.add('is-loading');
        }
        showHint('Applying leave…', false);

        requestJson('/api/leave/requests', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (data) {
            if (input) {
                input.value = '';
                autoResizeInput(input);
            }
            setComposerMode('log');
            var msg = (data && data.message) || 'Leave applied!';
            showToast(msg, 'success');
            showHint(msg, false);
            loadEntries();
        }).catch(function (err) {
            showHint((err && err.message) || 'Could not apply leave.', true);
        }).finally(function () {
            if (sendBtn) sendBtn.classList.remove('is-loading');
            refreshSendBtn();
        });
    }

    function bindEvents() {
        if (_bound) return;
        _bound = true;
        var input = document.getElementById('lgInput');
        var sendBtn = document.getElementById('lgSendBtn');
        if (input) {
            input.addEventListener('input', function () {
                autoResizeInput(input);
                refreshSendBtn();
            });
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendEntry();
                }
            });
        }
        if (sendBtn) sendBtn.addEventListener('click', sendEntry);
        var slackToggle = document.getElementById('lgSlackToggle');
        if (slackToggle) slackToggle.addEventListener('click', onSlackButton);

        var taskBtn = document.getElementById('lgTaskBtn');
        if (taskBtn) {
            taskBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                togglePlusMenu();
            });
        }
        var plusMenu = document.getElementById('lgPlusMenu');
        if (plusMenu) {
            plusMenu.addEventListener('click', function (e) {
                var item = e.target.closest('[data-action]');
                if (!item) return;
                var action = item.getAttribute('data-action');
                if (item.disabled || item.classList.contains('is-disabled')) {
                    showHint('Coming soon.', false);
                    return;
                }
                if (action === 'task' || action === 'leave') {
                    setComposerMode(action);
                    var inp = document.getElementById('lgInput');
                    if (inp) inp.focus();
                }
            });
        }
        var chipX = document.getElementById('lgModeChipX');
        if (chipX) {
            chipX.addEventListener('click', function (e) {
                e.stopPropagation();
                setComposerMode('log');
                var inp = document.getElementById('lgInput');
                if (inp) inp.focus();
            });
        }
        var leaveTypeTrigger = document.getElementById('lgLeaveTypeTrigger');
        if (leaveTypeTrigger) {
            leaveTypeTrigger.addEventListener('click', function (e) {
                e.stopPropagation();
                toggleLeaveTypeMenu();
            });
        }
        var leaveTypeMenu = document.getElementById('lgLeaveTypeMenu');
        if (leaveTypeMenu) {
            leaveTypeMenu.addEventListener('click', function (e) {
                var item = e.target.closest('.lg-leave-type-item');
                if (!item) return;
                selectLeaveType(
                    item.getAttribute('data-slug'),
                    item.getAttribute('data-name'),
                    item.getAttribute('data-hourly') === '1'
                );
            });
        }
        ['lgLeaveStart', 'lgLeaveEnd', 'lgLeavePermDate', 'lgLeaveFrom', 'lgLeaveTo'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('change', refreshSendBtn);
        });
        document.addEventListener('click', function (e) {
            if (e.target.closest('.lg-leave-type-pill')) return;
            closeLeaveTypeMenu();
            if (e.target.closest('.lg-composer-inner-relative')) return;
            closePlusMenu();
        });
    }

    function render() {
        ensureShell();
        bindEvents();
        tickClock();
        if (_clockTimer) clearInterval(_clockTimer);
        _clockTimer = setInterval(tickClock, 1000);
        loadSlackStatus();
        loadEntries();
        var input = document.getElementById('lgInput');
        if (input) {
            autoResizeInput(input);
            refreshSendBtn();
        }
    }

    window.LogsModule = {
        render: render
    };
})();
