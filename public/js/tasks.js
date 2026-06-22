(function () {
    'use strict';

    var config = window.__PORTAL_CONFIG || {};
    function escapeHtml(v) { return MeetingModule.escapeHtml(v); }
    // Render a description with clickable URLs (escape first, then linkify).
    function linkifyDescription(text) {
        return escapeHtml(text).replace(
            /(https?:\/\/[^\s<]+)/g,
            '<a href="$1" target="_blank" rel="noopener" style="color:#3b82f6;text-decoration:underline;word-break:break-all;">$1</a>'
        );
    }
    // Pull http(s) URLs out of a string. Used to build the "Quick links" row.
    function extractUrls(text) {
        var urls = [];
        var re = /https?:\/\/[^\s<]+/g;
        var m;
        while ((m = re.exec(String(text || ''))) !== null) urls.push(m[0]);
        return urls;
    }
    function quickLinksHtml(text) {
        var urls = extractUrls(text);
        if (!urls.length) return '';
        var labelFor = function (u) {
            try {
                var h = new URL(u).hostname.replace(/^www\./, '');
                return h.length > 28 ? h.slice(0, 27) + '…' : h;
            } catch (e) { return u.length > 32 ? u.slice(0, 31) + '…' : u; }
        };
        return '<div class="cu-quick-links">' +
            '<span class="cu-quick-links-label">Links:</span>' +
            urls.map(function (u) {
                return '<a href="' + escapeHtml(u) + '" target="_blank" rel="noopener" class="cu-quick-link">🔗 ' + escapeHtml(labelFor(u)) + '</a>';
            }).join('') +
            '</div>';
    }

    function autoGrowTextarea(el) {
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = (el.scrollHeight + 2) + 'px';
    }

    function isoPlusDays(days) {
        var d = new Date();
        d.setDate(d.getDate() + (days || 0));
        var pad = function (n) { return String(n).padStart(2, '0'); };
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }

    // Shared fetch wrapper with error handling
    function taskFetch(url, options) {
        options = options || {};
        options.credentials = 'same-origin';
        options.headers = Object.assign({
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }, options.headers || {});
        return fetch(url, options).then(function (r) {
            if (!r.ok) {
                return r.json().catch(function () { return {}; }).then(function (body) {
                    throw { status: r.status, message: body.error || 'Request failed' };
                });
            }
            return r.json();
        });
    }

    // Non-blocking toast notification
    function showTaskToast(message, type) {
        type = type || 'error';
        var toast = document.createElement('div');
        toast.className = 'task-toast task-toast-' + type;
        toast.textContent = message;
        document.body.appendChild(toast);
        requestAnimationFrame(function () { toast.classList.add('task-toast-show'); });
        setTimeout(function () {
            toast.classList.remove('task-toast-show');
            setTimeout(function () { toast.remove(); }, 300);
        }, 3000);
    }

    // Wire drag-and-drop file handling to `el`. `onFiles(File[])` runs on drop.
    // Adds/removes a visual `task-attach-dragover` class on enter/leave/drop.
    function wireFileDropZone(el, onFiles) {
        if (!el || typeof onFiles !== 'function') return;
        var depth = 0; // dragenter/leave fire per child, track depth so leave doesn't flicker
        el.addEventListener('dragenter', function (e) {
            if (!e.dataTransfer || (e.dataTransfer.types || []).indexOf('Files') < 0) return;
            e.preventDefault();
            depth++;
            el.classList.add('task-attach-dragover');
        });
        el.addEventListener('dragover', function (e) {
            if (!e.dataTransfer || (e.dataTransfer.types || []).indexOf('Files') < 0) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
        });
        el.addEventListener('dragleave', function () {
            depth = Math.max(0, depth - 1);
            if (depth === 0) el.classList.remove('task-attach-dragover');
        });
        el.addEventListener('drop', function (e) {
            if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) return;
            e.preventDefault();
            depth = 0;
            el.classList.remove('task-attach-dragover');
            onFiles(Array.from(e.dataTransfer.files));
        });
    }

    function humanFileSize(bytes) {
        if (!bytes && bytes !== 0) return '';
        if (bytes < 1024) return bytes + ' B';
        var units = ['KB', 'MB', 'GB'];
        var v = bytes / 1024;
        var i = 0;
        while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
        return v.toFixed(v >= 10 ? 0 : 1) + ' ' + units[i];
    }

    // Extract an image File from a paste event's clipboard, or null if none.
    function extractPastedImage(e) {
        var cd = e.clipboardData || (e.originalEvent && e.originalEvent.clipboardData);
        if (!cd || !cd.items) return null;
        for (var i = 0; i < cd.items.length; i++) {
            var item = cd.items[i];
            if (item.type && item.type.indexOf('image/') === 0) {
                var blob = item.getAsFile();
                if (!blob) continue;
                var ext = (blob.type.split('/')[1] || 'png').toLowerCase();
                if (ext === 'jpeg') ext = 'jpg';
                var ts = new Date();
                var pad = function (n) { return String(n).padStart(2, '0'); };
                var name = 'screenshot-' + ts.getFullYear() + pad(ts.getMonth() + 1) + pad(ts.getDate()) +
                    '-' + pad(ts.getHours()) + pad(ts.getMinutes()) + pad(ts.getSeconds()) + '.' + ext;
                try {
                    return new File([blob], name, { type: blob.type, lastModified: ts.getTime() });
                } catch (err) {
                    blob.name = name;
                    return blob;
                }
            }
        }
        return null;
    }

    // State variables
    var tasksCurrentFilter = 'all';
    var tasksSearchQuery = '';
    var tasksFilterPriority = '';
    var tasksFilterDeadlineFrom = '';
    var tasksFilterDeadlineTo = '';
    var tasksSearchTimer = null;
    var tasksViewMode = 'board';       // 'list' | 'board'
    var tasksIncludeClosed = true;
    var tasksFilterAssignedById = null;
    var tasksFilterAssigneeId = null;
    var tasksFilterStatus = null;
    var tasksLastTasks = [];

    function getInitials(name) {
        if (!name) return '?';
        var parts = name.trim().split(/\s+/);
        if (parts.length >= 2) return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
        return name.substring(0, 2).toUpperCase();
    }

    var tasksInitialized = false;
    var tasksFilterPerson = null;
    var COMPLETED_INITIAL_LIMIT = 5;
    var completedExpanded = false;
    var BOARD_COLUMNS = [
        { key: 'pending',     label: 'Pending',     dotColor: '#a1a1aa', allowCreate: true },
        { key: 'in_progress', label: 'In Progress', dotColor: '#60a5fa', allowCreate: true },
        { key: 'on_hold',     label: 'On Hold',     dotColor: '#eab308', allowCreate: true },
        { key: 'completed',   label: 'Completed',   dotColor: '#4ade80', allowCreate: false },
        { key: 'closed',      label: 'Closed',      dotColor: '#22c55e', allowCreate: false },
        { key: 'cancelled',   label: 'Cancelled',   dotColor: '#ef4444', allowCreate: false }
    ];

    function tessaOpenTaskModal() {
        return openTaskSlideover(null);
    }


    function tasksBuildGridCard(t) {
        var isCompleted = t.status === 'completed';
        var isCancelled = t.status === 'cancelled';
        var isOnHold = t.status === 'on_hold';
        var isOverdue = !!t.is_overdue;
        var statusClass = isCompleted ? 'tasks-status-done'
            : (isCancelled ? 'tasks-status-cancelled'
            : (isOnHold ? 'tasks-status-hold'
            : (isOverdue ? 'tasks-status-overdue'
            : (t.status === 'in_progress' ? 'tasks-status-progress'
            : 'tasks-status-open'))));
        var statusLabel = isCompleted ? 'Done'
            : (isCancelled ? 'Cancelled'
            : (isOnHold ? 'On Hold'
            : (isOverdue ? 'Overdue'
            : (t.status === 'in_progress' ? 'In Progress'
            : 'Pending'))));
        var priorityClass = 'tasks-priority-' + (t.priority || 'medium');
        var deadlineStr = t.deadline ? new Date(t.deadline).toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: 'numeric', month: 'short' }) : '';
        var unread = t.unread_count || 0;
        var badge = unread > 0 ? '<span class="tg-card-badge">' + unread + '</span>' : '';

        // People avatars
        var avatarsHtml = '';
        if (t.people && t.people.length) {
            var avatars = t.people.slice(0, 3).map(function (p) {
                var initial = getInitials(p.name);
                return '<span class="tg-avatar tg-av-blue" title="' + escapeHtml(p.name) + '">' + initial + '</span>';
            }).join('');
            if (t.people.length > 3) {
                avatars += '<span class="tg-avatar tg-av-gray">+' + (t.people.length - 3) + '</span>';
            }
            avatarsHtml = '<div class="tg-card-people">' + avatars + '</div>';
        }

        var priorityLabel = (t.priority || 'medium').charAt(0).toUpperCase() + (t.priority || 'medium').slice(1);

        var priorityColors = { urgent: '#ef4444', high: '#f97316', medium: '#3b82f6', low: '#27272a' };
        var pColor = priorityColors[t.priority] || priorityColors.medium;

        return '<div class="tg-card ' + ((isCompleted || isCancelled) ? 'tg-card-done' : '') + '" data-id="' + t.id + '" role="button" tabindex="0">' +
            '<div class="tg-card-priority-bar" style="background:' + pColor + '"></div>' +
            '<div class="tg-card-body">' +
                '<div class="tg-card-top">' +
                    '<span class="tg-card-title">' + escapeHtml(t.title || '') + '</span>' +
                    badge +
                '</div>' +
                '<div class="tg-card-row">' +
                    '<span class="tg-card-status ' + statusClass + '">' + statusLabel + '</span>' +
                    (deadlineStr ? '<span class="tg-card-deadline">' + escapeHtml(deadlineStr) + '</span>' : '') +
                '</div>' +
                (t.ai_summary ? '<div class="tg-card-ai">' + escapeHtml(t.ai_summary) + '</div>' : '') +
                (t.blocker_status === 'blocked' && t.blocker_note ? '<div class="tg-card-blocker">' + escapeHtml(t.blocker_note) + '</div>' : '') +
                (t.subtask_total > 0 ? '<div class="tg-card-subtasks"><div class="tg-subtask-bar"><div class="tg-subtask-fill" style="width:' + Math.round((t.subtask_done / t.subtask_total) * 100) + '%"></div></div><span class="tg-subtask-label">' + t.subtask_done + '/' + t.subtask_total + '</span></div>' : '') +
                '<div class="tg-card-footer">' +
                    avatarsHtml +
                    '<span class="tg-card-priority-dot ' + priorityClass + '">' + escapeHtml(priorityLabel) + '</span>' +
                '</div>' +
            '</div>' +
        '</div>';
    }

    function tasksBuildBoardCard(t) {
        var isCompleted = t.status === 'completed';
        var isCancelled = t.status === 'cancelled';
        var priorityLabel = (t.priority || 'medium').charAt(0).toUpperCase() + (t.priority || 'medium').slice(1);
        var deadlineStr = t.deadline ? new Date(t.deadline).toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: 'numeric', month: 'short' }) : '';
        var unread = t.unread_count || 0;
        var badge = unread > 0 ? '<span class="tg-card-badge">' + unread + '</span>' : '';

        var avatarsHtml = '';
        if (t.people && t.people.length) {
            var avatars = t.people.slice(0, 3).map(function (p) {
                var initial = getInitials(p.name);
                return '<span class="tg-avatar tg-av-blue" title="' + escapeHtml(p.name) + '">' + initial + '</span>';
            }).join('');
            if (t.people.length > 3) avatars += '<span class="tg-avatar tg-av-gray">+' + (t.people.length - 3) + '</span>';
            avatarsHtml = '<div class="tg-card-people">' + avatars + '</div>';
        }

        var deadlineClass = '';
        var deadlineTag = '';
        if (t.deadline && !isCompleted && !isCancelled) {
            var dl = new Date(t.deadline);
            var now = new Date();
            var todayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            var tomorrowStart = new Date(todayStart); tomorrowStart.setDate(tomorrowStart.getDate() + 1);
            if (dl < todayStart) { deadlineClass = ' tb-deadline-overdue'; deadlineTag = 'Overdue'; }
            else if (dl < tomorrowStart) { deadlineClass = ' tb-deadline-today'; deadlineTag = 'Today'; }
            else if (dl < new Date(todayStart.getTime() + 2 * 86400000)) { deadlineClass = ' tb-deadline-tomorrow'; deadlineTag = 'Tomorrow'; }
        }
        var deadlineHtml = deadlineStr
            ? '<span class="tg-card-deadline' + deadlineClass + '"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" style="width:12px;height:12px"><rect x="2" y="3" width="12" height="11" rx="2"/><path d="M2 7h12M5 1v4M11 1v4"/></svg>' + (deadlineTag ? deadlineTag + ' · ' : '') + escapeHtml(deadlineStr) + '</span>'
            : '';

        var statsItems = '';
        var msgCount = t.message_count || 0;
        if (msgCount > 0) {
            statsItems += '<span class="tb-card-stat"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 4a2 2 0 012-2h8a2 2 0 012 2v5a2 2 0 01-2 2H6l-3 2.5V11H4a2 2 0 01-2-2V4z"/></svg>' + msgCount + '</span>';
        }
        if (t.subtask_total > 0) {
            statsItems += '<span class="tb-card-stat"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3.5 8l3 3L12.5 5"/></svg>' + t.subtask_done + '/' + t.subtask_total + '</span>';
        }
        var statsHtml = statsItems ? '<div class="tb-card-stats">' + statsItems + '</div>' : '';

        // Assignee name for cards without people array
        var assigneeName = (t.assigned_to && t.assigned_to.name) ? t.assigned_to.name : '';
        if (!avatarsHtml && assigneeName) {
            var initial = getInitials(assigneeName);
            avatarsHtml = '<div class="tg-card-people"><span class="tg-avatar tg-av-blue" title="' + escapeHtml(assigneeName) + '">' + initial + '</span></div>';
        }

        return '<div class="tb-card ' + ((isCompleted || isCancelled) ? 'tg-card-done' : '') + '" data-id="' + t.id + '" draggable="true">' +
            '<div class="tb-card-body">' +
                '<div class="tg-card-top">' +
                    '<span class="tg-card-title">' + escapeHtml(t.title || '') + '</span>' +
                    badge +
                '</div>' +
                '<div class="tb-card-tags">' +
                    '<span class="tb-card-id">#' + t.id + '</span>' +
                    (function () { var hc = { on_track: '#4ade80', at_risk: '#f59e0b', blocked: '#ef4444' }; return hc[t.blocker_status] ? '<span class="tb-health-dot" style="background:' + hc[t.blocker_status] + '" title="' + (t.blocker_status || '').replace('_', ' ') + '"></span>' : ''; })() +
                    '<span class="tb-priority-pill tb-priority-' + (t.priority || 'medium') + '">' + escapeHtml(priorityLabel) + '</span>' +
                '</div>' +
                ((t.progress > 0) ? '<div class="tb-progress-bar"><div class="tb-progress-fill" style="width:' + t.progress + '%"></div></div>' : '') +
                '<div class="tb-card-footer">' +
                    avatarsHtml +
                    '<div class="tb-card-meta">' +
                        deadlineHtml +
                    '</div>' +
                '</div>' +
            '</div>' +
        '</div>';
    }

    function renderTasksBoard(tasks, container) {
        if (!container) return;
        var grouped = {};
        BOARD_COLUMNS.forEach(function (col) { grouped[col.key] = []; });
        tasks.forEach(function (t) {
            var key = t.status || 'pending';
            if (grouped[key]) grouped[key].push(t);
        });
        var html = '';
        BOARD_COLUMNS.forEach(function (col) {
            var colTasks = grouped[col.key] || [];
            var totalCount = colTasks.length;
            var isLimited = (col.key === 'completed' || col.key === 'cancelled') && !completedExpanded && totalCount > COMPLETED_INITIAL_LIMIT;
            var visibleTasks = isLimited ? colTasks.slice(0, COMPLETED_INITIAL_LIMIT) : colTasks;
            var cardsHtml = visibleTasks.map(function (t) { return tasksBuildBoardCard(t); }).join('');
            var showMoreHtml = isLimited
                ? '<button type="button" class="tb-show-more" data-status="' + col.key + '">Show ' + (totalCount - COMPLETED_INITIAL_LIMIT) + ' more</button>'
                : '';
            html += '<div class="tb-column" data-status="' + col.key + '">' +
                '<div class="tb-column-header">' +
                    '<span class="tb-column-dot" style="background:' + col.dotColor + '"></span>' +
                    '<span class="tb-column-title">' + escapeHtml(col.label) + '</span>' +
                    '<span class="tb-column-count" style="--col-color:' + col.dotColor + '" data-count="' + totalCount + '">' + totalCount + '</span>' +
                '</div>' +
                '<div class="tb-column-body" data-status="' + col.key + '">' +
                    (col.allowCreate ? '<button type="button" class="tb-column-add" data-status="' + col.key + '">+ Create</button>' : '') +
                    cardsHtml +
                    showMoreHtml +
                '</div>' +
            '</div>';
        });
        container.innerHTML = html;
        container.querySelectorAll('.tb-card').forEach(function (card) {
            var id = card.getAttribute('data-id');
            if (!id) return;
            card.addEventListener('click', function (e) {
                if (e.defaultPrevented) return;
                openTaskSlideover(parseInt(id, 10));
            });
        });
        initBoardDragDrop(container, tasks);
        container.querySelectorAll('.tb-column-add').forEach(function (btn) {
            btn.addEventListener('click', function () { boardQuickCreate(btn.getAttribute('data-status')); });
        });
        container.querySelectorAll('.tb-show-more').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                completedExpanded = true;
                renderTasks();
            });
        });
    }

    function initBoardDragDrop(container, tasks) {
        var draggedCard = null;
        var draggedTaskId = null;
        var sourceColumn = null;

        container.querySelectorAll('.tb-card').forEach(function (card) {
            card.addEventListener('dragstart', function (e) {
                draggedCard = card;
                draggedTaskId = parseInt(card.getAttribute('data-id'), 10);
                sourceColumn = card.closest('.tb-column-body').getAttribute('data-status');
                card.classList.add('tb-card-dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', String(draggedTaskId));
            });
            card.addEventListener('dragend', function () {
                card.classList.remove('tb-card-dragging');
                container.querySelectorAll('.tb-column-body').forEach(function (col) {
                    col.classList.remove('tb-column-dragover');
                });
                draggedCard = null;
                draggedTaskId = null;
                sourceColumn = null;
            });
        });

        container.querySelectorAll('.tb-column-body').forEach(function (colBody) {
            colBody.addEventListener('dragover', function (e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                colBody.classList.add('tb-column-dragover');
            });
            colBody.addEventListener('dragleave', function (e) {
                if (!colBody.contains(e.relatedTarget)) colBody.classList.remove('tb-column-dragover');
            });
            colBody.addEventListener('drop', function (e) {
                e.preventDefault();
                colBody.classList.remove('tb-column-dragover');
                var newStatus = colBody.getAttribute('data-status');
                if (!draggedCard || !draggedTaskId || newStatus === sourceColumn) return;
                if (newStatus === 'closed' || sourceColumn === 'completed' || sourceColumn === 'closed') {
                    showTaskToast('Use the Verify & Close / Reopen buttons in the task to change verification state.');
                    renderTasks();
                    return;
                }
                colBody.appendChild(draggedCard);
                updateBoardColumnCounts(container);
                fetch('/api/tessa/tasks/' + draggedTaskId, {
                    method: 'PUT',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ status: newStatus })
                }).then(function (r) { return r.json(); })
                .then(function (body) {
                    if (!body.ok) {
                        if (body.error) showTaskToast(body.error);
                        renderTasks();
                        return;
                    }
                    var task = tasks.find(function (t) { return t.id === draggedTaskId; });
                    if (task) task.status = newStatus;
                })
                .catch(function () { renderTasks(); });
            });
        });
    }

    function updateBoardColumnCounts(container) {
        container.querySelectorAll('.tb-column').forEach(function (col) {
            var body = col.querySelector('.tb-column-body');
            var countEl = col.querySelector('.tb-column-count');
            if (body && countEl) countEl.textContent = body.querySelectorAll('.tb-card').length;
        });
    }

    function boardQuickCreate(status) {
        var colBody = document.querySelector('.tb-column-body[data-status="' + status + '"]');
        if (!colBody || colBody.querySelector('.tb-quick-create')) return;

        var teamMembers = config.TEAM_MEMBERS || [];
        var allPeople = config.MODAL_PEOPLE || [];
        var people = teamMembers.length ? teamMembers : allPeople;

        var defaultDeadlineIso = isoPlusDays(3);
        var wrap = document.createElement('div');
        wrap.className = 'tb-quick-create';
        wrap.innerHTML =
            '<input type="text" class="tb-qc-title" placeholder="What needs to be done?" autofocus data-grammar-fix>' +
            '<div class="tb-qc-fields">' +
                '<div class="tb-qc-field">' +
                    '<select class="tb-qc-select" id="qcPriority" title="Priority">' +
                        '<option value="low">Low</option>' +
                        '<option value="medium" selected>Medium</option>' +
                        '<option value="high">High</option>' +
                        '<option value="urgent">Urgent</option>' +
                    '</select>' +
                '</div>' +
                '<div class="tb-qc-field">' +
                    '<input type="date" class="tb-qc-date" id="qcDeadline" title="Deadline" value="' + defaultDeadlineIso + '">' +
                '</div>' +
                '<div class="tb-qc-field">' +
                    '<select class="tb-qc-select" id="qcAssignee" title="Assignee">' +
                        '<option value="' + (config.userId || '') + '">Me</option>' +
                        people.filter(function (p) { return p.id !== config.userId; }).map(function (p) {
                            return '<option value="' + p.id + '">' + escapeHtml(p.name) + '</option>';
                        }).join('') +
                    '</select>' +
                '</div>' +
            '</div>';

        colBody.appendChild(wrap);
        var titleInput = wrap.querySelector('.tb-qc-title');
        titleInput.focus();

        function doSave() {
            var title = titleInput.value.trim();
            if (!title) { showTaskToast('Please enter a task title'); return; }
            var assignee = wrap.querySelector('#qcAssignee').value;
            var priority = wrap.querySelector('#qcPriority').value || 'medium';
            var deadline = wrap.querySelector('#qcDeadline').value || isoPlusDays(3);
            if (!assignee) { showTaskToast('Please select an assignee'); return; }

            var payload = {
                assigned_to: parseInt(assignee, 10),
                title: title,
                priority: priority,
                deadline: deadline
            };

            function notifyCreated(task) {
                var t = task || {};
                var titleShown = t.title || title;
                if (titleShown.length > 48) titleShown = titleShown.slice(0, 45) + '…';
                var dueLabel = relativeDate(t.deadline || deadline);
                var priorityLabel = priorityDisplay(t.priority || priority) || 'Normal';
                showTaskToast('Task created: "' + titleShown + '" • Due ' + dueLabel + ' • ' + priorityLabel + ' priority', 'success');
            }

            fetch('/api/tessa/tasks', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(payload)
            }).then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
            .then(function (res) {
                wrap.remove();
                if (!res.ok || !res.body.task) {
                    showTaskToast((res.body && res.body.error) || 'Failed to create task.');
                    renderTasks();
                    return;
                }
                if (status !== 'pending') {
                    fetch('/api/tessa/tasks/' + res.body.task.id, {
                        method: 'PUT',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ status: status })
                    }).then(function () { notifyCreated(res.body.task); renderTasks(); });
                } else {
                    notifyCreated(res.body.task);
                    renderTasks();
                }
            }).catch(function () {
                wrap.remove();
                showTaskToast('Failed to create task.');
                renderTasks();
            });
        }

        titleInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); doSave(); }
            if (e.key === 'Escape') wrap.remove();
        });
        // Click outside to close
        setTimeout(function () {
            document.addEventListener('click', function closeQc(e) {
                if (!wrap.contains(e.target)) { wrap.remove(); document.removeEventListener('click', closeQc); }
            });
        }, 10);
    }


    function applyPeopleFilter(tasks) {
        if (!tasksFilterPerson) return tasks;
        return tasks.filter(function (t) {
            return (t.assigned_to && t.assigned_to.name === tasksFilterPerson)
                || (t.assigned_by && t.assigned_by.name === tasksFilterPerson)
                || (t.people && t.people.some(function (p) { return p.name === tasksFilterPerson; }));
        });
    }

    function renderPeopleFilter(tasks) {
        var toolbar = document.getElementById('tasksToolbar');
        if (!toolbar) return;
        var existing = document.getElementById('tasksPeopleFilter');
        if (existing) existing.remove();

        var peopleMap = {};
        tasks.forEach(function (t) {
            if (t.assigned_to && t.assigned_to.name) peopleMap[t.assigned_to.name] = true;
            if (t.assigned_by && t.assigned_by.name) peopleMap[t.assigned_by.name] = true;
            if (t.people) {
                t.people.forEach(function (p) {
                    if (p && p.name) peopleMap[p.name] = true;
                });
            }
        });
        var names = Object.keys(peopleMap).sort();
        if (!names.length) return;

        var bar = document.createElement('div');
        bar.id = 'tasksPeopleFilter';
        bar.className = 'tasks-people-filter';
        bar.innerHTML = '<svg viewBox="0 0 20 20" fill="currentColor" class="tasks-people-icon"><path d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z"/></svg>' +
            names.map(function (name) {
                var initial = getInitials(name);
                var isActive = tasksFilterPerson === name;
                return '<button type="button" class="tasks-people-avatar' + (isActive ? ' active' : '') + '" data-name="' + escapeHtml(name) + '" title="' + escapeHtml(name) + '">' + escapeHtml(initial) + '</button>';
            }).join('');

        toolbar.insertAdjacentElement('afterend', bar);

        bar.querySelectorAll('.tasks-people-avatar').forEach(function (btn) {
            btn.onclick = function () {
                var name = btn.getAttribute('data-name');
                tasksFilterPerson = (tasksFilterPerson === name) ? null : name;
                renderTasks();
            };
        });
    }

    function renderTasks(filter) {
        filter = filter || tasksCurrentFilter;
        if (filter !== tasksCurrentFilter) completedExpanded = false;
        tasksCurrentFilter = filter;

        var gridBody = document.getElementById('tasksGridBody');
        var boardBody = document.getElementById('tasksBoardBody');
        var listBody = document.getElementById('tasksListBody');
        var toolbar = document.getElementById('tasksToolbar');

        // Always hide grid for non-recurring; toolbar visible
        if (gridBody) gridBody.classList.add('hidden');
        if (toolbar) toolbar.classList.remove('hidden');

        if (tasksViewMode === 'board') {
            if (listBody) listBody.classList.add('hidden');
            if (boardBody) { boardBody.classList.remove('hidden'); boardBody.innerHTML = '<div class="tasks-loading">Loading...</div>'; }
        } else {
            if (boardBody) boardBody.classList.add('hidden');
            if (listBody) { listBody.classList.remove('hidden'); listBody.innerHTML = '<div class="tasks-loading">Loading...</div>'; }
        }

        document.querySelectorAll('.tasks-filter-btn').forEach(function (btn) {
            btn.classList.toggle('active', btn.getAttribute('data-filter') === filter);
        });

        var url = '/api/tessa/tasks?filter=' + encodeURIComponent(filter);
        if (tasksSearchQuery) url += '&search=' + encodeURIComponent(tasksSearchQuery);
        if (tasksFilterPriority) url += '&priority=' + encodeURIComponent(tasksFilterPriority);
        if (tasksFilterDeadlineFrom) url += '&deadline_from=' + encodeURIComponent(tasksFilterDeadlineFrom);
        if (tasksFilterDeadlineTo) url += '&deadline_to=' + encodeURIComponent(tasksFilterDeadlineTo);
        if (!tasksIncludeClosed) url += '&include_closed=0';
        if (tasksFilterAssignedById) url += '&assigned_by_id=' + encodeURIComponent(tasksFilterAssignedById);
        if (tasksFilterAssigneeId) url += '&assignee_id=' + encodeURIComponent(tasksFilterAssigneeId);
        if (tasksFilterStatus) url += '&status=' + encodeURIComponent(tasksFilterStatus);

        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var tasks = data.tasks || [];
                tasksLastTasks = tasks;
                renderPeopleFilter(tasks);
                var filteredTasks = applyPeopleFilter(tasks);
                if (tasksViewMode === 'board') {
                    renderTasksBoard(filteredTasks, boardBody);
                } else if (window.TasksListView && window.TasksListView.render) {
                    window.TasksListView.render(filteredTasks, listBody);
                }
            })
            .catch(function () {
                var target = tasksViewMode === 'board' ? boardBody : listBody;
                if (target) target.innerHTML = '<div class="tasks-empty">Failed to load.</div>';
            });

        // Remove recurring "New" button when switching to normal tabs
        var recurrNewBtn = document.getElementById('recurrenceNewBtn');
        if (recurrNewBtn) recurrNewBtn.remove();

        // One-time initialization
        if (!tasksInitialized) {
            tasksInitialized = true;
            initTaskFilters();
            if (window.TasksListView && window.TasksListView.bindToolbar) {
                window.TasksListView.bindToolbar();
            }
        }
    }

    function setViewMode(mode) {
        if (mode !== 'list' && mode !== 'board') return;
        tasksViewMode = mode;
        renderTasks();
    }
    function setIncludeClosed(on) {
        tasksIncludeClosed = !!on;
        renderTasks();
    }
    function setAssignedByFilter(uid) {
        tasksFilterAssignedById = uid;
        renderTasks();
    }
    function setAssigneeFilter(uid) {
        tasksFilterAssigneeId = uid;
        renderTasks();
    }
    function setCombinedFilter(filter, status) {
        tasksCurrentFilter = filter || 'all';
        tasksFilterStatus = status || null;
        // Keep the existing tab-row in sync
        document.querySelectorAll('.tasks-filter-btn').forEach(function (btn) {
            btn.classList.toggle('active', btn.getAttribute('data-filter') === tasksCurrentFilter);
        });
        renderTasks(tasksCurrentFilter);
    }
    function setSearch(q) {
        tasksSearchQuery = q || '';
        renderTasks();
    }

    function initTaskFilters() {
        // Bind filter buttons (once)
        document.querySelectorAll('.tasks-filter-btn').forEach(function (btn) {
            var f = btn.getAttribute('data-filter');
            if (f === 'recurring') {
                btn.addEventListener('click', function () {
                    document.querySelectorAll('.tasks-filter-btn').forEach(function (b) { b.classList.remove('active'); });
                    btn.classList.add('active');
                    var boardBody = document.getElementById('tasksBoardBody');
                    var gridBody = document.getElementById('tasksGridBody');
                    var listBody = document.getElementById('tasksListBody');
                    var toolbar = document.getElementById('tasksToolbar');
                    if (boardBody) boardBody.classList.add('hidden');
                    if (listBody) listBody.classList.add('hidden');
                    if (toolbar) toolbar.classList.add('hidden');
                    if (gridBody) gridBody.classList.remove('hidden');
                    renderRecurrences();
                });
            } else {
                btn.addEventListener('click', function () { renderTasks(f); });
            }
        });

        // Bind "Assign Task" button
        var assignBtn = document.getElementById('tasksAssignBtn');
        if (assignBtn) {
            assignBtn.addEventListener('click', function () { openTaskSlideover(null); });
        }
    }

    // ── Task Modal Builder Functions ──

    function buildBodyHtml(task) {
        return '<div class="task-modal-section-label">Description</div>' +
            '<div class="task-modal-desc">' + (task.description ? linkifyDescription(task.description) : '<span style="color:#3f3f46">No description provided</span>') + '</div>' +
            (task.status_note ? '<div class="tasks-detail-note"><strong>Status Note:</strong> ' + escapeHtml(task.status_note) + '</div>' : '');
    }

    function buildStateBannerHtml(task) {
        var isOwner = task.assigned_by && task.assigned_by.id === (config.userId || 0);
        var isAssignee = task.assigned_to && task.assigned_to.id === (config.userId || 0);
        var isSharedAssigner = task.shared_assigned_by && task.shared_assigned_by.id === (config.userId || 0);
        var pendingDays = task.pending_extension_days;
        var blocks = '';

        // Mandatory-task banner — shows reporter what's required and shows the
        // assignee a Confirm-Form button when a form/sheet URL is required.
        // Hidden once the task is closed/cancelled — at that point it's history.
        if (task.is_mandatory && task.status !== 'closed' && task.status !== 'cancelled') {
            var reqLines = [];
            if (task.requires_attachment) {
                reqLines.push('Must upload at least one file as proof');
            }
            if (task.requires_form_url) {
                var url = String(task.requires_form_url);
                var safeUrl = escapeHtml(url);
                reqLines.push('Must fill this form/sheet: <a href="' + safeUrl + '" target="_blank" rel="noopener" class="cu-mandatory-link">' + safeUrl + '</a>');
            }
            if (!reqLines.length) {
                reqLines.push('Completion is required — incomplete tasks affect your KRA.');
            }
            var subBody = reqLines.map(function (l) { return '<li>' + l + '</li>'; }).join('');

            var actionHtml = '';
            if (isAssignee && task.requires_form_url && !task.proof_submitted_at) {
                actionHtml = '<div class="cu-mandatory-actions">' +
                    '<button type="button" id="taskConfirmFormBtn" class="cu-btn cu-btn-primary cu-btn-small">I have filled the form/sheet</button>' +
                    '</div>';
            } else if (task.requires_form_url && task.proof_submitted_at) {
                var when = new Date(task.proof_submitted_at).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });
                actionHtml = '<div class="cu-mandatory-confirmed">✓ Form submission confirmed on ' + escapeHtml(when) + (task.proof_note ? ' — ' + escapeHtml(task.proof_note) : '') + '</div>';
            }

            blocks += '<div class="cu-banner cu-banner-mandatory">' +
                '<div class="cu-mandatory-title">' +
                    '<span class="cu-mandatory-badge">MANDATORY</span>' +
                    '<span>This task must be completed.</span>' +
                '</div>' +
                '<ul class="cu-mandatory-list">' + subBody + '</ul>' +
                actionHtml +
            '</div>';
        }

        if (task.status === 'completed') {
            if (isOwner || isSharedAssigner) {
                var reopenHistory = (task.reopen_count > 0)
                    ? '<div class="tm-verify-history">Previously reopened ' + task.reopen_count + ' time' + (task.reopen_count === 1 ? '' : 's') + (task.reopen_reason ? '. Last reason: ' + escapeHtml(task.reopen_reason) : '.') + '</div>'
                    : '';
                blocks += '<div class="cu-banner cu-banner-verify" id="taskVerifyPanel">' +
                    '<div class="tm-verify-title">Completed — Please Verify</div>' +
                    '<div class="tm-verify-sub">Assignee marked this done. Verify the work was actually completed.</div>' +
                    reopenHistory +
                    '<div class="tm-verify-actions">' +
                        '<button type="button" id="taskVerifyCloseBtn" class="tm-verify-close-btn">Verify &amp; Close</button>' +
                        '<button type="button" id="taskReopenBtn" class="tm-verify-reopen-btn">Reopen</button>' +
                    '</div>' +
                    '<div class="tm-verify-reason hidden" id="taskReopenReasonBox">' +
                        '<label for="taskReopenReason" class="tm-verify-reason-label">Reason for reopening (sent to assignee)</label>' +
                        '<textarea id="taskReopenReason" rows="3" placeholder="What needs to be fixed?" data-grammar-fix></textarea>' +
                        '<div class="tm-verify-reason-actions">' +
                            '<button type="button" id="taskReopenSubmitBtn" class="tm-verify-reopen-submit">Submit Reopen</button>' +
                            '<button type="button" id="taskReopenCancelBtn" class="tm-verify-reopen-cancel">Cancel</button>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            } else {
                blocks += '<div class="cu-banner cu-banner-pending">Completed — awaiting reporter verification</div>';
            }
        } else if (task.status === 'closed') {
            blocks += '<div class="cu-banner cu-banner-closed">' +
                '<span class="tm-verify-closed-badge">Closed</span>' +
                (isOwner || isSharedAssigner ? '<button type="button" id="taskReopenBtn" class="tm-verify-reopen-btn tm-verify-reopen-small">Reopen</button>' : '') +
                (isOwner || isSharedAssigner ? '<div class="tm-verify-reason hidden" id="taskReopenReasonBox">' +
                    '<label for="taskReopenReason" class="tm-verify-reason-label">Reason for reopening (sent to assignee)</label>' +
                    '<textarea id="taskReopenReason" rows="3" placeholder="What needs to be revisited?" data-grammar-fix></textarea>' +
                    '<div class="tm-verify-reason-actions">' +
                        '<button type="button" id="taskReopenSubmitBtn" class="tm-verify-reopen-submit">Submit Reopen</button>' +
                        '<button type="button" id="taskReopenCancelBtn" class="tm-verify-reopen-cancel">Cancel</button>' +
                    '</div>' +
                '</div>' : '') +
            '</div>';
        }

        var isActive = task.status !== 'completed' && task.status !== 'cancelled' && task.status !== 'closed';
        if (isActive && task.deadline && pendingDays && isOwner) {
            var dayLabel = pendingDays === 1 ? '1 day' : pendingDays + ' days';
            blocks += '<div class="cu-banner cu-banner-extension" id="taskExtensionApproval">' +
                '<div class="cu-banner-extension-title">Extension Request: +' + dayLabel + '</div>' +
                '<div class="cu-banner-extension-actions">' +
                    '<button type="button" id="taskApproveExtBtn" class="cu-btn-success">Approve</button>' +
                    '<button type="button" id="taskDenyExtBtn" class="cu-btn-danger">Deny</button>' +
                '</div></div>';
        } else if (isActive && task.deadline && pendingDays && isAssignee) {
            blocks += '<div class="cu-banner cu-banner-extension-pending">Extension request pending approval...</div>';
        }

        return blocks;
    }

    function buildSidebarHtml(task) {
        var assignedByName = (task.assigned_by && task.assigned_by.name) || 'Unknown';
        var assignedToName = (task.assigned_to && task.assigned_to.name) || 'Unknown';
        var assignedByInitial = getInitials(assignedByName);
        var assignedToInitial = getInitials(assignedToName);
        var priorityLabel = (task.priority || 'medium').charAt(0).toUpperCase() + (task.priority || 'medium').slice(1);
        var deadlineStr = task.deadline ? new Date(task.deadline).toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) : 'No deadline';
        var createdStr = task.created_at ? new Date(task.created_at).toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: 'numeric', month: 'short', year: 'numeric' }) : '-';
        var isOwner = task.assigned_by && task.assigned_by.id === (config.userId || 0);
        var isAssignee = task.assigned_to && task.assigned_to.id === (config.userId || 0);
        var isSharedAssigner = task.shared_assigned_by && task.shared_assigned_by.id === (config.userId || 0);
        // The current assignee (or an existing shared assigner) can pass an active task on.
        // The creator uses Reassign instead, so don't double up the buttons for them.
        var canRedirect = !isOwner && (isAssignee || isSharedAssigner)
            && task.status !== 'completed' && task.status !== 'closed' && task.status !== 'cancelled';

        // The verify/closed/extension banners and status pill live above this block in the slide-over.
        return '' +
            '<div class="tm-detail-row">' +
                '<span class="tm-detail-label">Health</span>' +
                '<div class="tm-detail-value tm-health-buttons" id="taskHealthButtons">' +
                    '<button type="button" class="tm-health-btn tm-health-on_track' + (task.blocker_status === 'on_track' ? ' active' : '') + '" data-health="on_track">On Track</button>' +
                    '<button type="button" class="tm-health-btn tm-health-at_risk' + (task.blocker_status === 'at_risk' ? ' active' : '') + '" data-health="at_risk">At Risk</button>' +
                    '<button type="button" class="tm-health-btn tm-health-blocked' + (task.blocker_status === 'blocked' ? ' active' : '') + '" data-health="blocked">Blocked</button>' +
                '</div>' +
            '</div>' +
            '<div class="tm-detail-row">' +
                '<span class="tm-detail-label">Progress</span>' +
                '<div class="tm-detail-value tm-progress-row">' +
                    '<input type="range" min="0" max="100" step="10" value="' + (task.progress || 0) + '" id="taskProgressRange" class="tm-progress-range">' +
                    '<span class="tm-progress-label" id="taskProgressLabel">' + (task.progress || 0) + '%</span>' +
                '</div>' +
            '</div>' +
            '<div class="tm-details-section">' +
                '<div class="tm-details-title">Details</div>' +
                '<div class="tm-detail-row">' +
                    '<span class="tm-detail-label">Assignee</span>' +
                    '<div class="tm-detail-value" style="position:relative">' +
                        '<span class="tm-avatar tm-av-assignee">' + assignedToInitial + '</span>' +
                        '<span>' + escapeHtml(assignedToName) + '</span>' +
                        (isOwner ? '<button type="button" class="tm-reassign-link" id="taskReassignBtn">Reassign</button>' : '') +
                        (canRedirect ? '<button type="button" class="tm-reassign-link" id="taskRedirectBtn">Redirect</button>' : '') +
                    '</div>' +
                '</div>' +
                '<div class="tm-detail-row">' +
                    '<span class="tm-detail-label">Priority</span>' +
                    '<div class="tm-detail-value"><span class="tb-priority-pill tb-priority-' + (task.priority || 'medium') + '">' + escapeHtml(priorityLabel) + '</span></div>' +
                '</div>' +
                '<div class="tm-detail-row" style="position:relative">' +
                    '<span class="tm-detail-label">Due date</span>' +
                    '<div class="tm-detail-value tm-deadline-editable" id="taskDeadlineValue" style="cursor:pointer">' +
                        '<span id="taskDeadlineDisplay">' + escapeHtml(deadlineStr) + '</span>' +
                        '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" style="width:12px;height:12px;margin-left:6px;opacity:0.5"><path d="M11.5 1.5l3 3L5 14H2v-3L11.5 1.5z"/></svg>' +
                        '<div class="tm-deadline-picker hidden" id="taskDeadlinePicker">' +
                            '<input type="datetime-local" id="taskDeadlineInput" value="' + (task.deadline ? task.deadline.slice(0, 16) : '') + '" style="width:100%;padding:6px 8px;border:1px solid #444;border-radius:6px;background:#1a1a2e;color:#e2e8f0;font-size:13px;margin-bottom:6px">' +
                            '<div class="tm-deadline-quick" style="display:flex;gap:4px;flex-wrap:wrap">' +
                                '<button type="button" class="tm-dq-btn" data-offset="1">Tomorrow</button>' +
                                '<button type="button" class="tm-dq-btn" data-offset="3">+3 Days</button>' +
                                '<button type="button" class="tm-dq-btn" data-offset="friday">Friday</button>' +
                                '<button type="button" class="tm-dq-btn" data-offset="7">+1 Week</button>' +
                            '</div>' +
                            '<div style="display:flex;gap:4px;margin-top:6px">' +
                                '<button type="button" class="tm-dq-save" id="taskDeadlineSave" style="flex:1;padding:5px 0;border:none;border-radius:5px;background:#3b82f6;color:#fff;cursor:pointer;font-size:12px">Save</button>' +
                                '<button type="button" class="tm-dq-cancel" id="taskDeadlineCancel" style="padding:5px 10px;border:1px solid #555;border-radius:5px;background:transparent;color:#aaa;cursor:pointer;font-size:12px">Cancel</button>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="tm-detail-row">' +
                    '<span class="tm-detail-label">Reporter</span>' +
                    '<div class="tm-detail-value">' +
                        '<span class="tm-avatar tm-av-reporter">' + assignedByInitial + '</span>' +
                        '<span>' + escapeHtml(assignedByName) + '</span>' +
                    '</div>' +
                '</div>' +
                (task.shared_assigned_by && task.shared_assigned_by.id ?
                    '<div class="tm-detail-row">' +
                        '<span class="tm-detail-label">Shared assigner</span>' +
                        '<div class="tm-detail-value">' +
                            '<span class="tm-avatar tm-av-reporter">' + getInitials(task.shared_assigned_by.name) + '</span>' +
                            '<span>' + escapeHtml(task.shared_assigned_by.name) + '</span>' +
                        '</div>' +
                    '</div>' : '') +
                '<div class="tm-detail-row">' +
                    '<span class="tm-detail-label">Created</span>' +
                    '<div class="tm-detail-value">' + escapeHtml(createdStr) + '</div>' +
                '</div>' +
            '</div>' +
            (function () {
                var isActive = task.status !== 'completed' && task.status !== 'cancelled';
                var isOverdue = task.deadline && new Date(task.deadline) < new Date() && isActive;
                var isAssignee = task.assigned_to && task.assigned_to.id === (config.userId || 0);
                var nudgeHtml = (isActive && isOwner) ? '<button type="button" class="task-nudge-btn" id="taskNudgeBtn" style="width:100%;padding:8px 0;margin-bottom:8px;border:1px solid #8b5cf6;border-radius:8px;background:transparent;color:#8b5cf6;cursor:pointer;font-weight:600;font-size:13px">Nudge for Update</button>' : '';
                var escalateHtml = isOverdue ? '<button type="button" class="task-escalate-btn" id="taskEscalateBtn" style="width:100%;padding:8px 0;margin-bottom:8px;border:1px solid #f97316;border-radius:8px;background:transparent;color:#f97316;cursor:pointer;font-weight:600;font-size:13px">Escalate to Reporter</button>' : '';
                var extendHtml = '';
                var pendingDays = task.pending_extension_days;
                if (isActive && task.deadline && pendingDays && isOwner) {
                    var dayLabel = pendingDays === 1 ? '1 day' : pendingDays + ' days';
                    extendHtml = '<div id="taskExtensionApproval" style="margin-bottom:8px;padding:10px;border:1px solid #f59e0b;border-radius:8px;background:rgba(245,158,11,0.08)">' +
                        '<div style="font-size:12px;color:#f59e0b;margin-bottom:6px;font-weight:600;text-align:center">Extension Request: +' + dayLabel + '</div>' +
                        '<div style="display:flex;gap:6px">' +
                            '<button type="button" id="taskApproveExtBtn" style="flex:1;padding:8px 0;border:none;border-radius:8px;background:#22c55e;color:#fff;cursor:pointer;font-weight:600;font-size:13px">Approve</button>' +
                            '<button type="button" id="taskDenyExtBtn" style="flex:1;padding:8px 0;border:none;border-radius:8px;background:#ef4444;color:#fff;cursor:pointer;font-weight:600;font-size:13px">Deny</button>' +
                        '</div></div>';
                } else if (isActive && task.deadline && pendingDays && isAssignee) {
                    extendHtml = '<div style="margin-bottom:8px;padding:8px;border:1px solid #f59e0b;border-radius:8px;text-align:center;font-size:12px;color:#f59e0b">Extension request pending approval...</div>';
                } else if (isActive && isAssignee && task.deadline && !pendingDays) {
                    extendHtml = '<div id="taskExtendDeadline" style="margin-bottom:8px"><div style="font-size:12px;color:#94a3b8;margin-bottom:4px;text-align:center">Need more time?</div><div style="display:flex;gap:6px">' +
                        '<button type="button" class="task-extend-btn" data-days="1" style="flex:1;padding:8px 0;border:1px solid #0ea5e9;border-radius:8px;background:transparent;color:#0ea5e9;cursor:pointer;font-weight:600;font-size:13px">+1 Day</button>' +
                        '<button type="button" class="task-extend-btn" data-days="2" style="flex:1;padding:8px 0;border:1px solid #0ea5e9;border-radius:8px;background:transparent;color:#0ea5e9;cursor:pointer;font-weight:600;font-size:13px">+2 Days</button></div></div>';
                }
                return nudgeHtml + escalateHtml + extendHtml;
            })() +
            (isOwner ? '<button type="button" class="task-delete-btn" id="taskDeleteBtn">Delete Task</button>' : '');
    }

    function buildSubtasksAttachmentsHtml() {
        return '<div class="task-modal-attachments" id="taskAttachments">' +
                '<div class="task-attach-header">' +
                    '<span class="task-attach-title">Attachments <span class="task-attach-hint">— or paste a screenshot (Ctrl/Cmd+V)</span></span>' +
                    '<label class="task-attach-upload-btn" id="taskAttachUploadLabel">' +
                        '<input type="file" id="taskAttachFileInput" class="task-attach-file-input" multiple>' +
                        'Upload' +
                    '</label>' +
                '</div>' +
                '<div class="task-attach-list" id="taskAttachList"><div class="tthread-loading">Loading...</div></div>' +
            '</div>';
    }

    function uploadTaskAttachmentFile(taskId, file, onDone) {
        if (!file) return;
        if (file.size > 10 * 1024 * 1024) { showTaskToast(file.name + ' exceeds 10MB limit'); return; }
        var formData = new FormData();
        formData.append('file', file);
        fetch('/api/tessa/tasks/' + taskId + '/attachments', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        }).then(function (r) { return r.json(); }).then(function (body) {
            if (body.ok) {
                if (onDone) onDone();
            } else {
                showTaskToast(body.error || 'Failed to upload ' + file.name);
            }
        }).catch(function () { showTaskToast('Failed to upload ' + file.name); });
    }

    function buildThreadHtml() {
        return '<div class="tthread-section" id="tthreadSection">' +
            '<div class="tthread-header"><span class="tthread-title">Activity</span><button class="tthread-invite-btn" id="tthreadInviteBtn">+ Invite</button></div>' +
            '<div class="tthread-participants" id="tthreadParticipants"></div>' +
            '<div class="tthread-messages" id="tthreadMessages"><div class="tthread-loading">Loading...</div></div>' +
            '<div class="tthread-compose">' +
                '<textarea class="tthread-input" id="tthreadInput" rows="1" placeholder="Add a comment..."></textarea>' +
                '<button class="tthread-send-btn" id="tthreadSendBtn"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg></button>' +
            '</div>' +
        '</div>';
    }

    function bindModalHandlers(task, overlay, render) {
        // Health status buttons
        var healthBtns = document.getElementById('taskHealthButtons');
        if (healthBtns) {
            healthBtns.querySelectorAll('.tm-health-btn').forEach(function (btn) {
                btn.onclick = function () {
                    healthBtns.querySelectorAll('.tm-health-btn').forEach(function (b) { b.classList.remove('active'); });
                    btn.classList.add('active');
                    fetch('/api/tessa/tasks/' + task.id, {
                        method: 'PUT', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ blocker_status: btn.getAttribute('data-health') })
                    }).then(function () { renderTasks(); });
                };
            });
        }

        // Progress slider
        var progressRange = document.getElementById('taskProgressRange');
        var progressLabel = document.getElementById('taskProgressLabel');
        if (progressRange) {
            progressRange.oninput = function () {
                if (progressLabel) progressLabel.textContent = progressRange.value + '%';
            };
            progressRange.onchange = function () {
                fetch('/api/tessa/tasks/' + task.id, {
                    method: 'PUT', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ progress: parseInt(progressRange.value, 10) })
                }).then(function () { renderTasks(); });
            };
        }

        var sel = document.getElementById('taskStatusSelect');
        if (sel) {
            sel.onchange = function () {
                var newStatus = sel.value;
                sel.setAttribute('data-status', newStatus);
                sel.disabled = true;
                fetch('/api/tessa/tasks/' + task.id, {
                    method: 'PUT', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ status: newStatus })
                }).then(function (r) { return r.json(); }).then(function (body) {
                    sel.disabled = false;
                    if (body.ok && body.task) {
                        render(body.task);
                        renderTasks();
                    } else if (body.error) {
                        showTaskToast(body.error);
                    }
                }).catch(function () { sel.disabled = false; });
            };
        }

        var verifyCloseBtn = document.getElementById('taskVerifyCloseBtn');
        if (verifyCloseBtn) {
            verifyCloseBtn.onclick = function () {
                verifyCloseBtn.disabled = true;
                verifyCloseBtn.textContent = 'Closing...';
                fetch('/api/tessa/tasks/' + task.id + '/verify', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (r) { return r.json(); }).then(function (body) {
                    if (body.ok && body.task) {
                        showTaskToast(body.message || 'Task closed.');
                        render(body.task);
                        renderTasks();
                    } else {
                        verifyCloseBtn.disabled = false;
                        verifyCloseBtn.textContent = 'Verify & Close';
                        showTaskToast(body.error || 'Failed to close task.');
                    }
                }).catch(function () {
                    verifyCloseBtn.disabled = false;
                    verifyCloseBtn.textContent = 'Verify & Close';
                });
            };
        }

        var confirmFormBtn = document.getElementById('taskConfirmFormBtn');
        if (confirmFormBtn) {
            confirmFormBtn.onclick = function () {
                var note = prompt('Optional: paste a link to your submission or add a short note.', '');
                if (note === null) return; // user cancelled
                confirmFormBtn.disabled = true;
                confirmFormBtn.textContent = 'Recording...';
                fetch('/api/tessa/tasks/' + task.id + '/confirm-form', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ note: note })
                }).then(function (r) { return r.json().then(function (b) { return { ok: r.ok && b && b.ok, body: b }; }); })
                .then(function (res) {
                    if (res.ok && res.body.task) {
                        showTaskToast(res.body.message || 'Form submission confirmed.', 'success');
                        render(res.body.task);
                        renderTasks();
                    } else {
                        confirmFormBtn.disabled = false;
                        confirmFormBtn.textContent = 'I have filled the form/sheet';
                        showTaskToast((res.body && res.body.error) || 'Failed to record submission.');
                    }
                }).catch(function () {
                    confirmFormBtn.disabled = false;
                    confirmFormBtn.textContent = 'I have filled the form/sheet';
                });
            };
        }

        var reopenBtn = document.getElementById('taskReopenBtn');
        var reopenBox = document.getElementById('taskReopenReasonBox');
        if (reopenBtn && reopenBox) {
            reopenBtn.onclick = function () {
                reopenBox.classList.remove('hidden');
                var ta = document.getElementById('taskReopenReason');
                if (ta) ta.focus();
            };
        }

        var reopenCancelBtn = document.getElementById('taskReopenCancelBtn');
        if (reopenCancelBtn && reopenBox) {
            reopenCancelBtn.onclick = function () {
                reopenBox.classList.add('hidden');
                var ta = document.getElementById('taskReopenReason');
                if (ta) ta.value = '';
            };
        }

        var reopenSubmitBtn = document.getElementById('taskReopenSubmitBtn');
        if (reopenSubmitBtn) {
            reopenSubmitBtn.onclick = function () {
                var ta = document.getElementById('taskReopenReason');
                var reason = ta ? ta.value.trim() : '';
                if (!reason) {
                    showTaskToast('Please enter a reason before reopening.');
                    if (ta) ta.focus();
                    return;
                }
                reopenSubmitBtn.disabled = true;
                reopenSubmitBtn.textContent = 'Reopening...';
                fetch('/api/tessa/tasks/' + task.id + '/reopen', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ reason: reason })
                }).then(function (r) { return r.json(); }).then(function (body) {
                    if (body.ok && body.task) {
                        showTaskToast(body.message || 'Task reopened.');
                        render(body.task);
                        renderTasks();
                    } else {
                        reopenSubmitBtn.disabled = false;
                        reopenSubmitBtn.textContent = 'Submit Reopen';
                        showTaskToast(body.error || 'Failed to reopen task.');
                    }
                }).catch(function () {
                    reopenSubmitBtn.disabled = false;
                    reopenSubmitBtn.textContent = 'Submit Reopen';
                });
            };
        }

        var deleteBtn = document.getElementById('taskDeleteBtn');
        if (deleteBtn) {
            deleteBtn.onclick = function () {
                if (!confirm('Are you sure you want to delete this task? This cannot be undone.')) return;
                deleteBtn.disabled = true;
                deleteBtn.textContent = 'Deleting...';
                fetch('/api/tessa/tasks/' + task.id, {
                    method: 'DELETE', credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (r) { return r.json(); }).then(function (body) {
                    if (body.ok) {
                        overlay.classList.add('hidden');
                        renderTasks();
                    } else {
                        deleteBtn.disabled = false;
                        deleteBtn.textContent = 'Delete Task';
                        showTaskToast(body.error || 'Failed to delete task.');
                    }
                }).catch(function () {
                    deleteBtn.disabled = false;
                    deleteBtn.textContent = 'Delete Task';
                });
            };
        }

        var reassignBtn = document.getElementById('taskReassignBtn');
        if (reassignBtn) {
            reassignBtn.onclick = function () {
                var existing = document.getElementById('taskReassignDropdown');
                if (existing) { existing.remove(); return; }
                var currentAssigneeId = task.assigned_to && task.assigned_to.id ? task.assigned_to.id : 0;
                var people = (config.MODAL_PEOPLE || []).filter(function (p) { return p.id !== currentAssigneeId; });
                var dropdown = document.createElement('div');
                dropdown.className = 'tthread-invite-dropdown';
                dropdown.id = 'taskReassignDropdown';
                dropdown.innerHTML =
                    '<div class="tthread-invite-search"><input type="text" placeholder="Search people..." id="taskReassignSearch" /></div>' +
                    '<div class="tthread-invite-list">' +
                    people.map(function (p) {
                        var initial = getInitials(p.name);
                        return '<div class="tthread-invite-item" data-uid="' + p.id + '" data-name="' + escapeHtml(p.name) + '">' +
                            '<span class="tthread-invite-avatar">' + initial + '</span>' +
                            '<span class="tthread-invite-name">' + escapeHtml(p.name) + '</span>' +
                        '</div>';
                    }).join('') +
                    '</div>';
                reassignBtn.parentElement.appendChild(dropdown);
                var searchInput = document.getElementById('taskReassignSearch');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.oninput = function () {
                        var q = searchInput.value.toLowerCase();
                        dropdown.querySelectorAll('.tthread-invite-item').forEach(function (item) {
                            var name = (item.getAttribute('data-name') || '').toLowerCase();
                            item.style.display = name.indexOf(q) >= 0 ? '' : 'none';
                        });
                    };
                }
                dropdown.querySelectorAll('.tthread-invite-item').forEach(function (item) {
                    item.onclick = function () {
                        var uid = parseInt(item.getAttribute('data-uid'), 10);
                        item.style.opacity = '0.4';
                        fetch('/api/tessa/tasks/' + task.id, {
                            method: 'PUT', credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            body: JSON.stringify({ assigned_to: uid })
                        }).then(function (r) { return r.json(); }).then(function (body) {
                            if (body.ok && body.task) {
                                dropdown.remove();
                                render(body.task);
                                renderTasks();
                            }
                        });
                    };
                });
                setTimeout(function () {
                    document.addEventListener('click', function closeReassign(e) {
                        if (!dropdown.contains(e.target) && e.target !== reassignBtn) {
                            dropdown.remove();
                            document.removeEventListener('click', closeReassign);
                        }
                    });
                }, 10);
            };
        }

        // Redirect — the assignee or shared assigner passes the task on (people + due date).
        var redirectBtn = document.getElementById('taskRedirectBtn');
        if (redirectBtn) {
            redirectBtn.onclick = function () {
                var existing = document.getElementById('taskRedirectDropdown');
                if (existing) { existing.remove(); return; }
                var currentAssigneeId = task.assigned_to && task.assigned_to.id ? task.assigned_to.id : 0;
                var people = (config.MODAL_PEOPLE || []).filter(function (p) { return p.id !== currentAssigneeId; });
                var dlVal = task.deadline ? task.deadline.slice(0, 16) : '';
                var dropdown = document.createElement('div');
                dropdown.className = 'tthread-invite-dropdown';
                dropdown.id = 'taskRedirectDropdown';
                dropdown.innerHTML =
                    '<div class="tthread-invite-search"><input type="text" placeholder="Search people..." id="taskRedirectSearch" /></div>' +
                    '<div class="tthread-invite-list">' +
                    people.map(function (p) {
                        return '<div class="tthread-invite-item" data-uid="' + p.id + '" data-name="' + escapeHtml(p.name) + '">' +
                            '<span class="tthread-invite-avatar">' + getInitials(p.name) + '</span>' +
                            '<span class="tthread-invite-name">' + escapeHtml(p.name) + '</span>' +
                        '</div>';
                    }).join('') +
                    '</div>' +
                    '<div class="tm-redirect-foot">' +
                        '<div class="tm-redirect-foot-label">Due date</div>' +
                        '<input type="datetime-local" id="taskRedirectDeadline" class="tm-redirect-date" value="' + dlVal + '">' +
                        '<button type="button" id="taskRedirectConfirm" class="tm-redirect-confirm" disabled>Pick someone to redirect to</button>' +
                    '</div>';
                redirectBtn.parentElement.appendChild(dropdown);
                var selectedUid = 0;
                var confirmBtn = document.getElementById('taskRedirectConfirm');
                var searchInput = document.getElementById('taskRedirectSearch');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.oninput = function () {
                        var q = searchInput.value.toLowerCase();
                        dropdown.querySelectorAll('.tthread-invite-item').forEach(function (item) {
                            var name = (item.getAttribute('data-name') || '').toLowerCase();
                            item.style.display = name.indexOf(q) >= 0 ? '' : 'none';
                        });
                    };
                }
                dropdown.querySelectorAll('.tthread-invite-item').forEach(function (item) {
                    item.onclick = function () {
                        selectedUid = parseInt(item.getAttribute('data-uid'), 10);
                        dropdown.querySelectorAll('.tthread-invite-item').forEach(function (i) { i.classList.remove('tthread-invite-item-sel'); });
                        item.classList.add('tthread-invite-item-sel');
                        confirmBtn.disabled = false;
                        confirmBtn.textContent = 'Redirect to ' + (item.getAttribute('data-name') || '');
                    };
                });
                confirmBtn.onclick = function () {
                    if (!selectedUid) return;
                    confirmBtn.disabled = true;
                    confirmBtn.textContent = 'Redirecting...';
                    var dl = document.getElementById('taskRedirectDeadline').value;
                    fetch('/api/tessa/tasks/' + task.id + '/redirect', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ assigned_to: selectedUid, deadline: dl || null })
                    }).then(function (r) { return r.json(); }).then(function (body) {
                        if (body.ok && body.task) {
                            dropdown.remove();
                            showTaskToast('Task redirected. Notifications sent.', 'success');
                            render(body.task);
                            renderTasks();
                        } else {
                            confirmBtn.disabled = false;
                            confirmBtn.textContent = 'Redirect';
                            showTaskToast(body.error || 'Failed to redirect task');
                        }
                    }).catch(function () {
                        confirmBtn.disabled = false;
                        confirmBtn.textContent = 'Redirect';
                        showTaskToast('Failed to redirect task');
                    });
                };
                setTimeout(function () {
                    document.addEventListener('click', function closeRedirect(e) {
                        if (!dropdown.contains(e.target) && e.target !== redirectBtn) {
                            dropdown.remove();
                            document.removeEventListener('click', closeRedirect);
                        }
                    });
                }, 10);
            };
        }

        // Editable deadline
        var deadlineValue = document.getElementById('taskDeadlineValue');
        var deadlinePicker = document.getElementById('taskDeadlinePicker');
        var deadlineDisplay = document.getElementById('taskDeadlineDisplay');
        var deadlineInput = document.getElementById('taskDeadlineInput');
        if (deadlineValue && deadlinePicker) {
            deadlineDisplay.onclick = function (e) {
                e.stopPropagation();
                deadlinePicker.classList.toggle('hidden');
            };
            deadlinePicker.onclick = function (e) { e.stopPropagation(); };

            // Quick-pick buttons
            deadlinePicker.querySelectorAll('.tm-dq-btn').forEach(function (btn) {
                btn.onclick = function () {
                    var offset = btn.getAttribute('data-offset');
                    var d = new Date();
                    if (offset === 'friday') {
                        var day = d.getDay();
                        var daysUntilFri = (5 - day + 7) % 7 || 7;
                        d.setDate(d.getDate() + daysUntilFri);
                    } else {
                        d.setDate(d.getDate() + parseInt(offset, 10));
                    }
                    d.setHours(18, 0, 0, 0);
                    var y = d.getFullYear();
                    var mo = String(d.getMonth() + 1).padStart(2, '0');
                    var dd = String(d.getDate()).padStart(2, '0');
                    deadlineInput.value = y + '-' + mo + '-' + dd + 'T18:00';
                };
            });

            var saveBtn = document.getElementById('taskDeadlineSave');
            if (saveBtn) {
                saveBtn.onclick = function () {
                    var val = deadlineInput.value;
                    if (!val) { showTaskToast('Please select a date'); return; }
                    saveBtn.disabled = true;
                    saveBtn.textContent = 'Saving...';
                    fetch('/api/tessa/tasks/' + task.id, {
                        method: 'PUT', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ deadline: val })
                    }).then(function (r) { return r.json(); }).then(function (body) {
                        if (body.ok && body.task) {
                            render(body.task);
                            renderTasks();
                            showTaskToast('Deadline updated');
                        } else {
                            saveBtn.disabled = false;
                            saveBtn.textContent = 'Save';
                            showTaskToast(body.error || 'Failed to update deadline');
                        }
                    }).catch(function () { saveBtn.disabled = false; saveBtn.textContent = 'Save'; });
                };
            }

            var cancelBtn = document.getElementById('taskDeadlineCancel');
            if (cancelBtn) {
                cancelBtn.onclick = function () { deadlinePicker.classList.add('hidden'); };
            }
        }

        // Nudge button
        var nudgeBtn = document.getElementById('taskNudgeBtn');
        if (nudgeBtn) {
            nudgeBtn.onclick = function () {
                nudgeBtn.disabled = true;
                nudgeBtn.textContent = 'Sending...';
                fetch('/api/tessa/tasks/' + task.id + '/nudge', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (r) { return r.json(); }).then(function (body) {
                    if (body.ok) {
                        nudgeBtn.textContent = 'Nudge Sent ✓';
                        nudgeBtn.style.borderColor = '#4ade80';
                        nudgeBtn.style.color = '#4ade80';
                        showTaskToast('Nudge sent via Slack', 'success');
                    } else {
                        nudgeBtn.disabled = false;
                        nudgeBtn.textContent = 'Nudge for Update';
                        showTaskToast(body.error || 'Failed to nudge');
                    }
                }).catch(function () {
                    nudgeBtn.disabled = false;
                    nudgeBtn.textContent = 'Nudge for Update';
                });
            };
        }

        // Escalate button
        var escalateBtn = document.getElementById('taskEscalateBtn');
        if (escalateBtn) {
            escalateBtn.onclick = function () {
                escalateBtn.disabled = true;
                escalateBtn.textContent = 'Escalating...';
                fetch('/api/tessa/tasks/' + task.id + '/escalate', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (r) { return r.json(); }).then(function (body) {
                    if (body.ok) {
                        escalateBtn.textContent = 'Escalated';
                        escalateBtn.style.borderColor = '#4ade80';
                        escalateBtn.style.color = '#4ade80';
                        showTaskToast('Escalated — reporter notified via Slack');
                    } else {
                        escalateBtn.disabled = false;
                        escalateBtn.textContent = 'Escalate to Reporter';
                        showTaskToast(body.error || 'Failed to escalate');
                    }
                }).catch(function () {
                    escalateBtn.disabled = false;
                    escalateBtn.textContent = 'Escalate to Reporter';
                });
            };
        }

        // Extend Deadline buttons
        var extendContainer = document.getElementById('taskExtendDeadline');
        if (extendContainer) {
            extendContainer.querySelectorAll('.task-extend-btn').forEach(function (btn) {
                btn.onclick = function () {
                    var days = parseInt(btn.getAttribute('data-days'), 10);
                    btn.disabled = true;
                    btn.textContent = 'Extending...';
                    fetch('/api/tessa/tasks/' + task.id + '/extend-deadline', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ days: days })
                    }).then(function (r) { return r.json(); }).then(function (body) {
                        if (body.ok && body.task) {
                            render(body.task);
                            renderTasks();
                            if (body.pending) {
                                showTaskToast('Extension request sent to reporter for approval', 'success');
                            } else {
                                showTaskToast('Deadline extended by ' + days + ' day(s). Reporter notified.', 'success');
                            }
                        } else {
                            btn.disabled = false;
                            btn.textContent = '+' + days + ' Day' + (days > 1 ? 's' : '');
                            showTaskToast(body.error || 'Failed to extend deadline');
                        }
                    }).catch(function () {
                        btn.disabled = false;
                        btn.textContent = '+' + days + ' Day' + (days > 1 ? 's' : '');
                    });
                };
            });
        }

        // Approve/Deny extension buttons (reporter only)
        var approveBtn = document.getElementById('taskApproveExtBtn');
        if (approveBtn) {
            approveBtn.onclick = function () {
                approveBtn.disabled = true;
                approveBtn.textContent = 'Approving...';
                fetch('/api/tessa/tasks/' + task.id + '/approve-extension', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (r) { return r.json(); }).then(function (body) {
                    if (body.ok && body.task) {
                        render(body.task);
                        renderTasks();
                        showTaskToast('Extension approved. Assignee notified.', 'success');
                    } else {
                        approveBtn.disabled = false;
                        approveBtn.textContent = 'Approve';
                        showTaskToast(body.error || 'Failed to approve');
                    }
                }).catch(function () { approveBtn.disabled = false; approveBtn.textContent = 'Approve'; });
            };
        }

        var denyBtn = document.getElementById('taskDenyExtBtn');
        if (denyBtn) {
            denyBtn.onclick = function () {
                denyBtn.disabled = true;
                denyBtn.textContent = 'Denying...';
                fetch('/api/tessa/tasks/' + task.id + '/deny-extension', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (r) { return r.json(); }).then(function (body) {
                    if (body.ok && body.task) {
                        render(body.task);
                        renderTasks();
                        showTaskToast('Extension denied. Assignee notified.', 'success');
                    } else {
                        denyBtn.disabled = false;
                        denyBtn.textContent = 'Deny';
                        showTaskToast(body.error || 'Failed to deny');
                    }
                }).catch(function () { denyBtn.disabled = false; denyBtn.textContent = 'Deny'; });
            };
        }
    }

    function buildProgressTimelineHtml() {
        return '<div class="task-modal-section-label">Progress Updates</div>' +
            '<div id="taskCheckinDays" class="task-checkin-days"></div>' +
            '<div id="taskCheckinTimeline" class="task-checkin-timeline"><div class="tthread-loading">Loading...</div></div>' +
            '<div class="task-checkin-form hidden" id="taskCheckinForm">' +
                '<div class="task-checkin-form-date" id="checkinFormDate"></div>' +
                '<div class="task-checkin-tessa" id="checkinTessaQ" style="display:none;">' +
                    '<span class="dash-tessa-avatar">T</span>' +
                    '<span id="checkinTessaText" class="task-checkin-tessa-text"></span>' +
                '</div>' +
                '<input type="hidden" id="checkinDateInput" value="">' +
                '<div class="task-checkin-form-row">' +
                    '<label>Health</label>' +
                    '<select id="checkinHealthInput"><option value="on_track">On Track</option><option value="at_risk">At Risk</option><option value="blocked">Blocked</option></select>' +
                '</div>' +
                '<div class="task-checkin-form-row">' +
                    '<label>Progress</label>' +
                    '<input type="range" min="0" max="100" step="10" value="0" id="checkinProgressInput">' +
                    '<span class="tm-progress-label" id="checkinProgressLabel">0%</span>' +
                '</div>' +
                '<textarea id="checkinNoteInput" placeholder="What did you work on?"></textarea>' +
                '<div class="task-checkin-actions"><button type="button" class="task-checkin-submit" id="checkinSubmitBtn">Submit</button></div>' +
            '</div>';
    }

    function loadCheckins(taskId, taskCreatedAt, taskDeadline) {
        var timeline = document.getElementById('taskCheckinTimeline');
        var daysContainer = document.getElementById('taskCheckinDays');
        if (!timeline) return;

        fetch('/api/tessa/tasks/' + taskId + '/checkins', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var checkins = data.checkins || [];

                // Build day pills from task creation date to deadline (or today if no deadline)
                if (daysContainer && taskCreatedAt) {
                    var start = new Date(taskCreatedAt);
                    start.setHours(0, 0, 0, 0);
                    var today = new Date();
                    today.setHours(0, 0, 0, 0);
                    var endDate = today;
                    if (taskDeadline) {
                        var dl = new Date(taskDeadline);
                        dl.setHours(0, 0, 0, 0);
                        endDate = dl < today ? dl : today;
                    }
                    var updatedDates = {};
                    var healthColors = { on_track: '#4ade80', at_risk: '#f59e0b', blocked: '#ef4444' };
                    checkins.forEach(function (c) {
                        if (c.created_at) {
                            var d = new Date(c.created_at);
                            var key = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
                            updatedDates[key] = c.health_status;
                        }
                    });

                    var days = [];
                    var d = new Date(start);
                    while (d <= endDate) {
                        var key = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
                        var dayLabel = d.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: 'numeric', month: 'short' });
                        var weekday = d.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', weekday: 'short' });
                        var hasUpdate = updatedDates[key];
                        var color = hasUpdate ? healthColors[hasUpdate] || '#4ade80' : '';
                        days.push({ key: key, label: dayLabel, weekday: weekday, done: !!hasUpdate, color: color });
                        d.setDate(d.getDate() + 1);
                    }
                    days.reverse(); // newest first

                    daysContainer.innerHTML = days.map(function (day) {
                        return '<button type="button" class="task-day-pill' + (day.done ? ' done' : ' pending') + '" data-date="' + day.key + '" title="' + day.weekday + ', ' + day.label + '"' + (day.done ? ' style="border-color:' + day.color + '"' : '') + '>' +
                            '<span class="task-day-weekday">' + day.weekday + '</span>' +
                            '<span class="task-day-date">' + day.label + '</span>' +
                            (day.done ? '<span class="task-day-check" style="color:' + day.color + '">&#10003;</span>' : '') +
                        '</button>';
                    }).join('');

                    var todayKey = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
                    var tessaQCache = {};

                    daysContainer.querySelectorAll('.task-day-pill.pending').forEach(function (pill) {
                        pill.onclick = function () {
                            var dateKey = pill.getAttribute('data-date');
                            var form = document.getElementById('taskCheckinForm');
                            var dateInput = document.getElementById('checkinDateInput');
                            var dateLabelEl = document.getElementById('checkinFormDate');
                            if (form && dateInput) {
                                dateInput.value = dateKey;
                                if (dateLabelEl) dateLabelEl.textContent = pill.getAttribute('title');
                                form.classList.remove('hidden');
                                daysContainer.querySelectorAll('.task-day-pill').forEach(function (p) { p.classList.remove('selected'); });
                                pill.classList.add('selected');

                                var noteInput = document.getElementById('checkinNoteInput');
                                if (noteInput) noteInput.value = '';

                                // Show Tessa AI question
                                var tessaQ = document.getElementById('checkinTessaQ');
                                var tessaText = document.getElementById('checkinTessaText');
                                if (tessaQ && tessaText) {
                                    tessaQ.style.display = 'flex';
                                    if (tessaQCache[dateKey]) {
                                        tessaText.innerHTML = tessaQCache[dateKey];
                                    } else {
                                        tessaText.innerHTML = '<em class="dash-tessa-thinking">Tessa is thinking...</em>';
                                        fetch('/api/tessa/tasks/' + taskId + '/checkin-question?date=' + encodeURIComponent(dateKey), {
                                            credentials: 'same-origin',
                                            headers: { 'Accept': 'application/json' }
                                        })
                                        .then(function (r) { return r.json(); })
                                        .then(function (data) {
                                            if (data.question) {
                                                tessaQCache[dateKey] = data.question;
                                                tessaText.textContent = data.question;
                                            } else {
                                                var isToday = (dateKey === todayKey);
                                                var fallback = isToday
                                                    ? (checkins.length > 0 ? 'Any update since your last check-in?' : "What's your progress so far?")
                                                    : 'What happened with this task on ' + pill.getAttribute('title') + '?';
                                                tessaQCache[dateKey] = fallback;
                                                tessaText.textContent = fallback;
                                            }
                                        })
                                        .catch(function (err) {
                                            console.error('Tessa checkin-question failed:', err);
                                            var isToday = (dateKey === todayKey);
                                            tessaText.textContent = isToday ? 'Any update since your last check-in?' : 'What happened on ' + pill.getAttribute('title') + '?';
                                        });
                                    }
                                }
                            }
                        };
                    });
                }
                if (!checkins.length) {
                    timeline.innerHTML = '<div class="task-checkin-empty">No updates yet</div>';
                    return;
                }
                var healthColors = { on_track: '#4ade80', at_risk: '#f59e0b', blocked: '#ef4444' };
                var healthLabels = { on_track: 'On Track', at_risk: 'At Risk', blocked: 'Blocked' };
                timeline.innerHTML = checkins.map(function (c) {
                    var color = healthColors[c.health_status] || '#52525b';
                    var dateStr = c.created_at ? new Date(c.created_at).toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', weekday: 'short', day: 'numeric', month: 'short' }) : '';
                    var healthLabel = healthLabels[c.health_status] || '';
                    return '<div class="task-checkin-entry" data-cid="' + c.id + '">' +
                        '<span class="task-checkin-dot" style="background:' + color + '"></span>' +
                        '<div class="task-checkin-content">' +
                            '<div class="task-checkin-header">' +
                                '<span class="task-checkin-date">' + escapeHtml(dateStr) + '</span>' +
                                '<span class="task-checkin-health-label" style="color:' + color + '">' + escapeHtml(healthLabel) + '</span>' +
                                '<span class="task-checkin-progress-badge">' + c.progress + '%</span>' +
                                '<button class="task-checkin-delete" title="Delete update">&times;</button>' +
                            '</div>' +
                            (c.note ? '<div class="task-checkin-note">' + escapeHtml(c.note) + '</div>' : '') +
                            '<span class="task-checkin-user">' + escapeHtml(c.user_name) + '</span>' +
                        '</div>' +
                    '</div>';
                }).join('');

                // Bind delete handlers
                timeline.querySelectorAll('.task-checkin-entry').forEach(function (entry) {
                    var cid = entry.getAttribute('data-cid');
                    var deleteBtn = entry.querySelector('.task-checkin-delete');
                    if (deleteBtn) {
                        deleteBtn.onclick = function () {
                            if (!confirm('Delete this progress update?')) return;
                            fetch('/api/tessa/tasks/' + taskId + '/checkins/' + cid, {
                                method: 'DELETE', credentials: 'same-origin',
                                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                            }).then(function () {
                                loadCheckins(taskId, taskCreatedAt, taskDeadline);
                                renderTasks();
                            });
                        };
                    }
                });
            });
    }

    function bindCheckinForm(taskId, render) {
        var form = document.getElementById('taskCheckinForm');
        var progressInput = document.getElementById('checkinProgressInput');
        var progressLabel = document.getElementById('checkinProgressLabel');
        var submitBtn = document.getElementById('checkinSubmitBtn');

        if (progressInput && progressLabel) {
            progressInput.oninput = function () {
                progressLabel.textContent = progressInput.value + '%';
            };
        }

        if (submitBtn) {
            submitBtn.onclick = function () {
                var health = document.getElementById('checkinHealthInput').value;
                var progress = parseInt(progressInput.value, 10);
                var note = (document.getElementById('checkinNoteInput').value || '').trim();
                var checkinDate = (document.getElementById('checkinDateInput') || {}).value || null;

                submitBtn.disabled = true;
                submitBtn.textContent = 'Submitting...';

                fetch('/api/tessa/tasks/' + taskId + '/checkins', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ health_status: health, progress: progress, note: note || null, checkin_date: checkinDate })
                }).then(function (r) { return r.json(); }).then(function (body) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit Update';
                    if (body.ok) {
                        form.classList.add('hidden');
                        document.getElementById('checkinNoteInput').value = '';
                        loadCheckins(taskId, window._currentTaskCreatedAt, window._currentTaskDeadline);
                        // Update sidebar controls
                        var healthSel = document.getElementById('taskHealthSelect');
                        if (healthSel) healthSel.value = health;
                        var rangeEl = document.getElementById('taskProgressRange');
                        var labelEl = document.getElementById('taskProgressLabel');
                        if (rangeEl) rangeEl.value = progress;
                        if (labelEl) labelEl.textContent = progress + '%';
                        renderTasks();
                    } else {
                        showTaskToast(body.error || 'Failed to submit update');
                    }
                }).catch(function () {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit Update';
                    showTaskToast('Failed to submit update');
                });
            };
        }
    }

    // ── Task Slide-over ──

    function buildSlideoverShellHtml() {
        return '<div class="cu-slideover-panel" id="cuSlideoverPanel">' +
            '<div class="cu-so-header">' +
                '<div class="cu-so-tabs"><span class="cu-so-tab cu-so-tab-active">Task</span></div>' +
                '<div class="cu-so-header-actions">' +
                    '<button type="button" class="cu-so-icon-btn" id="cuSlideoverExpand" title="Open full">' +
                        '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 13l10-10M13 3v4M13 3H9"/></svg>' +
                    '</button>' +
                    '<button type="button" class="cu-so-icon-btn cu-so-close" id="cuSlideoverClose" title="Close">' +
                        '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 3l10 10M13 3L3 13"/></svg>' +
                    '</button>' +
                '</div>' +
            '</div>' +
            '<div class="cu-so-body" id="cuSlideoverBody"></div>' +
        '</div>';
    }

    function priorityColor(p) {
        return ({ urgent: '#ef4444', high: '#f97316', medium: '#3b82f6', low: '#a1a1aa' })[p] || '#a1a1aa';
    }
    function priorityDisplay(p) {
        return ({ urgent: 'Urgent', high: 'High', medium: 'Normal', low: 'Low' })[p] || '';
    }
    function statusDisplay(s) {
        return ({ pending: 'TO DO', in_progress: 'IN PROGRESS', on_hold: 'ON HOLD', completed: 'COMPLETE', closed: 'CLOSED', cancelled: 'CANCELLED' })[s] || 'TO DO';
    }
    function statusPillColor(s) {
        return ({ pending: '#a1a1aa', in_progress: '#60a5fa', on_hold: '#eab308', completed: '#22c55e', closed: '#22c55e', cancelled: '#ef4444' })[s] || '#a1a1aa';
    }
    // Effective pill label/color: when health is set (on_track/at_risk/blocked),
    // show that on the status pill so the user sees what they picked. Otherwise
    // fall back to the underlying workflow status. The kanban column is still
    // determined by `task.status`, so the task doesn't move.
    function effectivePillLabel(task) {
        var h = task && task.blocker_status;
        if (h === 'on_track') return 'ON TRACK';
        if (h === 'at_risk')  return 'AT RISK';
        if (h === 'blocked')  return 'BLOCKED';
        return statusDisplay(task && task.status);
    }
    function effectivePillColor(task) {
        var h = task && task.blocker_status;
        if (h === 'on_track') return '#4ade80';
        if (h === 'at_risk')  return '#f59e0b';
        if (h === 'blocked')  return '#ef4444';
        return statusPillColor(task && task.status);
    }
    function relativeDate(iso) {
        if (!iso) return '';
        var d = new Date(iso);
        var today = new Date(); today.setHours(0, 0, 0, 0);
        var dDay = new Date(d); dDay.setHours(0, 0, 0, 0);
        var diff = Math.round((dDay - today) / 86400000);
        if (diff === 0) return 'Today';
        if (diff === 1) return 'Tomorrow';
        if (diff === -1) return 'Yesterday';
        return d.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: 'numeric', month: 'short' });
    }

    function buildPillRowHtml(task) {
        var statusColor = effectivePillColor(task);
        var statusText = effectivePillLabel(task);
        var assigneeName = (task.assigned_to && task.assigned_to.name) || '?';
        var assigneeInitials = getInitials(assigneeName);
        var dueText = task.deadline ? relativeDate(task.deadline) : 'Due date';
        var dueClass = task.deadline ? ' cu-pill-set' : '';
        var pColor = priorityColor(task.priority);
        var pText = task.priority ? priorityDisplay(task.priority) : 'Priority';
        var pSetClass = task.priority ? ' cu-pill-set' : '';

        return '<div class="cu-so-pills">' +
            '<button type="button" class="cu-pill cu-pill-status" id="cuSoStatusPill" style="--pill-color:' + statusColor + '">' +
                '<span class="cu-pill-dot" style="background:' + statusColor + '"></span>' +
                '<span class="cu-pill-label">' + escapeHtml(statusText) + '</span>' +
            '</button>' +
            '<button type="button" class="cu-pill cu-pill-assignee" id="cuSoAssigneePill" title="' + escapeHtml(assigneeName) + '">' +
                '<span class="cu-pill-avatar">' + escapeHtml(assigneeInitials) + '</span>' +
            '</button>' +
            '<button type="button" class="cu-pill cu-pill-due' + dueClass + '" id="cuSoDuePill">' +
                '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" class="cu-pill-icon"><rect x="2" y="3" width="12" height="11" rx="2"/><path d="M2 7h12M5 1v4M11 1v4"/></svg>' +
                '<span class="cu-pill-label">' + escapeHtml(dueText) + '</span>' +
            '</button>' +
            '<button type="button" class="cu-pill cu-pill-priority' + pSetClass + '" id="cuSoPriorityPill">' +
                '<span class="cu-flag" style="color:' + pColor + '">⚑</span>' +
                '<span class="cu-pill-label">' + escapeHtml(pText) + '</span>' +
            '</button>' +
            '<button type="button" class="cu-pill cu-pill-more" id="cuSoMorePill" title="More actions">' +
                '<svg viewBox="0 0 16 16" fill="currentColor"><circle cx="3" cy="8" r="1.4"/><circle cx="8" cy="8" r="1.4"/><circle cx="13" cy="8" r="1.4"/></svg>' +
            '</button>' +
        '</div>';
    }

    function buildSubtasksSectionHtml() {
        return '<div class="cu-so-section">' +
            '<div class="cu-so-section-title-row">' +
                '<span class="cu-so-section-title">Checklist <span class="cu-checklist-count" id="taskChecklistCount"></span></span>' +
            '</div>' +
            '<div class="cu-subtasks-add">' +
                '<input type="text" id="taskSubtaskInput" class="cu-subtask-input" placeholder="Add an item and press Enter">' +
                '<button type="button" id="taskSubtaskAddBtn" class="cu-subtask-add-btn">Add</button>' +
            '</div>' +
            '<div id="taskSubtasksItems" class="cu-subtasks-list"><div class="tthread-loading">Loading...</div></div>' +
        '</div>';
    }

    function buildAttachmentsSectionHtml() {
        return '<div class="cu-so-section" id="taskAttachments">' +
            '<div class="cu-so-section-title-row">' +
                '<span class="cu-so-section-title">Attachments</span>' +
                '<label class="cu-so-section-action" id="taskAttachUploadLabel">' +
                    '<input type="file" id="taskAttachFileInput" class="task-attach-file-input" multiple>' +
                    'Upload' +
                '</label>' +
            '</div>' +
            '<div class="cu-attach-hint">Drop a file or paste a screenshot (Ctrl/Cmd+V).</div>' +
            '<div class="task-attach-list" id="taskAttachList"><div class="tthread-loading">Loading...</div></div>' +
        '</div>';
    }

    function buildBlockersSectionHtml(task) {
        var canEdit = !!(task && task.assigned_to && task.assigned_to.id === (config.userId || 0));
        if (!canEdit) return '';
        return '<div class="cu-so-section">' +
            '<div class="cu-so-section-title-row">' +
                '<span class="cu-so-section-title">Is anything blocking your work?</span>' +
            '</div>' +
            '<div class="cu-blocker-add">' +
                '<input type="text" id="taskBlockerInput" class="cu-blocker-input" placeholder="Type what\'s blocking and press Enter" maxlength="500">' +
                '<button type="button" id="taskBlockerAddBtn" class="cu-blocker-add-btn">Add</button>' +
            '</div>' +
            '<div id="taskBlockersList" class="cu-blocker-list"></div>' +
        '</div>';
    }

    function buildActivitySectionHtml() {
        return '<div class="cu-so-section">' +
            '<div class="cu-so-section-title-row">' +
                '<span class="cu-so-section-title">Activity</span>' +
                '<button type="button" class="cu-so-section-action" id="tthreadInviteBtn">+ Invite</button>' +
            '</div>' +
            '<div class="tthread-participants" id="tthreadParticipants"></div>' +
            '<div class="tthread-messages" id="tthreadMessages"><div class="tthread-loading">Loading...</div></div>' +
            '<div class="tthread-compose">' +
                '<textarea class="tthread-input" id="tthreadInput" rows="1" placeholder="Add a comment..."></textarea>' +
                '<button class="tthread-send-btn" id="tthreadSendBtn"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg></button>' +
            '</div>' +
        '</div>';
    }

    function buildSlideoverEditBodyHtml(task) {
        var titleVal = task.title || '';
        var descVal = task.description || '';
        return '<textarea class="cu-so-title" id="cuSoTitle" rows="1" placeholder="Task Name" data-grammar-fix>' + escapeHtml(titleVal) + '</textarea>' +
            '<textarea class="cu-so-desc" id="cuSoDesc" placeholder="Add description" data-grammar-fix>' + escapeHtml(descVal) + '</textarea>' +
            '<div id="cuSoQuickLinks">' + quickLinksHtml(descVal) + '</div>' +
            '<button type="button" class="cu-so-ai-btn" id="cuSoAiBtn">' +
                '<span class="cu-ai-icon">✨</span>' +
                '<span>Write with AI</span>' +
            '</button>' +
            buildPillRowHtml(task) +
            '<div id="cuSoBanners">' + buildStateBannerHtml(task) + '</div>' +
            buildBlockersSectionHtml(task) +
            buildAttachmentsSectionHtml() +
            buildActivitySectionHtml() +
            buildSubtasksSectionHtml() +
            '<div class="cu-so-section">' +
                '<div class="cu-so-section-title">Progress Updates</div>' +
                '<div id="taskCheckinDays" class="task-checkin-days"></div>' +
                '<div id="taskCheckinTimeline" class="task-checkin-timeline"><div class="tthread-loading">Loading...</div></div>' +
                '<div class="task-checkin-form hidden" id="taskCheckinForm">' +
                    '<div class="task-checkin-form-date" id="checkinFormDate"></div>' +
                    '<div class="task-checkin-tessa" id="checkinTessaQ" style="display:none;">' +
                        '<span class="dash-tessa-avatar">T</span>' +
                        '<span id="checkinTessaText" class="task-checkin-tessa-text"></span>' +
                    '</div>' +
                    '<input type="hidden" id="checkinDateInput" value="">' +
                    '<div class="task-checkin-form-row"><label>Health</label>' +
                        '<select id="checkinHealthInput"><option value="on_track">On Track</option><option value="at_risk">At Risk</option><option value="blocked">Blocked</option></select></div>' +
                    '<div class="task-checkin-form-row"><label>Progress</label>' +
                        '<input type="range" min="0" max="100" step="10" value="0" id="checkinProgressInput">' +
                        '<span class="tm-progress-label" id="checkinProgressLabel">0%</span>' +
                    '</div>' +
                    '<textarea id="checkinNoteInput" placeholder="What did you work on?"></textarea>' +
                    '<div class="task-checkin-actions"><button type="button" class="task-checkin-submit" id="checkinSubmitBtn">Submit</button></div>' +
                '</div>' +
            '</div>' +
            '<div class="cu-so-section">' +
                '<div class="cu-so-section-title">Details</div>' +
                buildSidebarHtml(task) +
            '</div>';
    }

    function buildSlideoverNewBodyHtml() {
        var people = config.MODAL_PEOPLE || [];
        var team = config.TEAM_MEMBERS || [];
        var teamIds = {}; team.forEach(function (p) { teamIds[p.id] = true; });
        var others = people.filter(function (p) { return !teamIds[p.id]; });

        function chip(p) {
            return '<button type="button" class="cu-chip" data-id="' + p.id + '" data-name="' + escapeHtml(p.name) + '">' + escapeHtml(p.name) + '</button>';
        }
        // "Everyone" chip — assigns the same task to every active employee at once.
        // Use case: company-wide form fills, uploads, doc submissions.
        var everyoneChip = '<button type="button" class="cu-chip cu-chip-everyone" data-id="all" data-name="Everyone">👥 Everyone</button>';
        var chipHtml = '<div class="cu-chip-group"><div class="cu-chip-group-chips">' + everyoneChip + '</div></div>';
        if (team.length) {
            chipHtml += '<div class="cu-chip-group"><span class="cu-chip-group-label">My Team</span><div class="cu-chip-group-chips">' + team.map(chip).join('') + '</div></div>';
            if (others.length) {
                chipHtml += '<div class="cu-chip-group"><button type="button" class="cu-chip-others-toggle" id="cuNewOthersToggle">Show Others (' + others.length + ')</button><div class="cu-chip-group-chips hidden" id="cuNewOthersList">' + others.map(chip).join('') + '</div></div>';
            }
        } else {
            chipHtml += '<div class="cu-chip-group-chips">' + people.map(chip).join('') + '</div>';
        }

        return '<textarea class="cu-so-title" id="cuSoTitle" rows="1" placeholder="Task Name" autofocus data-grammar-fix></textarea>' +
            '<textarea class="cu-so-desc" id="cuSoDesc" placeholder="Add description" data-grammar-fix></textarea>' +
            '<div id="cuSoQuickLinks"></div>' +
            '<button type="button" class="cu-so-ai-btn" id="cuSoAiBtn">' +
                '<span class="cu-ai-icon">✨</span><span>Write with AI</span>' +
            '</button>' +
            '<div class="cu-so-section">' +
                '<div class="cu-so-section-title">Assign to</div>' +
                '<div class="cu-chips-wrap" id="cuNewChips">' + chipHtml + '</div>' +
            '</div>' +
            '<div class="cu-so-section">' +
                '<div class="cu-so-pills">' +
                    '<button type="button" class="cu-pill" id="cuNewDueBtn"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" class="cu-pill-icon"><rect x="2" y="3" width="12" height="11" rx="2"/><path d="M2 7h12M5 1v4M11 1v4"/></svg><span>Due date</span></button>' +
                    '<button type="button" class="cu-pill" id="cuNewPriorityBtn"><span class="cu-flag" style="color:#a1a1aa">⚑</span><span>Priority</span></button>' +
                '</div>' +
                '<input type="hidden" id="cuNewDueValue">' +
                '<input type="hidden" id="cuNewPriorityValue" value="medium">' +
            '</div>' +
            '<div class="cu-so-section task-modal-attachments" id="cuNewAttachZone">' +
                '<div class="task-attach-header">' +
                    '<span class="task-attach-title">Attachments <span class="task-attach-hint">— drop files here, or paste a screenshot (Ctrl/Cmd+V)</span></span>' +
                    '<label class="task-attach-upload-btn">' +
                        '<input type="file" id="cuNewAttachInput" class="task-attach-file-input" multiple>' +
                        'Upload' +
                    '</label>' +
                '</div>' +
                '<div class="task-attach-list" id="cuNewAttachList"></div>' +
            '</div>' +
            '<div class="cu-so-section" id="cuNewLinksZone">' +
                '<div class="cu-so-section-title">Links</div>' +
                '<div class="cu-link-add">' +
                    '<input type="text" id="cuNewLinkInput" class="cu-link-input" placeholder="Paste a URL (Google Form, Sheet, Drive, etc.)">' +
                    '<button type="button" id="cuNewLinkAddBtn" class="cu-link-add-btn">Add link</button>' +
                '</div>' +
                '<div class="cu-link-list" id="cuNewLinkList"></div>' +
            '</div>' +
            '<div class="cu-so-section" id="cuNewMandatoryZone">' +
                '<label class="cu-mand-toggle">' +
                    '<input type="checkbox" id="cuNewMandatory">' +
                    '<span class="cu-mand-toggle-label">Make this task mandatory</span>' +
                '</label>' +
                '<div class="cu-mand-hint">Incomplete mandatory tasks affect the assignee\'s KRA score.</div>' +
                '<div class="cu-mand-sub hidden" id="cuNewMandatorySub">' +
                    '<label class="cu-mand-toggle">' +
                        '<input type="checkbox" id="cuNewRequiresAttach">' +
                        '<span>Require document upload — assignee must attach a file before completing.</span>' +
                    '</label>' +
                    '<div class="cu-mand-row">' +
                        '<label class="cu-mand-label" for="cuNewFormUrl">Form/Sheet URL (optional)</label>' +
                        '<input type="url" id="cuNewFormUrl" placeholder="https://forms.google.com/... or sheet link" class="cu-mand-input">' +
                        '<div class="cu-mand-hint">If provided, assignee must confirm submission before completing.</div>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<div class="cu-so-footer">' +
                '<button type="button" class="cu-btn cu-btn-ghost" id="cuNewCancelBtn">Cancel</button>' +
                '<button type="button" class="cu-btn cu-btn-primary" id="cuNewSaveBtn">Create Task</button>' +
            '</div>';
    }

    function openTaskSlideover(taskOrId, prefill) {
        // Remove any prior slide-over
        var existing = document.getElementById('taskSlideoverOverlay');
        if (existing) existing.remove();

        var overlay = document.createElement('div');
        overlay.id = 'taskSlideoverOverlay';
        overlay.className = 'cu-slideover-overlay';
        overlay.innerHTML = buildSlideoverShellHtml();
        document.body.appendChild(overlay);
        requestAnimationFrame(function () { overlay.classList.add('cu-slideover-open'); });

        var body = overlay.querySelector('#cuSlideoverBody');

        function closeOverlay() {
            // Clear unread badge from any related cards/rows
            var openId = overlay.getAttribute('data-task-id');
            if (openId) {
                document.querySelectorAll('.tb-card[data-id="' + openId + '"], .cu-row[data-task-id="' + openId + '"]').forEach(function (el) {
                    var b = el.querySelector('.tg-card-badge, .cu-row-unread');
                    if (b) b.remove();
                });
            }
            overlay.classList.remove('cu-slideover-open');
            setTimeout(function () { overlay.remove(); }, 200);
        }

        overlay.querySelector('#cuSlideoverClose').onclick = closeOverlay;
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeOverlay();
        });
        function onKey(e) {
            if (e.key === 'Escape' && document.body.contains(overlay)) {
                closeOverlay();
                document.removeEventListener('keydown', onKey);
            }
        }
        document.addEventListener('keydown', onKey);

        // Paste handler for screenshots → upload as attachment when in edit mode
        overlay.addEventListener('paste', function (e) {
            var tid = overlay.getAttribute('data-task-id');
            if (!tid) return;
            var file = extractPastedImage(e);
            if (!file) return;
            e.preventDefault();
            uploadTaskAttachmentFile(tid, file, function () {
                loadAttachments(tid);
                showTaskToast('Screenshot uploaded', 'success');
            });
        });

        if (taskOrId === null || taskOrId === undefined) {
            renderSlideoverNewMode(body, overlay, closeOverlay, prefill || {});
        } else if (typeof taskOrId === 'object') {
            renderSlideoverEditMode(body, overlay, closeOverlay, taskOrId);
        } else {
            body.innerHTML = '<div class="cu-so-loading">Loading task...</div>';
            fetch('/api/tessa/tasks/' + taskOrId, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (data && data.task) {
                        renderSlideoverEditMode(body, overlay, closeOverlay, data.task);
                    } else {
                        body.innerHTML = '<div class="cu-so-error">Could not load this task.</div>';
                    }
                });
        }
    }

    function renderSlideoverEditMode(body, overlay, closeOverlay, task) {
        body.innerHTML = buildSlideoverEditBodyHtml(task);
        overlay.setAttribute('data-task-id', String(task.id));

        function rerender(updatedTask) {
            renderSlideoverEditMode(body, overlay, closeOverlay, updatedTask);
            if (window.TasksListView && window.TasksListView.updateTaskInList) {
                window.TasksListView.updateTaskInList(updatedTask);
            }
        }

        // Auto-save title/description on blur
        var titleEl = body.querySelector('#cuSoTitle');
        if (titleEl) {
            autoGrowTextarea(titleEl);
            titleEl.addEventListener('input', function () { autoGrowTextarea(titleEl); });
            titleEl.addEventListener('blur', function () {
                var newVal = titleEl.value.trim();
                if (newVal && newVal !== task.title) {
                    persistTaskField(task.id, { title: newVal }, rerender);
                }
            });
        }
        var descEl = body.querySelector('#cuSoDesc');
        var quickLinksEl = body.querySelector('#cuSoQuickLinks');
        function refreshQuickLinks() {
            if (quickLinksEl) quickLinksEl.innerHTML = quickLinksHtml(descEl ? descEl.value : '');
        }
        if (descEl) {
            autoGrowTextarea(descEl);
            descEl.addEventListener('input', function () { autoGrowTextarea(descEl); refreshQuickLinks(); });
            descEl.addEventListener('blur', function () {
                var newVal = descEl.value;
                if (newVal !== (task.description || '')) {
                    persistTaskField(task.id, { description: newVal }, rerender);
                }
            });
        }

        // Write with AI
        var aiBtn = body.querySelector('#cuSoAiBtn');
        if (aiBtn) {
            aiBtn.onclick = function () {
                var title = (titleEl && titleEl.value || '').trim();
                if (!title) { showTaskToast('Add a title first.'); return; }
                aiBtn.disabled = true;
                aiBtn.innerHTML = '<span class="cu-ai-icon">✨</span><span>Thinking...</span>';
                fetch('/api/tessa/tasks/ai-expand', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ title: title, description: descEl ? descEl.value : '' })
                }).then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
                .then(function (res) {
                    aiBtn.disabled = false;
                    aiBtn.innerHTML = '<span class="cu-ai-icon">✨</span><span>Write with AI</span>';
                    if (res.ok && res.body.description) {
                        if (descEl) {
                            descEl.value = res.body.description;
                            descEl.dispatchEvent(new Event('input'));
                        }
                        persistTaskField(task.id, { description: res.body.description }, rerender);
                    } else {
                        showTaskToast((res.body && res.body.error) || 'AI service is busy.');
                    }
                }).catch(function () {
                    aiBtn.disabled = false;
                    aiBtn.innerHTML = '<span class="cu-ai-icon">✨</span><span>Write with AI</span>';
                });
            };
        }

        // Pill row popovers
        var statusPill = body.querySelector('#cuSoStatusPill');
        if (statusPill) {
            statusPill.onclick = function (e) {
                e.stopPropagation();
                if (task.status === 'closed' || task.status === 'cancelled') {
                    showTaskToast('Task is ' + task.status + '. Reopen first.'); return;
                }
                if (task.status === 'completed') {
                    showTaskToast('Task is awaiting verification — use Verify & Close or Reopen.');
                    return;
                }
                window.TasksPopovers.openStatus(statusPill, task, rerender);
            };
        }
        var assigneePill = body.querySelector('#cuSoAssigneePill');
        if (assigneePill) {
            assigneePill.onclick = function (e) {
                e.stopPropagation();
                if (task.assigned_by && task.assigned_by.id !== (config.userId || 0)) {
                    showTaskToast('Only the reporter can reassign here — use the Redirect button in details to pass this task on.');
                    return;
                }
                window.TasksPopovers.openAssignee(assigneePill, task, rerender);
            };
        }
        var duePill = body.querySelector('#cuSoDuePill');
        if (duePill) {
            duePill.onclick = function (e) {
                e.stopPropagation();
                if (task.status === 'closed') return;
                var isReporter = task.assigned_by && task.assigned_by.id === (config.userId || 0);
                if (!isReporter) {
                    showTaskToast('Only the task creator can change the due date. Use Extend Deadline if you need more time.');
                    return;
                }
                window.TasksPopovers.openDate(duePill, task, rerender);
            };
        }
        var priorityPill = body.querySelector('#cuSoPriorityPill');
        if (priorityPill) {
            priorityPill.onclick = function (e) {
                e.stopPropagation();
                if (task.status === 'closed') return;
                window.TasksPopovers.openPriority(priorityPill, task, rerender);
            };
        }
        var morePill = body.querySelector('#cuSoMorePill');
        if (morePill) {
            morePill.onclick = function (e) {
                e.stopPropagation();
                openTaskMorePopover(morePill, task, rerender, closeOverlay);
            };
        }

        // Existing handlers (verify panel, sidebar details, action buttons, deadline picker, etc.)
        bindModalHandlers(task, overlay, rerender);
        bindCheckinForm(task.id, rerender);

        // Load secondary data
        window._currentTaskCreatedAt = task.created_at;
        window._currentTaskDeadline = task.deadline;
        loadCheckins(task.id, task.created_at, task.deadline);
        loadAttachments(task.id);
        loadSubtasks(task.id);
        loadTaskThread(task.id);
        loadBlockers(task);

        // Header expand button
        var expandBtn = overlay.querySelector('#cuSlideoverExpand');
        if (expandBtn) {
            expandBtn.onclick = function () {
                overlay.classList.toggle('cu-slideover-fullscreen');
            };
        }
    }

    function renderSlideoverNewMode(body, overlay, closeOverlay, prefill) {
        prefill = prefill || {};
        body.innerHTML = buildSlideoverNewBodyHtml();

        // ── Mandatory section: toggle visibility of sub-options when enabled.
        // Everyone-mode auto-enables mandatory because that's the typical use
        // case (company-wide form-fills / mandatory document uploads).
        var mandatoryCheckbox = body.querySelector('#cuNewMandatory');
        var mandatorySub = body.querySelector('#cuNewMandatorySub');
        function updateMandatoryVisibility() {
            if (!mandatoryCheckbox || !mandatorySub) return;
            mandatorySub.classList.toggle('hidden', !mandatoryCheckbox.checked);
        }
        if (mandatoryCheckbox) {
            mandatoryCheckbox.addEventListener('change', updateMandatoryVisibility);
        }

        var chipsContainer = body.querySelector('#cuNewChips');
        var selectedAssigneeId = 0;
        var selectedAssigneeName = '';
        var selectedIsEveryone = false;
        if (chipsContainer) {
            chipsContainer.querySelectorAll('.cu-chip').forEach(function (chip) {
                chip.onclick = function () {
                    chipsContainer.querySelectorAll('.cu-chip').forEach(function (c) { c.classList.remove('cu-chip-selected'); });
                    chip.classList.add('cu-chip-selected');
                    var dataId = chip.getAttribute('data-id');
                    if (dataId === 'all') {
                        selectedIsEveryone = true;
                        selectedAssigneeId = 0;
                        selectedAssigneeName = 'Everyone';
                        if (mandatoryCheckbox && !mandatoryCheckbox.checked) {
                            mandatoryCheckbox.checked = true;
                            updateMandatoryVisibility();
                        }
                    } else {
                        selectedIsEveryone = false;
                        selectedAssigneeId = parseInt(dataId, 10);
                        selectedAssigneeName = chip.getAttribute('data-name') || '';
                    }
                };
            });
            var othersToggle = chipsContainer.querySelector('#cuNewOthersToggle');
            if (othersToggle) {
                othersToggle.onclick = function () {
                    var list = chipsContainer.querySelector('#cuNewOthersList');
                    if (list) {
                        list.classList.toggle('hidden');
                        othersToggle.textContent = list.classList.contains('hidden')
                            ? 'Show Others (' + list.querySelectorAll('.cu-chip').length + ')'
                            : 'Hide Others';
                    }
                };
            }

            // Pre-select an assignee (JP AI command center passes assignee_id).
            // Reveal the Others list first if the target chip is hidden inside it,
            // then trigger the existing click handler so all selection state is set.
            if (prefill.assignee_id) {
                var targetChip = chipsContainer.querySelector('.cu-chip[data-id="' + prefill.assignee_id + '"]');
                if (targetChip) {
                    var hiddenList = targetChip.closest('#cuNewOthersList');
                    if (hiddenList && hiddenList.classList.contains('hidden') && othersToggle) othersToggle.click();
                    targetChip.click();
                }
            }
        }

        // Pre-fill the title (JP AI command center may pass one).
        if (prefill.title) {
            var titleEl = body.querySelector('#cuSoTitle');
            if (titleEl) titleEl.value = prefill.title;
        }

        // Due date popover for new task
        var dueValueEl = body.querySelector('#cuNewDueValue');
        var priorityValueEl = body.querySelector('#cuNewPriorityValue');
        var dueBtn = body.querySelector('#cuNewDueBtn');
        if (dueBtn) {
            // Pre-fill default: today + 3 days, so blank submissions still carry a deadline.
            var defaultIso = isoPlusDays(3);
            dueValueEl.value = defaultIso;
            var dueSpan = dueBtn.querySelector('span:not(.cu-pill-icon)');
            if (dueSpan) dueSpan.textContent = relativeDate(defaultIso);
            dueBtn.classList.add('cu-pill-set');
            dueBtn.onclick = function (e) {
                e.stopPropagation();
                openSimplePicker('date', dueBtn, function (val, label) {
                    dueValueEl.value = val || '';
                    var span = dueBtn.querySelector('span:not(.cu-pill-icon)');
                    if (span) span.textContent = label || 'Due date';
                    dueBtn.classList.toggle('cu-pill-set', !!val);
                });
            };
        }
        var priorityBtn = body.querySelector('#cuNewPriorityBtn');
        if (priorityBtn) {
            // Pre-fill default Normal so the pill matches the hidden value.
            var defaultPriority = priorityValueEl.value || 'medium';
            var prioritySpan = priorityBtn.querySelectorAll('span')[1];
            if (prioritySpan) prioritySpan.textContent = priorityDisplay(defaultPriority);
            var priorityFlag = priorityBtn.querySelector('.cu-flag');
            if (priorityFlag) priorityFlag.style.color = priorityColor(defaultPriority);
            priorityBtn.classList.add('cu-pill-set');
            priorityBtn.onclick = function (e) {
                e.stopPropagation();
                openSimplePicker('priority', priorityBtn, function (val, label) {
                    priorityValueEl.value = val;
                    var span = priorityBtn.querySelectorAll('span')[1];
                    if (span) span.textContent = label;
                    var flag = priorityBtn.querySelector('.cu-flag');
                    if (flag) flag.style.color = priorityColor(val);
                    priorityBtn.classList.add('cu-pill-set');
                });
            };
        }

        // AI expand for new mode
        var aiBtn = body.querySelector('#cuSoAiBtn');
        var titleEl = body.querySelector('#cuSoTitle');
        var descEl = body.querySelector('#cuSoDesc');
        if (titleEl) {
            autoGrowTextarea(titleEl);
            titleEl.addEventListener('input', function () { autoGrowTextarea(titleEl); });
        }
        if (descEl) {
            autoGrowTextarea(descEl);
            descEl.addEventListener('input', function () { autoGrowTextarea(descEl); });
        }
        if (aiBtn) {
            aiBtn.onclick = function () {
                var title = (titleEl && titleEl.value || '').trim();
                if (!title) { showTaskToast('Add a title first.'); return; }
                aiBtn.disabled = true;
                aiBtn.innerHTML = '<span class="cu-ai-icon">✨</span><span>Thinking...</span>';
                fetch('/api/tessa/tasks/ai-expand', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ title: title, description: descEl ? descEl.value : '' })
                }).then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
                .then(function (res) {
                    aiBtn.disabled = false;
                    aiBtn.innerHTML = '<span class="cu-ai-icon">✨</span><span>Write with AI</span>';
                    if (res.ok && res.body.description && descEl) {
                        descEl.value = res.body.description;
                        autoGrowTextarea(descEl);
                    } else {
                        showTaskToast((res.body && res.body.error) || 'AI service is busy.');
                    }
                }).catch(function () {
                    aiBtn.disabled = false;
                    aiBtn.innerHTML = '<span class="cu-ai-icon">✨</span><span>Write with AI</span>';
                });
            };
        }

        // Link staging — URLs added before the task exists are appended to the
        // description on save so they show up as part of the task body.
        var stagedLinks = [];
        var linkListEl = body.querySelector('#cuNewLinkList');
        var linkInput = body.querySelector('#cuNewLinkInput');
        var linkAddBtn = body.querySelector('#cuNewLinkAddBtn');
        function renderStagedLinks() {
            if (!linkListEl) return;
            if (!stagedLinks.length) { linkListEl.innerHTML = ''; return; }
            linkListEl.innerHTML = stagedLinks.map(function (url, i) {
                return '<div class="cu-link-chip">' +
                    '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener">🔗 ' + escapeHtml(url) + '</a>' +
                    '<button type="button" class="cu-link-remove" data-i="' + i + '" title="Remove">&times;</button>' +
                    '</div>';
            }).join('');
            linkListEl.querySelectorAll('.cu-link-remove').forEach(function (btn) {
                btn.onclick = function () {
                    var idx = parseInt(btn.getAttribute('data-i'), 10);
                    stagedLinks.splice(idx, 1);
                    renderStagedLinks();
                };
            });
        }
        function addLink() {
            if (!linkInput) return;
            var url = (linkInput.value || '').trim();
            if (!url) return;
            if (!/^https?:\/\//i.test(url)) url = 'https://' + url;
            stagedLinks.push(url);
            linkInput.value = '';
            renderStagedLinks();
        }
        if (linkAddBtn) linkAddBtn.onclick = addLink;
        if (linkInput) {
            linkInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); addLink(); }
            });
        }

        // Attachment staging — files picked/pasted before the task exists
        // are held client-side and uploaded after task creation.
        var stagedFiles = [];
        var stagedListEl = body.querySelector('#cuNewAttachList');
        var attachInput = body.querySelector('#cuNewAttachInput');

        function renderStaged() {
            if (!stagedListEl) return;
            if (!stagedFiles.length) { stagedListEl.innerHTML = ''; return; }
            stagedListEl.innerHTML = stagedFiles.map(function (f, idx) {
                var isImg = f.type && f.type.indexOf('image/') === 0;
                var thumb = isImg && f._previewUrl
                    ? '<img src="' + f._previewUrl + '" class="task-attach-preview" alt="">'
                    : '<span class="task-attach-icon">' + getFileIcon(f.type) + '</span>';
                return '<div class="task-attach-item" data-idx="' + idx + '">' +
                    '<div class="task-attach-thumb">' + thumb + '</div>' +
                    '<div class="task-attach-info">' +
                        '<span class="task-attach-name">' + escapeHtml(f.name) + '</span>' +
                        '<span class="task-attach-meta">' + escapeHtml(humanFileSize(f.size)) + ' &middot; pending upload</span>' +
                    '</div>' +
                    '<button class="task-attach-delete" title="Remove">&times;</button>' +
                '</div>';
            }).join('');
            stagedListEl.querySelectorAll('.task-attach-delete').forEach(function (btn) {
                btn.onclick = function () {
                    var idx = parseInt(btn.closest('.task-attach-item').getAttribute('data-idx'), 10);
                    var removed = stagedFiles.splice(idx, 1)[0];
                    if (removed && removed._previewUrl) URL.revokeObjectURL(removed._previewUrl);
                    renderStaged();
                };
            });
        }

        function stageFile(file) {
            if (!file) return;
            if (file.size > 10 * 1024 * 1024) {
                showTaskToast(file.name + ' exceeds 10MB limit');
                return;
            }
            if (file.type && file.type.indexOf('image/') === 0) {
                try { file._previewUrl = URL.createObjectURL(file); } catch (e) { /* ignore */ }
            }
            stagedFiles.push(file);
            renderStaged();
        }

        if (attachInput) {
            attachInput.onchange = function () {
                Array.from(attachInput.files || []).forEach(stageFile);
                attachInput.value = '';
            };
        }

        // Drag-and-drop: highlight zone, stage dropped files.
        var dropZone = body.querySelector('#cuNewAttachZone');
        if (dropZone) wireFileDropZone(dropZone, function (files) { files.forEach(stageFile); });

        // Paste handler for screenshots in the new-task form. The shared
        // overlay-level paste handler bails when there's no data-task-id,
        // so we stage the file locally here.
        body.addEventListener('paste', function (e) {
            if (overlay.getAttribute('data-task-id')) return; // edit mode handles it
            var file = extractPastedImage(e);
            if (!file) return;
            e.preventDefault();
            stageFile(file);
            showTaskToast('Screenshot added — saved when you click Create Task.', 'success');
        });

        // Save / Cancel
        body.querySelector('#cuNewCancelBtn').onclick = function () {
            stagedFiles.forEach(function (f) { if (f._previewUrl) URL.revokeObjectURL(f._previewUrl); });
            closeOverlay();
        };
        body.querySelector('#cuNewSaveBtn').onclick = function () {
            var title = (titleEl && titleEl.value || '').trim();
            if (!title) { showTaskToast('Please enter a task title.'); return; }
            if (!selectedIsEveryone && !selectedAssigneeId) { showTaskToast('Please select an assignee.'); return; }
            if (selectedIsEveryone && !confirm('This will create the same task for every active employee. Continue?')) return;
            if (selectedIsEveryone && stagedFiles.length) {
                showTaskToast('Attachments are not supported when assigning to Everyone — paste links in the description instead.');
                return;
            }
            var saveBtn = body.querySelector('#cuNewSaveBtn');
            saveBtn.disabled = true;
            saveBtn.textContent = selectedIsEveryone ? 'Assigning to everyone…' : 'Creating...';

            // Defaults are pre-filled in the UI but guard here too so a stray
            // clear can never produce an empty submission.
            var deadline = dueValueEl.value || isoPlusDays(3);
            var priority = priorityValueEl.value || 'medium';
            var payload = {
                title: title,
                priority: priority,
                deadline: deadline
            };
            if (selectedIsEveryone) {
                payload.assign_to_all = true;
            } else {
                payload.assigned_to = selectedAssigneeId;
            }
            var desc = (descEl && descEl.value || '').trim();
            // Append staged links to the description so assignees see them as
            // clickable URLs in the body of the task.
            if (stagedLinks.length) {
                var linksBlock = stagedLinks.join('\n');
                desc = desc ? (desc + '\n\nLinks:\n' + linksBlock) : ('Links:\n' + linksBlock);
            }
            if (desc) payload.description = desc;

            // Mandatory-completion fields. Only send the gate fields when
            // mandatory is on — keeps the payload tight and matches the
            // server-side scrub for non-mandatory tasks.
            var mandEl = body.querySelector('#cuNewMandatory');
            if (mandEl && mandEl.checked) {
                payload.is_mandatory = true;
                var reqAttachEl = body.querySelector('#cuNewRequiresAttach');
                if (reqAttachEl && reqAttachEl.checked) payload.requires_attachment = true;
                var formUrlEl = body.querySelector('#cuNewFormUrl');
                var formUrlVal = (formUrlEl && formUrlEl.value || '').trim();
                if (formUrlVal) payload.requires_form_url = formUrlVal;
            }

            fetch('/api/tessa/tasks', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(payload)
            }).then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
            .then(function (res) {
                if (res.ok && res.body.count) {
                    showTaskToast('Task assigned to ' + res.body.count + ' employees.', 'success');
                    closeOverlay();
                    renderTasks();
                    return;
                }
                if (res.ok && res.body.task) {
                    var t = res.body.task;
                    var titleShown = (t.title || title);
                    if (titleShown.length > 48) titleShown = titleShown.slice(0, 45) + '…';
                    var dueLabel = relativeDate(t.deadline || deadline);
                    var priorityLabel = priorityDisplay(t.priority || priority) || 'Normal';

                    var afterUploads = function (uploaded, failed) {
                        var summary = 'Task created: "' + titleShown + '" • Due ' + dueLabel + ' • ' + priorityLabel + ' priority';
                        if (uploaded) summary += ' • ' + uploaded + ' attachment' + (uploaded === 1 ? '' : 's');
                        showTaskToast(summary, 'success');
                        if (failed) showTaskToast(failed + ' attachment' + (failed === 1 ? '' : 's') + ' failed to upload — open the task to retry.');
                        stagedFiles.forEach(function (f) { if (f._previewUrl) URL.revokeObjectURL(f._previewUrl); });
                        closeOverlay();
                        renderTasks();
                    };

                    if (!stagedFiles.length) {
                        afterUploads(0, 0);
                        return;
                    }

                    saveBtn.textContent = 'Uploading 0/' + stagedFiles.length + '…';
                    var uploaded = 0, failed = 0, done = 0;
                    stagedFiles.forEach(function (file) {
                        var fd = new FormData();
                        fd.append('file', file);
                        fetch('/api/tessa/tasks/' + t.id + '/attachments', {
                            method: 'POST', credentials: 'same-origin',
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            body: fd
                        })
                        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok && b && b.ok, body: b }; }); })
                        .then(function (uploadRes) {
                            if (uploadRes.ok) uploaded++; else failed++;
                        })
                        .catch(function () { failed++; })
                        .then(function () {
                            done++;
                            saveBtn.textContent = 'Uploading ' + done + '/' + stagedFiles.length + '…';
                            if (done === stagedFiles.length) afterUploads(uploaded, failed);
                        });
                    });
                } else {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Create Task';
                    showTaskToast((res.body && res.body.error) || 'Failed to create task.');
                }
            }).catch(function () {
                saveBtn.disabled = false;
                saveBtn.textContent = 'Create Task';
                showTaskToast('Failed to create task.');
            });
        };
    }

    function persistTaskField(taskId, payload, onUpdated) {
        fetch('/api/tessa/tasks/' + taskId, {
            method: 'PUT', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json().then(function (b) { return { ok: r.ok && b && b.ok, body: b }; }); })
        .then(function (res) {
            if (res.ok && res.body.task && onUpdated) onUpdated(res.body.task);
            else if (!res.ok) showTaskToast((res.body && res.body.error) || 'Failed to save');
        }).catch(function () { showTaskToast('Failed to save'); });
    }

    function openSimplePicker(kind, anchor, onPick) {
        // Lightweight wrapper around the popover library for the new-task form.
        var fakeTask = { id: 0, priority: 'medium', deadline: null, status: 'pending', assigned_to: null, assigned_by: null };
        if (kind === 'priority') {
            // Use popover but don't persist — intercept via custom handler
            window.TasksPopovers.openPriority(anchor, fakeTask, function () {});
            // Override the popover's persist by patching click handlers.
            setTimeout(function () {
                document.querySelectorAll('.cu-pop-priority [data-value]').forEach(function (btn) {
                    btn.onclick = function () {
                        var val = btn.getAttribute('data-value') || 'medium';
                        var label = priorityDisplay(val) || 'Priority';
                        document.querySelectorAll('.cu-pop').forEach(function (el) { el.remove(); });
                        onPick(val, label);
                    };
                });
            }, 0);
        } else if (kind === 'date') {
            window.TasksPopovers.openDate(anchor, fakeTask, function () {});
            setTimeout(function () {
                // Replace cell click handlers
                document.querySelectorAll('.cu-pop-date [data-cal-date]').forEach(function (cell) {
                    cell.onclick = function () {
                        var ymd = cell.getAttribute('data-cal-date');
                        document.querySelectorAll('.cu-pop').forEach(function (el) { el.remove(); });
                        onPick(ymd + 'T18:00', new Date(ymd).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }));
                    };
                });
                document.querySelectorAll('.cu-pop-date [data-preset]').forEach(function (btn) {
                    btn.onclick = function () {
                        var name = btn.getAttribute('data-preset');
                        if (name === 'recurring') return;
                        var d = new Date(); d.setHours(18, 0, 0, 0);
                        var dow = d.getDay();
                        if (name === 'today') {} else if (name === 'later') { d.setHours(d.getHours() + 3); }
                        else if (name === 'tomorrow') { d.setDate(d.getDate() + 1); }
                        else if (name === 'this_weekend') { var ts = (6 - dow + 7) % 7 || 7; d.setDate(d.getDate() + ts); }
                        else if (name === 'next_week') { var tm = ((1 - dow + 7) % 7) || 7; d.setDate(d.getDate() + tm); d.setHours(9, 0, 0, 0); }
                        else if (name === 'next_weekend') { var ts2 = (6 - dow + 7) % 7 || 7; d.setDate(d.getDate() + ts2 + 7); }
                        else if (name === '2_weeks') { d.setDate(d.getDate() + 14); }
                        else if (name === '4_weeks') { d.setDate(d.getDate() + 28); }
                        document.querySelectorAll('.cu-pop').forEach(function (el) { el.remove(); });
                        var iso = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0') +
                            'T' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
                        var label = d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
                        onPick(iso, label);
                    };
                });
            }, 0);
        }
    }

    function openTaskMorePopover(anchor, task, onUpdated, closeOverlay) {
        document.querySelectorAll('.cu-pop').forEach(function (el) { el.remove(); });
        var isOwner = task.assigned_by && task.assigned_by.id === (config.userId || 0);
        var isAssignee = task.assigned_to && task.assigned_to.id === (config.userId || 0);
        var isActive = task.status !== 'completed' && task.status !== 'closed' && task.status !== 'cancelled';
        var isOverdue = task.deadline && new Date(task.deadline) < new Date() && isActive;

        var items = [];
        if (isActive && isOwner) items.push({ key: 'nudge', label: 'Nudge for update' });
        if (isOverdue) items.push({ key: 'escalate', label: 'Escalate to reporter' });
        if (isActive && isAssignee && task.deadline && !task.pending_extension_days) {
            items.push({ key: 'extend1', label: 'Extend deadline +1 day' });
            items.push({ key: 'extend2', label: 'Extend deadline +2 days' });
        }
        if (isOwner) items.push({ key: 'delete', label: 'Delete task', danger: true });
        if (!items.length) items.push({ key: 'nothing', label: 'No actions available', disabled: true });

        var pop = document.createElement('div');
        pop.className = 'cu-pop cu-pop-more';
        pop.innerHTML = items.map(function (it) {
            var cls = 'cu-pop-row' + (it.danger ? ' cu-pop-row-danger' : '') + (it.disabled ? ' cu-pop-row-disabled' : '');
            return '<button type="button" class="' + cls + '" data-key="' + it.key + '"' + (it.disabled ? ' disabled' : '') + '>' +
                '<span class="cu-pop-row-label">' + escapeHtml(it.label) + '</span></button>';
        }).join('');
        document.body.appendChild(pop);
        var ar = anchor.getBoundingClientRect();
        pop.style.left = Math.max(8, ar.right - pop.offsetWidth) + 'px';
        pop.style.top = (ar.bottom + 4) + 'px';

        setTimeout(function () {
            document.addEventListener('click', function close(e) {
                if (!pop.contains(e.target)) { pop.remove(); document.removeEventListener('click', close); }
            });
        }, 0);

        pop.querySelectorAll('[data-key]').forEach(function (btn) {
            if (btn.disabled) return;
            btn.onclick = function () {
                var key = btn.getAttribute('data-key');
                pop.remove();
                if (key === 'nudge') {
                    fetch('/api/tessa/tasks/' + task.id + '/nudge', { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (r) { return r.json(); }).then(function (b) { showTaskToast(b.message || (b.error || 'Done'), b.ok ? 'success' : 'error'); });
                } else if (key === 'escalate') {
                    fetch('/api/tessa/tasks/' + task.id + '/escalate', { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (r) { return r.json(); }).then(function (b) { showTaskToast(b.message || (b.error || 'Done'), b.ok ? 'success' : 'error'); });
                } else if (key === 'extend1' || key === 'extend2') {
                    var days = key === 'extend1' ? 1 : 2;
                    fetch('/api/tessa/tasks/' + task.id + '/extend-deadline', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ days: days })
                    }).then(function (r) { return r.json(); }).then(function (body) {
                        if (body.ok && body.task) {
                            showTaskToast(body.message || 'Deadline updated', 'success');
                            if (onUpdated) onUpdated(body.task);
                        } else {
                            showTaskToast(body.error || 'Failed to extend');
                        }
                    });
                } else if (key === 'delete') {
                    if (!confirm('Delete this task? This cannot be undone.')) return;
                    fetch('/api/tessa/tasks/' + task.id, { method: 'DELETE', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (r) { return r.json(); }).then(function (body) {
                            if (body.ok) {
                                if (closeOverlay) closeOverlay();
                                renderTasks();
                            } else {
                                showTaskToast(body.error || 'Failed to delete');
                            }
                        });
                }
            };
        });
    }

    function blockerAgo(iso) {
        var t = new Date(iso).getTime();
        if (isNaN(t)) return '';
        var diff = Math.floor((Date.now() - t) / 1000);
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 86400 * 7) return Math.floor(diff / 86400) + 'd ago';
        return new Date(iso).toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: 'numeric', month: 'short' });
    }

    function loadBlockers(task) {
        var listEl = document.getElementById('taskBlockersList');
        var input = document.getElementById('taskBlockerInput');
        var addBtn = document.getElementById('taskBlockerAddBtn');
        if (!listEl) return;

        var canEdit = !!(task && task.assigned_to && task.assigned_to.id === (config.userId || 0));

        function renderList(items) {
            if (!items || !items.length) {
                listEl.innerHTML = '<div class="cu-blocker-empty">No blockers — you\'re clear to keep going.</div>';
                return;
            }
            listEl.innerHTML = items.map(function (b) {
                var who = (b.created_by && b.created_by.name) ? b.created_by.name : '';
                var when = b.created_at ? blockerAgo(b.created_at) : '';
                var meta = [who, when].filter(Boolean).join(' · ');
                return '<div class="cu-blocker-item" data-id="' + b.id + '">' +
                    '<div class="cu-blocker-body">' +
                        '<div class="cu-blocker-note">' + escapeHtml(b.note) + '</div>' +
                        (meta ? '<div class="cu-blocker-meta">' + escapeHtml(meta) + '</div>' : '') +
                    '</div>' +
                    (canEdit ? '<button type="button" class="cu-blocker-remove" title="Remove">×</button>' : '') +
                '</div>';
            }).join('');
            if (canEdit) {
                listEl.querySelectorAll('.cu-blocker-remove').forEach(function (btn) {
                    btn.onclick = function () {
                        var id = parseInt(btn.parentElement.getAttribute('data-id'), 10);
                        removeBlocker(task.id, id);
                    };
                });
            }
        }

        function refresh() {
            fetch('/api/tessa/tasks/' + task.id + '/blockers', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            }).then(function (r) { return r.json(); })
              .then(function (data) { renderList(data.blockers || []); })
              .catch(function () { listEl.innerHTML = '<div class="cu-blocker-empty">Failed to load.</div>'; });
        }

        function addBlocker() {
            if (!input) return;
            var note = (input.value || '').trim();
            if (!note) return;
            input.disabled = true;
            if (addBtn) addBtn.disabled = true;
            fetch('/api/tessa/tasks/' + task.id + '/blockers', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ note: note }),
            }).then(function (r) {
                return r.json().then(function (b) { return { ok: r.ok, body: b }; });
            }).then(function (res) {
                if (res.ok) {
                    input.value = '';
                    refresh();
                } else {
                    showTaskToast((res.body && res.body.error) || 'Could not add blocker.');
                }
            }).catch(function () {
                showTaskToast('Could not add blocker.');
            }).finally(function () {
                input.disabled = false;
                if (addBtn) addBtn.disabled = false;
                input.focus();
            });
        }

        function removeBlocker(taskId, blockerId) {
            fetch('/api/tessa/tasks/' + taskId + '/blockers/' + blockerId, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            }).then(function () { refresh(); }).catch(function () {});
        }

        if (canEdit) {
            if (addBtn) addBtn.onclick = addBlocker;
            if (input) {
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        addBlocker();
                    }
                });
            }
        }

        renderList(task.blockers || []);
        // Refresh from server to pick up creator names + timestamps
        refresh();
    }

    function openDependenciesModal(task, onChange) {
        document.querySelectorAll('.cu-dep-modal-backdrop').forEach(function (el) { el.remove(); });

        var backdrop = document.createElement('div');
        backdrop.className = 'cu-dep-modal-backdrop';
        backdrop.innerHTML =
            '<div class="cu-dep-modal" role="dialog" aria-label="Dependencies">' +
                '<button type="button" class="cu-dep-modal-close" aria-label="Close">×</button>' +
                '<div class="cu-dep-modal-title">Dependencies</div>' +
                '<div class="cu-dep-modal-sub">See what this task depends on and what depends on it.</div>' +
                buildDepSectionMarkup('waiting',  '⚠', 'Waiting On',  'Add waiting on task') +
                buildDepSectionMarkup('blocking', '⊘', 'Blocking',    'Add task that is blocked') +
                buildDepSectionMarkup('linked',   '↔', 'Linked',      'Add linked task') +
            '</div>';
        document.body.appendChild(backdrop);

        backdrop.addEventListener('click', function (e) {
            if (e.target === backdrop) closeModal();
        });
        backdrop.querySelector('.cu-dep-modal-close').onclick = closeModal;

        wireSection('waiting',  'dependencies', 'dependency_ids');
        wireSection('blocking', 'dependents',   'blocking_ids');
        wireSection('linked',   'linked',       'link_ids');

        function closeModal() {
            backdrop.remove();
        }

        function buildDepSectionMarkup(key, icon, label, addPrompt) {
            return '<div class="cu-dep-modal-section" data-section="' + key + '">' +
                '<div class="cu-dep-modal-section-head cu-dep-modal-' + key + '">' +
                    '<span class="cu-dep-modal-icon">' + icon + '</span>' +
                    '<span class="cu-dep-modal-label">' + label + '</span>' +
                '</div>' +
                '<div class="cu-dep-modal-list" data-list></div>' +
                '<button type="button" class="cu-dep-modal-add" data-add>+ ' + addPrompt + '</button>' +
                '<div class="cu-dep-modal-picker" data-picker style="display:none;">' +
                    '<input type="text" class="cu-dep-modal-search" placeholder="Search tasks..." />' +
                    '<div class="cu-dep-modal-results" data-results></div>' +
                '</div>' +
            '</div>';
        }

        function wireSection(key, taskField, postField) {
            var sec = backdrop.querySelector('[data-section="' + key + '"]');
            var listEl = sec.querySelector('[data-list]');
            var addBtn = sec.querySelector('[data-add]');
            var pickerEl = sec.querySelector('[data-picker]');
            var searchInput = sec.querySelector('.cu-dep-modal-search');
            var resultsEl = sec.querySelector('[data-results]');

            renderItems();

            addBtn.onclick = function () {
                pickerEl.style.display = 'block';
                addBtn.style.display = 'none';
                searchInput.value = '';
                searchInput.focus();
                runSearch('');
            };

            var t = null;
            searchInput.addEventListener('input', function () {
                clearTimeout(t);
                t = setTimeout(function () { runSearch(searchInput.value); }, 200);
            });
            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { closePicker(); }
            });

            function closePicker() {
                pickerEl.style.display = 'none';
                addBtn.style.display = '';
            }

            function renderItems() {
                var items = task[taskField] || [];
                if (!items.length) {
                    listEl.innerHTML = '';
                    return;
                }
                listEl.innerHTML = items.map(function (d) {
                    return '<div class="cu-dep-modal-item" data-id="' + d.id + '">' +
                        '<span class="cu-dep-status cu-dep-status-' + d.status + '"></span>' +
                        '<span class="cu-dep-modal-item-title">' + escapeHtml(d.title) + '</span>' +
                        '<button type="button" class="cu-dep-modal-remove" title="Remove">×</button>' +
                    '</div>';
                }).join('');
                listEl.querySelectorAll('.cu-dep-modal-remove').forEach(function (btn) {
                    btn.onclick = function () {
                        var id = parseInt(btn.parentElement.getAttribute('data-id'), 10);
                        persistChange(id, false);
                    };
                });
            }

            function runSearch(query) {
                resultsEl.innerHTML = '<div class="tthread-loading">Loading...</div>';
                var excludeIds = collectAllRelatedIds().concat([task.id]);
                var qs = 'exclude=' + task.id +
                    excludeIds.map(function (id) { return '&exclude_ids[]=' + id; }).join('') +
                    '&search=' + encodeURIComponent(query || '');
                fetch('/api/tessa/tasks/dependencies-options?' + qs, {
                    credentials: 'same-origin', headers: { 'Accept': 'application/json' }
                }).then(function (r) { return r.json(); }).then(function (data) {
                    var opts = data.options || [];
                    if (!opts.length) {
                        resultsEl.innerHTML = '<div class="cu-dep-empty">No matching tasks.</div>';
                        return;
                    }
                    resultsEl.innerHTML = opts.map(function (o) {
                        return '<button type="button" class="cu-dep-modal-result" data-id="' + o.id + '">' +
                            '<span class="cu-dep-status cu-dep-status-' + o.status + '"></span>' +
                            '<span>' + escapeHtml(o.title) + '</span>' +
                        '</button>';
                    }).join('');
                    resultsEl.querySelectorAll('[data-id]').forEach(function (btn) {
                        btn.onclick = function () {
                            var id = parseInt(btn.getAttribute('data-id'), 10);
                            persistChange(id, true);
                        };
                    });
                }).catch(function () {
                    resultsEl.innerHTML = '<div class="cu-dep-empty">Search failed.</div>';
                });
            }

            function persistChange(id, addIt) {
                var existing = (task[taskField] || []).map(function (x) { return x.id; });
                var newIds = addIt
                    ? (existing.indexOf(id) >= 0 ? existing : existing.concat([id]))
                    : existing.filter(function (x) { return x !== id; });
                var payload = {};
                payload[postField] = newIds;
                persistTaskField(task.id, payload, function (updated) {
                    task.dependencies = updated.dependencies || [];
                    task.dependents   = updated.dependents   || [];
                    task.linked       = updated.linked       || [];
                    refreshAllSections();
                    closePicker();
                    if (typeof onChange === 'function') onChange();
                });
            }
        }

        function collectAllRelatedIds() {
            var ids = [];
            (task.dependencies || []).forEach(function (d) { ids.push(d.id); });
            (task.dependents   || []).forEach(function (d) { ids.push(d.id); });
            (task.linked       || []).forEach(function (d) { ids.push(d.id); });
            return ids;
        }

        function refreshAllSections() {
            var sections = [
                { key: 'waiting',  field: 'dependencies' },
                { key: 'blocking', field: 'dependents'   },
                { key: 'linked',   field: 'linked'       }
            ];
            sections.forEach(function (s) {
                var sec = backdrop.querySelector('[data-section="' + s.key + '"]');
                if (!sec) return;
                var listEl = sec.querySelector('[data-list]');
                var items = task[s.field] || [];
                if (!items.length) { listEl.innerHTML = ''; return; }
                listEl.innerHTML = items.map(function (d) {
                    return '<div class="cu-dep-modal-item" data-id="' + d.id + '">' +
                        '<span class="cu-dep-status cu-dep-status-' + d.status + '"></span>' +
                        '<span class="cu-dep-modal-item-title">' + escapeHtml(d.title) + '</span>' +
                        '<button type="button" class="cu-dep-modal-remove" title="Remove">×</button>' +
                    '</div>';
                }).join('');
                listEl.querySelectorAll('.cu-dep-modal-remove').forEach(function (btn) {
                    btn.onclick = function () {
                        var id = parseInt(btn.parentElement.getAttribute('data-id'), 10);
                        var fieldMap = { dependencies: 'dependency_ids', dependents: 'blocking_ids', linked: 'link_ids' };
                        var existing = (task[s.field] || []).map(function (x) { return x.id; });
                        var newIds = existing.filter(function (x) { return x !== id; });
                        var payload = {};
                        payload[fieldMap[s.field]] = newIds;
                        persistTaskField(task.id, payload, function (updated) {
                            task.dependencies = updated.dependencies || [];
                            task.dependents   = updated.dependents   || [];
                            task.linked       = updated.linked       || [];
                            refreshAllSections();
                            if (typeof onChange === 'function') onChange();
                        });
                    };
                });
            });
        }
    }

    function loadTaskThread(taskId) {
        var msgBox = document.getElementById('tthreadMessages');
        var partBox = document.getElementById('tthreadParticipants');
        if (!msgBox) return;

        fetch('/api/tessa/tasks/' + taskId + '/thread', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var messages = data.messages || [];
                var participants = data.participants || [];

                // Participants chips
                if (partBox) {
                    partBox.innerHTML = participants.map(function (p) {
                        var roleCls = p.role === 'assigner' ? 'tthread-role-assigner' : (p.role === 'assignee' ? 'tthread-role-assignee' : 'tthread-role-invited');
                        return '<span class="tthread-participant ' + roleCls + '">' + escapeHtml(p.user_name) + '</span>';
                    }).join('');
                }

                // Messages — WhatsApp style: my messages right, others left
                var myUserId = config.userId || 0;
                if (!messages.length) {
                    msgBox.innerHTML = '<div class="tthread-empty">No messages yet. Start the conversation.</div>';
                } else {
                    var unreadShown = false;
                    msgBox.innerHTML = messages.map(function (m) {
                        var isMe = m.user_id === myUserId;
                        var alignClass = isMe ? 'tthread-msg-right' : 'tthread-msg-left';
                        var name = m.user_name || 'Unknown';
                        var timeStr = '';
                        if (m.created_at) {
                            var d = new Date(m.created_at);
                            timeStr = d.toLocaleTimeString('en-IN', { timeZone: 'Asia/Kolkata', hour: 'numeric', minute: '2-digit', hour12: true });
                        }
                        var unreadLine = '';
                        if (!unreadShown && m.is_unread && !isMe) {
                            unreadShown = true;
                            unreadLine = '<div class="tthread-unread-line"><span>Unread</span></div>';
                        }
                        return unreadLine +
                            '<div class="tthread-msg ' + alignClass + '">' +
                            '<div class="tthread-msg-bubble">' +
                                '<div class="tthread-msg-header">' +
                                    '<span class="tthread-msg-name">' + escapeHtml(name) + '</span>' +
                                    '<span class="tthread-msg-time">' + escapeHtml(timeStr) + '</span>' +
                                '</div>' +
                                '<div class="tthread-msg-body">' + escapeHtml(m.content) + '</div>' +
                            '</div>' +
                        '</div>';
                    }).join('');
                    msgBox.scrollTop = msgBox.scrollHeight;
                }

                // Send button
                var sendBtn = document.getElementById('tthreadSendBtn');
                var inputEl = document.getElementById('tthreadInput');
                if (sendBtn) {
                    sendBtn.onclick = function () {
                        var text = inputEl.value.trim();
                        if (!text) return;

                        // Instantly show message in UI
                        var msgBoxNow = document.getElementById('tthreadMessages');
                        if (msgBoxNow) {
                            var emptyEl = msgBoxNow.querySelector('.tthread-empty');
                            if (emptyEl) emptyEl.remove();
                            var userName = (config.userName || config.MODAL_PEOPLE && config.MODAL_PEOPLE.find(function (p) { return p.id === config.userId; }));
                            var displayName = userName && userName.name ? userName.name : 'You';
                            msgBoxNow.insertAdjacentHTML('beforeend',
                                '<div class="tthread-msg tthread-msg-right">' +
                                    '<div class="tthread-msg-bubble">' +
                                        '<div class="tthread-msg-header">' +
                                            '<span class="tthread-msg-name">' + escapeHtml(displayName) + '</span>' +
                                            '<span class="tthread-msg-time">just now</span>' +
                                        '</div>' +
                                        '<div class="tthread-msg-body">' + escapeHtml(text) + '</div>' +
                                    '</div>' +
                                '</div>'
                            );
                            msgBoxNow.scrollTop = msgBoxNow.scrollHeight;
                        }

                        var sendSvg = sendBtn.innerHTML;
                        inputEl.value = '';
                        sendBtn.disabled = true;

                        fetch('/api/tessa/tasks/' + taskId + '/thread', {
                            method: 'POST', credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            body: JSON.stringify({ content: text })
                        }).then(function (r) { return r.json(); }).then(function (body) {
                            if (body.ok && body.ai_summary) {
                                var card = document.querySelector('.tg-card[data-id="' + taskId + '"]');
                                if (card) {
                                    var existing = card.querySelector('.tg-card-ai');
                                    if (existing) { existing.textContent = body.ai_summary; }
                                    else { var cb = card.querySelector('.tg-card-body'); if (cb) cb.insertAdjacentHTML('beforeend', '<div class="tg-card-ai">' + escapeHtml(body.ai_summary) + '</div>'); }
                                }
                                var hintEl = document.querySelector('.tasks-ai-hint');
                                if (hintEl) { hintEl.textContent = body.ai_summary; }
                                else {
                                    var descEl = document.querySelector('.task-modal-desc');
                                    if (descEl) descEl.insertAdjacentHTML('afterend', '<div class="tasks-ai-hint">' + escapeHtml(body.ai_summary) + '</div>');
                                }
                            }
                            sendBtn.disabled = false;
                        }).catch(function () { sendBtn.disabled = false; });
                    };
                    if (inputEl) {
                        inputEl.onkeydown = function (e) {
                            if (e.key === 'Enter' && !e.shiftKey) {
                                e.preventDefault();
                                sendBtn.click();
                            }
                        };
                    }
                }

                // Invite button — dropdown multi-select
                var inviteBtn = document.getElementById('tthreadInviteBtn');
                if (inviteBtn) {
                    inviteBtn.onclick = function () {
                        var existing = document.getElementById('tthreadInviteDropdown');
                        if (existing) { existing.remove(); return; }

                        var people = (config.MODAL_PEOPLE || []).filter(function (p) {
                            return !participants.find(function (pt) { return pt.user_id === p.id; });
                        });
                        if (!people.length) {
                            var tip = document.createElement('div');
                            tip.className = 'tthread-invite-dropdown';
                            tip.id = 'tthreadInviteDropdown';
                            tip.innerHTML = '<div class="tthread-invite-empty">Everyone is already in this thread</div>';
                            inviteBtn.parentElement.style.position = 'relative';
                            inviteBtn.parentElement.appendChild(tip);
                            setTimeout(function () { tip.remove(); }, 2000);
                            return;
                        }

                        var dropdown = document.createElement('div');
                        dropdown.className = 'tthread-invite-dropdown';
                        dropdown.id = 'tthreadInviteDropdown';
                        var html = '<div class="tthread-invite-search"><input type="text" placeholder="Search people..." id="tthreadInviteSearch" /></div>';
                        html += '<div class="tthread-invite-list" id="tthreadInviteList">';
                        people.forEach(function (p) {
                            var initial = getInitials(p.name);
                            html += '<div class="tthread-invite-item" data-uid="' + p.id + '" data-name="' + escapeHtml(p.name) + '">' +
                                '<span class="tthread-invite-avatar">' + initial + '</span>' +
                                '<span class="tthread-invite-name">' + escapeHtml(p.name) + '</span>' +
                            '</div>';
                        });
                        html += '</div>';
                        dropdown.innerHTML = html;
                        inviteBtn.parentElement.style.position = 'relative';
                        inviteBtn.parentElement.appendChild(dropdown);

                        // Search filter
                        var searchInput = document.getElementById('tthreadInviteSearch');
                        if (searchInput) {
                            searchInput.focus();
                            searchInput.oninput = function () {
                                var q = searchInput.value.toLowerCase();
                                document.querySelectorAll('.tthread-invite-item').forEach(function (item) {
                                    var name = (item.getAttribute('data-name') || '').toLowerCase();
                                    item.style.display = name.indexOf(q) >= 0 ? '' : 'none';
                                });
                            };
                        }

                        // Click to invite
                        dropdown.querySelectorAll('.tthread-invite-item').forEach(function (item) {
                            item.onclick = function () {
                                var uid = parseInt(item.getAttribute('data-uid'), 10);
                                item.style.opacity = '0.4';
                                item.style.pointerEvents = 'none';
                                fetch('/api/tessa/tasks/' + taskId + '/invite', {
                                    method: 'POST', credentials: 'same-origin',
                                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                    body: JSON.stringify({ user_id: uid })
                                }).then(function (r) { return r.json(); }).then(function (body) {
                                    if (body.ok) {
                                        item.classList.add('tthread-invite-item-added');
                                        item.innerHTML += '<span class="tthread-invite-check">Added</span>';
                                        setTimeout(function () { dropdown.remove(); loadTaskThread(taskId); }, 600);
                                    }
                                });
                            };
                        });

                        // Close on click outside
                        setTimeout(function () {
                            document.addEventListener('click', function closeInvite(e) {
                                if (!dropdown.contains(e.target) && e.target !== inviteBtn) {
                                    dropdown.remove();
                                    document.removeEventListener('click', closeInvite);
                                }
                            });
                        }, 10);
                    };
                }
            })
            .catch(function () {
                msgBox.innerHTML = '<div class="tthread-empty">Failed to load thread.</div>';
            });
    }

    // ── Recurring Tasks ──
    function renderRecurrences() {
        var gridBody = document.getElementById('tasksGridBody');
        if (!gridBody) return;
        gridBody.innerHTML = '<div class="tasks-loading">Loading...</div>';

        if (!document.getElementById('recurrenceNewBtn')) {
            var headerLeft = document.querySelector('.tasks-header-left');
            if (headerLeft) {
                var btn = document.createElement('button');
                btn.id = 'recurrenceNewBtn';
                btn.className = 'tasks-assign-btn';
                btn.textContent = '+ New Recurring Task';
                btn.onclick = function () { openRecurrenceCreateModal(); };
                headerLeft.appendChild(btn);
            }
        }

        fetch('/api/tessa/recurrences', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var items = data.recurrences || [];
                if (!items.length) {
                    gridBody.innerHTML = '<div class="tasks-empty">No recurring tasks yet.</div>';
                    return;
                }
                var html = '<div class="recurrence-list">';
                items.forEach(function (r) {
                    var typeLabel = r.recurrence_type.charAt(0).toUpperCase() + r.recurrence_type.slice(1);
                    var dayLabel = '';
                    if (r.recurrence_type === 'weekly' && r.recurrence_day !== null) {
                        var days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                        dayLabel = ' (' + (days[r.recurrence_day] || '') + ')';
                    } else if (r.recurrence_type === 'monthly' && r.recurrence_day !== null) {
                        dayLabel = ' (Day ' + r.recurrence_day + ')';
                    }
                    var nextStr = r.next_run_at ? new Date(r.next_run_at).toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) : '';
                    var assigneeName = r.assigned_to && r.assigned_to.name ? r.assigned_to.name : '?';
                    var taskStatusHtml = '';
                    if (r.latest_task) {
                        var st = r.latest_task.status || 'pending';
                        taskStatusHtml = '<div class="recurrence-task-status">' +
                            '<select class="recurrence-status-select recurrence-status-select--' + st + '" data-task-id="' + r.latest_task.id + '">' +
                                '<option value="pending"' + (st === 'pending' ? ' selected' : '') + '>Pending</option>' +
                                '<option value="in_progress"' + (st === 'in_progress' ? ' selected' : '') + '>In Progress</option>' +
                                '<option value="completed"' + (st === 'completed' ? ' selected' : '') + '>Completed</option>' +
                            '</select>' +
                        '</div>';
                    }
                    html += '<div class="recurrence-card' + (!r.is_active ? ' recurrence-card-inactive' : '') + '">' +
                        '<div class="recurrence-card-info">' +
                            '<div class="recurrence-card-title">' + escapeHtml(r.title) + '</div>' +
                            '<div class="recurrence-card-meta">' +
                                '<span class="badge recurrence-badge recurrence-badge-' + r.recurrence_type + '">' + typeLabel + dayLabel + '</span>' +
                                '<span>To: ' + escapeHtml(assigneeName) + '</span>' +
                                '<span>Priority: ' + r.priority + '</span>' +
                                (nextStr ? '<span>Next: ' + escapeHtml(nextStr) + '</span>' : '') +
                            '</div>' +
                        '</div>' +
                        taskStatusHtml +
                        '<div class="recurrence-card-actions">' +
                            '<button class="recurrence-toggle-btn" data-rid="' + r.id + '" data-active="' + (r.is_active ? '1' : '0') + '">' + (r.is_active ? 'Pause' : 'Resume') + '</button>' +
                            '<button class="recurrence-delete-btn" data-rid="' + r.id + '">Delete</button>' +
                        '</div>' +
                    '</div>';
                });
                html += '</div>';
                gridBody.innerHTML = html;

                gridBody.querySelectorAll('.recurrence-status-select').forEach(function (sel) {
                    sel.onchange = function () {
                        var taskId = sel.getAttribute('data-task-id');
                        var newStatus = sel.value;
                        sel.disabled = true;
                        fetch('/api/tessa/tasks/' + taskId, {
                            method: 'PUT', credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            body: JSON.stringify({ status: newStatus })
                        }).then(function (r) { return r.json(); }).then(function () {
                            sel.className = 'recurrence-status-select recurrence-status-select--' + newStatus;
                            sel.disabled = false;
                        }).catch(function () {
                            sel.disabled = false;
                            renderRecurrences();
                        });
                    };
                });
                gridBody.querySelectorAll('.recurrence-toggle-btn').forEach(function (btn) {
                    btn.onclick = function () {
                        var rid = btn.getAttribute('data-rid');
                        var isActive = btn.getAttribute('data-active') === '1';
                        fetch('/api/tessa/recurrences/' + rid, {
                            method: 'PUT', credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            body: JSON.stringify({ is_active: !isActive })
                        }).then(function () { renderRecurrences(); });
                    };
                });
                gridBody.querySelectorAll('.recurrence-delete-btn').forEach(function (btn) {
                    btn.onclick = function () {
                        if (!confirm('Delete this recurring task?')) return;
                        var rid = btn.getAttribute('data-rid');
                        fetch('/api/tessa/recurrences/' + rid, {
                            method: 'DELETE', credentials: 'same-origin',
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                        }).then(function () { renderRecurrences(); });
                    };
                });
            });
    }

    function openRecurrenceCreateModal() {
        var modalPeople = config.MODAL_PEOPLE || [];
        var existing = document.getElementById('recurrenceCreateOverlay');
        if (existing) existing.remove();

        var overlay = document.createElement('div');
        overlay.id = 'recurrenceCreateOverlay';
        overlay.className = 'task-modal-overlay';
        overlay.innerHTML = '<div class="task-modal" style="max-width:500px">' +
            '<div class="task-modal-header"><h3>New Recurring Task</h3><button class="task-modal-close" id="recurrenceCreateClose">&times;</button></div>' +
            '<div class="task-modal-body" style="overflow-y:auto;max-height:60vh">' +
                '<div class="mtg-modal-field mtg-modal-field-full"><label>Assign to</label>' +
                    '<div class="mtg-modal-chips" id="recurrPeopleChips">' +
                    modalPeople.map(function (p) {
                        return '<button type="button" class="mtg-modal-chip" data-id="' + p.id + '">' + escapeHtml(p.name) + '</button>';
                    }).join('') +
                    '</div>' +
                '</div>' +
                '<div class="mtg-modal-field mtg-modal-field-full"><label>Title</label><input type="text" id="recurrTitle" placeholder="e.g. Weekly standup notes"></div>' +
                '<div class="mtg-modal-field mtg-modal-field-full"><label>Description (optional)</label><textarea id="recurrDesc" rows="2" placeholder="Details..." data-grammar-fix></textarea></div>' +
                '<div class="mtg-modal-field"><label>Priority</label><select id="recurrPriority"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="urgent">Urgent</option></select></div>' +
                '<div class="mtg-modal-field"><label>Frequency</label><select id="recurrType"><option value="daily">Daily</option><option value="weekly" selected>Weekly</option><option value="monthly">Monthly</option></select></div>' +
                '<div class="mtg-modal-field mtg-modal-field-full" id="recurrDayPicker"></div>' +
                '<div class="mtg-modal-field"><label>Deadline offset (hours)</label><input type="number" id="recurrDeadlineHours" value="24" min="1" max="720"></div>' +
            '</div>' +
            '<div class="tessa-task-modal-footer"><button class="btn btn-outline" id="recurrenceCreateCancel">Cancel</button><button class="recurrence-create-btn" id="recurrenceCreateSave">Create</button></div>' +
        '</div>';

        document.body.appendChild(overlay);

        // People chips
        var chips = document.getElementById('recurrPeopleChips');
        if (chips) {
            chips.querySelectorAll('.mtg-modal-chip').forEach(function (chip) {
                chip.onclick = function () {
                    chips.querySelectorAll('.mtg-modal-chip').forEach(function (c) { c.classList.remove('selected'); });
                    chip.classList.add('selected');
                };
            });
        }

        function updateDayPicker() {
            var type = document.getElementById('recurrType').value;
            var picker = document.getElementById('recurrDayPicker');
            if (!picker) return;
            if (type === 'daily') {
                picker.innerHTML = '';
            } else if (type === 'weekly') {
                var days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                picker.innerHTML = '<label>Day of week</label><div class="recurrence-schedule-picker">' +
                    days.map(function (d, i) {
                        return '<button type="button" class="recurrence-day-btn" data-day="' + i + '">' + d + '</button>';
                    }).join('') + '</div>';
                picker.querySelectorAll('.recurrence-day-btn').forEach(function (btn) {
                    btn.onclick = function () {
                        picker.querySelectorAll('.recurrence-day-btn').forEach(function (b) { b.classList.remove('active'); });
                        btn.classList.add('active');
                    };
                });
            } else {
                picker.innerHTML = '<label>Day of month</label><input type="number" id="recurrMonthDay" min="1" max="28" value="1" style="width:80px">';
            }
        }
        document.getElementById('recurrType').onchange = updateDayPicker;
        updateDayPicker();

        function closeModal() { overlay.remove(); }
        document.getElementById('recurrenceCreateClose').onclick = closeModal;
        document.getElementById('recurrenceCreateCancel').onclick = closeModal;
        overlay.onclick = function (e) { if (e.target === overlay) closeModal(); };

        document.getElementById('recurrenceCreateSave').onclick = function () {
            var selectedChip = document.querySelector('#recurrPeopleChips .mtg-modal-chip.selected');
            if (!selectedChip) { showTaskToast('Please select a person.'); return; }
            var title = (document.getElementById('recurrTitle').value || '').trim();
            if (!title) { showTaskToast('Please enter a title.'); return; }

            var type = document.getElementById('recurrType').value;
            var day = null;
            if (type === 'weekly') {
                var activeDay = document.querySelector('.recurrence-day-btn.active');
                day = activeDay ? parseInt(activeDay.getAttribute('data-day'), 10) : 1;
            } else if (type === 'monthly') {
                var monthInput = document.getElementById('recurrMonthDay');
                day = monthInput ? parseInt(monthInput.value, 10) : 1;
            }

            var payload = {
                title: title,
                description: (document.getElementById('recurrDesc').value || '').trim() || null,
                assigned_to: parseInt(selectedChip.getAttribute('data-id'), 10),
                priority: document.getElementById('recurrPriority').value,
                recurrence_type: type,
                recurrence_day: day,
                deadline_offset_hours: parseInt(document.getElementById('recurrDeadlineHours').value, 10) || 24
            };

            var saveBtn = document.getElementById('recurrenceCreateSave');
            saveBtn.disabled = true;
            saveBtn.textContent = 'Creating...';

            fetch('/api/tessa/recurrences', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(payload)
            }).then(function (r) { return r.json(); }).then(function (body) {
                if (body.ok) {
                    closeModal();
                    renderRecurrences();
                } else {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Create';
                    showTaskToast('Failed to create recurring task.');
                }
            }).catch(function () {
                saveBtn.disabled = false;
                saveBtn.textContent = 'Create';
            });
        };
    }

    function loadSubtasks(taskId) {
        var container = document.getElementById('taskSubtasksItems');
        if (!container) return;

        fetch('/api/tessa/tasks/' + taskId + '/subtasks', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var items = data.subtasks || [];
                var done = items.filter(function (s) { return s.is_completed; }).length;
                var countEl = document.getElementById('taskChecklistCount');
                if (countEl) countEl.textContent = items.length ? done + ' of ' + items.length : '';
                if (!items.length) {
                    container.innerHTML = '<div class="task-subtask-empty">No items yet</div>';
                } else {
                    container.innerHTML = items.map(function (s) {
                        return '<div class="task-subtask-row" data-sid="' + s.id + '">' +
                            '<label class="task-subtask-check">' +
                                '<input type="checkbox"' + (s.is_completed ? ' checked' : '') + '>' +
                                '<span class="task-subtask-text' + (s.is_completed ? ' task-subtask-done' : '') + '">' + escapeHtml(s.title) + '</span>' +
                            '</label>' +
                            '<button class="task-subtask-delete" title="Remove">&times;</button>' +
                        '</div>';
                    }).join('');

                    container.querySelectorAll('.task-subtask-row').forEach(function (row) {
                        var sid = row.getAttribute('data-sid');
                        row.querySelector('input[type="checkbox"]').onchange = function () {
                            fetch('/api/tessa/tasks/' + taskId + '/subtasks/' + sid, {
                                method: 'PUT', credentials: 'same-origin',
                                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                            }).then(function () { loadSubtasks(taskId); renderTasks(); });
                        };
                        row.querySelector('.task-subtask-delete').onclick = function () {
                            fetch('/api/tessa/tasks/' + taskId + '/subtasks/' + sid, {
                                method: 'DELETE', credentials: 'same-origin',
                                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                            }).then(function () { loadSubtasks(taskId); renderTasks(); });
                        };
                    });
                }
            });

        var addBtn = document.getElementById('taskSubtaskAddBtn');
        var addInput = document.getElementById('taskSubtaskInput');
        if (addBtn && addInput) {
            addBtn.onclick = function () {
                var title = addInput.value.trim();
                if (!title) return;
                addInput.value = '';
                fetch('/api/tessa/tasks/' + taskId + '/subtasks', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ title: title })
                }).then(function () { loadSubtasks(taskId); renderTasks(); });
            };
            addInput.onkeydown = function (e) {
                if (e.key === 'Enter') { e.preventDefault(); addBtn.click(); }
            };
        }
    }

    function loadAttachments(taskId) {
        var list = document.getElementById('taskAttachList');
        if (!list) return;

        fetch('/api/tessa/tasks/' + taskId + '/attachments', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var files = data.attachments || [];
                if (!files.length) {
                    list.innerHTML = '<div class="task-subtask-empty">No attachments</div>';
                    return;
                }
                list.innerHTML = files.map(function (f) {
                    var url = '/api/tessa/tasks/' + taskId + '/attachments/' + f.id + '/download';
                    var thumb = f.is_image
                        ? '<img src="' + url + '" class="task-attach-preview" alt="">'
                        : '<span class="task-attach-icon">' + getFileIcon(f.mime_type) + '</span>';
                    var canPreview = f.is_image || (f.mime_type && f.mime_type.indexOf('pdf') >= 0);
                    return '<div class="task-attach-item" data-aid="' + f.id + '">' +
                        '<div class="task-attach-thumb" ' + (canPreview ? 'data-preview-url="' + url + '" data-preview-type="' + (f.is_image ? 'image' : 'pdf') + '" data-preview-name="' + escapeHtml(f.file_name) + '"' : '') + ' style="cursor:' + (canPreview ? 'pointer' : 'default') + '">' +
                            thumb +
                        '</div>' +
                        '<div class="task-attach-info">' +
                            '<span class="task-attach-name" ' + (canPreview ? 'data-preview-url="' + url + '" data-preview-type="' + (f.is_image ? 'image' : 'pdf') + '" data-preview-name="' + escapeHtml(f.file_name) + '" style="cursor:pointer"' : '') + '>' + escapeHtml(f.file_name) + '</span>' +
                            '<span class="task-attach-meta">' + escapeHtml(f.human_size) + ' &middot; ' + escapeHtml(f.user_name) + '</span>' +
                        '</div>' +
                        '<a href="' + url + '" class="task-attach-download" title="Download" download>&#8595;</a>' +
                        '<button class="task-attach-delete" title="Remove">&times;</button>' +
                    '</div>';
                }).join('');

                // Preview on click
                list.querySelectorAll('[data-preview-url]').forEach(function (el) {
                    el.onclick = function (e) {
                        e.preventDefault();
                        var pUrl = el.getAttribute('data-preview-url');
                        var pType = el.getAttribute('data-preview-type');
                        var pName = el.getAttribute('data-preview-name');
                        showAttachmentPreview(pUrl, pType, pName);
                    };
                });

                list.querySelectorAll('.task-attach-delete').forEach(function (btn) {
                    btn.onclick = function () {
                        var aid = btn.closest('.task-attach-item').getAttribute('data-aid');
                        if (!confirm('Delete this attachment?')) return;
                        fetch('/api/tessa/tasks/' + taskId + '/attachments/' + aid, {
                            method: 'DELETE', credentials: 'same-origin',
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                        }).then(function () { loadAttachments(taskId); });
                    };
                });
            });

        var fileInput = document.getElementById('taskAttachFileInput');
        if (fileInput) {
            fileInput.onchange = function () {
                var files = fileInput.files;
                if (!files.length) return;
                Array.from(files).forEach(function (file) {
                    uploadTaskAttachmentFile(taskId, file, function () { loadAttachments(taskId); });
                });
                fileInput.value = '';
            };
        }

        // Drag-and-drop: upload dropped files immediately (task already exists).
        var dropZone = document.getElementById('taskAttachments');
        if (dropZone) {
            wireFileDropZone(dropZone, function (files) {
                files.forEach(function (file) {
                    uploadTaskAttachmentFile(taskId, file, function () { loadAttachments(taskId); });
                });
            });
        }
    }

    function showAttachmentPreview(url, type, name) {
        var existing = document.getElementById('attachPreviewOverlay');
        if (existing) existing.remove();
        var content = '';
        if (type === 'image') {
            content = '<img src="' + url + '" class="attach-preview-img" alt="">';
        } else if (type === 'pdf') {
            content = '<iframe src="' + url + '" class="attach-preview-iframe"></iframe>';
        }
        var overlay = document.createElement('div');
        overlay.id = 'attachPreviewOverlay';
        overlay.className = 'attach-preview-overlay';
        overlay.innerHTML =
            '<div class="attach-preview-container">' +
                '<div class="attach-preview-header">' +
                    '<span class="attach-preview-name">' + escapeHtml(name || 'Preview') + '</span>' +
                    '<div class="attach-preview-actions">' +
                        '<a href="' + url + '" download class="attach-preview-btn" title="Download">&#8595; Download</a>' +
                        '<button class="attach-preview-close" id="attachPreviewClose">&times;</button>' +
                    '</div>' +
                '</div>' +
                '<div class="attach-preview-body">' + content + '</div>' +
            '</div>';
        document.body.appendChild(overlay);
        document.getElementById('attachPreviewClose').onclick = function () { overlay.remove(); };
        overlay.onclick = function (e) { if (e.target === overlay) overlay.remove(); };
        document.addEventListener('keydown', function escClose(e) {
            if (e.key === 'Escape') { overlay.remove(); document.removeEventListener('keydown', escClose); }
        });
    }

    function getFileIcon(mime) {
        if (mime && mime.indexOf('pdf') >= 0) return 'PDF';
        if (mime && mime.indexOf('spreadsheet') >= 0) return 'XLS';
        if (mime && mime.indexOf('word') >= 0) return 'DOC';
        if (mime && mime.indexOf('zip') >= 0) return 'ZIP';
        if (mime && mime.indexOf('csv') >= 0) return 'CSV';
        return 'FILE';
    }

    // ─────────────────────────────────────────────────────────────────
    // Checklists — assigner-defined daily todo lists for an assignee.
    // The same items show every day; assignee ticks them off as they go,
    // and the box state resets next IST day (see server-side check_date).
    // Lives in the "Checklists" filter tab and reuses tasksGridBody.
    // ─────────────────────────────────────────────────────────────────

    var checklistsCache = { mine: [], assigned: [] };

    function renderChecklists() {
        // Top-level sidebar view (#checklistsView). The assignee's own
        // checklists are shown on the dashboard alongside sign-in/off, so this
        // workspace is the assigner-side roster + create entry point.
        var body = document.getElementById('checklistsBody');
        if (!body) return;
        body.innerHTML = '<div class="tasks-loading">Loading checklists...</div>';

        // The "+ Assign Checklist" button now lives in the view header itself
        // (rendered by the blade), so wire it up once.
        var headerBtn = document.getElementById('checklistAssignBtnHeader');
        if (headerBtn && !headerBtn.dataset.bound) {
            headerBtn.dataset.bound = '1';
            headerBtn.onclick = function () { openChecklistCreateModal(); };
        }

        fetch('/api/tessa/checklists?filter=assigned', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (body) {
                checklistsCache.assigned = (body && body.checklists) || [];
                paintChecklists();
            }).catch(function () {
                body.innerHTML = '<div class="tasks-empty">Failed to load checklists.</div>';
            });
    }

    function paintChecklists() {
        var bodyEl = document.getElementById('checklistsBody');
        if (!bodyEl) return;

        var html = '<div class="checklist-wrap">';
        html += '<div class="checklist-section">';
        html += '<div class="checklist-section-head"><h3>Assigned by Me</h3><span class="checklist-section-sub">Your team\'s daily progress</span></div>';
        if (!checklistsCache.assigned.length) {
            html += '<div class="tasks-empty">You haven\'t assigned any checklists yet. Click <strong>+ Assign Checklist</strong> above to start.</div>';
        } else {
            checklistsCache.assigned.forEach(function (c) { html += renderChecklistCard(c, 'assigner'); });
        }
        html += '</div></div>';
        bodyEl.innerHTML = html;

        // Edit / delete buttons (assigner view).
        bodyEl.querySelectorAll('.checklist-edit-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var clid = parseInt(btn.getAttribute('data-checklist-id'), 10);
                var c = checklistsCache.assigned.find(function (x) { return x.id === clid; });
                if (c) openChecklistEditModal(c);
            });
        });
        bodyEl.querySelectorAll('.checklist-delete-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var clid = btn.getAttribute('data-checklist-id');
                if (!confirm('Remove this checklist? The assignee will no longer see it.')) return;
                fetch('/api/tessa/checklists/' + clid, {
                    method: 'DELETE', credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (r) { return r.json(); }).then(function () { renderChecklists(); });
            });
        });
    }

    function renderChecklistCard(c, role) {
        var headBadge = role === 'assigner'
            ? '<span class="checklist-card-meta">For ' + escapeHtml(c.assignee ? c.assignee.name : '—') + '</span>'
            : '<span class="checklist-card-meta">From ' + escapeHtml(c.assigner ? c.assigner.name : '—') + '</span>';
        var actions = role === 'assigner'
            ? '<div class="checklist-card-actions">' +
                  '<button type="button" class="checklist-edit-btn cu-btn cu-btn-secondary cu-btn-small" data-checklist-id="' + c.id + '">Edit</button>' +
                  '<button type="button" class="checklist-delete-btn cu-btn cu-btn-secondary cu-btn-small" data-checklist-id="' + c.id + '">Delete</button>' +
              '</div>'
            : '';

        var itemsHtml = c.items.map(function (it) {
            var inputAttrs = role === 'assignee'
                ? 'class="checklist-item-check" data-checklist-id="' + c.id + '" data-item-id="' + it.id + '"' + (it.checked_today ? ' checked' : '')
                : 'class="checklist-item-check" disabled' + (it.checked_today ? ' checked' : '');
            // Assigner view shows the assignee's same-day update under each
            // item when present, so the manager can see status notes for the
            // current day without leaving the checklist card.
            var noteBlock = (role === 'assigner' && it.note_today)
                ? '<div class="checklist-item-note"><span class="checklist-item-note-label">Update</span>' + escapeHtml(it.note_today) + '</div>'
                : '';
            return '<div class="checklist-item-row' + (it.checked_today ? ' checklist-item-done' : '') + '">' +
                '<label>' +
                    '<input type="checkbox" ' + inputAttrs + '>' +
                    '<span>' + escapeHtml(it.title) + '</span>' +
                '</label>' +
                noteBlock +
            '</div>';
        }).join('');

        return '<div class="checklist-card" data-checklist-id="' + c.id + '">' +
            '<div class="checklist-card-head">' +
                '<div class="checklist-card-title-wrap">' +
                    '<h4 class="checklist-card-title">' + escapeHtml(c.title) + '</h4>' +
                    headBadge +
                '</div>' +
                '<span class="checklist-progress">' + c.done_today + '/' + c.item_count + '</span>' +
            '</div>' +
            (c.description ? '<div class="checklist-card-desc">' + escapeHtml(c.description) + '</div>' : '') +
            '<div class="checklist-items">' + itemsHtml + '</div>' +
            actions +
        '</div>';
    }

    function openChecklistCreateModal() {
        openChecklistModal(null);
    }

    function openChecklistEditModal(c) {
        openChecklistModal(c);
    }

    function openChecklistModal(existing) {
        var overlay = document.createElement('div');
        overlay.className = 'inv-modal-overlay';
        var isEdit = !!existing;

        var assigneeSelectHtml = isEdit
            ? '<input type="text" id="clAssigneeDisplay" value="' + escapeHtml(existing.assignee ? existing.assignee.name : '') + '" disabled style="background:#1a1a2e;color:#9ca3af;width:100%;padding:8px;border:1px solid #2a2a3e;border-radius:6px">' +
              '<input type="hidden" id="clAssignee" value="' + (existing.assignee ? existing.assignee.id : '') + '">'
            : '<select id="clAssignee" style="width:100%;padding:8px;border:1px solid #2a2a3e;background:#0f172a;color:#fff;border-radius:6px"><option value="">Loading...</option></select>';

        var initialItems = isEdit ? existing.items : [{ title: '' }];
        var itemRowsHtml = initialItems.map(function (it, i) {
            return checklistItemRowHtml(i, it.title || '', it.id || null);
        }).join('');

        overlay.innerHTML = '<div class="inv-modal" style="max-width:560px">' +
            '<div class="inv-modal-header"><h3>' + (isEdit ? 'Edit Checklist' : 'Assign Checklist') + '</h3><button type="button" class="inv-modal-close" id="clClose">&times;</button></div>' +
            '<div class="inv-modal-body">' +
                '<label style="display:block;margin-bottom:10px">Title<input type="text" id="clTitle" placeholder="e.g. Morning routine" value="' + (isEdit ? escapeHtml(existing.title || '') : '') + '" style="width:100%;padding:8px;border:1px solid #2a2a3e;background:#0f172a;color:#fff;border-radius:6px;margin-top:4px"></label>' +
                '<label style="display:block;margin-bottom:10px">Assignee' + assigneeSelectHtml + '</label>' +
                '<label style="display:block;margin-bottom:10px">Description (optional)<textarea id="clDescription" rows="2" placeholder="Short note for the assignee" style="width:100%;padding:8px;border:1px solid #2a2a3e;background:#0f172a;color:#fff;border-radius:6px;margin-top:4px;resize:vertical">' + (isEdit ? escapeHtml(existing.description || '') : '') + '</textarea></label>' +
                '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;margin-bottom:6px">' +
                    '<strong>Daily todo items</strong>' +
                    '<button type="button" class="cu-btn cu-btn-secondary cu-btn-small" id="clAddItem">+ Add item</button>' +
                '</div>' +
                '<div id="clItems">' + itemRowsHtml + '</div>' +
                '<p id="clMsg" style="margin:10px 0 0 0;font-size:13px;min-height:16px;color:#9ca3af"></p>' +
            '</div>' +
            '<div class="inv-modal-footer">' +
                '<button type="button" class="btn btn-outline" id="clCancel">Cancel</button>' +
                '<button type="button" class="btn btn-primary btn-lg" id="clSave">' + (isEdit ? 'Save Changes' : 'Assign Checklist') + '</button>' +
            '</div>' +
        '</div>';
        document.body.appendChild(overlay);

        function close() { overlay.remove(); }
        document.getElementById('clClose').onclick = close;
        document.getElementById('clCancel').onclick = close;
        overlay.onclick = function (e) { if (e.target === overlay) close(); };

        // Populate assignee dropdown for new checklists. Use the dedicated
        // checklist-assignees endpoint so the caller can pick any active user,
        // not just people they've already assigned regular tasks to.
        if (!isEdit) {
            fetch('/api/tessa/checklists/assignees', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (body) {
                    var sel = document.getElementById('clAssignee');
                    if (!sel) return;
                    var options = (body.users || []).map(function (u) {
                        return '<option value="' + u.id + '">' + escapeHtml(u.name) + '</option>';
                    }).join('');
                    sel.innerHTML = '<option value="">Select assignee...</option>' + options;
                }).catch(function () {
                    var sel = document.getElementById('clAssignee');
                    if (sel) sel.innerHTML = '<option value="">(Could not load list)</option>';
                });
        }

        function addItemRow() {
            var holder = document.getElementById('clItems');
            var idx = holder.querySelectorAll('.cl-item-row').length;
            holder.insertAdjacentHTML('beforeend', checklistItemRowHtml(idx, '', null));
            wireItemHandlers();
            var inputs = holder.querySelectorAll('.cl-item-input');
            if (inputs.length) inputs[inputs.length - 1].focus();
        }

        function wireItemHandlers() {
            document.querySelectorAll('.cl-item-remove').forEach(function (btn) {
                btn.onclick = function () {
                    if (document.querySelectorAll('.cl-item-row').length <= 1) {
                        alert('A checklist needs at least one item.');
                        return;
                    }
                    btn.closest('.cl-item-row').remove();
                };
            });
            // Pressing Enter on an item input spawns a fresh empty row below
            // and focuses it — way faster than reaching for the + Add Item
            // button when you're rattling off five things in a row.
            document.querySelectorAll('.cl-item-input').forEach(function (input) {
                if (input.dataset.enterBound) return;
                input.dataset.enterBound = '1';
                input.addEventListener('keydown', function (e) {
                    if (e.key !== 'Enter') return;
                    e.preventDefault();
                    var rows = document.querySelectorAll('.cl-item-row');
                    var thisRow = input.closest('.cl-item-row');
                    var isLast = rows[rows.length - 1] === thisRow;
                    if (isLast) {
                        addItemRow();
                    } else {
                        // Mid-list Enter just jumps to the next existing input.
                        var allInputs = Array.prototype.slice.call(document.querySelectorAll('.cl-item-input'));
                        var idx = allInputs.indexOf(input);
                        if (idx >= 0 && allInputs[idx + 1]) allInputs[idx + 1].focus();
                    }
                });
            });
        }
        wireItemHandlers();

        document.getElementById('clAddItem').onclick = addItemRow;

        document.getElementById('clSave').onclick = function () {
            var title = document.getElementById('clTitle').value.trim();
            var assigneeEl = document.getElementById('clAssignee');
            var assignee = assigneeEl ? assigneeEl.value : '';
            var description = document.getElementById('clDescription').value.trim();
            var itemRows = document.querySelectorAll('.cl-item-row');
            var items = [];
            itemRows.forEach(function (row) {
                var titleInput = row.querySelector('.cl-item-input');
                var idAttr = row.getAttribute('data-existing-id');
                var t = titleInput ? titleInput.value.trim() : '';
                if (t) {
                    if (isEdit) {
                        items.push({ id: idAttr ? parseInt(idAttr, 10) : null, title: t });
                    } else {
                        items.push(t);
                    }
                }
            });

            var msg = document.getElementById('clMsg');
            function fail(text) { msg.style.color = '#ef4444'; msg.textContent = text; }
            if (!title) { fail('Title is required.'); return; }
            if (!isEdit && !assignee) { fail('Pick an assignee.'); return; }
            if (!items.length) { fail('Add at least one item.'); return; }

            var saveBtn = document.getElementById('clSave');
            saveBtn.disabled = true;
            saveBtn.textContent = isEdit ? 'Saving...' : 'Assigning...';
            msg.style.color = '#9ca3af'; msg.textContent = '';

            var url = isEdit ? '/api/tessa/checklists/' + existing.id : '/api/tessa/checklists';
            var method = isEdit ? 'PATCH' : 'POST';
            var body = isEdit
                ? { title: title, description: description || null, items: items }
                : { title: title, description: description || null, assigned_to: parseInt(assignee, 10), items: items };

            fetch(url, {
                method: method, credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(body)
            }).then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); }).then(function (res) {
                if (!res.ok || !res.body.ok) { fail(res.body.error || 'Save failed'); saveBtn.disabled = false; saveBtn.textContent = isEdit ? 'Save Changes' : 'Assign Checklist'; return; }
                close();
                renderChecklists();
            }).catch(function () { fail('Network error'); saveBtn.disabled = false; saveBtn.textContent = isEdit ? 'Save Changes' : 'Assign Checklist'; });
        };
    }

    function checklistItemRowHtml(idx, title, existingId) {
        var idAttr = existingId ? ' data-existing-id="' + existingId + '"' : '';
        return '<div class="cl-item-row"' + idAttr + ' style="display:flex;gap:6px;margin-bottom:6px;align-items:center">' +
            '<input type="text" class="cl-item-input" value="' + escapeHtml(title || '') + '" placeholder="Item ' + (idx + 1) + '" style="flex:1;padding:6px 10px;border:1px solid #2a2a3e;background:#0f172a;color:#fff;border-radius:6px">' +
            '<button type="button" class="cl-item-remove" title="Remove" style="background:transparent;border:1px solid #2a2a3e;color:#9ca3af;width:30px;height:30px;border-radius:6px;cursor:pointer">×</button>' +
        '</div>';
    }

    window.TasksModule = {
        renderTasks: renderTasks,
        renderChecklists: renderChecklists,
        openChecklistCreateModal: openChecklistCreateModal,
        tessaOpenTaskModal: tessaOpenTaskModal,
        openTaskModal: openTaskSlideover,
        openTaskSlideover: openTaskSlideover,
        setViewMode: setViewMode,
        setIncludeClosed: setIncludeClosed,
        setAssignedByFilter: setAssignedByFilter,
        setAssigneeFilter: setAssigneeFilter,
        setCombinedFilter: setCombinedFilter,
        setSearch: setSearch,
        showTaskToast: showTaskToast,
        getInitials: getInitials,
        escapeHtml: escapeHtml
    };
})();
