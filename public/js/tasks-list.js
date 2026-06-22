(function () {
    'use strict';

    var POP = window.TasksPopovers || {};
    var config = window.__PORTAL_CONFIG || {};

    function escapeHtml(v) { return POP.escapeHtml ? POP.escapeHtml(v) : String(v == null ? '' : v); }
    function getInitials(name) { return POP.getInitials ? POP.getInitials(name) : (name || '?').slice(0, 2).toUpperCase(); }

    function showToast(msg, type) {
        if (window.TasksModule && window.TasksModule.showTaskToast) {
            window.TasksModule.showTaskToast(msg, type);
        }
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

    var COLUMNS = [
        { key: 'assigned_by', label: 'Assigned by',  align: 'left',   width: '90px' },
        { key: 'due_date',    label: 'Due date',     align: 'left',   width: '160px' },
        { key: 'priority',    label: 'Priority',     align: 'left',   width: '140px' },
        { key: 'created_at',  label: 'Date created', align: 'left',   width: '160px' }
    ];

    function rowIsImmutable(task) {
        return task.status === 'closed' || task.status === 'cancelled';
    }

    function renderRowAssignedByCell(task) {
        var name = (task.assigned_by && task.assigned_by.name) || '—';
        var initials = task.assigned_by ? getInitials(name) : '?';
        return '<span class="cu-cell cu-cell-assigned-by" title="' + escapeHtml(name) + '">' +
            '<span class="cu-avatar cu-avatar-row">' + escapeHtml(initials) + '</span>' +
            '</span>';
    }

    function renderRowDueDateCell(task) {
        var label = POP.formatRelativeDeadline ? POP.formatRelativeDeadline(task.deadline) : (task.deadline || '');
        var overdue = task.is_overdue ? ' cu-cell-overdue' : '';
        if (!label) {
            return '<button type="button" class="cu-cell cu-cell-empty" data-cell="due_date">' +
                '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" class="cu-cell-empty-icon"><rect x="2" y="3" width="12" height="11" rx="2"/><path d="M2 7h12M5 1v4M11 1v4"/></svg>' +
                '</button>';
        }
        return '<button type="button" class="cu-cell cu-cell-due' + overdue + '" data-cell="due_date">' +
            escapeHtml(label) +
            '</button>';
    }

    function renderRowPriorityCell(task) {
        if (!task.priority) {
            return '<button type="button" class="cu-cell cu-cell-empty" data-cell="priority">' +
                '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" class="cu-cell-empty-icon"><path d="M3 2v12M3 3h9l-2 2.5L12 8H3"/></svg>' +
                '</button>';
        }
        var color = POP.priorityColor ? POP.priorityColor(task.priority) : '#3b82f6';
        var label = POP.priorityLabel ? POP.priorityLabel(task.priority) : 'Normal';
        return '<button type="button" class="cu-cell cu-cell-priority" data-cell="priority">' +
            '<span class="cu-flag" style="color:' + color + '">⚑</span>' +
            '<span>' + escapeHtml(label) + '</span>' +
            '</button>';
    }

    function renderRowCreatedCell(task) {
        var label = POP.formatRelativeCreated ? POP.formatRelativeCreated(task.created_at) : '';
        return '<span class="cu-cell cu-cell-created">' + escapeHtml(label) + '</span>';
    }

    function statusDot(task) {
        var color = POP.statusColor ? POP.statusColor(task.status) : '#a1a1aa';
        if (task.status === 'completed' || task.status === 'closed') {
            return '<span class="cu-row-status cu-row-status-done" style="background:' + color + '" title="' + (POP.statusLabel ? POP.statusLabel(task.status) : task.status) + '"></span>';
        }
        return '<span class="cu-row-status" style="border-color:' + color + '" title="' + (POP.statusLabel ? POP.statusLabel(task.status) : task.status) + '"></span>';
    }

    function renderRow(task) {
        var disabled = rowIsImmutable(task);
        var rowClass = 'cu-row' + (disabled ? ' cu-row-disabled' : '') + (task.status === 'completed' ? ' cu-row-completed' : '');
        var subtaskHint = (task.subtask_total > 0)
            ? '<span class="cu-row-sub-count">' + task.subtask_done + '/' + task.subtask_total + '</span>'
            : '';
        var unread = task.unread_count > 0
            ? '<span class="cu-row-unread">' + task.unread_count + '</span>'
            : '';
        var mandatoryBadge = task.is_mandatory
            ? '<span class="cu-row-mandatory" title="Mandatory task — incomplete affects KRA">MANDATORY</span>'
            : '';
        return '<div class="' + rowClass + '" data-task-id="' + task.id + '">' +
            '<div class="cu-row-name">' +
                statusDot(task) +
                '<span class="cu-row-title">' + escapeHtml(task.title || '') + '</span>' +
                mandatoryBadge +
                subtaskHint +
                unread +
            '</div>' +
            '<div class="cu-row-cells">' +
                renderRowAssignedByCell(task) +
                renderRowDueDateCell(task) +
                renderRowPriorityCell(task) +
                renderRowCreatedCell(task) +
                '<span class="cu-row-spacer"></span>' +
            '</div>' +
        '</div>';
    }

    function renderHeader(count) {
        var cellsHtml = COLUMNS.map(function (c) {
            return '<span class="cu-th cu-th-' + c.key + '">' + escapeHtml(c.label) +
                (c.key === 'created_at' ? ' <span class="cu-th-sort">↓</span>' : '') +
                '</span>';
        }).join('');
        return '<div class="cu-list-header">' +
            '<span class="cu-th-name">' + count + ' Task' + (count === 1 ? '' : 's') + '</span>' +
            '<div class="cu-th-cells">' + cellsHtml + '</div>' +
        '</div>';
    }

    function renderAddRow() {
        return '<div class="cu-row cu-row-add" id="cuAddRowBtn">' +
            '<span class="cu-row-add-icon">+</span>' +
            '<span class="cu-row-add-label">Add Task</span>' +
        '</div>';
    }

    function renderTasksList(tasks, container) {
        if (!container) return;
        if (!tasks.length) {
            container.innerHTML =
                renderHeader(0) +
                '<div class="cu-empty">' +
                    '<div class="cu-empty-title">No tasks here yet</div>' +
                    '<div class="cu-empty-sub">Click "+ Add Task" or use the row below to create your first task.</div>' +
                '</div>' +
                renderAddRow();
        } else {
            container.innerHTML =
                renderHeader(tasks.length) +
                '<div class="cu-rows" id="cuRowsBody">' +
                    tasks.map(renderRow).join('') +
                '</div>' +
                renderAddRow();
        }

        bindRowClicks(tasks, container);
        var addBtn = container.querySelector('#cuAddRowBtn');
        if (addBtn) addBtn.onclick = function () {
            if (window.TasksModule && window.TasksModule.openTaskSlideover) {
                window.TasksModule.openTaskSlideover(null);
            }
        };
    }

    function bindRowClicks(tasks, container) {
        container.querySelectorAll('.cu-row[data-task-id]').forEach(function (row) {
            var id = parseInt(row.getAttribute('data-task-id'), 10);
            var task = tasks.find(function (t) { return t.id === id; });
            if (!task) return;
            var disabled = rowIsImmutable(task);

            // Cell click handlers (priority / due_date) — must stop propagation
            var isReporter = task.assigned_by && task.assigned_by.id === (config.userId || 0);
            row.querySelectorAll('[data-cell]').forEach(function (cell) {
                cell.onclick = function (e) {
                    e.stopPropagation();
                    if (disabled) return;
                    var which = cell.getAttribute('data-cell');
                    if (which === 'priority') {
                        POP.openPriority(cell, task, function (updated) { updateTaskInList(updated); });
                    } else if (which === 'due_date') {
                        if (!isReporter) {
                            showToast('Only the task creator can change the due date. Assignee can request an extension from the task itself.');
                            return;
                        }
                        POP.openDate(cell, task, function (updated) { updateTaskInList(updated); });
                    }
                };
            });

            // Status dot click toggles status popover (also blocked when immutable)
            var statusEl = row.querySelector('.cu-row-status');
            if (statusEl) {
                statusEl.onclick = function (e) {
                    e.stopPropagation();
                    if (disabled) return;
                    if (task.status === 'completed') {
                        showToast('Task is awaiting verification — open it to verify or reopen.');
                        return;
                    }
                    POP.openStatus(statusEl, task, function (updated) { updateTaskInList(updated); });
                };
            }

            // Click row → open slide-over
            row.addEventListener('click', function () {
                if (window.TasksModule && window.TasksModule.openTaskSlideover) {
                    window.TasksModule.openTaskSlideover(task.id);
                }
            });
        });
    }

    function updateTaskInList(updated) {
        var row = document.querySelector('.cu-row[data-task-id="' + updated.id + '"]');
        if (!row) {
            if (window.TasksModule && window.TasksModule.renderTasks) window.TasksModule.renderTasks();
            return;
        }
        // Replace cell content in place
        var cells = row.querySelector('.cu-row-cells');
        if (cells) {
            cells.innerHTML =
                renderRowAssignedByCell(updated) +
                renderRowDueDateCell(updated) +
                renderRowPriorityCell(updated) +
                renderRowCreatedCell(updated) +
                '<span class="cu-row-spacer"></span>';
        }
        var titleEl = row.querySelector('.cu-row-title');
        if (titleEl && updated.title) titleEl.textContent = updated.title;
        var statusEl = row.querySelector('.cu-row-status');
        if (statusEl) {
            var color = POP.statusColor ? POP.statusColor(updated.status) : '#a1a1aa';
            statusEl.style.background = (updated.status === 'completed' || updated.status === 'closed') ? color : 'transparent';
            statusEl.style.borderColor = color;
        }
        // Reflect immutability when status changes to closed/cancelled
        if (rowIsImmutable(updated)) {
            row.classList.add('cu-row-disabled');
        } else {
            row.classList.remove('cu-row-disabled');
        }
        // Rebind row interactions to use new task object
        var fakeTasks = [updated];
        bindRowClicks(fakeTasks, row.parentElement.parentElement);
    }

    // ── Toolbar wiring ─────────────────────────────────────────────────
    var toolbarBound = false;
    function bindToolbar() {
        if (toolbarBound) return;
        toolbarBound = true;

        // View toggle
        document.querySelectorAll('.cu-view-btn').forEach(function (btn) {
            btn.onclick = function () {
                var mode = btn.getAttribute('data-view');
                document.querySelectorAll('.cu-view-btn').forEach(function (b) { b.classList.toggle('active', b === btn); });
                if (window.TasksModule && window.TasksModule.setViewMode) {
                    window.TasksModule.setViewMode(mode);
                }
            };
        });

        // Combined Filter pill
        var FILTER_OPTIONS = [
            { key: 'all',          label: 'All',                            filter: 'all',                      status: null },
            { key: 'to_me',        label: 'Assigned to me',                 filter: 'assigned_to_me',           status: null },
            { key: 'by_me',        label: 'Assigned by me',                 filter: 'assigned_by_me',           status: null },
            { key: 'awaiting',     label: 'Awaiting reporter verification', filter: 'awaiting_my_verification', status: null },
            { key: 'on_hold',      label: 'On hold',                        filter: 'all',                      status: 'on_hold' },
            { key: 'closed',       label: 'Closed',                         filter: 'all',                      status: 'closed' },
            { key: 'cancelled',    label: 'Cancelled',                      filter: 'all',                      status: 'cancelled' }
        ];
        var filterPillBtn = document.getElementById('cuFilterPillBtn');
        var filterPillLabel = document.getElementById('cuFilterPillLabel');
        if (filterPillBtn) {
            filterPillBtn.onclick = function (e) {
                e.stopPropagation();
                var existing = document.getElementById('cuFilterPop');
                if (existing) { existing.remove(); return; }
                var pop = document.createElement('div');
                pop.id = 'cuFilterPop';
                pop.className = 'cu-pop cu-pop-filter';
                pop.innerHTML = '<div class="cu-pop-title">Filter</div>' +
                    FILTER_OPTIONS.map(function (o) {
                        return '<button type="button" class="cu-pop-row" data-key="' + o.key + '">' +
                            '<span class="cu-pop-row-label">' + escapeHtml(o.label) + '</span>' +
                            '</button>';
                    }).join('');
                document.body.appendChild(pop);
                var ar = filterPillBtn.getBoundingClientRect();
                pop.style.left = Math.max(8, ar.right - pop.offsetWidth) + 'px';
                pop.style.top = (ar.bottom + 4) + 'px';

                pop.querySelectorAll('[data-key]').forEach(function (row) {
                    row.onclick = function () {
                        var key = row.getAttribute('data-key');
                        var opt = FILTER_OPTIONS.find(function (o) { return o.key === key; });
                        pop.remove();
                        if (!opt) return;
                        if (filterPillLabel) filterPillLabel.textContent = opt.label;
                        filterPillBtn.classList.toggle('cu-tb-btn-active', key !== 'all');
                        if (window.TasksModule && window.TasksModule.setCombinedFilter) {
                            window.TasksModule.setCombinedFilter(opt.filter, opt.status);
                        }
                    };
                });
                setTimeout(function () {
                    document.addEventListener('click', function close(e) {
                        if (!pop.contains(e.target)) { pop.remove(); document.removeEventListener('click', close); }
                    });
                }, 0);
            };
        }

        // Closed toggle
        var closedBtn = document.getElementById('cuClosedBtn');
        if (closedBtn) {
            closedBtn.onclick = function () {
                var on = closedBtn.getAttribute('data-on') === '1';
                closedBtn.setAttribute('data-on', on ? '0' : '1');
                closedBtn.classList.toggle('cu-tb-btn-off', on);
                if (window.TasksModule && window.TasksModule.setIncludeClosed) {
                    window.TasksModule.setIncludeClosed(!on);
                }
            };
        }

        // "Assigned by" filter dropdown — filter tasks by who created them
        var assignedByBtn = document.getElementById('cuAssignedByBtn');
        var assignedByLabel = document.getElementById('cuAssignedByLabel');
        if (assignedByBtn) {
            assignedByBtn.onclick = function (e) {
                e.stopPropagation();
                var existing = document.getElementById('cuAssignedByPop');
                if (existing) { existing.remove(); return; }
                var people = config.MODAL_PEOPLE || [];
                var pop = document.createElement('div');
                pop.id = 'cuAssignedByPop';
                pop.className = 'cu-pop cu-pop-assignee';
                pop.innerHTML =
                    '<div class="cu-pop-title">Filter by who assigned</div>' +
                    '<div class="cu-pop-search"><input type="text" placeholder="Search..." class="cu-pop-search-input"></div>' +
                    '<div class="cu-pop-list">' +
                        '<button type="button" class="cu-pop-row" data-uid="">' +
                            '<span class="cu-pop-row-label">Anyone</span>' +
                        '</button>' +
                        people.map(function (p) {
                            return '<button type="button" class="cu-pop-row" data-uid="' + p.id + '" data-name="' + escapeHtml(p.name) + '">' +
                                '<span class="cu-avatar">' + getInitials(p.name) + '</span>' +
                                '<span class="cu-pop-row-label">' + escapeHtml(p.name) + '</span>' +
                                '</button>';
                        }).join('') +
                    '</div>';
                document.body.appendChild(pop);
                var ar = assignedByBtn.getBoundingClientRect();
                pop.style.left = Math.max(8, ar.right - pop.offsetWidth) + 'px';
                pop.style.top = (ar.bottom + 4) + 'px';

                var search = pop.querySelector('.cu-pop-search-input');
                if (search) {
                    search.focus();
                    search.oninput = function () {
                        var q = search.value.toLowerCase();
                        pop.querySelectorAll('[data-name]').forEach(function (row) {
                            var name = (row.getAttribute('data-name') || '').toLowerCase();
                            row.style.display = name.indexOf(q) >= 0 ? '' : 'none';
                        });
                    };
                }

                pop.querySelectorAll('[data-uid]').forEach(function (row) {
                    row.onclick = function () {
                        var uid = row.getAttribute('data-uid');
                        var name = row.getAttribute('data-name') || '';
                        pop.remove();
                        if (assignedByLabel) {
                            assignedByLabel.textContent = uid ? name : 'Assigned by';
                        }
                        assignedByBtn.classList.toggle('cu-tb-btn-active', !!uid);
                        if (window.TasksModule && window.TasksModule.setAssignedByFilter) {
                            window.TasksModule.setAssignedByFilter(uid ? parseInt(uid, 10) : null);
                        }
                    };
                });
                setTimeout(function () {
                    document.addEventListener('click', function close(e) {
                        if (!pop.contains(e.target)) { pop.remove(); document.removeEventListener('click', close); }
                    });
                }, 0);
            };
        }

        // Search input — debounced
        var searchEl = document.getElementById('tasksSearchInput');
        if (searchEl) {
            var t = null;
            searchEl.addEventListener('input', function (e) {
                clearTimeout(t);
                t = setTimeout(function () {
                    if (window.TasksModule && window.TasksModule.setSearch) {
                        window.TasksModule.setSearch(e.target.value.trim());
                    }
                }, 300);
            });
        }

        // "Assignee" filter — only people I've actually assigned tasks to
        var assigneeBtn = document.getElementById('cuAssigneeBtn');
        var assigneeLabel = document.getElementById('cuAssigneeLabel');
        if (assigneeBtn) {
            assigneeBtn.onclick = function (e) {
                e.stopPropagation();
                var existing = document.getElementById('cuAssigneePop');
                if (existing) { existing.remove(); return; }
                var pop = document.createElement('div');
                pop.id = 'cuAssigneePop';
                pop.className = 'cu-pop cu-pop-assignee';
                pop.innerHTML =
                    '<div class="cu-pop-title">View tasks assigned to</div>' +
                    '<div class="cu-pop-search"><input type="text" placeholder="Search..." class="cu-pop-search-input"></div>' +
                    '<div class="cu-pop-list" id="cuAssigneeList"><div class="tthread-loading">Loading...</div></div>';
                document.body.appendChild(pop);
                var ar = assigneeBtn.getBoundingClientRect();
                pop.style.left = Math.max(8, ar.right - pop.offsetWidth) + 'px';
                pop.style.top = (ar.bottom + 4) + 'px';

                fetch('/api/tessa/tasks/my-assignees-options', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        var people = data.options || [];
                        var listEl = pop.querySelector('#cuAssigneeList');
                        if (!people.length) {
                            listEl.innerHTML = '<div class="cu-dep-empty">You haven\'t assigned any tasks yet.</div>';
                            return;
                        }
                        listEl.innerHTML =
                            '<button type="button" class="cu-pop-row" data-uid=""><span class="cu-pop-row-label">Anyone</span></button>' +
                            people.map(function (p) {
                                return '<button type="button" class="cu-pop-row" data-uid="' + p.id + '" data-name="' + escapeHtml(p.name) + '">' +
                                    '<span class="cu-avatar">' + getInitials(p.name) + '</span>' +
                                    '<span class="cu-pop-row-label">' + escapeHtml(p.name) + '</span>' +
                                    '</button>';
                            }).join('');

                        var search = pop.querySelector('.cu-pop-search-input');
                        if (search) {
                            search.focus();
                            search.oninput = function () {
                                var q = search.value.toLowerCase();
                                listEl.querySelectorAll('[data-name]').forEach(function (row) {
                                    var name = (row.getAttribute('data-name') || '').toLowerCase();
                                    row.style.display = name.indexOf(q) >= 0 ? '' : 'none';
                                });
                            };
                        }

                        listEl.querySelectorAll('[data-uid]').forEach(function (row) {
                            row.onclick = function () {
                                var uid = row.getAttribute('data-uid');
                                var name = row.getAttribute('data-name') || '';
                                pop.remove();
                                if (assigneeLabel) assigneeLabel.textContent = uid ? name : 'Assignee';
                                assigneeBtn.classList.toggle('cu-tb-btn-active', !!uid);
                                if (window.TasksModule && window.TasksModule.setAssigneeFilter) {
                                    window.TasksModule.setAssigneeFilter(uid ? parseInt(uid, 10) : null);
                                }
                            };
                        });
                    })
                    .catch(function () {
                        var listEl = pop.querySelector('#cuAssigneeList');
                        if (listEl) listEl.innerHTML = '<div class="cu-dep-empty">Failed to load.</div>';
                    });

                setTimeout(function () {
                    document.addEventListener('click', function close(e) {
                        if (!pop.contains(e.target)) { pop.remove(); document.removeEventListener('click', close); }
                    });
                }, 0);
            };
        }

        // "Me" badge avatar
        var meBadge = document.getElementById('cuMeBadge');
        if (meBadge && config.userName) {
            meBadge.textContent = getInitials(config.userName);
        }
    }

    window.TasksListView = {
        render: renderTasksList,
        bindToolbar: bindToolbar,
        updateTaskInList: updateTaskInList
    };
})();
