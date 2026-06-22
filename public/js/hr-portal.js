/**
 * HR Module — Employee management, Profile, Leave management, and HR Dashboard.
 * Extracted from portal.js into a standalone IIFE.
 * Exposes window.HRModule with render functions.
 */
(function () {
    'use strict';

    /* ── Shared utility proxies ── */
    function requestJson(url, options) { return MeetingModule.requestJson(url, options); }
    function escapeHtml(v) { return MeetingModule.escapeHtml(v); }
    // Shared <datalist> of designation suggestions ("AI Intern" first) so the
    // free-text Designation inputs offer consistent one-click picks while still
    // accepting any custom text.
    function designationDatalist(listId) {
        var opts = (cachedDesignationSuggestions || []).map(function (d) {
            return '<option value="' + escapeHtml(d) + '"></option>';
        }).join('');
        return '<datalist id="' + listId + '">' + opts + '</datalist>';
    }
    // Today's month-day in IST. Matches the year-stripped `birthday` the API
    // sends for the directory, and the m-d slice of a full DOB on My Profile.
    function bdayTodayMd() {
        return new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Kolkata', month: '2-digit', day: '2-digit' });
    }

    /* ── Cached data ── */
    var cachedRoles = [];
    var cachedDepartments = [];
    var cachedDesignations = [];
    var cachedDesignationSuggestions = [];
    var cachedCanSeeSalary = false;
    var cachedCanManage = true;
    // Add Member "candidate mode": prefill carried in from the Hiring pipeline's
    // "Add to Team" CTA (name/work-email/personal-email/mobile/designation +
    // candidate_id). Null for a normal manual add.
    var pendingAddPrefill = null;
    // Cross-view trigger: when "Add to Team" switches into the Team section, this
    // holds the prefill until renderEmployees finishes — which then opens the form,
    // so the async team render switchView kicks off can't clobber it.
    var pendingCrossViewAdd = null;

    /* ── Employee Management ── */
    var empSearch = '';
    var empType = '';
    var empDocStatus = '';
    var empStatusFilter = '';
    var empDeptFilter = '';
    var empShowAll = false;
    var empExpanded = null;
    var empScrollAnchor = null;

    function stringToColor(str) {
        var hash = 0;
        for (var i = 0; i < str.length; i++) hash = str.charCodeAt(i) + ((hash << 5) - hash);
        var h = Math.abs(hash) % 360;
        return 'hsl(' + h + ',50%,35%)';
    }

    function statusBadge(status) {
        var map = {
            'active': { cls: 'emp-status-active', label: 'Active' },
            'probation': { cls: 'emp-status-probation', label: 'Probation' },
            'intern': { cls: 'emp-status-intern', label: 'Intern' },
            'notice_period': { cls: 'emp-status-notice', label: 'Notice Period' },
            'resigned': { cls: 'emp-status-resigned', label: 'Resigned' },
            'terminated': { cls: 'emp-status-terminated', label: 'Terminated' },
            'absconding': { cls: 'emp-status-resigned', label: 'Absconding' },
            'exited': { cls: 'emp-status-resigned', label: 'Exited' }
        };
        var s = map[status] || { cls: 'emp-status-active', label: status || 'Active' };
        return '<span class="emp-status-badge ' + s.cls + '">' + s.label + '</span>';
    }

    function daysRemaining(dateStr) {
        if (!dateStr) return '';
        var diff = Math.ceil((new Date(dateStr) - new Date()) / (1000 * 60 * 60 * 24));
        if (diff < 0) return '<span style="color:#ef4444">' + Math.abs(diff) + 'd overdue</span>';
        if (diff <= 7) return '<span style="color:#f59e0b">' + diff + 'd left</span>';
        return '<span style="color:#6b7280">' + diff + 'd left</span>';
    }

    function hideSubPages() {
        ['addMemberView', 'editMemberView', 'promoteMemberView'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.classList.add('hidden');
        });
    }

    /**
     * Bind the document Upload / Delete buttons inside `rootEl` to the shared
     * /api/employees upload_doc / delete_doc actions. `onDone` runs after a
     * successful change so the host view can re-render. Used by both the Team tab
     * and the Employee Records → Documents tab.
     */
    function bindDocActions(rootEl, onDone) {
        rootEl.querySelectorAll('.emp-doc-upload-btn').forEach(function (btn) {
            btn.addEventListener('click', function (ev) {
                ev.stopPropagation();
                var userId = btn.getAttribute('data-user');
                var field = btn.getAttribute('data-field');
                var input = document.createElement('input');
                input.type = 'file';
                input.accept = '.pdf,.jpg,.jpeg,.png';
                input.onchange = function () {
                    if (!input.files[0]) return;
                    btn.disabled = true;
                    btn.textContent = 'Uploading...';
                    var fd = new FormData();
                    fd.append('action', 'upload_doc');
                    fd.append('id', userId);
                    fd.append('field', field);
                    fd.append('file', input.files[0]);
                    fetch('/api/employees', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: fd
                    }).then(function (r) { return r.json(); }).then(function (body) {
                        if (body.ok) { onDone(); }
                        else { alert(body.error || 'Upload failed'); btn.disabled = false; btn.textContent = 'Upload'; }
                    }).catch(function () { btn.disabled = false; btn.textContent = 'Upload'; });
                };
                input.click();
            });
        });

        rootEl.querySelectorAll('.emp-doc-del-btn').forEach(function (btn) {
            btn.addEventListener('click', function (ev) {
                ev.stopPropagation();
                if (!confirm('Delete this document?')) return;
                btn.disabled = true;
                btn.textContent = '...';
                fetch('/api/employees', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ action: 'delete_doc', id: parseInt(btn.getAttribute('data-user'), 10), field: btn.getAttribute('data-field') })
                }).then(function (r) { return r.json(); }).then(function (body) {
                    if (body.ok) onDone();
                    else { alert(body.error || 'Delete failed'); btn.disabled = false; btn.textContent = 'Delete'; }
                }).catch(function () { btn.disabled = false; btn.textContent = 'Delete'; });
            });
        });
    }

    /* ── Employee Records → Documents tab ──
       A Tessa-native browser over /api/employees (the same data + documents the Team
       tab uses), replacing the old Google Drive folder embed that 403'd. Lists each
       employee with their uploaded documents; HR uploads/deletes inline. Reads live
       from the DB, so it always reflects the current state ("autofill from Tessa"). */
    var hrDocsSearch = '';
    var hrDocsMissingOnly = false;
    var hrDocsCache = null;
    var hrDocsCanManage = false;

    async function renderHrRecordsDocs(reload) {
        var box = document.getElementById('hrRecDocs');
        if (!box) return;

        if (reload || !hrDocsCache) {
            box.innerHTML = '<div class="kpi-status-msg" style="padding:24px;color:#9ca3af;">Loading documents…</div>';
            try {
                var data = await requestJson('/api/employees');
                hrDocsCache = data.employees || [];
                hrDocsCanManage = (data.can_manage !== false);
            } catch (err) {
                console.error('renderHrRecordsDocs failed', err);
                box.innerHTML = '<div class="emp-empty" style="padding:24px;color:#9ca3af;">Failed to load documents.</div>';
                return;
            }
        }

        var q = hrDocsSearch.trim().toLowerCase();
        var employees = hrDocsCache.filter(function (e) {
            return !q || (e.name || '').toLowerCase().indexOf(q) !== -1;
        });

        var html = '<div style="padding:16px 18px 48px;">';
        html += '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px;position:sticky;top:0;background:#0b0b0b;padding:8px 0;z-index:2;">' +
            '<h2 style="margin:0;font-size:18px;color:#fff;">Employee Documents</h2>' +
            '<span style="color:#9ca3af;font-size:13px;">' + employees.length + ' employees</span>' +
            '<input id="hrDocsSearch" type="text" placeholder="Search name…" value="' + escapeHtml(hrDocsSearch) + '" ' +
                'style="margin-left:auto;background:#1a1a2e;border:1px solid #2a2a3e;color:#fff;border-radius:8px;padding:7px 11px;font-size:13px;min-width:200px;" />' +
            '<label style="display:flex;align-items:center;gap:6px;color:#cbd5e1;font-size:13px;cursor:pointer;white-space:nowrap;">' +
                '<input id="hrDocsMissingOnly" type="checkbox"' + (hrDocsMissingOnly ? ' checked' : '') + ' /> Missing only</label>' +
        '</div>';

        var shown = 0;
        employees.forEach(function (e) {
            var docs = e.documents || {};
            var keys = Object.keys(docs);
            if (!keys.length) return;
            var uploaded = keys.filter(function (k) { return docs[k].uploaded; }).length;
            var tileKeys = hrDocsMissingOnly ? keys.filter(function (k) { return !docs[k].uploaded; }) : keys;
            if (!tileKeys.length) return;
            shown++;

            html += '<div style="border:1px solid #2a2a3e;border-radius:10px;padding:12px 14px;margin-bottom:12px;background:#13131f;">' +
                '<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap;">' +
                    '<span style="font-weight:600;color:#fff;font-size:14px;">' + escapeHtml(e.name || '—') + '</span>' +
                    (e.designation ? '<span style="color:#9ca3af;font-size:12px;">' + escapeHtml(e.designation) + '</span>' : '') +
                    '<span style="margin-left:auto;color:' + (uploaded === keys.length ? '#4ade80' : '#f59e0b') + ';font-size:12px;">' + uploaded + '/' + keys.length + ' uploaded</span>' +
                '</div>' +
                '<div class="emp-docs-grid">';
            tileKeys.forEach(function (dk) {
                var d = docs[dk];
                html += '<div class="emp-doc-tile ' + (d.uploaded ? 'emp-doc-tile-ok' : 'emp-doc-tile-miss') + '">' +
                    (d.uploaded
                        ? '<a href="' + escapeHtml(d.path) + '" target="_blank" class="emp-doc-link">' + escapeHtml(d.label) + '</a>'
                        : '<span class="emp-doc-missing-label">' + escapeHtml(d.label) + '</span>') +
                    '<span class="emp-doc-status">' + (d.uploaded ? 'Uploaded' : 'Missing') + '</span>' +
                    (hrDocsCanManage
                        ? (d.uploaded
                            ? '<button class="emp-doc-del-btn" data-user="' + e.id + '" data-field="' + escapeHtml(dk) + '">Delete</button>'
                            : '<button class="emp-doc-upload-btn" data-user="' + e.id + '" data-field="' + escapeHtml(dk) + '" data-label="' + escapeHtml(d.label) + '">Upload</button>')
                        : '') +
                    (dk === 'nda_path'
                        ? '<a href="/api/employees/' + e.id + '/nda" target="_blank" style="display:block;margin-top:4px;font-size:12px;color:#60a5fa;text-decoration:none">⬇ Generate pre-filled NDA</a>'
                        : '') +
                '</div>';
            });
            html += '</div>'; // close .emp-docs-grid

            // Drive folder embed (lazy) — shown alongside the tiles, not replacing them.
            var driveId = e.google_drive_folder_id || '';
            if (driveId) {
                html += '<div class="emp-drive-row">' +
                    '<a class="emp-drive-open" href="https://drive.google.com/drive/folders/' + encodeURIComponent(driveId) + '" target="_blank" rel="noopener">Open in Drive &#8599;</a>' +
                    '<button type="button" class="emp-drive-toggle" data-folder="' + escapeHtml(driveId) + '">Show embedded folder &#9662;</button>' +
                    '<div class="emp-drive-embed" style="display:none" data-loaded="0"></div>' +
                '</div>';
            } else {
                html += '<div class="emp-drive-row emp-drive-none">No Drive folder yet — uploads sync here once Google Drive is set up.</div>';
            }
            html += '</div>'; // close card
        });

        if (!shown) {
            html += '<div class="emp-empty" style="padding:24px;color:#9ca3af;">No documents match your filter.</div>';
        }
        html += '</div>';
        box.innerHTML = html;

        var searchEl = document.getElementById('hrDocsSearch');
        if (searchEl) {
            searchEl.onchange = function () { hrDocsSearch = this.value; renderHrRecordsDocs(false); };
            searchEl.onkeydown = function (ev) { if (ev.key === 'Enter') this.blur(); };
        }
        var missingEl = document.getElementById('hrDocsMissingOnly');
        if (missingEl) missingEl.onchange = function () { hrDocsMissingOnly = this.checked; renderHrRecordsDocs(false); };

        // Drive embed toggles — lazy-inject the iframe on first open so we don't load
        // one iframe per employee up front.
        box.querySelectorAll('.emp-drive-toggle').forEach(function (btn) {
            btn.onclick = function () {
                var embed = btn.parentNode.querySelector('.emp-drive-embed');
                if (!embed) return;
                if (embed.getAttribute('data-loaded') === '0') {
                    var fid = btn.getAttribute('data-folder');
                    embed.innerHTML = '<iframe class="emp-drive-iframe" loading="lazy" src="https://drive.google.com/embeddedfolderview?id=' + encodeURIComponent(fid) + '#grid"></iframe>';
                    embed.setAttribute('data-loaded', '1');
                    embed.style.display = '';
                    btn.innerHTML = 'Hide embedded folder &#9652;';
                    return;
                }
                var hidden = embed.style.display === 'none';
                embed.style.display = hidden ? '' : 'none';
                btn.innerHTML = hidden ? 'Hide embedded folder &#9652;' : 'Show embedded folder &#9662;';
            };
        });

        // Upload / delete reuse the Team tab's flow; reload from the server on success.
        bindDocActions(box, function () { renderHrRecordsDocs(true); });
    }

    /* ── Employee Records → Employee Documents: native Drive folder browser ──
       Restyled folder/file tiles over GET /api/employees/drive-folder (writer-backed listing).
       Folder clicks navigate in-page (breadcrumb stack); file clicks preview inline via Drive's
       embed-friendly .../preview URL. No target="_blank" anywhere → nothing opens a new tab. */
    var driveStack = []; // [{id, name}] — index 0 is the root (master HR folder)

    function driveFolderSvg() {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>';
    }
    function driveFileSvg() {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>';
    }

    // Entry point (called on tab activation). Resets to the root folder unless reset===false.
    function renderDriveBrowser(reset) {
        var box = document.getElementById('hrRecDocs');
        if (!box) return;
        if (reset !== false || !driveStack.length) {
            driveStack = [{ id: '', name: 'Employee Documents' }];
        }
        driveLoadCurrent();
    }

    function driveNavTo(id, name) {
        driveStack.push({ id: id, name: name });
        driveLoadCurrent();
    }

    function driveCrumbTo(index) {
        driveStack = driveStack.slice(0, index + 1);
        driveLoadCurrent();
    }

    async function driveLoadCurrent() {
        var box = document.getElementById('hrRecDocs');
        if (!box) return;
        var cur = driveStack[driveStack.length - 1] || { id: '', name: 'Employee Documents' };

        var crumbs = driveStack.map(function (c, i) {
            var last = i === driveStack.length - 1;
            return '<button type="button" class="drvb-crumb' + (last ? ' is-current' : '') + '" data-idx="' + i + '"' + (last ? ' disabled' : '') + '>' + escapeHtml(c.name) + '</button>' +
                (last ? '' : '<span class="drvb-crumb-sep">&#8250;</span>');
        }).join('');

        box.innerHTML = '<div class="drvb-wrap">' +
            '<div class="drvb-bar">' + crumbs + '</div>' +
            '<div class="drvb-body"><div class="drvb-msg">Loading…</div></div>' +
        '</div>';

        box.querySelectorAll('.drvb-crumb').forEach(function (btn) {
            if (btn.disabled) return;
            btn.onclick = function () { driveCrumbTo(parseInt(btn.getAttribute('data-idx'), 10)); };
        });

        var bodyEl = box.querySelector('.drvb-body');
        var data;
        try {
            data = await requestJson('/api/employees/drive-folder' + (cur.id ? ('?folder=' + encodeURIComponent(cur.id)) : ''));
        } catch (err) {
            bodyEl.innerHTML = '<div class="drvb-msg">Failed to load this folder.</div>';
            return;
        }

        if (!data || !data.ok) {
            bodyEl.innerHTML = '<div class="drvb-msg">' +
                ((data && data.reason === 'unconfigured')
                    ? 'Google Drive isn’t connected. Ask an HR admin to reconnect Google.'
                    : 'Couldn’t open this folder.') +
            '</div>';
            return;
        }

        var files = data.files || [];
        if (!files.length) {
            bodyEl.innerHTML = '<div class="drvb-msg">This folder is empty.</div>';
            return;
        }

        var html = '<div class="drvb-grid">';
        files.forEach(function (f) {
            if (!f.id) return;
            var kind = f.is_folder ? 'folder' : 'file';
            var del = '<button type="button" class="drvb-del" title="Delete" ' +
                'data-id="' + escapeHtml(f.id) + '" data-name="' + escapeHtml(f.name) + '" data-kind="' + kind + '">&#128465;</button>';
            if (f.is_folder) {
                html += '<div class="drvb-tile-wrap">' +
                    '<button type="button" class="drvb-tile drvb-tile-folder" data-id="' + escapeHtml(f.id) + '" data-name="' + escapeHtml(f.name) + '">' +
                        '<span class="drvb-ic drvb-ic-folder">' + driveFolderSvg() + '</span>' +
                        '<span class="drvb-name">' + escapeHtml(f.name) + '</span>' +
                    '</button>' + del +
                '</div>';
            } else {
                html += '<div class="drvb-tile-wrap">' +
                    '<button type="button" class="drvb-tile drvb-tile-file" data-id="' + escapeHtml(f.id) + '" data-name="' + escapeHtml(f.name) + '">' +
                        '<span class="drvb-ic">' + (f.iconLink ? '<img src="' + escapeHtml(f.iconLink) + '" alt="" />' : driveFileSvg()) + '</span>' +
                        '<span class="drvb-name">' + escapeHtml(f.name) + '</span>' +
                    '</button>' + del +
                '</div>';
            }
        });
        html += '</div>';
        bodyEl.innerHTML = html;

        bodyEl.querySelectorAll('.drvb-tile-folder').forEach(function (t) {
            t.onclick = function () { driveNavTo(t.getAttribute('data-id'), t.getAttribute('data-name')); };
        });
        bodyEl.querySelectorAll('.drvb-tile-file').forEach(function (t) {
            t.onclick = function () { openDrivePreview(t.getAttribute('data-id'), t.getAttribute('data-name')); };
        });
        bodyEl.querySelectorAll('.drvb-del').forEach(function (b) {
            b.onclick = function (ev) {
                ev.stopPropagation();
                driveDelete(b.getAttribute('data-id'), b.getAttribute('data-name'), b.getAttribute('data-kind'));
            };
        });
    }

    // Delete (→ Google Drive Trash, recoverable ~30 days). Double-confirmed: files take two
    // confirms; folders also require typing the folder name (the delete cascades to everything
    // inside). The backend re-checks the item is inside the master HR tree and refuses the root.
    async function driveDelete(id, name, kind) {
        if (!id) return;
        name = name || 'this item';
        if (kind === 'folder') {
            if (!confirm('Delete the entire "' + name + '" folder and EVERYTHING inside it?')) return;
            var typed = prompt('This removes every document in "' + name + '". Type the folder name to confirm:');
            if (typed === null) return;
            if (typed.trim() !== String(name).trim()) { alert('Name did not match — nothing was deleted.'); return; }
        } else {
            if (!confirm('Move "' + name + '" to Trash?')) return;
            if (!confirm('It will be removed from Employee Documents (recoverable from Google Drive Trash for ~30 days). Delete now?')) return;
        }
        try {
            var resp = await fetch('/api/employees/drive-item/trash', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ id: id })
            });
            var data = await resp.json().catch(function () { return {}; });
            if (resp.ok && data && data.ok) {
                driveLoadCurrent();
            } else {
                alert((data && data.error) || 'Could not delete. Please try again.');
            }
        } catch (e) {
            alert('Could not delete. Please check your connection and try again.');
        }
    }

    // In-Tessa file preview — Drive's embed-friendly .../preview URL renders inline (the folder is
    // shared "anyone with the link", so it loads without the viewer's own Drive access). No new tab.
    function openDrivePreview(id, name) {
        var existing = document.getElementById('drvbPreview');
        if (existing) existing.remove();
        if (!id) return;
        var modal = document.createElement('div');
        modal.className = 'drvb-preview';
        modal.id = 'drvbPreview';
        modal.innerHTML = '<div class="drvb-preview-box">' +
            '<div class="drvb-preview-head"><span>' + escapeHtml(name || 'Document') + '</span>' +
                '<button type="button" class="drvb-preview-close" aria-label="Close">&#10005;</button></div>' +
            '<div class="drvb-preview-body">' +
                '<iframe src="https://drive.google.com/file/d/' + encodeURIComponent(id) + '/preview" allow="autoplay" title="' + escapeHtml(name || 'Document') + '"></iframe>' +
            '</div></div>';
        document.body.appendChild(modal);
        requestAnimationFrame(function () { modal.classList.add('is-open'); });
        var close = function () { modal.remove(); document.removeEventListener('keydown', drivePreviewEsc); };
        modal.addEventListener('click', function (ev) { if (ev.target === modal) close(); });
        modal.querySelector('.drvb-preview-close').onclick = close;
        document.addEventListener('keydown', drivePreviewEsc);
    }

    function drivePreviewEsc(ev) {
        if (ev.key !== 'Escape') return;
        var m = document.getElementById('drvbPreview');
        if (m) { m.remove(); document.removeEventListener('keydown', drivePreviewEsc); }
    }

    async function renderEmployees() {
        var root = document.getElementById('employeesView');
        if (!root) return;
        hideSubPages();
        root.classList.remove('hidden');
        root.innerHTML = '<div class="emp-wrap"><div class="kpi-status-msg">Loading team...</div></div>';

        try {
            var url = '/api/employees';
            var params = [];
            if (empSearch) params.push('search=' + encodeURIComponent(empSearch));
            if (empType) params.push('employment_type=' + encodeURIComponent(empType));
            if (empDocStatus) params.push('doc_status=' + encodeURIComponent(empDocStatus));
            if (empStatusFilter) params.push('employee_status=' + encodeURIComponent(empStatusFilter));
            if (empDeptFilter) params.push('department_id=' + encodeURIComponent(empDeptFilter));
            if (empShowAll) params.push('show_all=1');
            if (params.length) url += '?' + params.join('&');

            var data = await requestJson(url);
            var employees = data.employees || [];
            var stats = data.stats || {};
            cachedRoles = data.roles || [];
            cachedDepartments = data.departments || [];
            cachedDesignations = data.designations || [];
            cachedDesignationSuggestions = data.designation_suggestions || [];
            cachedCanSeeSalary = data.can_see_salary || false;
            // Read-only viewers (e.g. Finance can download but not edit) — hide write controls.
            cachedCanManage = (data.can_manage !== false);

            // Stats strip
            var html = '<div class="emp-wrap">';
            html += '<div class="emp-header"><h2 class="emp-title">Team</h2><span class="emp-count">' + stats.total + ' members</span>' +
                '<div style="margin-left:auto;display:flex;gap:8px;align-items:center">' +
                    '<button class="btn btn-outline emp-download-btn" id="empDownloadBtn" style="position:relative">⬇ Download Data</button>' +
                    (cachedCanManage ? '<button class="btn btn-primary emp-add-btn" id="empAddBtn">+ Add Member</button>' : '') +
                '</div>' +
            '</div>';

            html += '<div class="emp-stats">' +
                '<div class="emp-stat-pill"><span class="emp-stat-num">' + stats.total + '</span><span class="emp-stat-lbl">Total</span></div>' +
                '<div class="emp-stat-pill"><span class="emp-stat-num">' + stats.full_time + '</span><span class="emp-stat-lbl">Full-time</span></div>' +
                '<div class="emp-stat-pill"><span class="emp-stat-num">' + stats.internship + '</span><span class="emp-stat-lbl">Interns</span></div>' +
                '<div class="emp-stat-pill" style="border-color:#8b5cf6"><span class="emp-stat-num">' + (stats.freelancer || 0) + '</span><span class="emp-stat-lbl">Freelancers</span></div>' +
                '<div class="emp-stat-pill" style="border-color:#f59e0b"><span class="emp-stat-num">' + stats.on_probation + '</span><span class="emp-stat-lbl">Probation</span></div>' +
                '<div class="emp-stat-pill" style="border-color:#f97316"><span class="emp-stat-num">' + stats.notice_period + '</span><span class="emp-stat-lbl">Notice</span></div>' +
                '<div class="emp-stat-pill emp-stat-good"><span class="emp-stat-num">' + stats.docs_complete + '</span><span class="emp-stat-lbl">Docs OK</span></div>' +
                '<div class="emp-stat-pill emp-stat-warn"><span class="emp-stat-num">' + stats.docs_pending + '</span><span class="emp-stat-lbl">Docs Pending</span></div>' +
                (stats.joined_this_month ? '<div class="emp-stat-pill" style="border-color:#22c55e"><span class="emp-stat-num">' + stats.joined_this_month + '</span><span class="emp-stat-lbl">Joined (Month)</span></div>' : '') +
                (stats.exited_this_month ? '<div class="emp-stat-pill" style="border-color:#ef4444"><span class="emp-stat-num">' + stats.exited_this_month + '</span><span class="emp-stat-lbl">Exited (Month)</span></div>' : '') +
            '</div>';

            // Filters
            html += '<div class="emp-filters">' +
                '<input type="text" class="emp-search" id="empSearch" placeholder="Search by name..." value="' + escapeHtml(empSearch) + '">' +
                '<select class="emp-select" id="empStatusFilter">' +
                    '<option value="">All Status</option>' +
                    '<option value="active"' + (empStatusFilter === 'active' ? ' selected' : '') + '>Active</option>' +
                    '<option value="probation"' + (empStatusFilter === 'probation' ? ' selected' : '') + '>Probation</option>' +
                    '<option value="intern"' + (empStatusFilter === 'intern' ? ' selected' : '') + '>Intern</option>' +
                    '<option value="notice_period"' + (empStatusFilter === 'notice_period' ? ' selected' : '') + '>Notice Period</option>' +
                    '<option value="exited"' + (empStatusFilter === 'exited' ? ' selected' : '') + '>Exited</option>' +
                    '<option value="resigned"' + (empStatusFilter === 'resigned' ? ' selected' : '') + '>Resigned</option>' +
                    '<option value="terminated"' + (empStatusFilter === 'terminated' ? ' selected' : '') + '>Terminated</option>' +
                    '<option value="freelancer"' + (empStatusFilter === 'freelancer' ? ' selected' : '') + '>Freelancer</option>' +
                '</select>' +
                '<select class="emp-select" id="empTypeFilter">' +
                    '<option value="">All Types</option>' +
                    '<option value="full_time"' + (empType === 'full_time' ? ' selected' : '') + '>Full-time</option>' +
                    '<option value="internship"' + (empType === 'internship' ? ' selected' : '') + '>Internship</option>' +
                    '<option value="freelancer"' + (empType === 'freelancer' ? ' selected' : '') + '>Freelancer</option>' +
                '</select>' +
                '<select class="emp-select" id="empDeptFilter">' +
                    '<option value="">All Depts</option>';
            cachedDepartments.forEach(function (d) {
                html += '<option value="' + d.id + '"' + (empDeptFilter == d.id ? ' selected' : '') + '>' + escapeHtml(d.name) + '</option>';
            });
            html += '</select>' +
                '<select class="emp-select" id="empDocFilter">' +
                    '<option value="">All Docs</option>' +
                    '<option value="complete"' + (empDocStatus === 'complete' ? ' selected' : '') + '>Docs Complete</option>' +
                    '<option value="incomplete"' + (empDocStatus === 'incomplete' ? ' selected' : '') + '>Docs Incomplete</option>' +
                '</select>' +
                '<label style="display:flex;align-items:center;gap:4px;font-size:12px;color:#6b7280;cursor:pointer"><input type="checkbox" id="empShowAll"' + (empShowAll ? ' checked' : '') + '> Show exited</label>' +
                (empSearch || empType || empDocStatus || empStatusFilter || empDeptFilter || empShowAll ? '<button class="emp-clear-btn" id="empClearBtn">Clear</button>' : '') +
            '</div>';

            // Employee cards
            if (!employees.length) {
                html += '<div class="emp-empty">No team members found.</div>';
            } else {
                html += '<div class="emp-list">';
                employees.forEach(function (e) {
                    var isExpanded = empExpanded === e.id;
                    var typeBadge = e.employment_type === 'full_time'
                        ? '<span class="badge emp-badge emp-badge-ft">Full-time</span>'
                        : (e.employment_type === 'internship' ? '<span class="badge emp-badge emp-badge-int">Intern</span>'
                        : (e.employment_type === 'freelancer' ? '<span class="badge emp-badge" style="background:#8b5cf6;color:#fff">Freelancer</span>' : ''));

                    // Doc status icons (5 key docs)
                    var docs = e.documents || {};
                    var docIcons = '';
                    var keyDocs = ['aadhar_front_path', 'pan_path', 'passport_photo_path', 'signed_offer_letter_path', 'nda_path'];
                    var keyLabels = ['Aadhar', 'PAN', 'Photo', 'Offer', 'NDA'];
                    keyDocs.forEach(function (dk, i) {
                        var d = docs[dk];
                        var up = d && d.uploaded;
                        docIcons += '<span class="emp-doc-icon ' + (up ? 'emp-doc-ok' : 'emp-doc-miss') + '" title="' + keyLabels[i] + (up ? ' (uploaded)' : ' (missing)') + '">' +
                            (up ? '&#10003;' : '&#10007;') + '</span>';
                    });

                    html += '<div class="emp-card' + (isExpanded ? ' emp-card-expanded' : '') + (!e.is_active ? ' emp-card-inactive' : '') + ((e.birthday && e.birthday === bdayTodayMd()) ? ' emp-card--birthday' : '') + '" data-id="' + e.id + '">' +
                        '<div class="emp-card-row">' +
                            '<div class="emp-card-left">' +
                                '<div class="emp-card-avatar" style="background:' + stringToColor(e.name) + '">' + e.name.charAt(0).toUpperCase() + '</div>' +
                                '<div class="emp-card-info">' +
                                    '<div class="emp-card-name">' + escapeHtml(e.name) + ' ' + statusBadge(e.employee_status) + ((e.birthday && e.birthday === bdayTodayMd()) ? ' <span class="emp-birthday-badge">🎂 Birthday today</span>' : '') + '</div>' +
                                    '<div class="emp-card-role">' + escapeHtml(e.designation || e.role || '') + ' ' + typeBadge +
                                        (e.department ? ' <span style="color:#6b7280;font-size:11px">(' + escapeHtml(e.department) + ')</span>' : '') +
                                    '</div>' +
                                '</div>' +
                            '</div>' +
                            '<div class="emp-card-mid">' +
                                (e.personal_mobile ? '<div class="emp-card-detail"><span class="emp-detail-lbl">Mobile</span> ' + escapeHtml(e.personal_mobile) + '</div>' : '') +
                                (e.personal_email ? '<div class="emp-card-detail"><span class="emp-detail-lbl">Email</span> ' + escapeHtml(e.personal_email) + '</div>' : '') +
                                (e.emergency_contact_name ? '<div class="emp-card-detail"><span class="emp-detail-lbl">Emergency</span> ' + escapeHtml(e.emergency_contact_name) + (e.emergency_contact_number ? ' (' + escapeHtml(e.emergency_contact_number) + ')' : '') + '</div>' : '') +
                            '</div>' +
                            '<div class="emp-card-right">' +
                                '<div class="emp-doc-icons">' + docIcons + '</div>' +
                                '<div class="emp-doc-score">' + e.docs_complete + '/' + e.docs_total + '</div>' +
                            '</div>' +
                        '</div>';

                    // Expanded detail
                    if (isExpanded) {
                        html += '<div class="emp-detail">' +
                            '<div class="emp-detail-grid">' +
                                '<div class="emp-detail-item"><span class="emp-detail-key">Office Email</span><span class="emp-detail-val">' + escapeHtml(e.email) + '</span></div>' +
                                '<div class="emp-detail-item"><span class="emp-detail-key">Reporting To</span><span class="emp-detail-val">' + escapeHtml(e.reporting_manager || '—') + '</span></div>' +
                                '<div class="emp-detail-item"><span class="emp-detail-key">Projects</span><span class="emp-detail-val">' + escapeHtml(e.projects || '—') + '</span></div>' +
                                '<div class="emp-detail-item"><span class="emp-detail-key">Department</span><span class="emp-detail-val">' + escapeHtml(e.department || '—') + '</span></div>' +
                                '<div class="emp-detail-item"><span class="emp-detail-key">Joining Date</span><span class="emp-detail-val">' + escapeHtml(e.joining_date || '—') + '</span></div>' +
                                '<div class="emp-detail-item"><span class="emp-detail-key">Experienced</span><span class="emp-detail-val">' + (e.experienced ? 'Yes' : 'No') + '</span></div>' +
                                '<div class="emp-detail-item"><span class="emp-detail-key">Status</span><span class="emp-detail-val">' + statusBadge(e.employee_status) + '</span></div>' +
                                (e.hourly_rate ? '<div class="emp-detail-item"><span class="emp-detail-key">Hourly Rate</span><span class="emp-detail-val">&#8377;' + e.hourly_rate + '</span></div>' : '');

                        // Probation info
                        if (e.employee_status === 'probation') {
                            html += '<div class="emp-detail-item"><span class="emp-detail-key">Probation Ends</span><span class="emp-detail-val">' + escapeHtml(e.probation_end_date || '—') + ' ' + daysRemaining(e.probation_end_date) + '</span></div>';
                        }
                        // Intern info
                        if (e.employee_status === 'intern') {
                            html += '<div class="emp-detail-item"><span class="emp-detail-key">Internship Ends</span><span class="emp-detail-val">' + escapeHtml(e.internship_end_date || '—') + ' ' + daysRemaining(e.internship_end_date) + '</span></div>';
                            if (e.stipend_amount) html += '<div class="emp-detail-item"><span class="emp-detail-key">Stipend</span><span class="emp-detail-val">&#8377;' + e.stipend_amount + '/mo</span></div>';
                        }
                        // Exit info
                        if (e.exit_date) {
                            html += '<div class="emp-detail-item"><span class="emp-detail-key">Exit Date</span><span class="emp-detail-val">' + escapeHtml(e.exit_date) + '</span></div>';
                            if (e.exit_reason) html += '<div class="emp-detail-item"><span class="emp-detail-key">Exit Reason</span><span class="emp-detail-val">' + escapeHtml(e.exit_reason) + '</span></div>';
                        }

                        // Personal Information — always show so HR can see at a
                        // glance which fields each employee has filled (a missing
                        // field is itself useful information; previously the
                        // labels were hidden when blank and HR couldn't tell).
                        html += '<div class="emp-detail-item"><span class="emp-detail-key">Birthday</span><span class="emp-detail-val">' + (e.birthday_label ? escapeHtml(e.birthday_label) : '—') + '</span></div>';
                        html += '<div class="emp-detail-item"><span class="emp-detail-key">Blood Group</span><span class="emp-detail-val">' + (e.blood_group ? escapeHtml(e.blood_group) : '—') + '</span></div>';
                        html += '<div class="emp-detail-item"><span class="emp-detail-key">Marital Status</span><span class="emp-detail-val">' + (e.marital_status ? escapeHtml(e.marital_status) : '—') + '</span></div>';

                        // PF
                        var pfApp = e.pf && e.pf.applicable;
                        html += '<div class="emp-detail-item"><span class="emp-detail-key">PF</span><span class="emp-detail-val">' + (pfApp ? ('Applicable' + (e.pf.uan ? ' (UAN ' + escapeHtml(e.pf.uan) + ')' : '')) : 'Not applicable') + '</span></div>';

                        // Insurance
                        var insApp = e.insurance && e.insurance.applicable;
                        html += '<div class="emp-detail-item"><span class="emp-detail-key">Insurance</span><span class="emp-detail-val">' + (insApp ? ('Yes' + (e.insurance.number ? ' (' + escapeHtml(e.insurance.number) + ')' : '')) : 'No') + '</span></div>';

                        // Personal contact — repeated in the detail grid so the
                        // expanded card carries the full profile without forcing
                        // the reader to crane up to the collapsed-row strip.
                        html += '<div class="emp-detail-item"><span class="emp-detail-key">Personal Mobile</span><span class="emp-detail-val">' + (e.personal_mobile ? escapeHtml(e.personal_mobile) : '—') + '</span></div>';
                        html += '<div class="emp-detail-item"><span class="emp-detail-key">Personal Email</span><span class="emp-detail-val">' + (e.personal_email ? escapeHtml(e.personal_email) : '—') + '</span></div>';
                        html += '<div class="emp-detail-item"><span class="emp-detail-key">Qualification</span><span class="emp-detail-val">' + (e.qualification ? escapeHtml(e.qualification) : '—') + '</span></div>';
                        var addrSameView = e.current_address && e.permanent_address && e.current_address === e.permanent_address;
                        html += '<div class="emp-detail-item"><span class="emp-detail-key">Current Address</span><span class="emp-detail-val">' + (e.current_address ? escapeHtml(e.current_address) : '—') + '</span></div>';
                        html += '<div class="emp-detail-item"><span class="emp-detail-key">Permanent Address</span><span class="emp-detail-val">' + (addrSameView ? 'Same as current' : (e.permanent_address ? escapeHtml(e.permanent_address) : '—')) + '</span></div>';
                        var emgVal = e.emergency_contact_name
                            ? (escapeHtml(e.emergency_contact_name) + (e.emergency_contact_number ? ' (' + escapeHtml(e.emergency_contact_number) + ')' : ''))
                            : '—';
                        html += '<div class="emp-detail-item"><span class="emp-detail-key">Emergency Contact</span><span class="emp-detail-val">' + emgVal + '</span></div>';

                        // Close the detail-grid before the section blocks below render full-width
                        html += '</div>';

                        // Nominee details — always rendered so missing fields
                        // are visible to whoever owns this employee's profile.
                        var nom = e.nominee || {};
                        html += '<div class="emp-docs-header" style="margin-top:16px">Nominee Details</div>' +
                            '<div class="emp-detail-grid">' +
                                '<div class="emp-detail-item"><span class="emp-detail-key">Name</span><span class="emp-detail-val">' + (nom.name ? escapeHtml(nom.name) : '—') + '</span></div>' +
                                '<div class="emp-detail-item"><span class="emp-detail-key">Age</span><span class="emp-detail-val">' + (nom.age != null ? escapeHtml(String(nom.age)) : '—') + '</span></div>' +
                                '<div class="emp-detail-item"><span class="emp-detail-key">DOB</span><span class="emp-detail-val">' + (nom.dob ? escapeHtml(nom.dob) : '—') + '</span></div>' +
                                '<div class="emp-detail-item"><span class="emp-detail-key">Relation</span><span class="emp-detail-val">' + (nom.relation ? escapeHtml(nom.relation) : '—') + '</span></div>' +
                            '</div>';

                        // Onboarding checklist (for probation/intern)
                        if (e.onboarding) {
                            var ob = e.onboarding;
                            var obItems = [
                                { label: 'Company Email', done: ob.email },
                                { label: 'Personal Mobile', done: ob.mobile },
                                { label: 'Personal Email', done: ob.personal_email },
                                { label: 'Emergency Contact', done: ob.emergency_contact },
                                { label: 'Key Documents', done: ob.docs },
                            ];
                            var obDone = obItems.filter(function (i) { return i.done; }).length;
                            html += '<div class="emp-docs-header" style="margin-top:16px">Onboarding (' + obDone + '/' + obItems.length + ')</div>' +
                                '<div class="emp-onboard-list">';
                            obItems.forEach(function (item) {
                                html += '<span class="emp-onboard-item ' + (item.done ? 'emp-onboard-done' : 'emp-onboard-pending') + '">' +
                                    (item.done ? '&#10003; ' : '&#10007; ') + item.label + '</span>';
                            });
                            html += '</div>';
                        }

                        // Salary section (CEO/CFO only)
                        if (cachedCanSeeSalary) {
                            html += '<div class="emp-docs-header" style="margin-top:16px">Compensation</div>' +
                                '<div class="emp-detail-grid">' +
                                    '<div class="emp-detail-item"><span class="emp-detail-key">Monthly Salary</span><span class="emp-detail-val">' + (e.monthly_salary ? '&#8377;' + Number(e.monthly_salary).toLocaleString('en-IN') : '—') + '</span></div>' +
                                    '<div class="emp-detail-item"><span class="emp-detail-key">Annual CTC</span><span class="emp-detail-val">' + (e.annual_ctc ? '&#8377;' + Number(e.annual_ctc).toLocaleString('en-IN') : '—') + '</span></div>' +
                                '</div>' +
                                '<div style="display:flex;gap:8px;margin-top:8px">' +
                                    (cachedCanManage ? '<button class="emp-edit-btn emp-salary-btn" data-id="' + e.id + '">Revise Salary</button>' : '') +
                                    '<button class="emp-edit-btn emp-salary-history-btn" data-id="' + e.id + '" style="background:#f1f5f9;color:#334155">Salary History</button>' +
                                '</div>';
                        }

                        // Bank Details — read-only, for HR/Finance verification during salary processing.
                        // Click the card to open a modal with the full (unmasked) account number & IFSC.
                        var bank = e.bank || {};
                        var bankHasAny = !!(bank.account_holder_name || bank.account_number || bank.ifsc_code);
                        var bankFilled = !!(bank.account_holder_name && bank.account_number && bank.ifsc_code && bank.has_passbook);
                        var bankBorder = bankFilled ? '#166534' : (bankHasAny ? '#92400e' : '#7f1d1d');
                        var bankIcon = bankFilled ? '#22c55e' : (bankHasAny ? '#f59e0b' : '#ef4444');
                        var bankTitle = bankFilled ? 'Verified bank details' : (bankHasAny ? 'Bank details added' : 'Bank details missing');
                        var bankSubtitle;
                        if (bankHasAny) {
                            var bankParts = [];
                            if (bank.account_holder_name) bankParts.push(escapeHtml(bank.account_holder_name));
                            if (bank.account_number) bankParts.push('****' + escapeHtml(String(bank.account_number).slice(-4)));
                            if (bank.ifsc_code) bankParts.push(escapeHtml(bank.ifsc_code));
                            bankSubtitle = bankParts.join(' • ') + (bank.has_passbook ? '' : ' • passbook pending');
                        } else {
                            bankSubtitle = 'Employee has not added bank details yet.';
                        }
                        html += '<div class="emp-docs-header" style="margin-top:16px">Bank Details</div>' +
                            '<div class="' + (bankHasAny ? 'emp-bank-card' : '') + '" data-id="' + e.id + '"' + (bankHasAny ? ' title="Click to view full account number & IFSC"' : '') + ' style="display:flex;align-items:center;gap:12px;padding:14px 16px;background:#1a1a2e;border-radius:10px;border:1px solid ' + bankBorder + (bankHasAny ? ';cursor:pointer' : '') + '">' +
                                '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="' + bankIcon + '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M3 10h18M5 6l7-3 7 3M4 10v11M20 10v11M8 14v3M12 14v3M16 14v3"/></svg>' +
                                '<div style="flex:1">' +
                                    '<div style="font-weight:600;font-size:14px;color:#fff">' + bankTitle + '</div>' +
                                    '<div style="font-size:12px;color:#9ca3af">' + bankSubtitle + '</div>' +
                                '</div>' +
                                (bankHasAny
                                    ? '<button type="button" class="emp-edit-btn emp-bank-view-btn" style="background:#1e40af;color:#fff;min-width:120px">View Details</button>'
                                    : '<span style="color:#9ca3af;font-size:12px">No details</span>') +
                            '</div>';

                        // Documents
                        html += '<div class="emp-docs-header">Documents</div>' +
                            '<div class="emp-docs-grid">';

                        var allDocKeys = Object.keys(docs);
                        allDocKeys.forEach(function (dk) {
                            var d = docs[dk];
                            html += '<div class="emp-doc-tile ' + (d.uploaded ? 'emp-doc-tile-ok' : 'emp-doc-tile-miss') + '">' +
                                (d.uploaded
                                    ? '<a href="' + escapeHtml(d.path) + '" target="_blank" class="emp-doc-link">' + escapeHtml(d.label) + '</a>'
                                    : '<span class="emp-doc-missing-label">' + escapeHtml(d.label) + '</span>') +
                                '<span class="emp-doc-status">' + (d.uploaded ? 'Uploaded' : 'Missing') + '</span>' +
                                (cachedCanManage
                                    ? (d.uploaded
                                        ? '<button class="emp-doc-del-btn" data-user="' + e.id + '" data-field="' + escapeHtml(dk) + '">Delete</button>'
                                        : '<button class="emp-doc-upload-btn" data-user="' + e.id + '" data-field="' + escapeHtml(dk) + '" data-label="' + escapeHtml(d.label) + '">Upload</button>')
                                    : '') +
                                (dk === 'nda_path'
                                    ? '<a href="/api/employees/' + e.id + '/nda" target="_blank" style="display:block;margin-top:4px;font-size:12px;color:#60a5fa;text-decoration:none">⬇ Generate pre-filled NDA</a>'
                                    : '') +
                            '</div>';
                        });

                        html += '</div>' +
                            '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">' +
                                (cachedCanManage ? '<button class="emp-edit-btn" data-id="' + e.id + '">Edit Details</button>' : '') +
                                (cachedCanManage ? '<button class="emp-edit-btn emp-status-btn" data-id="' + e.id + '" style="background:#fef3c7;color:#92400e">Change Status</button>' : '') +
                                (cachedCanManage && e.employee_status === 'intern' ? '<button class="emp-edit-btn emp-convert-btn" data-id="' + e.id + '" style="background:#dbeafe;color:#1e40af">Convert to Full-time</button>' : '') +
                                (cachedCanManage && e.is_active && e.employee_status !== 'intern' ? '<button class="emp-edit-btn emp-promote-btn" data-id="' + e.id + '" style="background:#d1fae5;color:#065f46">Promote / Increment</button>' : '') +
                                '<button class="emp-edit-btn emp-history-btn" data-id="' + e.id + '" style="background:#f1f5f9;color:#334155">History</button>' +
                            '</div>' +
                        '</div>';
                    }

                    html += '</div>';
                });
                html += '</div>';
            }

            html += '</div>';
            root.innerHTML = html;

            // Bind events
            document.getElementById('empSearch').addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') { empSearch = ev.target.value; renderEmployees(); }
            });
            document.getElementById('empTypeFilter').onchange = function () { empType = this.value; renderEmployees(); };
            document.getElementById('empDocFilter').onchange = function () { empDocStatus = this.value; renderEmployees(); };
            document.getElementById('empStatusFilter').onchange = function () { empStatusFilter = this.value; renderEmployees(); };
            document.getElementById('empDeptFilter').onchange = function () { empDeptFilter = this.value; renderEmployees(); };
            document.getElementById('empShowAll').onchange = function () { empShowAll = this.checked; renderEmployees(); };
            var clr = document.getElementById('empClearBtn');
            if (clr) clr.onclick = function () { empSearch = ''; empType = ''; empDocStatus = ''; empStatusFilter = ''; empDeptFilter = ''; empShowAll = false; renderEmployees(); };

            // Add member button
            var addBtn = document.getElementById('empAddBtn');
            if (addBtn) addBtn.onclick = function () { navigateToAddMember(); };

            // Download Data button
            var dlBtn = document.getElementById('empDownloadBtn');
            if (dlBtn) dlBtn.onclick = function () { showDownloadDataModal(); };

            // Card click to expand
            root.querySelectorAll('.emp-card').forEach(function (card) {
                card.querySelector('.emp-card-row').addEventListener('click', function () {
                    var id = parseInt(card.getAttribute('data-id'), 10);
                    // Remember where this card sits in the viewport so we can re-pin it
                    // after the (destructive) re-render, instead of jumping to the top.
                    empScrollAnchor = { id: id, top: card.getBoundingClientRect().top };
                    empExpanded = empExpanded === id ? null : id;
                    renderEmployees();
                });
            });

            // Edit buttons
            root.querySelectorAll('.emp-edit-btn:not(.emp-status-btn):not(.emp-salary-btn):not(.emp-salary-history-btn):not(.emp-convert-btn):not(.emp-promote-btn):not(.emp-history-btn):not(.emp-bank-view-btn)').forEach(function (btn) {
                btn.addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    var id = parseInt(btn.getAttribute('data-id'), 10);
                    var emp = employees.find(function (e) { return e.id === id; });
                    if (emp) navigateToEditMember(emp);
                });
            });

            // Status change buttons
            root.querySelectorAll('.emp-status-btn').forEach(function (btn) {
                btn.addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    var id = parseInt(btn.getAttribute('data-id'), 10);
                    var emp = employees.find(function (e) { return e.id === id; });
                    if (emp) showStatusChangeModal(emp);
                });
            });

            // Salary revision buttons
            root.querySelectorAll('.emp-salary-btn').forEach(function (btn) {
                btn.addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    var id = parseInt(btn.getAttribute('data-id'), 10);
                    var emp = employees.find(function (e) { return e.id === id; });
                    if (emp) showSalaryRevisionModal(emp);
                });
            });

            // Salary history buttons
            root.querySelectorAll('.emp-salary-history-btn').forEach(function (btn) {
                btn.addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    var id = parseInt(btn.getAttribute('data-id'), 10);
                    var emp = employees.find(function (e) { return e.id === id; });
                    if (emp) showSalaryHistoryModal(emp);
                });
            });

            // Bank details card — open read-only modal with full account number / IFSC.
            // The "View Details" button sits inside the card and bubbles up to here.
            root.querySelectorAll('.emp-bank-card').forEach(function (cardEl) {
                cardEl.addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    var id = parseInt(cardEl.getAttribute('data-id'), 10);
                    var emp = employees.find(function (e) { return e.id === id; });
                    if (emp) showEmployeeBankModal(emp);
                });
            });

            // Convert-to-fulltime buttons (interns)
            root.querySelectorAll('.emp-convert-btn').forEach(function (btn) {
                btn.addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    var id = parseInt(btn.getAttribute('data-id'), 10);
                    var emp = employees.find(function (e) { return e.id === id; });
                    if (emp) showConvertInternModal(emp);
                });
            });

            // Promote / Increment buttons
            root.querySelectorAll('.emp-promote-btn').forEach(function (btn) {
                btn.addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    var id = parseInt(btn.getAttribute('data-id'), 10);
                    var emp = employees.find(function (e) { return e.id === id; });
                    if (emp) navigateToPromote(emp);
                });
            });

            // History buttons
            root.querySelectorAll('.emp-history-btn').forEach(function (btn) {
                btn.addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    var id = parseInt(btn.getAttribute('data-id'), 10);
                    var emp = employees.find(function (e) { return e.id === id; });
                    if (emp) showHistoryModal(emp);
                });
            });

            // Document upload / delete buttons (shared with Employee Records → Documents tab).
            bindDocActions(root, renderEmployees);

            // Re-pin the just-clicked card to the same viewport offset it had before
            // the re-render. Expanding/collapsing rebuilds the whole list (scroll falls
            // to 0 during the "Loading…" flash), so without this the page jumps to the
            // top and the user has to scroll back down to the details they opened.
            if (empScrollAnchor) {
                var anchorCard = root.querySelector('.emp-card[data-id="' + empScrollAnchor.id + '"]');
                if (anchorCard) {
                    var delta = anchorCard.getBoundingClientRect().top - empScrollAnchor.top;
                    if (delta) window.scrollBy(0, delta);
                }
                empScrollAnchor = null;
            }

        } catch (err) {
            console.error('renderEmployees failed', err);
            root.innerHTML = '<div class="emp-wrap"><div class="emp-empty">Failed to load team data.</div></div>';
            empScrollAnchor = null;
        }

        // Cross-view "Add to Team": now the team data + caches are ready (or the load
        // failed), open the prefilled Add Member form if one is queued. Deferred to
        // here — running it earlier lets this async render re-show the list over it.
        openPendingCrossViewAdd();
    }

    /* ── Add Member: Navigation ── */
    function navigateToAddMember(prefill) {
        pendingAddPrefill = prefill || null;
        hideSubPages();
        var empView = document.getElementById('employeesView');
        var addView = document.getElementById('addMemberView');
        if (empView) empView.classList.add('hidden');
        if (addView) { addView.classList.remove('hidden'); renderAddMember(); }
    }

    /**
     * Open the Add Member form pre-filled for an accepted candidate (called from
     * the Hiring pipeline's "Add to Team" CTA via window.HRModule). Switches into
     * the Team section; the actual form open is DEFERRED to the end of
     * renderEmployees (see openPendingCrossViewAdd) so the async team render that
     * switchView kicks off can't re-hide the form, and the role/department caches
     * are ready before the form paints.
     */
    function composeAddMemberFor(prefill) {
        pendingCrossViewAdd = prefill || {};
        if (window.MeetingModule && MeetingModule.switchView) {
            MeetingModule.switchView('employees'); // → renderEmployees → openPendingCrossViewAdd()
        } else {
            openPendingCrossViewAdd(); // already inside the Team section
        }
    }

    /** Consume a queued cross-view "Add to Team" prefill and open the form once. */
    function openPendingCrossViewAdd() {
        if (!pendingCrossViewAdd) return;
        var p = pendingCrossViewAdd;
        pendingCrossViewAdd = null;
        navigateToAddMember(p);
    }

    function navigateBackToTeam() {
        renderEmployees();
    }

    /* ── Add Member: Full Page ── */
    function renderAddMember() {
        var root = document.getElementById('addMemberView');
        if (!root) return;
        // Snapshot the candidate-mode prefill for this render so the submit handler
        // below stays bound to it even if another add starts later.
        var prefill = pendingAddPrefill;

        var rolesOpts = cachedRoles.map(function (r) {
            return '<option value="' + r.id + '">' + escapeHtml(r.name) + '</option>';
        }).join('');

        var deptOpts = '<option value="">— None —</option>' + cachedDepartments.map(function (d) {
            return '<option value="' + d.id + '">' + escapeHtml(d.name) + '</option>';
        }).join('');

        var today = new Date().toISOString().split('T')[0];

        var html = '<div class="add-member-wrap">' +
            '<div class="add-member-header">' +
                '<a href="#" class="add-member-back" id="addMemberBack">&larr; Back to Team</a>' +
                '<h2 class="add-member-title">Add New Team Member</h2>' +
            '</div>' +
            '<form id="addEmpForm">' +

            // Section 1: Basic Info
            '<div class="add-member-section">' +
                '<h3 class="add-member-section-title">Basic Information</h3>' +
                '<div class="add-member-grid">' +
                    '<label>Name <span class="add-member-req">*</span><input type="text" id="addEmpName" required placeholder="Full name"></label>' +
                    '<label>Father\'s / Mother\'s Name<input type="text" id="addEmpParent" placeholder="Parent / guardian name"></label>' +
                    '<label>Office Email <span class="add-member-req">*</span><input type="email" id="addEmpEmail" required placeholder="name@innovfix.in"></label>' +
                    '<label>Password<input type="text" id="addEmpPwd" value="welcome123" placeholder="Default: welcome123"></label>' +
                    '<label>Gender<select id="addEmpGender"><option value="">— Select —</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></select></label>' +
                    '<label>Date of Birth<input type="date" id="addEmpDob"></label>' +
                '</div>' +
            '</div>' +

            // Section 2: Role & Org
            '<div class="add-member-section">' +
                '<h3 class="add-member-section-title">Role &amp; Organization</h3>' +
                '<div class="add-member-grid">' +
                    '<label>Role <span class="add-member-req">*</span><select id="addEmpRole" required>' + rolesOpts + '</select></label>' +
                    '<label>Reporting Manager<select id="addEmpManager"><option value="">— None —</option></select></label>' +
                    '<label>Department<select id="addEmpDept">' + deptOpts + '</select></label>' +
                    '<label>Designation<input type="text" id="addEmpDesignation" list="addEmpDesignationList" placeholder="e.g. AI Intern"></label>' + designationDatalist('addEmpDesignationList') +
                '</div>' +
            '</div>' +

            // Section 3: Employment Details
            '<div class="add-member-section">' +
                '<h3 class="add-member-section-title">Employment Details</h3>' +
                '<div class="add-member-grid">' +
                    '<label>Employment Type<select id="addEmpType"><option value="full_time">Full-time</option><option value="internship">Internship</option><option value="freelancer">Freelancer</option></select></label>' +
                    '<label>Joining Date<input type="date" id="addEmpJoining" value="' + today + '"></label>' +
                '</div>' +
                '<div id="addEmpConditional"></div>' +
            '</div>' +

            // Section 4: Contact Info
            '<div class="add-member-section">' +
                '<h3 class="add-member-section-title">Contact Information</h3>' +
                '<div class="add-member-grid">' +
                    '<label>Personal Mobile<input type="text" id="addEmpMobile" placeholder="+91 ..."></label>' +
                    '<label>Personal Email<input type="email" id="addEmpPersonalEmail" placeholder="personal@email.com"></label>' +
                    '<label>Emergency Contact Name<input type="text" id="addEmpEmgName" placeholder="Parent / Guardian name"></label>' +
                    '<label>Emergency Contact Number<input type="text" id="addEmpEmgNum" placeholder="+91 ..."></label>' +
                '</div>' +
            '</div>' +

            // Section 5: Additional Details
            '<div class="add-member-section">' +
                '<h3 class="add-member-section-title">Additional Details</h3>' +
                '<div class="add-member-grid">' +
                    '<label>Experienced<select id="addEmpExperienced"><option value="">— Select —</option><option value="1">Yes</option><option value="0">No (Fresher)</option></select></label>' +
                    '<label>Hourly Rate<input type="number" id="addEmpHourlyRate" step="0.01" placeholder="0.00"></label>' +
                    '<label>Notice Period (days)<input type="number" id="addEmpNoticePeriod" value="30" placeholder="30"></label>' +
                '</div>' +
            '</div>' +

            // Status + Actions
            '<p id="addEmpStatus" style="font-size:14px;min-height:20px"></p>' +
            '<div class="add-member-actions">' +
                '<button type="button" class="btn btn-outline" id="addEmpCancel">Cancel</button>' +
                '<button type="submit" class="btn btn-primary btn-lg" id="addEmpSave">Add Team Member</button>' +
            '</div>' +

            '</form></div>';

        root.innerHTML = html;

        // Back button
        document.getElementById('addMemberBack').onclick = function (e) { e.preventDefault(); navigateBackToTeam(); };
        document.getElementById('addEmpCancel').onclick = function () { navigateBackToTeam(); };

        // Populate managers dropdown
        var managerSel = document.getElementById('addEmpManager');
        fetch('/api/employees?show_all=0', { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                (data.employees || []).forEach(function (e) {
                    var opt = document.createElement('option');
                    opt.value = e.id;
                    opt.textContent = e.name;
                    managerSel.appendChild(opt);
                });
            });

        // Conditional fields based on employment type
        function updateConditional() {
            var type = document.getElementById('addEmpType').value;
            var div = document.getElementById('addEmpConditional');
            if (type === 'internship') {
                div.innerHTML = '<div class="add-member-grid" style="margin-top:12px">' +
                    '<label>Internship Start<input type="date" id="addEmpIntStart" value="' + today + '"></label>' +
                    '<label>Internship End<input type="date" id="addEmpIntEnd"></label>' +
                    '<label>Stipend (monthly)<input type="number" id="addEmpStipend" step="0.01" placeholder="0.00"></label>' +
                '</div>';
            } else if (type === 'freelancer') {
                div.innerHTML = '<div class="add-member-grid" style="margin-top:12px">' +
                    '<label>Hourly Rate<input type="number" id="addEmpFreelanceRate" step="0.01" placeholder="0.00"></label>' +
                '</div>';
            } else {
                div.innerHTML = '<div class="add-member-grid" style="margin-top:12px">' +
                    '<label>Probation End Date<input type="date" id="addEmpProbEnd"></label>' +
                    (cachedCanSeeSalary ? '<label>Monthly Salary<input type="number" id="addEmpSalary" step="0.01" placeholder="0.00"></label>' +
                    '<label>Annual CTC<input type="number" id="addEmpCtc" step="0.01" placeholder="0.00"></label>' : '') +
                '</div>';
            }
        }
        document.getElementById('addEmpType').onchange = updateConditional;
        updateConditional();

        // Candidate mode — prefill from the accepted candidate. The Office Email is
        // the reserved firstname@innovfix.in login id (editable); personal email /
        // mobile / designation come from the résumé + JD.
        if (prefill) {
            var setVal = function (id, v) { var elx = document.getElementById(id); if (elx && v) elx.value = v; };
            setVal('addEmpName', prefill.name);
            setVal('addEmpEmail', prefill.work_email);
            setVal('addEmpPersonalEmail', prefill.personal_email);
            setVal('addEmpMobile', prefill.personal_mobile);
            setVal('addEmpDesignation', prefill.designation);
            var titleEl = root.querySelector('.add-member-title');
            if (titleEl && prefill.name) titleEl.textContent = 'Add to Team — ' + prefill.name;
        }

        // Form submit
        document.getElementById('addEmpForm').onsubmit = function (ev) {
            ev.preventDefault();
            var btn = document.getElementById('addEmpSave');
            var status = document.getElementById('addEmpStatus');
            btn.disabled = true; btn.textContent = 'Adding...';
            status.textContent = '';

            var payload = {
                action: 'create',
                name: document.getElementById('addEmpName').value,
                parent_name: document.getElementById('addEmpParent').value || null,
                email: document.getElementById('addEmpEmail').value,
                password: document.getElementById('addEmpPwd').value || 'welcome123',
                role_id: document.getElementById('addEmpRole').value,
                reporting_manager_id: document.getElementById('addEmpManager').value || null,
                department_id: document.getElementById('addEmpDept').value || null,
                designation: document.getElementById('addEmpDesignation').value || null,
                gender: document.getElementById('addEmpGender').value || null,
                date_of_birth: document.getElementById('addEmpDob').value || null,
                employment_type: document.getElementById('addEmpType').value,
                joining_date: document.getElementById('addEmpJoining').value || null,
                personal_mobile: document.getElementById('addEmpMobile').value || null,
                personal_email: document.getElementById('addEmpPersonalEmail').value || null,
                emergency_contact_name: document.getElementById('addEmpEmgName').value || null,
                emergency_contact_number: document.getElementById('addEmpEmgNum').value || null,
                experienced: document.getElementById('addEmpExperienced').value !== '' ? parseInt(document.getElementById('addEmpExperienced').value) : null,
                hourly_rate: document.getElementById('addEmpHourlyRate').value || null,
                notice_period_days: document.getElementById('addEmpNoticePeriod').value || 30,
            };

            // Candidate mode: link the new account back to the accepted candidate so
            // the server transitions the pipeline + pings Fida/Yuvanesh.
            if (prefill && prefill.candidate_id) payload.candidate_id = prefill.candidate_id;

            if (payload.employment_type === 'internship') {
                var intStart = document.getElementById('addEmpIntStart');
                var intEnd = document.getElementById('addEmpIntEnd');
                var stipend = document.getElementById('addEmpStipend');
                if (intStart) payload.internship_start_date = intStart.value || null;
                if (intEnd) payload.internship_end_date = intEnd.value || null;
                if (stipend) payload.stipend_amount = stipend.value || null;
            } else {
                var probEnd = document.getElementById('addEmpProbEnd');
                var salary = document.getElementById('addEmpSalary');
                var ctc = document.getElementById('addEmpCtc');
                if (probEnd) payload.probation_end_date = probEnd.value || null;
                if (salary) payload.monthly_salary = salary.value || null;
                if (ctc) payload.annual_ctc = ctc.value || null;
            }

            fetch('/api/employees', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(payload)
            }).then(function (r) { return r.json(); }).then(function (body) {
                if (body.ok) {
                    pendingAddPrefill = null;
                    status.style.color = '#22c55e';
                    status.textContent = body.message || 'Team member added successfully!';
                    setTimeout(function () { navigateBackToTeam(); }, 1200);
                } else {
                    status.style.color = '#ef4444';
                    status.textContent = body.error || body.message || 'Failed to add member';
                    btn.disabled = false; btn.textContent = 'Add Team Member';
                }
            }).catch(function () {
                status.style.color = '#ef4444';
                status.textContent = 'Network error';
                btn.disabled = false; btn.textContent = 'Add Team Member';
            });
        };
    }

    /* ── Edit Employee: Navigation ── */
    function navigateToEditMember(emp) {
        hideSubPages();
        var empView = document.getElementById('employeesView');
        var editView = document.getElementById('editMemberView');
        if (empView) empView.classList.add('hidden');
        if (editView) { editView.classList.remove('hidden'); renderEditMember(emp); }
    }

    function navigateBackFromEdit() {
        renderEmployees();
    }

    /* ── Edit Employee: Full Page ── */
    function renderEditMember(emp) {
        var root = document.getElementById('editMemberView');
        if (!root) return;

        var rolesOpts = cachedRoles.map(function (r) {
            return '<option value="' + r.id + '"' + (emp.role === r.name ? ' selected' : '') + '>' + escapeHtml(r.name) + '</option>';
        }).join('');

        var deptOpts = '<option value="">— None —</option>' + cachedDepartments.map(function (d) {
            return '<option value="' + d.id + '"' + (emp.department_id == d.id ? ' selected' : '') + '>' + escapeHtml(d.name) + '</option>';
        }).join('');

        var html = '<div class="add-member-wrap">' +
            '<div class="add-member-header">' +
                '<a href="#" class="add-member-back" id="editMemberBack">&larr; Back to Team</a>' +
                '<h2 class="add-member-title">Edit — ' + escapeHtml(emp.name) + '</h2>' +
                '<div style="margin-top:6px">' + statusBadge(emp.employee_status) +
                    ' <span style="color:#71717a;font-size:13px">' + escapeHtml(emp.email) + '</span></div>' +
            '</div>' +

            // Section 1: Role & Org
            '<div class="add-member-section">' +
                '<h3 class="add-member-section-title">Role &amp; Organization</h3>' +
                '<div class="add-member-grid">' +
                    '<label>Role<select id="empEdRole">' + rolesOpts + '</select></label>' +
                    '<label>Reporting Manager<select id="empEdManager"><option value="">— None —</option></select></label>' +
                    '<label>Designation<input type="text" id="empEdDesignation" list="empEdDesignationList" value="' + escapeHtml(emp.designation || '') + '"></label>' + designationDatalist('empEdDesignationList') +
                    '<label>Department<select id="empEdDept">' + deptOpts + '</select></label>' +
                '</div>' +
            '</div>' +

            // Section 2: Employment Details
            '<div class="add-member-section">' +
                '<h3 class="add-member-section-title">Employment Details</h3>' +
                '<div class="add-member-grid">' +
                    '<label>Employment Type<select id="empEdType"><option value="full_time"' + (emp.employment_type === 'full_time' ? ' selected' : '') + '>Full-time</option><option value="internship"' + (emp.employment_type === 'internship' ? ' selected' : '') + '>Internship</option><option value="freelancer"' + (emp.employment_type === 'freelancer' ? ' selected' : '') + '>Freelancer</option></select></label>' +
                    '<label>Joining Date<input type="date" id="empEdJoining" value="' + escapeHtml(emp.joining_date || '') + '"></label>' +
                    '<label>Experienced<select id="empEdExperienced"><option value="">— Select —</option><option value="1"' + (emp.experienced ? ' selected' : '') + '>Yes</option><option value="0"' + (emp.experienced === false ? ' selected' : '') + '>No (Fresher)</option></select></label>' +
                    '<label>Hourly Rate<input type="number" id="empEdHourlyRate" step="0.01" value="' + escapeHtml(emp.hourly_rate || '') + '"></label>' +
                    '<label>Notice Period (days)<input type="number" id="empEdNoticePeriod" value="' + (emp.notice_period_days || 30) + '"></label>' +
                '</div>' +
                (emp.employee_status === 'probation' ?
                    '<div class="add-member-grid" style="margin-top:12px">' +
                        '<label>Probation Start<input type="date" id="empEdProbStart" value="' + escapeHtml(emp.probation_start_date || '') + '"></label>' +
                        '<label>Probation End<input type="date" id="empEdProbEnd" value="' + escapeHtml(emp.probation_end_date || '') + '"></label>' +
                    '</div>' : '') +
                (emp.employee_status === 'intern' ?
                    '<div class="add-member-grid" style="margin-top:12px">' +
                        '<label>Internship Start<input type="date" id="empEdIntStart" value="' + escapeHtml(emp.internship_start_date || '') + '" disabled></label>' +
                        '<label>Internship End<input type="date" id="empEdIntEnd" value="' + escapeHtml(emp.internship_end_date || '') + '" disabled></label>' +
                        '<label>Stipend (monthly)<input type="number" id="empEdStipend" value="' + escapeHtml(emp.stipend_amount || '') + '" disabled></label>' +
                    '</div>' : '') +
            '</div>' +

            // Section 3: Personal Details
            '<div class="add-member-section">' +
                '<h3 class="add-member-section-title">Personal Details</h3>' +
                '<div class="add-member-grid">' +
                    '<label>Gender<select id="empEdGender"><option value="">— Select —</option><option value="male"' + (emp.gender === 'male' ? ' selected' : '') + '>Male</option><option value="female"' + (emp.gender === 'female' ? ' selected' : '') + '>Female</option><option value="other"' + (emp.gender === 'other' ? ' selected' : '') + '>Other</option></select></label>' +
                    '<label>Date of Birth<input type="date" id="empEdDob" value="' + escapeHtml(emp.date_of_birth || '') + '"></label>' +
                    '<label>Father\'s / Mother\'s Name<input type="text" id="empEdParent" value="' + escapeHtml(emp.parent_name || '') + '" placeholder="Parent / guardian name"></label>' +
                    '<label>Personal Mobile<input type="text" id="empEdMobile" value="' + escapeHtml(emp.personal_mobile || '') + '"></label>' +
                    '<label>Personal Email<input type="email" id="empEdEmail" value="' + escapeHtml(emp.personal_email || '') + '"></label>' +
                    '<label>Blood Group<select id="empEdBlood"><option value="">— Select —</option>' +
                        ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'].map(function (bg) {
                            return '<option value="' + bg + '"' + (emp.blood_group === bg ? ' selected' : '') + '>' + bg + '</option>';
                        }).join('') +
                    '</select></label>' +
                    '<label>Marital Status<select id="empEdMarital"><option value="">— Select —</option>' +
                        [['unmarried','Unmarried'],['married','Married'],['divorced','Divorced']].map(function (m) {
                            return '<option value="' + m[0] + '"' + (emp.marital_status === m[0] ? ' selected' : '') + '>' + m[1] + '</option>';
                        }).join('') +
                    '</select></label>' +
                    '<label>Qualification<input type="text" id="empEdQual" placeholder="e.g. B.Tech CSE, MBA" value="' + escapeHtml(emp.qualification || '') + '"></label>' +
                    (function () {
                        var same = !!(emp.current_address && emp.permanent_address && emp.current_address === emp.permanent_address);
                        return '<label style="grid-column:1/-1">Current Address<textarea id="empEdCurAddr" rows="2" placeholder="Where they live now">' + escapeHtml(emp.current_address || '') + '</textarea></label>' +
                            '<label style="grid-column:1/-1;display:flex;align-items:center;gap:8px;font-weight:500"><input type="checkbox" id="empEdAddrSame"' + (same ? ' checked' : '') + ' style="width:auto;margin:0"> Permanent address is same as current</label>' +
                            '<label style="grid-column:1/-1" id="empEdPermAddrWrap"' + (same ? ' hidden' : '') + '>Permanent Address<textarea id="empEdPermAddr" rows="2" placeholder="Hometown / permanent address">' + escapeHtml(emp.permanent_address || '') + '</textarea></label>';
                    })() +
                '</div>' +
            '</div>' +

            // Section 4: Emergency Contact
            '<div class="add-member-section">' +
                '<h3 class="add-member-section-title">Emergency Contact</h3>' +
                '<div class="add-member-grid">' +
                    '<label>Contact Name<input type="text" id="empEdEmgName" value="' + escapeHtml(emp.emergency_contact_name || '') + '"></label>' +
                    '<label>Contact Number<input type="text" id="empEdEmgNum" value="' + escapeHtml(emp.emergency_contact_number || '') + '"></label>' +
                '</div>' +
            '</div>' +

            // Section 5: Nominee Details
            (function () {
                var nom = emp.nominee || {};
                return '<div class="add-member-section">' +
                    '<h3 class="add-member-section-title">Nominee Details</h3>' +
                    '<div class="add-member-grid">' +
                        '<label>Nominee Name<input type="text" id="empEdNomName" value="' + escapeHtml(nom.name || '') + '"></label>' +
                        '<label>Nominee Age<input type="number" min="0" max="120" id="empEdNomAge" value="' + (nom.age != null ? escapeHtml(String(nom.age)) : '') + '"></label>' +
                        '<label>Nominee DOB<input type="date" id="empEdNomDob" value="' + escapeHtml(nom.dob || '') + '"></label>' +
                        '<label>Relation<input type="text" id="empEdNomRel" placeholder="e.g. Spouse, Parent" value="' + escapeHtml(nom.relation || '') + '"></label>' +
                    '</div>' +
                '</div>';
            })() +

            // Section 6: Provident Fund (PF)
            (function () {
                var pf = emp.pf || {};
                return '<div class="add-member-section">' +
                    '<h3 class="add-member-section-title">Provident Fund (PF)</h3>' +
                    '<div class="add-member-grid">' +
                        '<label style="display:flex;align-items:center;gap:8px;flex-direction:row"><input type="checkbox" id="empEdPfApp"' + (pf.applicable ? ' checked' : '') + ' style="width:auto"> PF Applicable</label>' +
                        '<label>PF UAN<input type="text" id="empEdPfUan" placeholder="12-digit UAN" maxlength="15" value="' + escapeHtml(pf.uan || '') + '"' + (pf.applicable ? '' : ' disabled') + '></label>' +
                    '</div>' +
                '</div>';
            })() +

            // Section 7: Insurance
            (function () {
                var ins = emp.insurance || {};
                return '<div class="add-member-section">' +
                    '<h3 class="add-member-section-title">Insurance</h3>' +
                    '<div class="add-member-grid">' +
                        '<label style="display:flex;align-items:center;gap:8px;flex-direction:row"><input type="checkbox" id="empEdInsApp"' + (ins.applicable ? ' checked' : '') + ' style="width:auto"> Insurance Provided</label>' +
                        '<label>Insurance Number<input type="text" id="empEdInsNum" value="' + escapeHtml(ins.number || '') + '"' + (ins.applicable ? '' : ' disabled') + '></label>' +
                    '</div>' +
                '</div>';
            })() +

            // Section 8: Bank Details
            (function () {
                var bank = emp.bank || {};
                return '<div class="add-member-section">' +
                    '<h3 class="add-member-section-title">Bank Details</h3>' +
                    '<div class="add-member-grid">' +
                        '<label>Account Holder Name<input type="text" id="empEdBankName" value="' + escapeHtml(bank.account_holder_name || '') + '"></label>' +
                        '<label>Account Number<input type="text" id="empEdBankAcc" value="' + escapeHtml(bank.account_number || '') + '"></label>' +
                        '<label>IFSC Code<input type="text" id="empEdBankIfsc" placeholder="e.g. HDFC0001234" maxlength="11" value="' + escapeHtml(bank.ifsc_code || '') + '"></label>' +
                    '</div>' +
                '</div>';
            })() +

            // Status + Actions
            '<p id="empEditStatus" style="font-size:14px;min-height:20px"></p>' +
            '<div class="add-member-actions">' +
                '<button type="button" class="btn btn-outline" id="empEditCancel">Cancel</button>' +
                '<button type="button" class="btn btn-primary btn-lg" id="empEditSave">Save Changes</button>' +
            '</div>' +

        '</div>';

        root.innerHTML = html;

        // Back / Cancel
        document.getElementById('editMemberBack').onclick = function (e) { e.preventDefault(); navigateBackFromEdit(); };
        document.getElementById('empEditCancel').onclick = function () { navigateBackFromEdit(); };

        // Populate managers dropdown with current manager pre-selected
        var managerSel = document.getElementById('empEdManager');
        fetch('/api/employees?show_all=0', { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                (data.employees || []).forEach(function (e) {
                    if (e.id === emp.id) return; // skip self
                    var opt = document.createElement('option');
                    opt.value = e.id;
                    opt.textContent = e.name;
                    if (emp.reporting_manager === e.name) opt.selected = true;
                    managerSel.appendChild(opt);
                });
            });

        // PF / Insurance "applicable" toggles gate their accompanying text input,
        // mirroring the My Profile page so the disabled state lines up.
        var pfApp = document.getElementById('empEdPfApp');
        var pfUan = document.getElementById('empEdPfUan');
        if (pfApp && pfUan) pfApp.addEventListener('change', function () { pfUan.disabled = !pfApp.checked; if (!pfApp.checked) pfUan.value = ''; });
        var insApp = document.getElementById('empEdInsApp');
        var insNum = document.getElementById('empEdInsNum');
        if (insApp && insNum) insApp.addEventListener('change', function () { insNum.disabled = !insApp.checked; if (!insApp.checked) insNum.value = ''; });

        // "Permanent same as current" address toggle.
        var addrSameEd = document.getElementById('empEdAddrSame');
        var permWrapEd = document.getElementById('empEdPermAddrWrap');
        var curAddrEd = document.getElementById('empEdCurAddr');
        var permAddrEd = document.getElementById('empEdPermAddr');
        if (addrSameEd && permWrapEd) {
            addrSameEd.addEventListener('change', function () {
                if (addrSameEd.checked) {
                    permWrapEd.hidden = true;
                    permAddrEd.value = curAddrEd.value;
                } else {
                    permWrapEd.hidden = false;
                }
            });
            curAddrEd.addEventListener('input', function () {
                if (addrSameEd.checked) permAddrEd.value = curAddrEd.value;
            });
        }

        // Save
        document.getElementById('empEditSave').onclick = function () {
            var btn = document.getElementById('empEditSave');
            var status = document.getElementById('empEditStatus');
            btn.disabled = true;
            btn.textContent = 'Saving...';
            status.textContent = '';

            var nomAge = document.getElementById('empEdNomAge').value;
            var pfApp = document.getElementById('empEdPfApp').checked;
            var insApp = document.getElementById('empEdInsApp').checked;
            fetch('/api/employees', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({
                    action: 'update',
                    id: emp.id,
                    role_id: document.getElementById('empEdRole').value || null,
                    reporting_manager_id: document.getElementById('empEdManager').value || null,
                    designation: document.getElementById('empEdDesignation').value,
                    department_id: document.getElementById('empEdDept').value || null,
                    gender: document.getElementById('empEdGender').value || null,
                    date_of_birth: document.getElementById('empEdDob').value || null,
                    personal_mobile: document.getElementById('empEdMobile').value,
                    personal_email: document.getElementById('empEdEmail').value,
                    emergency_contact_name: document.getElementById('empEdEmgName').value,
                    emergency_contact_number: document.getElementById('empEdEmgNum').value,
                    employment_type: document.getElementById('empEdType').value,
                    joining_date: document.getElementById('empEdJoining').value || null,
                    experienced: document.getElementById('empEdExperienced').value !== '' ? parseInt(document.getElementById('empEdExperienced').value) : null,
                    hourly_rate: document.getElementById('empEdHourlyRate').value || null,
                    notice_period_days: document.getElementById('empEdNoticePeriod').value || null,
                    blood_group: document.getElementById('empEdBlood').value || null,
                    marital_status: document.getElementById('empEdMarital').value || null,
                    qualification: document.getElementById('empEdQual').value || null,
                    parent_name: document.getElementById('empEdParent').value || null,
                    current_address: document.getElementById('empEdCurAddr').value || null,
                    permanent_address: (function () {
                        var same = document.getElementById('empEdAddrSame');
                        var cur = document.getElementById('empEdCurAddr').value;
                        var perm = document.getElementById('empEdPermAddr').value;
                        return (same && same.checked ? cur : perm) || null;
                    })(),
                    nominee_name: document.getElementById('empEdNomName').value || null,
                    nominee_age: nomAge === '' ? null : parseInt(nomAge, 10),
                    nominee_dob: document.getElementById('empEdNomDob').value || null,
                    nominee_relation: document.getElementById('empEdNomRel').value || null,
                    pf_applicable: pfApp ? 1 : 0,
                    pf_uan: pfApp ? (document.getElementById('empEdPfUan').value || null) : null,
                    insurance_applicable: insApp ? 1 : 0,
                    insurance_number: insApp ? (document.getElementById('empEdInsNum').value || null) : null,
                    bank_account_holder_name: document.getElementById('empEdBankName').value || null,
                    bank_account_number: document.getElementById('empEdBankAcc').value || null,
                    bank_ifsc_code: document.getElementById('empEdBankIfsc').value || null,
                    // Probation dates only render for employees on probation;
                    // send them only when the inputs are present on the page.
                    probation_start_date: (function () { var el = document.getElementById('empEdProbStart'); return el ? (el.value || null) : undefined; })(),
                    probation_end_date: (function () { var el = document.getElementById('empEdProbEnd'); return el ? (el.value || null) : undefined; })(),
                })
            }).then(function (r) { return r.json(); }).then(function (body) {
                if (body.ok) {
                    status.style.color = '#22c55e';
                    status.textContent = 'Changes saved!';
                    setTimeout(function () { navigateBackFromEdit(); }, 1000);
                } else {
                    status.style.color = '#ef4444';
                    status.textContent = body.error || 'Save failed';
                    btn.disabled = false; btn.textContent = 'Save Changes';
                }
            }).catch(function () {
                status.style.color = '#ef4444';
                status.textContent = 'Network error';
                btn.disabled = false; btn.textContent = 'Save Changes';
            });
        };
    }

    /* ── Download Data Modal ── */
    function showDownloadDataModal() {
        var overlay = document.createElement('div');
        overlay.className = 'inv-modal-overlay';
        overlay.innerHTML = '<div class="inv-modal" style="max-width:560px">' +
            '<div class="inv-modal-header"><h3>Download Team Data</h3><button type="button" class="inv-modal-close" id="dlClose">&times;</button></div>' +
            '<div class="inv-modal-body">' +
                '<div id="dlTabs" style="display:flex;gap:4px;border-bottom:1px solid #2a2a3e;margin-bottom:12px">' +
                    '<button type="button" class="dl-tab" data-mode="fields" style="padding:8px 14px;background:transparent;border:none;border-bottom:2px solid #3b82f6;color:#fff;font-size:13px;cursor:pointer">Field Data</button>' +
                    '<button type="button" class="dl-tab" data-mode="docs" style="padding:8px 14px;background:transparent;border:none;border-bottom:2px solid transparent;color:#9ca3af;font-size:13px;cursor:pointer">Document Files</button>' +
                '</div>' +
                '<p id="dlIntro" style="font-size:12px;color:#9ca3af;margin:0 0 10px 0">Pick the fields you want in the Excel file. The export uses your current filters.</p>' +
                '<div style="display:flex;gap:8px;align-items:center;margin-bottom:10px">' +
                    '<input type="search" id="dlSearch" placeholder="Filter fields..." style="flex:1;padding:8px 10px;border:1px solid #2a2a3e;background:#0f172a;color:#fff;border-radius:6px;font-size:13px">' +
                    '<button type="button" class="emp-edit-btn" id="dlSelectAll" style="background:#1e40af;color:#fff">Select All</button>' +
                    '<button type="button" class="emp-edit-btn" id="dlClearSel" style="background:#1f2937;color:#fff">Clear</button>' +
                '</div>' +
                '<div id="dlFieldList" style="max-height:340px;overflow:auto;border:1px solid #2a2a3e;border-radius:8px;padding:10px;background:#0f172a">' +
                    '<div style="color:#9ca3af;font-size:13px">Loading fields...</div>' +
                '</div>' +
                '<p id="dlMsg" style="margin:10px 0 0 0;font-size:13px;min-height:16px;color:#9ca3af"></p>' +
            '</div>' +
            '<div class="inv-modal-footer">' +
                '<button type="button" class="btn btn-outline" id="dlCancel">Cancel</button>' +
                '<button type="button" class="btn btn-primary btn-lg" id="dlDownload">Download Excel</button>' +
            '</div>' +
        '</div>';
        document.body.appendChild(overlay);

        function close() { overlay.remove(); }
        document.getElementById('dlClose').onclick = close;
        document.getElementById('dlCancel').onclick = close;
        overlay.onclick = function (e) { if (e.target === overlay) close(); };

        function setMsg(text, color) {
            var m = document.getElementById('dlMsg');
            m.textContent = text || '';
            m.style.color = color || '#9ca3af';
        }

        // Each tab keeps its own field list + selection so flipping tabs
        // doesn't wipe what the user already picked on the other side.
        var mode = 'fields';
        var cache = {
            fields: { fields: [], selected: {}, loaded: false, endpoint: '/api/employees/export-fields' },
            docs:   { fields: [], selected: {}, loaded: false, endpoint: '/api/employees/export-document-fields' }
        };

        function state() { return cache[mode]; }

        function renderList(filterText) {
            var list = document.getElementById('dlFieldList');
            var st = state();
            var q = (filterText || '').toLowerCase().trim();
            var rows = st.fields
                .filter(function (f) { return !q || f.label.toLowerCase().indexOf(q) !== -1; })
                .map(function (f) {
                    var checked = st.selected[f.key] ? ' checked' : '';
                    return '<label style="display:flex;align-items:center;gap:8px;padding:6px 4px;font-size:13px;color:#e5e7eb;cursor:pointer;border-bottom:1px solid #1e293b">' +
                        '<input type="checkbox" data-key="' + escapeHtml(f.key) + '"' + checked + ' style="width:auto">' +
                        '<span>' + escapeHtml(f.label) + '</span>' +
                    '</label>';
                });
            list.innerHTML = rows.length ? rows.join('') : '<div style="color:#9ca3af;font-size:13px;padding:6px">No fields match.</div>';
            list.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
                cb.addEventListener('change', function () {
                    var k = cb.getAttribute('data-key');
                    if (cb.checked) st.selected[k] = true; else delete st.selected[k];
                });
            });
        }

        function loadCurrentTab() {
            var st = state();
            if (st.loaded) { renderList(document.getElementById('dlSearch').value); return; }
            fetch(st.endpoint, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) { return r.json(); }).then(function (body) {
                if (!body.ok || !body.fields) { setMsg('Failed to load fields', '#ef4444'); return; }
                st.fields = body.fields;
                st.loaded = true;
                if (mode === 'fields') {
                    // Sensible default for the fields tab — Name + Phone.
                    ['name', 'personal_mobile'].forEach(function (k) {
                        if (st.fields.find(function (f) { return f.key === k; })) st.selected[k] = true;
                    });
                } else {
                    // Documents tab defaults to all docs selected — that's the
                    // "download everything" intent the user described.
                    st.fields.forEach(function (f) { st.selected[f.key] = true; });
                }
                renderList(document.getElementById('dlSearch').value);
            }).catch(function () { setMsg('Failed to load fields', '#ef4444'); });
        }

        // Tab switching
        document.querySelectorAll('.dl-tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                mode = tab.getAttribute('data-mode');
                document.querySelectorAll('.dl-tab').forEach(function (t) {
                    var active = t.getAttribute('data-mode') === mode;
                    t.style.color = active ? '#fff' : '#9ca3af';
                    t.style.borderBottomColor = active ? '#3b82f6' : 'transparent';
                });
                document.getElementById('dlIntro').textContent = mode === 'docs'
                    ? 'Pick which document types to include. Each cell holds a clickable link to the uploaded file. The export uses your current filters.'
                    : 'Pick the fields you want in the Excel file. The export uses your current filters.';
                document.getElementById('dlSearch').value = '';
                setMsg('');
                loadCurrentTab();
            });
        });

        loadCurrentTab();

        document.getElementById('dlSearch').addEventListener('input', function (e) { renderList(e.target.value); });
        document.getElementById('dlSelectAll').onclick = function () {
            var st = state();
            st.fields.forEach(function (f) { st.selected[f.key] = true; });
            renderList(document.getElementById('dlSearch').value);
        };
        document.getElementById('dlClearSel').onclick = function () {
            state().selected = {};
            renderList(document.getElementById('dlSearch').value);
        };

        document.getElementById('dlDownload').onclick = function () {
            var st = state();
            var keys = Object.keys(st.selected);
            if (!keys.length) { setMsg(mode === 'docs' ? 'Pick at least one document type.' : 'Pick at least one field.', '#ef4444'); return; }
            var dlBtn = document.getElementById('dlDownload');
            dlBtn.disabled = true;
            dlBtn.textContent = 'Generating...';
            setMsg('Preparing your file...', '#9ca3af');

            // Mirror current filters for export
            var fd = new FormData();
            keys.forEach(function (k) { fd.append('fields[]', k); });
            if (empSearch) fd.append('search', empSearch);
            if (empType) fd.append('employment_type', empType);
            if (empStatusFilter) fd.append('employee_status', empStatusFilter);
            if (empDeptFilter) fd.append('department_id', empDeptFilter);
            if (empShowAll) fd.append('show_all', '1');

            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
            var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
            if (csrfMeta) headers['X-CSRF-TOKEN'] = csrfMeta.getAttribute('content');

            var url = mode === 'docs' ? '/api/employees/export-documents' : '/api/employees/export';
            fetch(url, {
                method: 'POST', credentials: 'same-origin', headers: headers, body: fd
            }).then(function (r) {
                if (!r.ok) {
                    return r.json().then(function (b) { throw new Error(b.error || 'Export failed'); });
                }
                var disp = r.headers.get('Content-Disposition') || '';
                var match = disp.match(/filename="?([^"]+)"?/);
                var filename = match ? match[1] : 'employees.xlsx';
                return r.blob().then(function (blob) { return { blob: blob, filename: filename }; });
            }).then(function (out) {
                var url = URL.createObjectURL(out.blob);
                var a = document.createElement('a');
                a.href = url; a.download = out.filename;
                document.body.appendChild(a); a.click();
                setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 100);
                close();
            }).catch(function (err) {
                setMsg(err.message || 'Export failed', '#ef4444');
                dlBtn.disabled = false; dlBtn.textContent = 'Download Excel';
            });
        };
    }

    /* ── Bank Details Modal ── */
    // Read-only modal showing an employee's full bank details (account number &
    // IFSC unmasked) for HR/Finance/CEO verification. Opened from the Team
    // employee-detail bank card. Editing happens elsewhere (Edit Details).
    function showEmployeeBankModal(emp) {
        var bank = (emp && emp.bank) || {};
        var overlay = document.createElement('div');
        overlay.className = 'inv-modal-overlay';

        var dash = '<span style="color:#6b7280">—</span>';
        function row(label, value) {
            return '<div style="display:flex;justify-content:space-between;gap:16px;padding:11px 0;border-bottom:1px solid #2a2a3e">' +
                '<span style="color:#9ca3af;font-size:13px">' + label + '</span>' +
                '<span style="color:#fff;font-size:14px;font-weight:600;text-align:right;word-break:break-all">' + value + '</span>' +
            '</div>';
        }
        var passbook = (bank.has_passbook && bank.passbook_path)
            ? '<a href="' + escapeHtml(bank.passbook_path) + '" target="_blank" class="btn btn-primary" style="text-decoration:none">View Passbook</a>'
            : '<span style="color:#9ca3af;font-size:13px">No passbook uploaded</span>';

        overlay.innerHTML = '<div class="inv-modal" style="max-width:460px">' +
            '<div class="inv-modal-header"><h3>Bank Details — ' + escapeHtml(emp.name || '') + '</h3><button type="button" class="inv-modal-close" id="empBankClose">&times;</button></div>' +
            '<div class="inv-modal-body">' +
                row('Account Holder Name', bank.account_holder_name ? escapeHtml(bank.account_holder_name) : dash) +
                row('Account Number', bank.account_number ? escapeHtml(String(bank.account_number)) : dash) +
                row('IFSC Code', bank.ifsc_code ? escapeHtml(bank.ifsc_code) : dash) +
                '<div style="margin-top:16px;display:flex;justify-content:flex-end">' + passbook + '</div>' +
            '</div>' +
            '<div class="inv-modal-footer"><button type="button" class="btn btn-outline" id="empBankCloseBtn">Close</button></div>' +
        '</div>';

        document.body.appendChild(overlay);
        function close() { overlay.remove(); }
        document.getElementById('empBankClose').onclick = close;
        document.getElementById('empBankCloseBtn').onclick = close;
        overlay.onclick = function (e) { if (e.target === overlay) close(); };
    }

    function showBankDetailsModal(bank) {
        var overlay = document.createElement('div');
        overlay.className = 'inv-modal-overlay';

        var passbookPreview = bank.has_passbook
            ? '<div style="margin-top:6px;display:flex;gap:8px;align-items:center;font-size:12px"><a href="' + escapeHtml(bank.passbook_path) + '" target="_blank" style="color:#3b82f6">View current passbook</a><button type="button" id="bankPassbookDel" style="background:transparent;border:none;color:#ef4444;cursor:pointer;font-size:12px">Remove</button></div>'
            : '';

        overlay.innerHTML = '<div class="inv-modal" style="max-width:520px">' +
            '<div class="inv-modal-header"><h3>Bank Details</h3><button type="button" class="inv-modal-close" id="bankClose">&times;</button></div>' +
            '<div class="inv-modal-body">' +
                '<p style="font-size:12px;color:#9ca3af;margin-bottom:12px">Required for salary processing. All fields are mandatory.</p>' +
                '<div class="emp-edit-grid">' +
                    '<label>Account Holder Name<input type="text" id="bankHolder" value="' + escapeHtml(bank.account_holder_name || '') + '" placeholder="As per passbook"></label>' +
                    '<label>Account Number<input type="text" id="bankAccNum" value="' + escapeHtml(bank.account_number || '') + '" placeholder="Bank account number" inputmode="numeric"></label>' +
                    '<label>IFSC Code<input type="text" id="bankIfsc" value="' + escapeHtml(bank.ifsc_code || '') + '" placeholder="e.g. HDFC0001234" maxlength="11" style="text-transform:uppercase"></label>' +
                    '<label>First Page of Passbook<input type="file" id="bankPassbook" accept="image/jpeg,image/png,application/pdf">' + passbookPreview + '</label>' +
                '</div>' +
                '<p id="bankMsg" style="margin-top:8px;font-size:13px;min-height:16px"></p>' +
            '</div>' +
            '<div class="inv-modal-footer"><button type="button" class="btn btn-outline" id="bankCancel">Cancel</button><button type="button" class="btn btn-primary btn-lg" id="bankSave">Save</button></div>' +
        '</div>';

        document.body.appendChild(overlay);

        function close() { overlay.remove(); }
        document.getElementById('bankClose').onclick = close;
        document.getElementById('bankCancel').onclick = close;
        overlay.onclick = function (e) { if (e.target === overlay) close(); };

        function setMsg(text, color) {
            var msg = document.getElementById('bankMsg');
            msg.textContent = text || '';
            msg.style.color = color || '#9ca3af';
        }

        var delBtn = document.getElementById('bankPassbookDel');
        if (delBtn) {
            delBtn.onclick = function () {
                if (!confirm('Remove the uploaded passbook image?')) return;
                fetch('/api/profile', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ action: 'delete_bank_passbook' })
                }).then(function (r) { return r.json(); }).then(function (body) {
                    if (body.ok) { close(); renderProfile(); } else { setMsg(body.error || 'Failed to remove', '#ef4444'); }
                }).catch(function () { setMsg('Network error', '#ef4444'); });
            };
        }

        document.getElementById('bankSave').onclick = function () {
            var btn = document.getElementById('bankSave');
            var holder = document.getElementById('bankHolder').value.trim();
            var accNum = document.getElementById('bankAccNum').value.trim();
            var ifsc = document.getElementById('bankIfsc').value.trim().toUpperCase();
            var fileInput = document.getElementById('bankPassbook');
            var file = fileInput && fileInput.files && fileInput.files[0];

            if (!holder) { setMsg('Account holder name is required', '#ef4444'); return; }
            if (!/^\d{6,20}$/.test(accNum)) { setMsg('Account number must be 6–20 digits', '#ef4444'); return; }
            if (!/^[A-Z]{4}0[A-Z0-9]{6}$/.test(ifsc)) { setMsg('IFSC must be 11 chars, e.g. HDFC0001234', '#ef4444'); return; }
            if (!bank.has_passbook && !file) { setMsg('Please upload the first page of your passbook', '#ef4444'); return; }

            btn.disabled = true; btn.textContent = 'Saving...';
            setMsg('Saving...', '#9ca3af');

            fetch('/api/profile', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({
                    action: 'update_bank',
                    bank_account_holder_name: holder,
                    bank_account_number: accNum,
                    bank_ifsc_code: ifsc,
                })
            }).then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); }).then(function (res) {
                if (!res.ok || !res.body.ok) {
                    var firstErr = res.body && res.body.errors ? Object.values(res.body.errors)[0][0] : (res.body && res.body.error) || 'Save failed';
                    setMsg(firstErr, '#ef4444');
                    btn.disabled = false; btn.textContent = 'Save';
                    return;
                }
                if (file) {
                    var fd = new FormData();
                    fd.append('action', 'upload_bank_passbook');
                    fd.append('file', file);
                    return fetch('/api/profile', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: fd
                    }).then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); }).then(function (res2) {
                        if (!res2.ok || !res2.body.ok) {
                            var err = res2.body && res2.body.errors ? Object.values(res2.body.errors)[0][0] : (res2.body && res2.body.error) || 'Upload failed';
                            setMsg(err, '#ef4444');
                            btn.disabled = false; btn.textContent = 'Save';
                            return;
                        }
                        close(); renderProfile();
                    });
                }
                close(); renderProfile();
            }).catch(function () {
                setMsg('Network error', '#ef4444');
                btn.disabled = false; btn.textContent = 'Save';
            });
        };
    }

    /* ── Status Change Modal ── */
    function showStatusChangeModal(emp) {
        var overlay = document.createElement('div');
        overlay.className = 'inv-modal-overlay';

        var transitions = {
            'active': ['freelancer', 'notice_period', 'exited', 'terminated', 'absconding'],
            'probation': ['active', 'freelancer', 'notice_period', 'exited', 'terminated', 'absconding'],
            'intern': ['active', 'freelancer', 'probation', 'exited', 'resigned', 'terminated', 'absconding'],
            'notice_period': ['resigned', 'exited', 'active'],
            'resigned': ['exited', 'active', 'freelancer'],
            'terminated': ['exited', 'active', 'freelancer'],
            'absconding': ['exited', 'terminated', 'active'],
            'exited': ['active', 'freelancer'],
            'freelancer': ['active', 'notice_period', 'exited', 'terminated']
        };
        var effectiveStatus = (emp.employment_type === 'freelancer') ? 'freelancer' : emp.employee_status;
        var allowed = transitions[effectiveStatus] || ['active', 'freelancer', 'exited', 'resigned', 'terminated'];

        var statusOpts = allowed.map(function (s) {
            var labels = { active: 'Active (Confirmed)', probation: 'Probation', notice_period: 'Notice Period', resigned: 'Resigned', terminated: 'Terminated', absconding: 'Absconding', exited: 'Exited', intern: 'Intern', freelancer: 'Freelancer' };
            return '<option value="' + s + '">' + (labels[s] || s) + '</option>';
        }).join('');

        var today = new Date().toISOString().split('T')[0];

        overlay.innerHTML = '<div class="inv-modal" style="max-width:420px">' +
            '<div class="inv-modal-header"><h3>Change Status — ' + escapeHtml(emp.name) + '</h3><button type="button" class="inv-modal-close" id="statusClose">&times;</button></div>' +
            '<div class="inv-modal-body">' +
                '<p style="font-size:13px;color:#6b7280;margin-bottom:12px">Current status: ' + (emp.employment_type === 'freelancer' ? '<span class="badge emp-badge" style="background:#8b5cf6;color:#fff">Freelancer</span>' : statusBadge(emp.employee_status)) + '</p>' +
                '<div class="emp-edit-grid">' +
                    '<label>New Status<select id="statusNew">' + statusOpts + '</select></label>' +
                    '<div id="statusConditional"></div>' +
                '</div>' +
                '<p id="statusMsg" style="margin-top:8px;font-size:13px"></p>' +
            '</div>' +
            '<div class="inv-modal-footer"><button type="button" class="btn btn-outline" id="statusCancel">Cancel</button><button type="button" class="btn btn-primary btn-lg" id="statusSave">Change Status</button></div>' +
        '</div>';

        document.body.appendChild(overlay);

        function updateFields() {
            var status = document.getElementById('statusNew').value;
            var div = document.getElementById('statusConditional');
            var html = '';
            if (status === 'resigned' || status === 'terminated' || status === 'absconding' || status === 'exited') {
                html += '<label>Exit Date<input type="date" id="statusExitDate" value="' + today + '"></label>' +
                    '<label>Exit Reason<input type="text" id="statusExitReason" placeholder="Reason for leaving"></label>' +
                    '<label>Last Working Date<input type="date" id="statusLwd" value="' + today + '"></label>';
            }
            if (status === 'notice_period') {
                html += '<label>Resignation Date<input type="date" id="statusResDate" value="' + today + '"></label>';
            }
            if (status === 'probation' && emp.employee_status === 'intern') {
                html += '<label>Probation End Date<input type="date" id="statusProbEnd"></label>';
            }
            if ((status === 'active' || status === 'probation') && emp.employee_status === 'intern' && cachedCanSeeSalary) {
                html += '<label>Monthly Salary<input type="number" id="statusSalary" step="0.01"></label>' +
                    '<label>Annual CTC<input type="number" id="statusCtc" step="0.01"></label>';
            }
            div.innerHTML = html;
        }
        document.getElementById('statusNew').onchange = updateFields;
        updateFields();

        function close() { overlay.remove(); }
        document.getElementById('statusClose').onclick = close;
        document.getElementById('statusCancel').onclick = close;
        overlay.onclick = function (e) { if (e.target === overlay) close(); };

        document.getElementById('statusSave').onclick = function () {
            var btn = document.getElementById('statusSave');
            var msg = document.getElementById('statusMsg');
            var newStatus = document.getElementById('statusNew').value;

            if (!confirm('Change status of ' + emp.name + ' to ' + newStatus + '?')) return;

            btn.disabled = true; btn.textContent = 'Changing...';
            msg.textContent = '';

            var payload = { action: 'change_status', id: emp.id, new_status: newStatus };

            var exitDate = document.getElementById('statusExitDate');
            var exitReason = document.getElementById('statusExitReason');
            var lwd = document.getElementById('statusLwd');
            var resDate = document.getElementById('statusResDate');
            var probEnd = document.getElementById('statusProbEnd');
            var salary = document.getElementById('statusSalary');
            var ctc = document.getElementById('statusCtc');

            if (exitDate) payload.exit_date = exitDate.value;
            if (exitReason) payload.exit_reason = exitReason.value;
            if (lwd) payload.last_working_date = lwd.value;
            if (resDate) payload.resignation_date = resDate.value;
            if (probEnd) payload.probation_end_date = probEnd.value;
            if (salary) payload.monthly_salary = salary.value;
            if (ctc) payload.annual_ctc = ctc.value;

            fetch('/api/employees', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(payload)
            }).then(function (r) { return r.json(); }).then(function (body) {
                if (body.ok) {
                    msg.style.color = '#22c55e';
                    msg.textContent = body.message || 'Status changed!';
                    setTimeout(function () { close(); renderEmployees(); }, 800);
                } else {
                    msg.style.color = '#ef4444';
                    msg.textContent = body.error || 'Failed';
                    btn.disabled = false; btn.textContent = 'Change Status';
                }
            }).catch(function () { btn.disabled = false; btn.textContent = 'Change Status'; });
        };
    }

    /* ── Salary Revision Modal ── */
    function showSalaryRevisionModal(emp) {
        var overlay = document.createElement('div');
        overlay.className = 'inv-modal-overlay';
        var today = new Date().toISOString().split('T')[0];

        overlay.innerHTML = '<div class="inv-modal" style="max-width:420px">' +
            '<div class="inv-modal-header"><h3>Revise Salary — ' + escapeHtml(emp.name) + '</h3><button type="button" class="inv-modal-close" id="salClose">&times;</button></div>' +
            '<div class="inv-modal-body">' +
                '<div style="background:#f8fafc;padding:10px;border-radius:8px;margin-bottom:12px;font-size:13px">' +
                    '<div>Current Monthly: <strong>' + (emp.monthly_salary ? '&#8377;' + Number(emp.monthly_salary).toLocaleString('en-IN') : '—') + '</strong></div>' +
                    '<div>Current CTC: <strong>' + (emp.annual_ctc ? '&#8377;' + Number(emp.annual_ctc).toLocaleString('en-IN') : '—') + '</strong></div>' +
                '</div>' +
                '<div class="emp-edit-grid">' +
                    '<label>New Monthly Salary<input type="number" id="salMonthly" step="0.01" value="' + (emp.monthly_salary || '') + '"></label>' +
                    '<label>New Annual CTC<input type="number" id="salCtc" step="0.01" value="' + (emp.annual_ctc || '') + '"></label>' +
                    '<label>Effective Date<input type="date" id="salDate" value="' + today + '" required></label>' +
                    '<label>Reason<input type="text" id="salReason" placeholder="e.g. Annual appraisal"></label>' +
                '</div>' +
                '<p id="salMsg" style="margin-top:8px;font-size:13px"></p>' +
            '</div>' +
            '<div class="inv-modal-footer"><button type="button" class="btn btn-outline" id="salCancel">Cancel</button><button type="button" class="btn btn-primary btn-lg" id="salSave">Update Salary</button></div>' +
        '</div>';

        document.body.appendChild(overlay);

        function close() { overlay.remove(); }
        document.getElementById('salClose').onclick = close;
        document.getElementById('salCancel').onclick = close;
        overlay.onclick = function (e) { if (e.target === overlay) close(); };

        document.getElementById('salSave').onclick = function () {
            var btn = document.getElementById('salSave');
            var msg = document.getElementById('salMsg');
            btn.disabled = true; btn.textContent = 'Saving...';

            fetch('/api/employees', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({
                    action: 'update_salary',
                    id: emp.id,
                    monthly_salary: document.getElementById('salMonthly').value || null,
                    annual_ctc: document.getElementById('salCtc').value || null,
                    effective_date: document.getElementById('salDate').value,
                    revision_reason: document.getElementById('salReason').value || null,
                })
            }).then(function (r) { return r.json(); }).then(function (body) {
                if (body.ok) {
                    msg.style.color = '#22c55e';
                    msg.textContent = 'Salary updated!';
                    setTimeout(function () { close(); renderEmployees(); }, 800);
                } else {
                    msg.style.color = '#ef4444';
                    msg.textContent = body.error || 'Failed';
                    btn.disabled = false; btn.textContent = 'Update Salary';
                }
            }).catch(function () { btn.disabled = false; btn.textContent = 'Update Salary'; });
        };
    }

    /* ── Salary History Modal ── */
    async function showSalaryHistoryModal(emp) {
        var overlay = document.createElement('div');
        overlay.className = 'inv-modal-overlay';
        overlay.innerHTML = '<div class="inv-modal" style="max-width:600px">' +
            '<div class="inv-modal-header"><h3>Salary History — ' + escapeHtml(emp.name) + '</h3><button type="button" class="inv-modal-close" id="salHistClose">&times;</button></div>' +
            '<div class="inv-modal-body"><div class="kpi-status-msg">Loading...</div></div></div>';
        document.body.appendChild(overlay);

        function close() { overlay.remove(); }
        document.getElementById('salHistClose').onclick = close;
        overlay.onclick = function (e) { if (e.target === overlay) close(); };

        try {
            var data = await requestJson('/api/employees/' + emp.id + '/salary-history');
            var revisions = data.revisions || [];
            var body = overlay.querySelector('.inv-modal-body');

            if (!revisions.length) {
                body.innerHTML = '<div class="emp-empty">No salary revisions found.</div>';
                return;
            }

            var html = '<table style="width:100%;font-size:13px;border-collapse:collapse">' +
                '<thead><tr style="text-align:left;border-bottom:2px solid #e2e8f0">' +
                '<th style="padding:6px 8px">Date</th><th style="padding:6px 8px">Old Monthly</th><th style="padding:6px 8px">New Monthly</th><th style="padding:6px 8px">Reason</th><th style="padding:6px 8px">By</th></tr></thead><tbody>';
            revisions.forEach(function (r) {
                html += '<tr style="border-bottom:1px solid #f1f5f9">' +
                    '<td style="padding:6px 8px">' + escapeHtml(r.effective_date) + '</td>' +
                    '<td style="padding:6px 8px">' + (r.previous_monthly_salary ? '&#8377;' + Number(r.previous_monthly_salary).toLocaleString('en-IN') : '—') + '</td>' +
                    '<td style="padding:6px 8px;font-weight:600">' + (r.new_monthly_salary ? '&#8377;' + Number(r.new_monthly_salary).toLocaleString('en-IN') : '—') + '</td>' +
                    '<td style="padding:6px 8px">' + escapeHtml(r.revision_reason || '—') + '</td>' +
                    '<td style="padding:6px 8px">' + escapeHtml(r.revised_by || '—') + '</td>' +
                '</tr>';
            });
            html += '</tbody></table>';
            body.innerHTML = html;
        } catch (e) {
            overlay.querySelector('.inv-modal-body').innerHTML = '<div class="emp-empty">Failed to load salary history.</div>';
        }
    }

    /* ── Convert Intern to Full-time Modal ── */
    function showConvertInternModal(emp) {
        var overlay = document.createElement('div');
        overlay.className = 'inv-modal-overlay';
        var today = new Date().toISOString().split('T')[0];

        overlay.innerHTML = '<div class="inv-modal" style="max-width:460px">' +
            '<div class="inv-modal-header"><h3>Convert to Full-time — ' + escapeHtml(emp.name) + '</h3><button type="button" class="inv-modal-close" id="convertClose">&times;</button></div>' +
            '<div class="inv-modal-body">' +
                '<p style="font-size:13px;color:#6b7280;margin-bottom:12px">Current: ' + statusBadge('intern') + ' — ' + escapeHtml(emp.designation || '') + '</p>' +
                '<div class="emp-edit-grid">' +
                    '<label>Convert As<select id="convertAs"><option value="active">Active (Confirmed)</option><option value="probation">Probation First</option></select></label>' +
                    '<div id="convertConditional"></div>' +
                    (cachedCanSeeSalary ? '<label>Monthly Salary<input type="number" id="convertSalary" step="0.01" placeholder="0.00"></label>' +
                    '<label>Annual CTC<input type="number" id="convertCtc" step="0.01" placeholder="0.00"></label>' : '') +
                '</div>' +
                '<p id="convertMsg" style="margin-top:8px;font-size:13px"></p>' +
            '</div>' +
            '<div class="inv-modal-footer"><button type="button" class="btn btn-outline" id="convertCancel">Cancel</button><button type="button" class="btn btn-primary btn-lg" id="convertSave">Convert</button></div>' +
        '</div>';

        document.body.appendChild(overlay);

        function updateConditional() {
            var div = document.getElementById('convertConditional');
            if (document.getElementById('convertAs').value === 'probation') {
                var probEnd = new Date(); probEnd.setDate(probEnd.getDate() + 30);
                div.innerHTML = '<label>Probation End Date<input type="date" id="convertProbEnd" value="' + probEnd.toISOString().split('T')[0] + '"></label>';
            } else {
                div.innerHTML = '';
            }
        }
        document.getElementById('convertAs').onchange = updateConditional;
        updateConditional();

        function close() { overlay.remove(); }
        document.getElementById('convertClose').onclick = close;
        document.getElementById('convertCancel').onclick = close;
        overlay.onclick = function (e) { if (e.target === overlay) close(); };

        document.getElementById('convertSave').onclick = function () {
            var btn = document.getElementById('convertSave');
            var msg = document.getElementById('convertMsg');
            btn.disabled = true; btn.textContent = 'Converting...';

            var payload = {
                action: 'change_status',
                id: emp.id,
                new_status: document.getElementById('convertAs').value,
            };
            var probEnd = document.getElementById('convertProbEnd');
            if (probEnd) payload.probation_end_date = probEnd.value;
            var salary = document.getElementById('convertSalary');
            var ctc = document.getElementById('convertCtc');
            if (salary) payload.monthly_salary = salary.value || null;
            if (ctc) payload.annual_ctc = ctc.value || null;

            fetch('/api/employees', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(payload)
            }).then(function (r) { return r.json(); }).then(function (body) {
                if (body.ok) {
                    msg.style.color = '#22c55e';
                    msg.textContent = 'Converted to full-time!';
                    setTimeout(function () { close(); renderEmployees(); }, 1000);
                } else {
                    msg.style.color = '#ef4444';
                    msg.textContent = body.error || 'Failed';
                    btn.disabled = false; btn.textContent = 'Convert';
                }
            }).catch(function () { btn.disabled = false; btn.textContent = 'Convert'; });
        };
    }

    /* ── Promote / Increment: Full Page ── */
    function navigateToPromote(emp) {
        hideSubPages();
        var empView = document.getElementById('employeesView');
        var promView = document.getElementById('promoteMemberView');
        if (empView) empView.classList.add('hidden');
        if (promView) { promView.classList.remove('hidden'); renderPromotePage(emp); }
    }

    function renderPromotePage(emp) {
        var root = document.getElementById('promoteMemberView');
        if (!root) return;

        var rolesOpts = cachedRoles.map(function (r) {
            return '<option value="' + r.id + '"' + (emp.role === r.name ? ' selected' : '') + '>' + escapeHtml(r.name) + '</option>';
        }).join('');

        var deptOpts = '<option value="">— Same —</option>' + cachedDepartments.map(function (d) {
            return '<option value="' + d.id + '"' + (emp.department_id == d.id ? ' selected' : '') + '>' + escapeHtml(d.name) + '</option>';
        }).join('');

        var today = new Date().toISOString().split('T')[0];

        var html = '<div class="add-member-wrap">' +
            '<div class="add-member-header">' +
                '<a href="#" class="add-member-back" id="promoteBack">&larr; Back to Team</a>' +
                '<h2 class="add-member-title">Promote / Increment — ' + escapeHtml(emp.name) + '</h2>' +
                '<div style="margin-top:6px">' + statusBadge(emp.employee_status) +
                    ' <span style="color:#71717a;font-size:13px">' + escapeHtml(emp.designation || '') + ' | ' + escapeHtml(emp.department || '') + '</span></div>' +
            '</div>' +

            // Current info (read-only)
            '<div class="add-member-section">' +
                '<h3 class="add-member-section-title">Current Details</h3>' +
                '<div class="add-member-grid">' +
                    '<label>Role<input type="text" value="' + escapeHtml(emp.role || '') + '" disabled></label>' +
                    '<label>Designation<input type="text" value="' + escapeHtml(emp.designation || '') + '" disabled></label>' +
                    '<label>Department<input type="text" value="' + escapeHtml(emp.department || '') + '" disabled></label>' +
                    (cachedCanSeeSalary && emp.monthly_salary ? '<label>Monthly Salary<input type="text" value="&#8377;' + Number(emp.monthly_salary).toLocaleString('en-IN') + '" disabled></label>' : '') +
                '</div>' +
            '</div>' +

            // New details
            '<div class="add-member-section">' +
                '<h3 class="add-member-section-title">New Details</h3>' +
                '<div class="add-member-grid">' +
                    '<label>Type<select id="promType"><option value="promotion">Promotion</option><option value="increment">Increment</option><option value="role_change">Role Change</option><option value="department_transfer">Department Transfer</option></select></label>' +
                    '<label>New Designation<input type="text" id="promDesignation" list="promDesignationList" value="' + escapeHtml(emp.designation || '') + '"></label>' + designationDatalist('promDesignationList') +
                    '<label>New Role<select id="promRole">' + rolesOpts + '</select></label>' +
                    '<label>New Department<select id="promDept">' + deptOpts + '</select></label>' +
                    '<label>Effective Date<input type="date" id="promDate" value="' + today + '"></label>' +
                    (cachedCanSeeSalary ? '<label>New Monthly Salary<input type="number" id="promSalary" step="0.01" value="' + (emp.monthly_salary || '') + '"></label>' +
                    '<label>New Annual CTC<input type="number" id="promCtc" step="0.01" value="' + (emp.annual_ctc || '') + '"></label>' : '') +
                '</div>' +
            '</div>' +

            // Notes
            '<div class="add-member-section">' +
                '<h3 class="add-member-section-title">Notes</h3>' +
                '<textarea id="promNotes" class="add-member-textarea" rows="3" placeholder="Reason for promotion / increment..."></textarea>' +
            '</div>' +

            '<p id="promStatus" style="font-size:14px;min-height:20px"></p>' +
            '<div class="add-member-actions">' +
                '<button type="button" class="btn btn-outline" id="promCancel">Cancel</button>' +
                '<button type="button" class="btn btn-primary btn-lg" id="promSave">Submit</button>' +
            '</div>' +
        '</div>';

        root.innerHTML = html;

        document.getElementById('promoteBack').onclick = function (e) { e.preventDefault(); renderEmployees(); };
        document.getElementById('promCancel').onclick = function () { renderEmployees(); };

        document.getElementById('promSave').onclick = function () {
            var btn = document.getElementById('promSave');
            var status = document.getElementById('promStatus');
            btn.disabled = true; btn.textContent = 'Submitting...';
            status.textContent = '';

            var payload = {
                action: 'promote',
                id: emp.id,
                promotion_type: document.getElementById('promType').value,
                new_designation: document.getElementById('promDesignation').value,
                new_role_id: document.getElementById('promRole').value,
                new_department_id: document.getElementById('promDept').value || null,
                effective_date: document.getElementById('promDate').value,
                notes: document.getElementById('promNotes').value || null,
            };
            var salary = document.getElementById('promSalary');
            var ctc = document.getElementById('promCtc');
            if (salary) payload.monthly_salary = salary.value || null;
            if (ctc) payload.annual_ctc = ctc.value || null;

            fetch('/api/employees', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(payload)
            }).then(function (r) { return r.json(); }).then(function (body) {
                if (body.ok) {
                    status.style.color = '#22c55e';
                    status.textContent = body.message || 'Done!';
                    setTimeout(function () { renderEmployees(); }, 1200);
                } else {
                    status.style.color = '#ef4444';
                    status.textContent = body.error || 'Failed';
                    btn.disabled = false; btn.textContent = 'Submit';
                }
            }).catch(function () { btn.disabled = false; btn.textContent = 'Submit'; });
        };
    }

    /* ── History Modal (Promotions + Salary) ── */
    async function showHistoryModal(emp) {
        var overlay = document.createElement('div');
        overlay.className = 'inv-modal-overlay';
        overlay.innerHTML = '<div class="inv-modal" style="max-width:650px">' +
            '<div class="inv-modal-header"><h3>History — ' + escapeHtml(emp.name) + '</h3><button type="button" class="inv-modal-close" id="histClose">&times;</button></div>' +
            '<div class="inv-modal-body"><div class="kpi-status-msg">Loading...</div></div></div>';
        document.body.appendChild(overlay);

        function close() { overlay.remove(); }
        document.getElementById('histClose').onclick = close;
        overlay.onclick = function (e) { if (e.target === overlay) close(); };

        try {
            var promData = await requestJson('/api/employees/' + emp.id + '/promotion-history');
            var salData = cachedCanSeeSalary ? await requestJson('/api/employees/' + emp.id + '/salary-history') : { revisions: [] };

            var promotions = promData.promotions || [];
            var revisions = salData.revisions || [];
            var body = overlay.querySelector('.inv-modal-body');

            var html = '';

            // Promotions
            html += '<h4 style="font-size:14px;font-weight:700;color:#e4e4e7;margin-bottom:8px">Promotions & Changes (' + promotions.length + ')</h4>';
            if (promotions.length) {
                html += '<table style="width:100%;font-size:12px;border-collapse:collapse;margin-bottom:16px">' +
                    '<thead><tr style="text-align:left;border-bottom:2px solid #27272a"><th style="padding:5px 6px">Date</th><th style="padding:5px 6px">Type</th><th style="padding:5px 6px">Change</th><th style="padding:5px 6px">By</th></tr></thead><tbody>';
                promotions.forEach(function (p) {
                    var typeLabel = { promotion: 'Promotion', increment: 'Increment', role_change: 'Role Change', department_transfer: 'Dept Transfer' };
                    var change = '';
                    if (p.old_designation !== p.new_designation) change += escapeHtml(p.old_designation || '') + ' &rarr; ' + escapeHtml(p.new_designation || '');
                    if (p.salary_change) change += (change ? '<br>' : '') + '&#8377;' + Number(p.salary_change.old || 0).toLocaleString('en-IN') + ' &rarr; &#8377;' + Number(p.salary_change.new || 0).toLocaleString('en-IN');
                    if (p.notes) change += (change ? '<br>' : '') + '<span style="color:#71717a">' + escapeHtml(p.notes) + '</span>';
                    html += '<tr style="border-bottom:1px solid #1e1e2e">' +
                        '<td style="padding:5px 6px">' + escapeHtml(p.effective_date) + '</td>' +
                        '<td style="padding:5px 6px"><span class="emp-status-badge emp-status-active">' + (typeLabel[p.promotion_type] || p.promotion_type) + '</span></td>' +
                        '<td style="padding:5px 6px">' + (change || '—') + '</td>' +
                        '<td style="padding:5px 6px">' + escapeHtml(p.promoted_by || '—') + '</td></tr>';
                });
                html += '</tbody></table>';
            } else {
                html += '<div style="font-size:13px;color:#71717a;margin-bottom:16px">No promotions recorded yet.</div>';
            }

            // Salary revisions
            if (cachedCanSeeSalary && revisions.length) {
                html += '<h4 style="font-size:14px;font-weight:700;color:#e4e4e7;margin-bottom:8px">Salary Revisions (' + revisions.length + ')</h4>';
                html += '<table style="width:100%;font-size:12px;border-collapse:collapse">' +
                    '<thead><tr style="text-align:left;border-bottom:2px solid #27272a"><th style="padding:5px 6px">Date</th><th style="padding:5px 6px">Old</th><th style="padding:5px 6px">New</th><th style="padding:5px 6px">Reason</th></tr></thead><tbody>';
                revisions.forEach(function (r) {
                    html += '<tr style="border-bottom:1px solid #1e1e2e">' +
                        '<td style="padding:5px 6px">' + escapeHtml(r.effective_date) + '</td>' +
                        '<td style="padding:5px 6px">' + (r.previous_monthly_salary ? '&#8377;' + Number(r.previous_monthly_salary).toLocaleString('en-IN') : '—') + '</td>' +
                        '<td style="padding:5px 6px;font-weight:600">' + (r.new_monthly_salary ? '&#8377;' + Number(r.new_monthly_salary).toLocaleString('en-IN') : '—') + '</td>' +
                        '<td style="padding:5px 6px">' + escapeHtml(r.revision_reason || '—') + '</td></tr>';
                });
                html += '</tbody></table>';
            }

            body.innerHTML = html || '<div style="font-size:13px;color:#71717a">No history found.</div>';
        } catch (e) {
            overlay.querySelector('.inv-modal-body').innerHTML = '<div class="emp-empty">Failed to load history.</div>';
        }
    }

    /* ── My Profile ── */
    async function renderProfile() {
        var root = document.getElementById('profileView');
        if (!root) return;
        root.innerHTML = '<div class="emp-wrap"><div class="kpi-status-msg">Loading profile...</div></div>';

        try {
            var data = await requestJson('/api/profile');
            var p = data.profile;

            function profColor(str) {
                var hash = 0;
                for (var i = 0; i < str.length; i++) hash = str.charCodeAt(i) + ((hash << 5) - hash);
                return 'hsl(' + (Math.abs(hash) % 360) + ',50%,35%)';
            }

            var typeBadge = p.employment_type === 'full_time'
                ? '<span class="badge emp-badge emp-badge-ft">Full-time</span>'
                : (p.employment_type === 'internship' ? '<span class="badge emp-badge emp-badge-int">Intern</span>'
                : (p.employment_type === 'freelancer' ? '<span class="badge emp-badge" style="background:#8b5cf6;color:#fff">Freelancer</span>' : ''));

            // Profile photo (from passport photo) with initials fallback if it
            // is missing/not an image, or 404s at request time.
            var profInitial = p.name.charAt(0).toUpperCase();
            var avatarInner = p.profile_photo
                ? '<img src="' + escapeHtml(p.profile_photo) + '" alt="" '
                    + 'style="width:100%;height:100%;border-radius:10px;object-fit:cover;display:block" '
                    + 'data-fb="' + escapeHtml(profInitial) + '" '
                    + 'onerror="this.style.display=\'none\';this.parentNode.appendChild(document.createTextNode(this.dataset.fb))">'
                : profInitial;

            var html = '<div class="emp-wrap">';
            html += '<div class="emp-header"><h2 class="emp-title">My Profile</h2></div>';

            // Profile card — always expanded; festive when it's their day.
            var myBday = !!(p.date_of_birth && p.date_of_birth.slice(5) === bdayTodayMd());
            html += '<div class="card emp-card emp-card-expanded' + (myBday ? ' emp-card--birthday emp-card--birthday-self' : '') + '">' +
                '<div class="emp-card-row" style="cursor:default">' +
                    '<div class="emp-card-left">' +
                        '<div class="emp-card-avatar" style="background:' + profColor(p.name) + '">' + avatarInner + '</div>' +
                        '<div class="emp-card-info">' +
                            '<div class="emp-card-name">' + escapeHtml(p.name) + ' ' + statusBadge(p.employee_status) + (myBday ? ' <span class="emp-birthday-badge">🎉 Happy Birthday!</span>' : '') + '</div>' +
                            '<div class="emp-card-role">' + escapeHtml(p.designation || p.role || '') + ' ' + typeBadge +
                                (p.department ? ' <span style="color:#6b7280;font-size:11px">(' + escapeHtml(p.department) + ')</span>' : '') +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="emp-card-right">' +
                        '<div class="emp-doc-score">' + p.docs_complete + '/' + p.docs_total + ' docs</div>' +
                    '</div>' +
                '</div>';

            // Detail section
            html += '<div class="emp-detail">' +
                '<div class="emp-detail-grid">' +
                    '<div class="emp-detail-item"><span class="emp-detail-key">Office Email</span><span class="emp-detail-val">' + escapeHtml(p.email) + '</span></div>' +
                    '<div class="emp-detail-item"><span class="emp-detail-key">Reporting To</span><span class="emp-detail-val">' + escapeHtml(p.reporting_manager || '—') + '</span></div>' +
                    '<div class="emp-detail-item"><span class="emp-detail-key">Projects</span><span class="emp-detail-val">' + escapeHtml(p.projects || '—') + '</span></div>' +
                    '<div class="emp-detail-item"><span class="emp-detail-key">Department</span><span class="emp-detail-val">' + escapeHtml(p.department || '—') + '</span></div>' +
                    '<div class="emp-detail-item"><span class="emp-detail-key">Designation</span><span class="emp-detail-val">' + escapeHtml(p.designation || '—') + '</span></div>' +
                    (p.can_edit_doj
                        ? '<div class="emp-detail-item"><span class="emp-detail-key">Joining Date</span>' +
                              '<span class="emp-detail-val" style="display:flex;gap:6px;align-items:center">' +
                                  '<input type="date" id="profDoj" value="' + escapeHtml(p.joining_date || '') + '" style="background:#0f172a;color:#fff;border:1px solid #2a2a3e;border-radius:6px;padding:4px 8px;font-size:13px">' +
                                  '<button type="button" class="emp-edit-btn" id="profDojSaveBtn" style="padding:4px 10px;font-size:12px">Save</button>' +
                              '</span>' +
                          '</div>'
                        : '<div class="emp-detail-item"><span class="emp-detail-key">Joining Date</span><span class="emp-detail-val">' + escapeHtml(p.joining_date || '—') + '</span></div>') +
                    '<div class="emp-detail-item"><span class="emp-detail-key">Employment</span><span class="emp-detail-val">' + escapeHtml(p.employment_type || '—') + '</span></div>' +
                '</div>';

            // Editable personal fields
            var nominee = p.nominee || {};
            var pf = p.pf || {};
            var ins = p.insurance || {};
            var bloodGroups = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'];
            var bloodOpts = '<option value="">— Select —</option>' + bloodGroups.map(function (bg) {
                return '<option value="' + bg + '"' + (p.blood_group === bg ? ' selected' : '') + '>' + bg + '</option>';
            }).join('');
            var maritalStatuses = [
                { v: 'unmarried', l: 'Unmarried' },
                { v: 'married', l: 'Married' },
                { v: 'divorced', l: 'Divorced' },
            ];
            var maritalOpts = '<option value="">— Select —</option>' + maritalStatuses.map(function (m) {
                return '<option value="' + m.v + '"' + (p.marital_status === m.v ? ' selected' : '') + '>' + m.l + '</option>';
            }).join('');

            var sameAddr = !!(p.current_address && p.permanent_address && p.current_address === p.permanent_address);
            html += '<div class="emp-docs-header">Personal Details</div>' +
                '<div class="prof-edit-grid">' +
                    '<label>Personal Mobile<input type="text" class="prof-input" id="profMobile" value="' + escapeHtml(p.personal_mobile || '') + '"></label>' +
                    '<label>Personal Email<input type="text" class="prof-input" id="profEmail" value="' + escapeHtml(p.personal_email || '') + '"></label>' +
                    '<label>Blood Group<select class="prof-input" id="profBlood">' + bloodOpts + '</select></label>' +
                    '<label>Marital Status<select class="prof-input" id="profMarital">' + maritalOpts + '</select></label>' +
                    '<label>Date of Birth<input type="date" class="prof-input" id="profDob" value="' + escapeHtml(p.date_of_birth || '') + '"></label>' +
                    '<label>Father\'s / Mother\'s Name<input type="text" class="prof-input" id="profParent" placeholder="Parent / guardian name" value="' + escapeHtml(p.parent_name || '') + '"></label>' +
                    '<label>Gender<select class="prof-input" id="profGender"><option value="">— Select —</option><option value="male"' + (p.gender === 'male' ? ' selected' : '') + '>Male</option><option value="female"' + (p.gender === 'female' ? ' selected' : '') + '>Female</option><option value="other"' + (p.gender === 'other' ? ' selected' : '') + '>Other</option></select></label>' +
                    '<label>Qualification<input type="text" class="prof-input" id="profQual" placeholder="e.g. B.Tech CSE, MBA" value="' + escapeHtml(p.qualification || '') + '"></label>' +
                    '<label>Emergency Contact Name<input type="text" class="prof-input" id="profEmgName" value="' + escapeHtml(p.emergency_contact_name || '') + '"></label>' +
                    '<label>Emergency Contact Number<input type="text" class="prof-input" id="profEmgNum" value="' + escapeHtml(p.emergency_contact_number || '') + '"></label>' +
                    '<label style="grid-column:1/-1">Current Address<textarea class="prof-input" id="profCurAddr" rows="2" placeholder="Where you live now">' + escapeHtml(p.current_address || '') + '</textarea></label>' +
                    '<label style="grid-column:1/-1;display:flex;align-items:center;gap:8px;font-weight:500"><input type="checkbox" id="profAddrSame"' + (sameAddr ? ' checked' : '') + ' style="width:auto;margin:0"> Permanent address is same as current</label>' +
                    '<label style="grid-column:1/-1" id="profPermAddrWrap"' + (sameAddr ? ' hidden' : '') + '>Permanent Address<textarea class="prof-input" id="profPermAddr" rows="2" placeholder="Hometown / permanent address">' + escapeHtml(p.permanent_address || '') + '</textarea></label>' +
                '</div>' +
                '<button class="emp-edit-btn" id="profSaveBtn" style="margin-top:12px">Save Personal Details</button>';

            // Nominee details (separate group)
            html += '<div class="emp-docs-header" style="margin-top:24px">Nominee Details</div>' +
                '<div class="prof-edit-grid">' +
                    '<label>Nominee Name<input type="text" class="prof-input" id="profNomName" value="' + escapeHtml(nominee.name || '') + '"></label>' +
                    '<label>Nominee Age<input type="number" min="0" max="120" class="prof-input" id="profNomAge" value="' + (nominee.age != null ? escapeHtml(String(nominee.age)) : '') + '"></label>' +
                    '<label>Nominee DOB<input type="date" class="prof-input" id="profNomDob" value="' + escapeHtml(nominee.dob || '') + '"></label>' +
                    '<label>Relation<input type="text" class="prof-input" id="profNomRel" placeholder="e.g. Spouse, Parent" value="' + escapeHtml(nominee.relation || '') + '"></label>' +
                '</div>' +
                '<button class="emp-edit-btn" id="profNomSaveBtn" style="margin-top:12px">Save Nominee Details</button>';

            // PF section — interns are excluded
            html += '<div class="emp-docs-header" style="margin-top:24px">Provident Fund (PF)</div>';
            if (pf.is_intern) {
                html += '<div style="padding:14px 16px;background:#1a1a2e;border-radius:10px;border:1px solid #2a2a3e;color:#9ca3af;font-size:13px">PF is not applicable for interns.</div>';
            } else {
                html += '<div class="prof-edit-grid">' +
                        '<label style="display:flex;align-items:center;gap:8px"><input type="checkbox" id="profPfApp"' + (pf.applicable ? ' checked' : '') + ' style="width:auto"> PF Applicable</label>' +
                        '<label id="profPfUanWrap"' + (pf.applicable ? '' : ' style="display:none"') + '>PF UAN<input type="text" class="prof-input" id="profPfUan" value="' + escapeHtml(pf.uan || '') + '" placeholder="12-digit UAN" maxlength="15"></label>' +
                    '</div>' +
                    '<button class="emp-edit-btn" id="profPfSaveBtn" style="margin-top:12px">Save PF Details</button>';
            }

            // Insurance section — voluntary, employee-driven
            html += '<div class="emp-docs-header" style="margin-top:24px">Insurance</div>' +
                '<p style="font-size:12px;color:#9ca3af;margin:0 0 8px 0">Only fill this in if the company has issued you an insurance policy.</p>' +
                '<div class="prof-edit-grid">' +
                    '<label style="display:flex;align-items:center;gap:8px"><input type="checkbox" id="profInsApp"' + (ins.applicable ? ' checked' : '') + ' style="width:auto"> Insurance Provided</label>' +
                    '<label id="profInsNumWrap"' + (ins.applicable ? '' : ' style="display:none"') + '>Insurance Number<input type="text" class="prof-input" id="profInsNum" value="' + escapeHtml(ins.number || '') + '"></label>' +
                '</div>' +
                '<button class="emp-edit-btn" id="profInsSaveBtn" style="margin-top:12px">Save Insurance Details</button>';

            // Bank Details
            var bank = p.bank || {};
            var bankFilled = !!(bank.account_holder_name && bank.account_number && bank.ifsc_code && bank.has_passbook);
            html += '<div class="emp-docs-header" style="margin-top:24px">Bank Details</div>' +
                '<div class="prof-bank-row" style="display:flex;align-items:center;gap:12px;padding:14px 16px;background:#1a1a2e;border-radius:10px;border:1px solid ' + (bankFilled ? '#166534' : '#7f1d1d') + '">' +
                    '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="' + (bankFilled ? '#22c55e' : '#ef4444') + '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M3 10h18M5 6l7-3 7 3M4 10v11M20 10v11M8 14v3M12 14v3M16 14v3"/></svg>' +
                    '<div style="flex:1">' +
                        '<div style="font-weight:600;font-size:14px;color:#fff">' + (bankFilled ? 'Bank details on file' : 'Bank details required') + '</div>' +
                        '<div style="font-size:12px;color:#9ca3af">' + (bankFilled
                            ? escapeHtml(bank.account_holder_name) + ' • ****' + escapeHtml(String(bank.account_number).slice(-4)) + ' • ' + escapeHtml(bank.ifsc_code)
                            : 'Add account holder name, account number, IFSC, and first page of passbook.') + '</div>' +
                    '</div>' +
                    '<button class="emp-edit-btn" id="bankEditBtn" style="min-width:120px">' + (bankFilled ? 'Edit' : 'Add Details') + '</button>' +
                '</div>';

            // Preferences
            html += '<div class="emp-docs-header" style="margin-top:24px">Preferences</div>' +
                '<div class="prof-pref-row">' +
                    '<div class="prof-pref-info">' +
                        '<div class="prof-pref-title">🐱 Meow sound on sign-in & sign-off</div>' +
                        '<div class="prof-pref-sub">Plays a soft meow when you toggle sign-in or sign-off. <button type="button" class="prof-pref-test" id="profMeowTest">Test</button></div>' +
                    '</div>' +
                    '<label class="dash-toggle prof-pref-toggle"><input type="checkbox" id="profMeowToggle"' + (p.meow_sound_enabled ? ' checked' : '') + '><span class="dash-toggle-slider"></span></label>' +
                '</div>';

            // Change Password — moved here from the sidebar so all personal
            // account settings live together in My Profile.
            html += '<div class="emp-docs-header" style="margin-top:24px">Change Password</div>' +
                '<div class="prof-edit-grid">' +
                    '<label>Current Password<input type="password" class="prof-input" id="profPwCurrent" autocomplete="current-password" placeholder="Enter current password"></label>' +
                    '<label>New Password<input type="password" class="prof-input" id="profPwNew" autocomplete="new-password" minlength="8" placeholder="At least 8 characters"></label>' +
                    '<label>Confirm New Password<input type="password" class="prof-input" id="profPwConfirm" autocomplete="new-password" minlength="8" placeholder="Re-enter new password"></label>' +
                '</div>' +
                '<p id="profPwStatus" class="change-password-status"></p>' +
                '<button class="emp-edit-btn" id="profPwSaveBtn" style="margin-top:8px">Update Password</button>';

            // Integrations — Slack
            html += '<div class="emp-docs-header" style="margin-top:24px">Integrations</div>' +
                '<div id="slackIntegration" style="padding:0 4px">' +
                    '<div style="display:flex;align-items:center;gap:12px;padding:14px 16px;background:#1a1a2e;border-radius:10px;border:1px solid #2a2a3e">' +
                        '<svg width="28" height="28" viewBox="0 0 54 54" xmlns="http://www.w3.org/2000/svg"><path d="M19.7 43.3a4.6 4.6 0 01-4.6 4.6 4.6 4.6 0 01-4.6-4.6 4.6 4.6 0 014.6-4.6h4.6v4.6zm2.3 0a4.6 4.6 0 014.6-4.6 4.6 4.6 0 014.6 4.6v11.5a4.6 4.6 0 01-4.6 4.6 4.6 4.6 0 01-4.6-4.6V43.3z" fill="#E01E5A"/><path d="M26.6 19.7a4.6 4.6 0 01-4.6-4.6 4.6 4.6 0 014.6-4.6 4.6 4.6 0 014.6 4.6v4.6h-4.6zm0 2.4a4.6 4.6 0 014.6 4.6 4.6 4.6 0 01-4.6 4.6H15.1a4.6 4.6 0 01-4.6-4.6 4.6 4.6 0 014.6-4.6h11.5z" fill="#36C5F0"/><path d="M50.2 26.7a4.6 4.6 0 014.6 4.6 4.6 4.6 0 01-4.6 4.6 4.6 4.6 0 01-4.6-4.6v-4.6h4.6zm-2.3 0a4.6 4.6 0 01-4.6 4.6 4.6 4.6 0 01-4.6-4.6V15.1a4.6 4.6 0 014.6-4.6 4.6 4.6 0 014.6 4.6v11.6z" fill="#2EB67D"/><path d="M38.8 50.2a4.6 4.6 0 01-4.6 4.6 4.6 4.6 0 01-4.6-4.6 4.6 4.6 0 014.6-4.6h4.6v4.6zm-2.3-2.3a4.6 4.6 0 01-4.6-4.6V31.8a4.6 4.6 0 014.6-4.6 4.6 4.6 0 014.6 4.6v11.5a4.6 4.6 0 01-4.6 4.6z" fill="#ECB22E"/></svg>' +
                        '<div style="flex:1">' +
                            '<div style="font-weight:600;font-size:14px;color:#fff">Slack</div>' +
                            '<div id="slackStatusText" style="font-size:12px;color:#9ca3af">Checking...</div>' +
                        '</div>' +
                        '<button class="emp-edit-btn" id="slackConnectBtn" style="min-width:120px">Loading...</button>' +
                    '</div>' +
                '</div>' +
                '<div id="githubIntegration" style="padding:0 4px;margin-top:8px">' +
                    '<div style="display:flex;align-items:center;gap:12px;padding:14px 16px;background:#1a1a2e;border-radius:10px;border:1px solid #2a2a3e">' +
                        '<svg width="28" height="28" viewBox="0 0 24 24" fill="#fff"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/></svg>' +
                        '<div style="flex:1">' +
                            '<div style="font-weight:600;font-size:14px;color:#fff">GitHub</div>' +
                            '<div id="githubStatusText" style="font-size:12px;color:#9ca3af">Checking...</div>' +
                        '</div>' +
                        '<button class="emp-edit-btn" id="githubConnectBtn" style="min-width:120px">Loading...</button>' +
                    '</div>' +
                '</div>' +
                '<div id="googleIntegration" style="padding:0 4px;margin-top:8px">' +
                    '<div style="display:flex;align-items:center;gap:12px;padding:14px 16px;background:#1a1a2e;border-radius:10px;border:1px solid #2a2a3e">' +
                        '<svg width="28" height="28" viewBox="0 0 24 24"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>' +
                        '<div style="flex:1">' +
                            '<div style="font-weight:600;font-size:14px;color:#fff">Google</div>' +
                            '<div id="googleStatusText" style="font-size:12px;color:#9ca3af">Checking...</div>' +
                        '</div>' +
                        '<button class="emp-edit-btn" id="googleConnectBtn" style="min-width:120px">Loading...</button>' +
                    '</div>' +
                '</div>';

            // Category selector — drives which document tiles are shown.
            var joinedAs = p.joined_as || '';
            var joinedAsOptions = [
                { v: 'intern', l: 'Intern' },
                { v: 'fresher', l: 'Fresher (Full-time)' },
                { v: 'experienced', l: 'Experienced (Full-time)' },
            ];
            html += '<div class="emp-docs-header" style="margin-top:24px">When you joined Innovfix, you were ?</div>' +
                '<div style="display:flex;flex-wrap:wrap;gap:14px;padding:14px 16px;background:#1a1a2e;border-radius:10px;border:1px solid #2a2a3e">' +
                    joinedAsOptions.map(function (o) {
                        return '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:#e5e7eb;font-size:13px">' +
                            '<input type="radio" name="profJoinedAs" value="' + o.v + '"' + (joinedAs === o.v ? ' checked' : '') + ' style="width:auto;margin:0;cursor:pointer"> ' + o.l +
                            '</label>';
                    }).join('') +
                    (joinedAs ? '' : '<span style="font-size:12px;color:#9ca3af;flex-basis:100%">Pick one — it tailors the document list below.</span>') +
                '</div>';

            var docs = p.documents || {};
            var canEditDocs = p.can_edit_docs === true;

            // Required Documents (Feature 7) — per employment type. Each must be
            // downloaded, printed, filled & signed by hand, scanned, then uploaded.
            // Reuses the .emp-doc-upload-btn / .emp-doc-del-btn classes so the existing
            // upload/delete handlers (bound further below) pick these up automatically.
            var requiredDocs = p.required_docs || [];
            if (requiredDocs.length) {
                html += '<div class="emp-docs-header" style="margin-top:24px">Required Documents</div>' +
                    '<div style="font-size:12px;color:#9ca3af;margin:6px 0 12px;line-height:1.5">Download each template, print it, fill &amp; sign it by hand, then scan and upload the signed copy — a typed or digital fill is not accepted.</div>' +
                    '<div class="emp-docs-grid">';
                requiredDocs.forEach(function (d) {
                    var dk = d.field;
                    var actionBtn;
                    if (d.uploaded) {
                        actionBtn = canEditDocs
                            ? '<button class="emp-doc-del-btn" data-field="' + escapeHtml(dk) + '">Delete</button>'
                            : '<span class="emp-doc-frozen">Locked</span>';
                    } else {
                        actionBtn = '<button class="emp-doc-upload-btn" data-field="' + escapeHtml(dk) + '">Upload Scanned Copy</button>';
                    }
                    var tplLabel = d.field === 'nda_path' ? '⬇ Download Pre-filled NDA' : '⬇ Download Template';
                    var tplLink = d.template_url
                        ? '<a href="' + escapeHtml(d.template_url) + '" target="_blank" style="display:block;margin-top:4px;font-size:12px;color:#60a5fa;text-decoration:none">' + tplLabel + '</a>'
                        : '<span style="display:block;margin-top:4px;font-size:12px;color:#6b7280">Template coming soon</span>';
                    html += '<div class="emp-doc-tile ' + (d.uploaded ? 'emp-doc-tile-ok' : 'emp-doc-tile-miss') + '">' +
                        (d.uploaded
                            ? '<a href="' + escapeHtml(d.path) + '" target="_blank" class="emp-doc-link">' + escapeHtml(d.label) + '</a>'
                            : '<span class="emp-doc-missing-label">' + escapeHtml(d.label) + '</span>') +
                        '<span class="emp-doc-status">' + (d.uploaded ? 'Uploaded' : 'Required') + '</span>' +
                        tplLink +
                        actionBtn +
                    '</div>';
                });
                html += '</div>';
            }

            // Documents
            html += '<div class="emp-docs-header" style="margin-top:24px">My Documents</div>' +
                '<div class="emp-docs-grid">';

            Object.keys(docs).forEach(function (dk) {
                var d = docs[dk];
                var actionBtn = '';
                if (d.uploaded) {
                    if (canEditDocs) {
                        actionBtn = '<button class="emp-doc-del-btn" data-field="' + escapeHtml(dk) + '">Delete</button>';
                    } else {
                        actionBtn = '<span class="emp-doc-frozen">Locked</span>';
                    }
                } else {
                    actionBtn = '<button class="emp-doc-upload-btn" data-field="' + escapeHtml(dk) + '">Upload</button>';
                }
                html += '<div class="emp-doc-tile ' + (d.uploaded ? 'emp-doc-tile-ok' : 'emp-doc-tile-miss') + '">' +
                    (d.uploaded
                        ? '<a href="' + escapeHtml(d.path) + '" target="_blank" class="emp-doc-link">' + escapeHtml(d.label) + '</a>'
                        : '<span class="emp-doc-missing-label">' + escapeHtml(d.label) + '</span>') +
                    '<span class="emp-doc-status">' + (d.uploaded ? 'Uploaded' : 'Missing') + '</span>' +
                    actionBtn +
                '</div>';
            });

            html += '</div></div></div></div>';
            root.innerHTML = html;

            // DOJ inline save — only present for users in DOJ_SELF_EDIT_USER_IDS
            // (JP + Meghana today). For everyone else the input isn't rendered.
            var dojBtn = document.getElementById('profDojSaveBtn');
            if (dojBtn) dojBtn.onclick = function () {
                var input = document.getElementById('profDoj');
                if (!input.value) { alert('Pick a date first.'); return; }
                dojBtn.disabled = true;
                dojBtn.textContent = 'Saving...';
                fetch('/api/profile', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ action: 'update_doj', joining_date: input.value })
                }).then(function (r) { return r.json(); }).then(function (body) {
                    if (body.ok) {
                        dojBtn.textContent = 'Saved!';
                        setTimeout(function () { dojBtn.disabled = false; dojBtn.textContent = 'Save'; }, 1500);
                    } else {
                        alert(body.error || 'Save failed'); dojBtn.disabled = false; dojBtn.textContent = 'Save';
                    }
                }).catch(function () { alert('Save failed'); dojBtn.disabled = false; dojBtn.textContent = 'Save'; });
            };

            // "Permanent same as current" checkbox: hide/show the permanent textarea
            // and mirror current → permanent so both stay in sync until unticked.
            var addrSame = document.getElementById('profAddrSame');
            var permAddrWrap = document.getElementById('profPermAddrWrap');
            var curAddrEl = document.getElementById('profCurAddr');
            var permAddrEl = document.getElementById('profPermAddr');
            if (addrSame) {
                addrSame.addEventListener('change', function () {
                    if (addrSame.checked) {
                        permAddrWrap.hidden = true;
                        permAddrEl.value = curAddrEl.value;
                    } else {
                        permAddrWrap.hidden = false;
                    }
                });
                curAddrEl.addEventListener('input', function () {
                    if (addrSame.checked) permAddrEl.value = curAddrEl.value;
                });
            }

            // Save personal details — single atomic POST so a mid-save session
            // expiry can't half-save (was: chained 'update' + 'update_personal_info').
            var profSaveBtn = document.getElementById('profSaveBtn');
            profSaveBtn.onclick = function () {
                profSaveBtn.disabled = true;
                profSaveBtn.textContent = 'Saving...';
                var curAddr = curAddrEl.value;
                var permAddr = (addrSame && addrSame.checked) ? curAddr : permAddrEl.value;
                fetch('/api/profile', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({
                        action: 'update_personal_info',
                        personal_mobile: document.getElementById('profMobile').value,
                        personal_email: document.getElementById('profEmail').value,
                        emergency_contact_name: document.getElementById('profEmgName').value,
                        emergency_contact_number: document.getElementById('profEmgNum').value,
                        blood_group: document.getElementById('profBlood').value,
                        marital_status: document.getElementById('profMarital').value,
                        date_of_birth: document.getElementById('profDob').value || null,
                        qualification: document.getElementById('profQual').value,
                        parent_name: document.getElementById('profParent').value,
                        gender: document.getElementById('profGender').value || null,
                        current_address: curAddr,
                        permanent_address: permAddr,
                    })
                }).then(function (r) { return r.json(); }).then(function (body) {
                    if (body && body.ok) {
                        profSaveBtn.textContent = 'Saved';
                        // Re-render so the docs grid reflects any qualification
                        // change (master's degree toggles the PG cert tile).
                        var prevQual = p.qualification || '';
                        var newQual = document.getElementById('profQual').value || '';
                        if (prevQual.trim().toLowerCase() !== newQual.trim().toLowerCase()) {
                            renderProfile();
                        }
                    } else {
                        var msg = (body && body.errors) ? Object.values(body.errors)[0][0] : ((body && body.error) || (body && body.message) || 'Save failed');
                        alert(msg); profSaveBtn.disabled = false; profSaveBtn.textContent = 'Save Personal Details';
                    }
                }).catch(function (err) {
                    alert((err && err.message) || 'Save failed');
                    profSaveBtn.disabled = false; profSaveBtn.textContent = 'Save Personal Details';
                });
            };
            ['profMobile','profEmail','profBlood','profMarital','profQual','profParent','profGender','profCurAddr','profPermAddr','profAddrSame','profEmgName','profEmgNum'].forEach(function (id) {
                var el = document.getElementById(id);
                if (!el) return;
                var reset = function () { profSaveBtn.disabled = false; profSaveBtn.textContent = 'Save Personal Details'; };
                el.addEventListener('input', reset);
                el.addEventListener('change', reset);
            });
            if (p.personal_mobile || p.personal_email || p.blood_group || p.marital_status || p.qualification || p.current_address || p.permanent_address || p.emergency_contact_name || p.emergency_contact_number) {
                profSaveBtn.textContent = 'Saved';
                profSaveBtn.disabled = true;
            }

            // Save nominee details
            var nomBtn = document.getElementById('profNomSaveBtn');
            if (nomBtn) {
                nomBtn.onclick = function () {
                    nomBtn.disabled = true;
                    nomBtn.textContent = 'Saving...';
                    var age = document.getElementById('profNomAge').value;
                    fetch('/api/profile', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({
                            action: 'update_personal_info',
                            nominee_name: document.getElementById('profNomName').value,
                            nominee_age: age === '' ? null : parseInt(age, 10),
                            nominee_dob: document.getElementById('profNomDob').value || null,
                            nominee_relation: document.getElementById('profNomRel').value,
                        })
                    }).then(function (r) { return r.json(); }).then(function (body) {
                        if (body.ok) { nomBtn.textContent = 'Saved'; }
                        else {
                            var firstErr = body.errors ? Object.values(body.errors)[0][0] : (body.error || 'Save failed');
                            alert(firstErr); nomBtn.disabled = false; nomBtn.textContent = 'Save Nominee Details';
                        }
                    }).catch(function () { nomBtn.disabled = false; nomBtn.textContent = 'Save Nominee Details'; });
                };
                ['profNomName','profNomAge','profNomDob','profNomRel'].forEach(function (id) {
                    var el = document.getElementById(id);
                    if (!el) return;
                    var reset = function () { nomBtn.disabled = false; nomBtn.textContent = 'Save Nominee Details'; };
                    el.addEventListener('input', reset);
                    el.addEventListener('change', reset);
                });
                if (nominee.name || nominee.age != null || nominee.dob || nominee.relation) {
                    nomBtn.textContent = 'Saved';
                    nomBtn.disabled = true;
                }
            }

            // PF toggle controls UAN input enabled-state
            var pfApp = document.getElementById('profPfApp');
            var pfUan = document.getElementById('profPfUan');
            var pfUanWrap = document.getElementById('profPfUanWrap');
            if (pfApp && pfUan) {
                pfApp.addEventListener('change', function () {
                    if (pfUanWrap) pfUanWrap.style.display = pfApp.checked ? '' : 'none';
                    if (!pfApp.checked) pfUan.value = '';
                });
            }
            var pfBtn = document.getElementById('profPfSaveBtn');
            if (pfBtn) {
                pfBtn.onclick = function () {
                    pfBtn.disabled = true;
                    pfBtn.textContent = 'Saving...';
                    fetch('/api/profile', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({
                            action: 'update_pf',
                            pf_applicable: pfApp.checked,
                            pf_uan: pfApp.checked ? pfUan.value.trim() : null,
                        })
                    }).then(function (r) { return r.json(); }).then(function (body) {
                        if (body.ok) { pfBtn.textContent = 'Saved'; }
                        else {
                            var firstErr = body.errors ? Object.values(body.errors)[0][0] : (body.error || 'Save failed');
                            alert(firstErr); pfBtn.disabled = false; pfBtn.textContent = 'Save PF Details';
                        }
                    }).catch(function () { pfBtn.disabled = false; pfBtn.textContent = 'Save PF Details'; });
                };
                ['profPfApp','profPfUan'].forEach(function (id) {
                    var el = document.getElementById(id);
                    if (!el) return;
                    var reset = function () { pfBtn.disabled = false; pfBtn.textContent = 'Save PF Details'; };
                    el.addEventListener('input', reset);
                    el.addEventListener('change', reset);
                });
                if (pf.applicable || pf.uan) {
                    pfBtn.textContent = 'Saved';
                    pfBtn.disabled = true;
                }
            }

            // Insurance toggle controls number input enabled-state
            var insApp = document.getElementById('profInsApp');
            var insNum = document.getElementById('profInsNum');
            var insNumWrap = document.getElementById('profInsNumWrap');
            if (insApp && insNum) {
                insApp.addEventListener('change', function () {
                    if (insNumWrap) insNumWrap.style.display = insApp.checked ? '' : 'none';
                    if (!insApp.checked) insNum.value = '';
                });
            }
            var insBtn = document.getElementById('profInsSaveBtn');
            if (insBtn) {
                insBtn.onclick = function () {
                    insBtn.disabled = true;
                    insBtn.textContent = 'Saving...';
                    fetch('/api/profile', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({
                            action: 'update_insurance',
                            insurance_applicable: insApp.checked,
                            insurance_number: insApp.checked ? insNum.value.trim() : null,
                        })
                    }).then(function (r) { return r.json(); }).then(function (body) {
                        if (body.ok) { insBtn.textContent = 'Saved'; }
                        else { alert(body.error || 'Save failed'); insBtn.disabled = false; insBtn.textContent = 'Save Insurance Details'; }
                    }).catch(function () { insBtn.disabled = false; insBtn.textContent = 'Save Insurance Details'; });
                };
                ['profInsApp','profInsNum'].forEach(function (id) {
                    var el = document.getElementById(id);
                    if (!el) return;
                    var reset = function () { insBtn.disabled = false; insBtn.textContent = 'Save Insurance Details'; };
                    el.addEventListener('input', reset);
                    el.addEventListener('change', reset);
                });
                if (ins.applicable || ins.number) {
                    insBtn.textContent = 'Saved';
                    insBtn.disabled = true;
                }
            }

            // Bank Details modal
            var bankBtn = document.getElementById('bankEditBtn');
            if (bankBtn) {
                bankBtn.onclick = function () { showBankDetailsModal(p.bank || {}); };
            }

            // Category radio — save on change, then re-render so the docs grid
            // below reflects the new category's exclusion list.
            document.querySelectorAll('input[name="profJoinedAs"]').forEach(function (r) {
                r.addEventListener('change', function () {
                    if (!r.checked) return;
                    var val = r.value;
                    document.querySelectorAll('input[name="profJoinedAs"]').forEach(function (x) { x.disabled = true; });
                    fetch('/api/profile', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ action: 'update_joined_as', joined_as: val })
                    }).then(function (resp) { return resp.json(); }).then(function (body) {
                        if (body && body.ok) {
                            renderProfile();
                        } else {
                            alert((body && body.error) || 'Failed to save');
                            document.querySelectorAll('input[name="profJoinedAs"]').forEach(function (x) { x.disabled = false; });
                        }
                    }).catch(function () {
                        alert('Failed to save');
                        document.querySelectorAll('input[name="profJoinedAs"]').forEach(function (x) { x.disabled = false; });
                    });
                });
            });

            // Meow sound preference toggle
            var meowToggle = document.getElementById('profMeowToggle');
            if (meowToggle) {
                meowToggle.addEventListener('change', function () {
                    var enabled = meowToggle.checked;
                    meowToggle.disabled = true;
                    fetch('/api/profile', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ action: 'update_preferences', meow_sound_enabled: enabled })
                    }).then(function (r) { return r.json(); }).then(function (body) {
                        if (body.ok) {
                            if (window.__PORTAL_CONFIG) window.__PORTAL_CONFIG.meowSoundEnabled = enabled;
                            if (enabled && window.PortalSounds) window.PortalSounds.playMeow();
                        } else {
                            meowToggle.checked = !enabled;
                            alert(body.error || 'Failed to save preference');
                        }
                    }).catch(function () {
                        meowToggle.checked = !enabled;
                        alert('Failed to save preference');
                    }).finally(function () { meowToggle.disabled = false; });
                });
            }
            var meowTestBtn = document.getElementById('profMeowTest');
            if (meowTestBtn) {
                meowTestBtn.onclick = function () {
                    console.log('[meow] Test clicked — PortalSounds available:', !!window.PortalSounds);
                    if (window.PortalSounds) {
                        window.PortalSounds.playMeow();
                        console.log('[meow] playMeow() invoked. Check your system volume if you hear nothing.');
                    } else {
                        console.warn('[meow] window.PortalSounds is missing — portal.js may not have loaded yet.');
                    }
                };
            }

            // Change Password (inline) — mirrors the old modal flow, posting to
            // the same /api/auth/change-password endpoint.
            var pwBtn = document.getElementById('profPwSaveBtn');
            if (pwBtn) {
                var pwStatus = document.getElementById('profPwStatus');
                var setPwStatus = function (msg, isError) {
                    if (pwStatus) {
                        pwStatus.textContent = msg || '';
                        pwStatus.className = 'change-password-status' + (isError ? ' error' : '');
                    }
                };
                pwBtn.onclick = function () {
                    var cur = document.getElementById('profPwCurrent');
                    var nw = document.getElementById('profPwNew');
                    var cf = document.getElementById('profPwConfirm');
                    if (!cur || !nw || !cf) return;
                    if (!cur.value || !nw.value || !cf.value) { setPwStatus('Fill in all three fields.', true); return; }
                    if (nw.value.length < 8) { setPwStatus('New password must be at least 8 characters.', true); return; }
                    if (nw.value !== cf.value) { setPwStatus('New password and confirmation do not match.', true); return; }
                    pwBtn.disabled = true;
                    pwBtn.textContent = 'Saving...';
                    setPwStatus('Saving...');
                    fetch('/api/auth/change-password', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({
                            current_password: cur.value,
                            new_password: nw.value,
                            new_password_confirmation: cf.value
                        })
                    }).then(function (r) {
                        return r.json().catch(function () { return {}; }).then(function (body) { return { ok: r.ok, body: body }; });
                    }).then(function (res) {
                        var body = res.body || {};
                        if (res.ok && body.ok) {
                            setPwStatus('Password changed successfully.');
                            cur.value = ''; nw.value = ''; cf.value = '';
                        } else {
                            var errMsg = 'Failed to change password.';
                            if (body.errors) {
                                var first = Object.keys(body.errors)[0];
                                if (first && body.errors[first] && body.errors[first][0]) errMsg = body.errors[first][0];
                            } else if (body.message) {
                                errMsg = body.message;
                            }
                            setPwStatus(errMsg, true);
                        }
                    }).catch(function (err) {
                        setPwStatus((err && err.message) || 'Unable to change password. Please try again.', true);
                    }).finally(function () {
                        pwBtn.disabled = false;
                        pwBtn.textContent = 'Update Password';
                    });
                };
            }

            // Slack integration
            (function initSlackIntegration() {
                var btn = document.getElementById('slackConnectBtn');
                var statusEl = document.getElementById('slackStatusText');
                if (!btn || !statusEl) return;

                fetch('/api/slack/status', {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data.connected) {
                        statusEl.textContent = 'Connected to ' + (data.team_name || 'Slack');
                        statusEl.style.color = '#34d399';
                        btn.textContent = 'Disconnect';
                        btn.style.background = '#dc2626';
                        btn.style.color = '#fff';
                        btn.onclick = function () {
                            if (!confirm('Disconnect your Slack account?')) return;
                            btn.disabled = true;
                            btn.textContent = 'Disconnecting...';
                            fetch('/api/slack/disconnect', {
                                method: 'POST', credentials: 'same-origin',
                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                            }).then(function (r) { return r.json(); }).then(function () {
                                renderProfile();
                            }).catch(function () { btn.disabled = false; btn.textContent = 'Disconnect'; });
                        };
                    } else {
                        statusEl.textContent = 'Not connected';
                        statusEl.style.color = '#9ca3af';
                        btn.textContent = 'Connect Slack';
                        btn.style.background = '#4A154B';
                        btn.style.color = '#fff';
                        btn.onclick = function () {
                            btn.disabled = true;
                            btn.textContent = 'Redirecting...';
                            fetch('/api/slack/connect', {
                                credentials: 'same-origin',
                                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                            }).then(function (r) { return r.json(); }).then(function (data) {
                                if (data.ok && data.url) {
                                    window.location.href = data.url;
                                } else {
                                    alert('Failed to start Slack connection');
                                    btn.disabled = false;
                                    btn.textContent = 'Connect Slack';
                                }
                            }).catch(function () { btn.disabled = false; btn.textContent = 'Connect Slack'; });
                        };
                    }
                }).catch(function () {
                    statusEl.textContent = 'Unable to check status';
                    btn.textContent = 'Connect Slack';
                    btn.style.background = '#4A154B';
                    btn.style.color = '#fff';
                });
            })();

            // GitHub integration
            (function initGitHubIntegration() {
                var btn = document.getElementById('githubConnectBtn');
                var statusEl = document.getElementById('githubStatusText');
                if (!btn || !statusEl) return;

                fetch('/api/github/status', {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data.connected) {
                        statusEl.textContent = 'Connected as @' + (data.username || 'user');
                        statusEl.style.color = '#34d399';
                        btn.textContent = 'Disconnect';
                        btn.style.background = '#dc2626';
                        btn.style.color = '#fff';
                        btn.onclick = function () {
                            if (!confirm('Disconnect your GitHub account?')) return;
                            btn.disabled = true; btn.textContent = 'Disconnecting...';
                            fetch('/api/github/disconnect', {
                                method: 'POST', credentials: 'same-origin',
                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                            }).then(function () { renderProfile(); });
                        };
                    } else {
                        statusEl.textContent = 'Not connected';
                        statusEl.style.color = '#9ca3af';
                        btn.textContent = 'Connect GitHub';
                        btn.style.background = '#333';
                        btn.style.color = '#fff';
                        btn.onclick = function () {
                            btn.disabled = true; btn.textContent = 'Redirecting...';
                            fetch('/api/github/connect', {
                                credentials: 'same-origin',
                                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                            }).then(function (r) { return r.json(); }).then(function (data) {
                                if (data.ok && data.url) { window.location.href = data.url; }
                                else { btn.disabled = false; btn.textContent = 'Connect GitHub'; }
                            }).catch(function () { btn.disabled = false; btn.textContent = 'Connect GitHub'; });
                        };
                    }
                }).catch(function () {
                    statusEl.textContent = 'Unable to check';
                    btn.textContent = 'Connect GitHub';
                    btn.style.background = '#333'; btn.style.color = '#fff';
                });
            })();

            // Google integration
            (function initGoogleIntegration() {
                var btn = document.getElementById('googleConnectBtn');
                var statusEl = document.getElementById('googleStatusText');
                if (!btn || !statusEl) return;

                fetch('/api/google/status', {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data.connected) {
                        statusEl.textContent = 'Connected as ' + (data.email || data.name || 'user');
                        statusEl.style.color = '#34d399';
                        btn.textContent = 'Disconnect';
                        btn.style.background = '#dc2626'; btn.style.color = '#fff';
                        btn.onclick = function () {
                            if (!confirm('Disconnect your Google account?')) return;
                            btn.disabled = true; btn.textContent = 'Disconnecting...';
                            fetch('/api/google/disconnect', {
                                method: 'POST', credentials: 'same-origin',
                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                            }).then(function () { renderProfile(); });
                        };
                    } else {
                        statusEl.textContent = 'Not connected';
                        statusEl.style.color = '#9ca3af';
                        btn.textContent = 'Connect Google';
                        btn.style.background = '#4285F4'; btn.style.color = '#fff';
                        btn.onclick = function () {
                            btn.disabled = true; btn.textContent = 'Redirecting...';
                            fetch('/api/google/connect', {
                                credentials: 'same-origin',
                                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                            }).then(function (r) { return r.json(); }).then(function (data) {
                                if (data.ok && data.url) { window.location.href = data.url; }
                                else { btn.disabled = false; btn.textContent = 'Connect Google'; alert(data.error || 'Failed'); }
                            }).catch(function () { btn.disabled = false; btn.textContent = 'Connect Google'; });
                        };
                    }
                }).catch(function () {
                    statusEl.textContent = 'Unable to check';
                    btn.textContent = 'Connect Google';
                    btn.style.background = '#4285F4'; btn.style.color = '#fff';
                });
            })();

            // Upload doc buttons
            root.querySelectorAll('.emp-doc-upload-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var field = btn.getAttribute('data-field');
                    var input = document.createElement('input');
                    input.type = 'file';
                    input.accept = '.pdf,.jpg,.jpeg,.png';
                    input.onchange = function () {
                        if (!input.files[0]) return;
                        btn.disabled = true;
                        btn.textContent = 'Uploading...';
                        var fd = new FormData();
                        fd.append('action', 'upload_doc');
                        fd.append('field', field);
                        fd.append('file', input.files[0]);
                        fetch('/api/profile', {
                            method: 'POST', credentials: 'same-origin',
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            body: fd
                        }).then(function (r) { return r.json(); }).then(function (body) {
                            if (body.ok) renderProfile();
                            else { alert(body.error || 'Upload failed'); btn.disabled = false; btn.textContent = 'Upload'; }
                        }).catch(function () { btn.disabled = false; btn.textContent = 'Upload'; });
                    };
                    input.click();
                });
            });

            // Delete doc buttons
            root.querySelectorAll('.emp-doc-del-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (!confirm('Delete this document?')) return;
                    btn.disabled = true;
                    btn.textContent = '...';
                    fetch('/api/profile', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ action: 'delete_doc', field: btn.getAttribute('data-field') })
                    }).then(function (r) { return r.json(); }).then(function (body) {
                        if (body.ok) renderProfile();
                        else { alert(body.error || 'Delete failed'); btn.disabled = false; btn.textContent = 'Delete'; }
                    }).catch(function () { btn.disabled = false; btn.textContent = 'Delete'; });
                });
            });

        } catch (err) {
            console.error('renderProfile failed', err);
            root.innerHTML = '<div class="emp-wrap"><div class="emp-empty">Failed to load profile.</div></div>';
        }
    }

    /* ── HR Dashboard ── */
    // ── Probation lifecycle tracker ────────────────────────────────────────
    var _hrProbFilter = 'all';   // persists across re-renders
    var _hrProbData = null;      // last {tracker, counts} for instant client-side filtering

    var PROB_STATE_META = {
        overdue:      { label: 'Overdue',      color: '#ef4444', bg: 'rgba(239,68,68,.13)' },
        ending_soon:  { label: 'Ending soon',  color: '#f59e0b', bg: 'rgba(245,158,11,.13)' },
        on_probation: { label: 'On probation', color: '#3b82f6', bg: 'rgba(59,130,246,.13)' },
        confirmed:    { label: 'Confirmed',    color: '#10b981', bg: 'rgba(16,185,129,.13)' }
    };

    function hrInitials(name) {
        return String(name || '?').trim().split(/\s+/).slice(0, 2).map(function (p) { return p.charAt(0); }).join('').toUpperCase();
    }

    function probRightLabel(r) {
        if (r.state === 'confirmed') return 'Confirmed';
        if (r.state === 'overdue') return Math.abs(r.days_remaining) + 'd overdue';
        return r.days_remaining + 'd left';
    }

    function renderProbationTracker(tracker, counts) {
        if (!tracker.length) {
            return '<div id="hrProbTracker" class="hr-dash-section hr-prob-wrap" style="margin-top:16px">' +
                '<h3 class="hr-dash-section-title">Probation Tracker</h3>' +
                '<div class="emp-empty" style="font-size:13px">No one is on probation right now. 🎉</div></div>';
        }

        var html = '<div id="hrProbTracker" class="hr-dash-section hr-prob-wrap" style="margin-top:16px">';
        var dueCount = (counts.overdue || 0) + (counts.ending_soon || 0);

        if (dueCount > 0) {
            html += '<div class="hr-prob-banner">' +
                '<div class="hr-prob-banner-ico">⚠</div>' +
                '<div class="hr-prob-banner-txt">' +
                    '<div class="hr-prob-banner-title">' + dueCount + ' probation period' + (dueCount > 1 ? 's' : '') + ' ending soon</div>' +
                    '<div class="hr-prob-banner-sub">Review each member and release their probation confirmation letter.</div>' +
                '</div>' +
                '<button class="hr-prob-letters-btn" data-prob-action="go-letters">Go to Letters</button>' +
            '</div>';
        }

        var chips = [
            ['all', 'All', counts.all || 0],
            ['ending_soon', 'Ending soon', counts.ending_soon || 0],
            ['overdue', 'Overdue', counts.overdue || 0],
            ['on_probation', 'On probation', counts.on_probation || 0],
            ['confirmed', 'Confirmed', counts.confirmed || 0]
        ];
        html += '<div class="hr-prob-chips">';
        chips.forEach(function (c) {
            if (c[0] !== 'all' && !c[2]) return;   // hide empty buckets, always keep "All"
            html += '<button class="hr-prob-chip' + (_hrProbFilter === c[0] ? ' is-active' : '') + '" data-prob-filter="' + c[0] + '">' +
                escapeHtml(c[1]) + ' <span class="hr-prob-chip-n">' + c[2] + '</span></button>';
        });
        html += '</div>';

        var rows = tracker.filter(function (r) { return _hrProbFilter === 'all' || r.state === _hrProbFilter; });
        html += '<div class="hr-prob-table-wrap"><table class="hr-prob-table"><thead><tr>' +
            '<th>Member</th><th>Type</th><th>Probation</th><th>Status</th><th class="hr-prob-act-col">Action</th>' +
            '</tr></thead><tbody>';
        if (!rows.length) {
            html += '<tr><td colspan="5" class="hr-prob-empty">No members in this view.</td></tr>';
        }
        rows.forEach(function (r) {
            var meta = PROB_STATE_META[r.state] || PROB_STATE_META.on_probation;
            var avatar = r.avatar_url
                ? '<span class="hr-prob-av"><img src="' + escapeHtml(r.avatar_url) + '" alt=""></span>'
                : '<span class="hr-prob-av hr-prob-av-i" style="background:' + stringToColor(r.name) + '">' + escapeHtml(hrInitials(r.name)) + '</span>';
            var pct = Math.max(0, Math.min(100, r.progress_pct || 0));
            var bar = '<div class="hr-prob-bar">' +
                '<div class="hr-prob-bar-top"><span>' + escapeHtml(r.duration_label || '') + '</span>' +
                '<span style="color:' + meta.color + ';font-weight:600">' + escapeHtml(probRightLabel(r)) + '</span></div>' +
                '<div class="hr-prob-bar-track"><div class="hr-prob-bar-fill" style="width:' + pct + '%;background:' + meta.color + '"></div></div></div>';
            var badge = '<span class="hr-prob-badge" style="color:' + meta.color + ';background:' + meta.bg + '">' +
                '<span class="hr-prob-dot" style="background:' + meta.color + '"></span>' + escapeHtml(meta.label) + '</span>';

            var action;
            if (r.state === 'confirmed') {
                action = r.letter_id
                    ? '<button class="hr-prob-btn hr-prob-btn-ghost" data-prob-action="view-letter" data-letter-id="' + escapeHtml(String(r.letter_id)) + '">View letter</button>'
                    : '<button class="hr-prob-btn hr-prob-btn-primary" data-prob-action="release-letter" data-user-id="' + escapeHtml(String(r.id)) + '">Release letter</button>';
            } else {
                action = '<div class="hr-prob-actions">' +
                    '<button class="hr-prob-btn hr-prob-btn-primary" data-prob-action="release-letter" data-user-id="' + escapeHtml(String(r.id)) + '">Release letter</button>' +
                    '<button class="hr-prob-btn hr-prob-btn-ok" data-prob-action="confirm" data-user-id="' + escapeHtml(String(r.id)) + '" data-name="' + escapeHtml(r.name) + '">Confirm</button>' +
                    '<button class="hr-prob-btn hr-prob-btn-ghost" data-prob-action="extend" data-user-id="' + escapeHtml(String(r.id)) + '" data-name="' + escapeHtml(r.name) + '">Extend</button>' +
                '</div>';
            }

            html += '<tr>' +
                '<td><div class="hr-prob-member">' + avatar +
                    '<div class="hr-prob-mtext"><div class="hr-prob-name">' + escapeHtml(r.name) + '</div>' +
                    '<div class="hr-prob-role">' + escapeHtml(r.designation || r.role || '') + '</div></div></div></td>' +
                '<td><span class="hr-prob-type">' + escapeHtml(r.type || '') + '</span></td>' +
                '<td class="hr-prob-bar-cell">' + bar + '</td>' +
                '<td>' + badge + '</td>' +
                '<td class="hr-prob-act-col">' + action + '</td>' +
            '</tr>';
        });
        html += '</tbody></table></div></div>';
        return html;
    }

    function probPost(url, payload) {
        return fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); });
    }

    function bindProbActions(root) {
        if (!root || root.dataset.probBound === '1') return;
        root.dataset.probBound = '1';
        root.addEventListener('click', function (ev) {
            var chip = ev.target.closest ? ev.target.closest('[data-prob-filter]') : null;
            if (chip) {
                _hrProbFilter = chip.getAttribute('data-prob-filter');
                var el = document.getElementById('hrProbTracker');
                if (el && _hrProbData) el.outerHTML = renderProbationTracker(_hrProbData.tracker, _hrProbData.counts);
                return;
            }

            var btn = ev.target.closest ? ev.target.closest('[data-prob-action]') : null;
            if (!btn) return;
            var action = btn.getAttribute('data-prob-action');
            var uid = btn.getAttribute('data-user-id');
            var name = btn.getAttribute('data-name') || 'this employee';

            if (action === 'go-letters') {
                if (window.MeetingModule && MeetingModule.switchView) MeetingModule.switchView('letters');
                return;
            }
            if (action === 'release-letter') {
                if (window.MeetingModule && MeetingModule.switchView) MeetingModule.switchView('letters');
                if (window.LettersModule && LettersModule.composeForUser) {
                    setTimeout(function () { LettersModule.composeForUser(uid, 'probation', null); }, 250);
                } else {
                    alert('Open the Letters page to issue the letter.');
                }
                return;
            }
            if (action === 'view-letter') {
                var lid = btn.getAttribute('data-letter-id');
                if (lid) window.open('/api/letters/' + lid + '/download', '_blank');
                return;
            }
            if (action === 'confirm') {
                if (!window.confirm('Confirm ' + name + '? This ends probation and marks them Active.')) return;
                btn.disabled = true;
                probPost('/api/hr/probation/confirm', { user_id: Number(uid) }).then(function (res) {
                    if (res.ok && res.body && res.body.ok) { renderHRDashboard(); }
                    else { btn.disabled = false; alert((res.body && (res.body.error || res.body.message)) || 'Could not confirm.'); }
                }).catch(function () { btn.disabled = false; alert('Could not confirm.'); });
                return;
            }
            if (action === 'extend') {
                var ans = window.prompt('Extend ' + name + '’s probation by how many days? Enter 15 or 30.', '15');
                if (ans === null) return;
                var days = parseInt(ans, 10);
                if (days !== 15 && days !== 30) { alert('Please enter 15 or 30.'); return; }
                btn.disabled = true;
                probPost('/api/hr/probation/extend', { user_id: Number(uid), days: days }).then(function (res) {
                    if (res.ok && res.body && res.body.ok) { renderHRDashboard(); }
                    else { btn.disabled = false; alert((res.body && (res.body.error || res.body.message)) || 'Could not extend.'); }
                }).catch(function () { btn.disabled = false; alert('Could not extend.'); });
                return;
            }
        });
    }

    async function renderHRDashboard() {
        var root = document.getElementById('hr_dashboardView');
        if (!root) return;
        root.innerHTML = '<div class="emp-wrap"><div class="kpi-status-msg">Loading HR dashboard...</div></div>';

        try {
            var data = await requestJson('/api/hr/dashboard');

            var sc = data.status_counts || {};
            var tc = data.type_counts || {};

            var html = '<div class="emp-wrap">';
            html += '<div class="emp-header"><h2 class="emp-title">HR Overview</h2></div>';

            // Stat cards
            html += '<div class="hr-dash-stats">' +
                '<div class="hr-dash-card hr-dash-card-blue"><div class="hr-dash-card-num">' + (data.total_active || 0) + '</div><div class="hr-dash-card-lbl">Active Team</div></div>' +
                '<div class="hr-dash-card hr-dash-card-green"><div class="hr-dash-card-num">' + (tc.full_time || 0) + '</div><div class="hr-dash-card-lbl">Full-time</div></div>' +
                '<div class="hr-dash-card hr-dash-card-purple"><div class="hr-dash-card-num">' + (tc.internship || 0) + '</div><div class="hr-dash-card-lbl">Interns</div></div>' +
                '<div class="hr-dash-card" style="border-left:4px solid #8b5cf6"><div class="hr-dash-card-num">' + (tc.freelancer || 0) + '</div><div class="hr-dash-card-lbl">Freelancers</div></div>' +
                '<div class="hr-dash-card hr-dash-card-yellow"><div class="hr-dash-card-num">' + (sc.probation || 0) + '</div><div class="hr-dash-card-lbl">On Probation</div></div>' +
                '<div class="hr-dash-card hr-dash-card-orange"><div class="hr-dash-card-num">' + (sc.notice_period || 0) + '</div><div class="hr-dash-card-lbl">Notice Period</div></div>' +
                '<div class="hr-dash-card hr-dash-card-red"><div class="hr-dash-card-num">' + ((sc.resigned || 0) + (sc.terminated || 0)) + '</div><div class="hr-dash-card-lbl">Exited (Total)</div></div>' +
            '</div>';

            // Joiners & Leavers this month
            var joiners = data.joiners_this_month || [];
            var leavers = data.leavers_this_month || [];

            html += '<div class="hr-dash-row">';
            html += '<div class="hr-dash-section"><h3 class="hr-dash-section-title">Joined This Month (' + joiners.length + ')</h3>';
            if (joiners.length) {
                html += '<div class="hr-dash-list">';
                joiners.forEach(function (j) {
                    html += '<div class="hr-dash-list-item"><span class="hr-dash-list-name">' + escapeHtml(j.name) + '</span><span class="hr-dash-list-meta">' + escapeHtml(j.role) + ' | ' + escapeHtml(j.joining_date) + '</span></div>';
                });
                html += '</div>';
            } else {
                html += '<div class="emp-empty" style="font-size:13px">No joiners this month</div>';
            }
            html += '</div>';

            html += '<div class="hr-dash-section"><h3 class="hr-dash-section-title">Left This Month (' + leavers.length + ')</h3>';
            if (leavers.length) {
                html += '<div class="hr-dash-list">';
                leavers.forEach(function (l) {
                    html += '<div class="hr-dash-list-item"><span class="hr-dash-list-name">' + escapeHtml(l.name) + '</span><span class="hr-dash-list-meta">' + escapeHtml(l.exit_reason || l.employee_status || '') + ' | ' + escapeHtml(l.exit_date) + '</span></div>';
                });
                html += '</div>';
            } else {
                html += '<div class="emp-empty" style="font-size:13px">No exits this month</div>';
            }
            html += '</div></div>';

            // ── Probation lifecycle tracker (replaces the old flat "ending soon" list) ──
            _hrProbData = { tracker: data.probation_tracker || [], counts: data.tracker_counts || {} };
            html += renderProbationTracker(_hrProbData.tracker, _hrProbData.counts);

            // Intern conversion due (kept as the existing simple list)
            var intAlerts = data.intern_alerts || [];
            if (intAlerts.length) {
                html += '<div class="hr-dash-row"><div class="hr-dash-section hr-dash-alert-section"><h3 class="hr-dash-section-title" style="color:#3b82f6">Intern Conversion Due (' + intAlerts.length + ')</h3>' +
                    '<div class="hr-dash-list">';
                intAlerts.forEach(function (i) {
                    html += '<div class="hr-dash-list-item"><span class="hr-dash-list-name">' + escapeHtml(i.name) + '</span><span class="hr-dash-list-meta">' + escapeHtml(i.internship_end_date) + ' — ' + i.days_remaining + ' days left</span></div>';
                });
                html += '</div></div></div>';
            }

            // Department breakdown
            var depts = data.department_breakdown || [];
            if (depts.length) {
                html += '<div class="hr-dash-section" style="margin-top:16px"><h3 class="hr-dash-section-title">Department Breakdown</h3>' +
                    '<table class="hr-dash-table"><thead><tr><th>Department</th><th>Head</th><th>Headcount</th><th>Probation</th><th>Interns</th></tr></thead><tbody>';
                depts.forEach(function (d) {
                    html += '<tr><td>' + escapeHtml(d.name) + '</td><td>' + escapeHtml(d.head || '—') + '</td><td>' + d.headcount + '</td><td>' + d.on_probation + '</td><td>' + d.interns + '</td></tr>';
                });
                if (data.unassigned_department) {
                    html += '<tr style="color:#6b7280"><td>Unassigned</td><td>—</td><td>' + data.unassigned_department + '</td><td>—</td><td>—</td></tr>';
                }
                html += '</tbody></table></div>';
            }

            // Salary overview (if available)
            if (data.salary_overview) {
                var so = data.salary_overview;
                html += '<div class="hr-dash-section" style="margin-top:16px"><h3 class="hr-dash-section-title">Salary Overview</h3>' +
                    '<div class="hr-dash-stats">' +
                        '<div class="hr-dash-card hr-dash-card-green"><div class="hr-dash-card-num">&#8377;' + Number(so.total_monthly_payroll).toLocaleString('en-IN') + '</div><div class="hr-dash-card-lbl">Monthly Payroll</div></div>' +
                        '<div class="hr-dash-card hr-dash-card-blue"><div class="hr-dash-card-num">&#8377;' + Number(so.average_monthly_salary).toLocaleString('en-IN') + '</div><div class="hr-dash-card-lbl">Avg Monthly</div></div>' +
                        '<div class="hr-dash-card hr-dash-card-purple"><div class="hr-dash-card-num">&#8377;' + Number(so.total_annual_cost).toLocaleString('en-IN') + '</div><div class="hr-dash-card-lbl">Annual Cost</div></div>' +
                    '</div></div>';
            }

            html += '</div>';
            root.innerHTML = html;
            bindProbActions(root);

        } catch (err) {
            console.error('renderHRDashboard failed', err);
            root.innerHTML = '<div class="emp-wrap"><div class="emp-empty">Failed to load HR dashboard.</div></div>';
        }
    }

    /* ── Leave Management ── */
    var leaveFilterStatus = '';
    var teamLeaveFilter = '';
    var leaveActiveTab = 'mine';   // active tab for managers; default = My Leaves. Persists across re-renders.

    async function renderLeave() {
        var root = document.getElementById('leaveView');
        if (!root) return;
        root.innerHTML = '<div class="lv-wrap"><div class="kpi-status-msg">Loading leave...</div></div>';

        try {
            var reqRes = await requestJson('/api/leave/requests' + (leaveFilterStatus ? '?status=' + leaveFilterStatus : ''));
            var typRes = await requestJson('/api/leave/types');
            // Team Leave is manager-only: gate on whether the current user actually has
            // direct reports. TEAM_MEMBERS = active users whose reporting_manager_id is this
            // user (DashboardController). Non-managers (e.g. interns) never see the tab, and
            // we skip the team-requests fetch entirely for them.
            var teamMembers = (window.__PORTAL_CONFIG && window.__PORTAL_CONFIG.TEAM_MEMBERS) || [];
            var hasTeam = teamMembers.length > 0;
            var teamRes = null;
            if (hasTeam) {
                try { teamRes = await requestJson('/api/leave/team-requests' + (teamLeaveFilter ? '?status=' + teamLeaveFilter : '')); } catch(e) {}
            }

            var requests = reqRes.leave_requests || [];
            var leaveTypes = typRes.leave_types || [];
            var teamRequests = teamRes ? (teamRes.team_requests || []) : [];

            var statusColors = { pending: '#f59e0b', approved: '#22c55e', rejected: '#ef4444', cancelled: '#6b7280' };

            // Policy note + leave type cards
            var autoTypes = leaveTypes.filter(function(t) { return !t.requires_approval; }).map(function(t) { return t.name.replace(' Leave', ''); });
            var approvalTypes = leaveTypes.filter(function(t) { return t.requires_approval; }).map(function(t) { return t.name.replace(' Leave', ''); });
            var balHtml = '<div class="lv-policy-note" style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#0369a1;">' +
                (autoTypes.length ? '<strong>Auto-approved:</strong> ' + autoTypes.join(', ') + ' &nbsp;|&nbsp; ' : '') +
                (approvalTypes.length ? '<strong>Manager approval:</strong> ' + approvalTypes.join(', ') + ' &nbsp;|&nbsp; ' : '') +
                'No pay cuts. Be responsible for your work.' +
                '</div>' +
                '<div class="lv-balance-grid">' + leaveTypes.map(function(t) {
                var approvalTag = t.requires_approval
                    ? '<span style="font-size:11px;color:#f59e0b;">Manager approval</span>'
                    : '<span style="font-size:11px;color:#22c55e;">Auto-approved</span>';
                return '<div class="lv-bal-card">' +
                    '<div class="lv-bal-type">' + escapeHtml(t.name) + '</div>' +
                    approvalTag +
                    '</div>';
            }).join('') + '</div>';

            // My Leaves status filter (lives inside the My Leaves panel; Apply button moved to shared header)
            var myFilterHtml = '<div class="lv-actions">' +
                '<select class="tkt-filter-select" id="lvStatusFilter">' +
                '<option value="">All Requests</option>' +
                '<option value="pending"' + (leaveFilterStatus === 'pending' ? ' selected' : '') + '>Pending</option>' +
                '<option value="approved"' + (leaveFilterStatus === 'approved' ? ' selected' : '') + '>Approved</option>' +
                '<option value="rejected"' + (leaveFilterStatus === 'rejected' ? ' selected' : '') + '>Rejected</option>' +
                '<option value="cancelled"' + (leaveFilterStatus === 'cancelled' ? ' selected' : '') + '>Cancelled</option>' +
                '</select></div>';

            // My requests list
            var listHtml = '';
            if (requests.length === 0) {
                listHtml = '<div class="lv-empty">No leave requests found.</div>';
            } else {
                listHtml = '<div class="lv-list">' + requests.map(function(r) {
                    var sc = statusColors[r.status] || '#6b7280';
                    var canCancel = r.status === 'pending';
                    // Server gates this: approved, not already requested, and the
                    // leave's last day hasn't passed (end_date >= today IST).
                    var canRequestCancel = !!r.can_request_cancellation;
                    var cancelPending = r.status === 'approved' && r.cancellation_requested_at;
                    var myActions = canCancel
                        ? '<div class="lv-card-actions"><button class="btn btn-outline-danger btn-sm lv-cancel-btn" data-id="' + r.id + '">Cancel</button></div>'
                        : canRequestCancel
                            ? '<div class="lv-card-actions"><button class="btn btn-outline-danger btn-sm lv-req-cancel-btn" data-id="' + r.id + '">Request Cancellation</button></div>'
                            : cancelPending
                                ? '<p class="lv-card-note" style="color:#f59e0b;">Cancellation requested — awaiting manager approval.</p>'
                                : '';
                    var isComp = r.leave_type && r.leave_type.slug === 'compensate';
                    var durationStr = isComp
                        ? 'Off ' + r.start_date + ' · Working ' + (r.compensation_date || '—')
                        : r.hours
                            ? r.start_date + ' · ' + (r.from_time || '') + ' – ' + (r.to_time || '') + ' (' + r.hours + 'h)'
                            : r.start_date + ' to ' + r.end_date + ' (' + r.total_days + 'd)';
                    return '<div class="card lv-card">' +
                        '<div class="lv-card-header">' +
                        '<div><span class="lv-card-type">' + escapeHtml(r.leave_type ? r.leave_type.name : '') + '</span>' +
                        '<span class="lv-card-dates">' + durationStr + '</span></div>' +
                        '<span class="tkt-badge" style="background:' + sc + '">' + escapeHtml(r.status) + '</span>' +
                        '</div>' +
                        (r.reason ? '<p class="lv-card-reason">' + escapeHtml(r.reason) + '</p>' : '') +
                        (r.reviewer_note ? '<p class="lv-card-note">Note: ' + escapeHtml(r.reviewer_note) + '</p>' : '') +
                        (r.reviewer ? '<p class="lv-card-reviewer">Reviewed by ' + escapeHtml(r.reviewer.name) + '</p>' : '') +
                        myActions +
                        '</div>';
                }).join('') + '</div>';
            }

            // Team Leave section (for managers) — rendered inside the Team Leave tab panel
            var teamHtml = '';
            var pendingCount = 0;
            if (hasTeam) {
                pendingCount = teamRequests.filter(function(r) { return r.status === 'pending' || (r.status === 'approved' && r.cancellation_requested_at); }).length;
                var teamFilterHtml = '<div class="lv-actions">' +
                    '<select class="tkt-filter-select" id="lvTeamFilter">' +
                    '<option value="">All</option>' +
                    '<option value="pending"' + (teamLeaveFilter === 'pending' ? ' selected' : '') + '>Pending' + (pendingCount && !teamLeaveFilter ? ' (' + pendingCount + ')' : '') + '</option>' +
                    '<option value="approved"' + (teamLeaveFilter === 'approved' ? ' selected' : '') + '>Approved</option>' +
                    '<option value="rejected"' + (teamLeaveFilter === 'rejected' ? ' selected' : '') + '>Rejected</option>' +
                    '</select></div>';

                var teamListHtml = '';
                if (teamRequests.length === 0) {
                    teamListHtml = '<div class="lv-empty">No team leave requests.</div>';
                } else {
                    // Action-needed first (pending reviews + cancellation requests),
                    // so they're visible at the top under the default "All" filter.
                    var teamSorted = teamRequests.slice().sort(function(a, b) {
                        var an = (a.status === 'pending' || (a.status === 'approved' && a.cancellation_requested_at)) ? 0 : 1;
                        var bn = (b.status === 'pending' || (b.status === 'approved' && b.cancellation_requested_at)) ? 0 : 1;
                        return an - bn;
                    });
                    teamListHtml = '<div class="lv-list">' + teamSorted.map(function(r) {
                        var sc = statusColors[r.status] || '#6b7280';
                        var isPending = r.status === 'pending';
                        var cancelReq = r.status === 'approved' && r.cancellation_requested_at;
                        var isCompT = r.leave_type && r.leave_type.slug === 'compensate';
                        var teamDurationStr = isCompT
                            ? 'Off ' + r.start_date + ' · Working ' + (r.compensation_date || '—')
                            : r.hours
                                ? r.start_date + ' · ' + (r.from_time || '') + ' – ' + (r.to_time || '') + ' (' + r.hours + 'h)'
                                : r.start_date + ' to ' + r.end_date + ' (' + r.total_days + 'd)';
                        var teamActions = isPending
                            ? '<div class="lv-card-actions">' +
                                '<button class="btn btn-success btn-sm lv-approve-btn" data-id="' + r.id + '">Approve</button>' +
                                '<button class="btn btn-outline-danger btn-sm lv-reject-btn" data-id="' + r.id + '">Reject</button>' +
                                '</div>'
                            : cancelReq
                                ? '<div class="lv-card-actions">' +
                                    '<button class="btn btn-success btn-sm lv-approve-cancel-btn" data-id="' + r.id + '">Approve Cancellation</button>' +
                                    '<button class="btn btn-outline-danger btn-sm lv-reject-cancel-btn" data-id="' + r.id + '">Reject Cancellation</button>' +
                                    '</div>'
                                : '';
                        return '<div class="card lv-card lv-card-team' + ((isPending || cancelReq) ? ' lv-card-pending' : '') + '">' +
                            '<div class="lv-card-header">' +
                            '<div><strong>' + escapeHtml(r.user ? r.user.name : '') + '</strong> &mdash; ' +
                            '<span class="lv-card-type">' + escapeHtml(r.leave_type ? r.leave_type.name : '') + '</span>' +
                            '<span class="lv-card-dates">' + teamDurationStr + '</span></div>' +
                            '<span class="tkt-badge" style="background:' + sc + '">' + escapeHtml(r.status) + '</span>' +
                            '</div>' +
                            (cancelReq ? '<p class="lv-card-note" style="color:#ef4444;font-weight:600;">⚠ Cancellation requested' + (r.cancellation_reason ? ': ' + escapeHtml(r.cancellation_reason) : '') + '</p>' : '') +
                            (r.reason ? '<p class="lv-card-reason">' + escapeHtml(r.reason) + '</p>' : '') +
                            (r.reviewer_note ? '<p class="lv-card-note">Note: ' + escapeHtml(r.reviewer_note) + '</p>' : '') +
                            (r.reviewer ? '<p class="lv-card-reviewer">Reviewed by ' + escapeHtml(r.reviewer.name) + '</p>' : '') +
                            teamActions +
                            '</div>';
                    }).join('') + '</div>';
                }

                teamHtml = teamFilterHtml + teamListHtml;
            }

            // Shared header (title + Apply button, always visible regardless of tab)
            var headerHtml = '<div class="lv-header-row">' +
                '<h2 class="tkt-title">Leave Management</h2>' +
                '<button type="button" class="btn btn-primary lv-apply-btn" id="lvApplyBtn">+ Apply Leave</button>' +
                '</div>';

            // My Leaves panel content: policy note + leave-type cards + status filter + my list
            var myPanelHtml = balHtml + myFilterHtml + listHtml;

            if (hasTeam) {
                var activeTab = (leaveActiveTab === 'mine') ? 'mine' : 'team';
                var tabsHtml = '<div class="lv-tabs">' +
                    '<button type="button" class="lv-tab' + (activeTab === 'mine' ? ' active' : '') + '" data-lvtab="mine">My Leaves</button>' +
                    '<button type="button" class="lv-tab' + (activeTab === 'team' ? ' active' : '') + '" data-lvtab="team">Team Leave' +
                        (pendingCount > 0 ? ' <span class="lv-tab-badge">' + pendingCount + '</span>' : '') + '</button>' +
                    '</div>';
                root.innerHTML = '<div class="lv-wrap">' + headerHtml + tabsHtml +
                    '<div class="lv-panel' + (activeTab === 'mine' ? ' lv-panel-active' : '') + '" id="lvPanelMine">' + myPanelHtml + '</div>' +
                    '<div class="lv-panel' + (activeTab === 'team' ? ' lv-panel-active' : '') + '" id="lvPanelTeam">' + teamHtml + '</div>' +
                    '</div>';
            } else {
                root.innerHTML = '<div class="lv-wrap">' + headerHtml +
                    '<div class="lv-panel lv-panel-active" id="lvPanelMine">' + myPanelHtml + '</div>' +
                    '</div>';
            }

            // Event handlers
            document.getElementById('lvStatusFilter').onchange = function() {
                leaveFilterStatus = this.value;
                renderLeave();
            };

            var teamFilter = document.getElementById('lvTeamFilter');
            if (teamFilter) teamFilter.onchange = function() {
                teamLeaveFilter = this.value;
                renderLeave();
            };

            var applyBtn = document.getElementById('lvApplyBtn');
            if (applyBtn) applyBtn.onclick = function() { showApplyLeaveModal(leaveTypes); };

            // Tab switching — pure show/hide, both panels already in the DOM (no refetch)
            root.querySelectorAll('.lv-tab').forEach(function(btn) {
                btn.onclick = function() {
                    leaveActiveTab = btn.getAttribute('data-lvtab');
                    root.querySelectorAll('.lv-tab').forEach(function(b) { b.classList.toggle('active', b === btn); });
                    var t = document.getElementById('lvPanelTeam');
                    var m = document.getElementById('lvPanelMine');
                    if (t) t.classList.toggle('lv-panel-active', leaveActiveTab === 'team');
                    if (m) m.classList.toggle('lv-panel-active', leaveActiveTab === 'mine');
                };
            });

            root.querySelectorAll('.lv-cancel-btn').forEach(function(btn) {
                btn.onclick = async function() {
                    if (!confirm('Cancel this leave request?')) return;
                    btn.disabled = true; btn.textContent = 'Cancelling...';
                    try {
                        await requestJson('/api/leave/requests/' + btn.getAttribute('data-id') + '/cancel', { method: 'POST' });
                        renderLeave();
                    } catch(e) { btn.disabled = false; btn.textContent = 'Cancel'; }
                };
            });

            root.querySelectorAll('.lv-approve-btn').forEach(function(btn) {
                btn.onclick = async function() {
                    btn.disabled = true; btn.textContent = 'Approving...';
                    try {
                        await requestJson('/api/leave/requests/' + btn.getAttribute('data-id') + '/review', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'approve' })
                        });
                        renderLeave();
                        updateLeaveBadge();
                    } catch(e) { btn.disabled = false; btn.textContent = 'Approve'; }
                };
            });

            root.querySelectorAll('.lv-reject-btn').forEach(function(btn) {
                btn.onclick = async function() {
                    var note = prompt('Reason for rejection (optional):');
                    btn.disabled = true; btn.textContent = 'Rejecting...';
                    try {
                        await requestJson('/api/leave/requests/' + btn.getAttribute('data-id') + '/review', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'reject', note: note || '' })
                        });
                        renderLeave();
                        updateLeaveBadge();
                    } catch(e) { btn.disabled = false; btn.textContent = 'Reject'; }
                };
            });

            // Employee: request cancellation of an approved leave (My Leaves).
            root.querySelectorAll('.lv-req-cancel-btn').forEach(function(btn) {
                btn.onclick = async function() {
                    var reason = prompt('Reason for cancelling this approved leave (optional):');
                    if (reason === null) return;
                    btn.disabled = true; btn.textContent = 'Requesting...';
                    try {
                        await requestJson('/api/leave/requests/' + btn.getAttribute('data-id') + '/request-cancellation', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ reason: reason || '' })
                        });
                        renderLeave();
                    } catch(e) { btn.disabled = false; btn.textContent = 'Request Cancellation'; }
                };
            });

            // Manager: approve a cancellation request -> the leave is cancelled.
            root.querySelectorAll('.lv-approve-cancel-btn').forEach(function(btn) {
                btn.onclick = async function() {
                    if (!confirm('Approve cancellation? This leave will be cancelled.')) return;
                    btn.disabled = true; btn.textContent = 'Cancelling...';
                    try {
                        await requestJson('/api/leave/requests/' + btn.getAttribute('data-id') + '/review-cancellation', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'approve' })
                        });
                        renderLeave();
                        updateLeaveBadge();
                    } catch(e) { btn.disabled = false; btn.textContent = 'Approve Cancellation'; }
                };
            });

            // Manager: reject a cancellation request -> the leave stands.
            root.querySelectorAll('.lv-reject-cancel-btn').forEach(function(btn) {
                btn.onclick = async function() {
                    var note = prompt('Reason for declining the cancellation (optional):');
                    if (note === null) return;
                    btn.disabled = true; btn.textContent = 'Declining...';
                    try {
                        await requestJson('/api/leave/requests/' + btn.getAttribute('data-id') + '/review-cancellation', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'reject', note: note || '' })
                        });
                        renderLeave();
                        updateLeaveBadge();
                    } catch(e) { btn.disabled = false; btn.textContent = 'Reject Cancellation'; }
                };
            });

        } catch(err) {
            console.error('Leave load failed', err);
            root.innerHTML = '<div class="lv-wrap"><div class="lv-header"><h2 class="tkt-title">Leave Management</h2></div><div class="lv-empty">Unable to load leave data.</div></div>';
        }
    }

    function updateLeaveBadge() {
        var badge = document.querySelector('.top-nav-link[data-view="leave"] .side-nav-badge');
        requestJson('/api/leave/team-pending').then(function(res) {
            var count = (res.pending_requests || []).length;
            if (badge && count === 0) badge.remove();
            else if (!badge && count > 0) {
                var link = document.querySelector('.top-nav-link[data-view="leave"]');
                if (link) {
                    var dot = document.createElement('span');
                    dot.className = 'side-nav-badge';
                    dot.title = count + ' pending approval';
                    link.appendChild(dot);
                }
            }
        }).catch(function() {});
    }

    function showApplyLeaveModal(leaveTypes) {
        var overlay = document.createElement('div');
        overlay.className = 'inv-modal-overlay';

        var autoSlugs = {};
        var hourlySlugs = {};
        leaveTypes.forEach(function(t) {
            if (!t.requires_approval) autoSlugs[t.slug] = true;
            if (t.is_hourly) hourlySlugs[t.slug] = true;
        });

        var typesOpts = leaveTypes.map(function(t) {
            var tag = t.requires_approval ? '' : ' (auto-approved)';
            return '<option value="' + escapeHtml(t.slug) + '" data-hourly="' + (t.is_hourly ? '1' : '0') + '">' + escapeHtml(t.name) + tag + '</option>';
        }).join('');

        overlay.innerHTML = '<div class="inv-modal" style="max-width:420px">' +
            '<div class="inv-modal-header"><h3>Apply for Leave</h3><button type="button" class="inv-modal-close" id="lvCloseModal">&times;</button></div>' +
            '<form id="lvApplyForm">' +
            '<div class="inv-modal-body">' +
                '<div class="inv-field"><label>Leave Type</label>' +
                '<select id="lvType" class="input lv-modal-select" required>' + typesOpts + '</select>' +
                '<p id="lvTypeHint" class="lv-modal-hint"></p></div>' +
                '<div id="lvDateFields" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">' +
                '<div class="inv-field"><label>Start Date</label><input type="date" id="lvStart" class="input lv-modal-input" required></div>' +
                '<div class="inv-field"><label>End Date</label><input type="date" id="lvEnd" class="input lv-modal-input" required></div>' +
                '</div>' +
                '<div id="lvHoursFields" style="display:none;">' +
                '<div class="inv-field"><label>Date</label><input type="date" id="lvPermDate" class="input lv-modal-input"></div>' +
                '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:8px;">' +
                '<div class="inv-field"><label>From</label><input type="time" id="lvFromTime" class="input lv-modal-input" value="10:00"></div>' +
                '<div class="inv-field"><label>To</label><input type="time" id="lvToTime" class="input lv-modal-input" value="12:00"></div>' +
                '</div>' +
                '<p id="lvHoursCalc" class="lv-modal-hint" style="margin-top:4px;color:#a1a1aa;"></p></div>' +
                '<div id="lvCompFields" style="display:none;">' +
                '<div class="inv-field"><label>Working day you want off <span style="color:#52525b;font-weight:400">(Mon–Fri)</span></label><input type="date" id="lvCompOff" class="input lv-modal-input"></div>' +
                '<div class="inv-field" style="margin-top:8px"><label>Weekend day you will work <span style="color:#52525b;font-weight:400">(Sat / Sun)</span></label><input type="date" id="lvCompWork" class="input lv-modal-input"></div>' +
                '<p id="lvCompHint" class="lv-modal-hint" style="margin-top:4px;"></p></div>' +
                '<div class="inv-field"><label>Reason <span style="color:#52525b;font-weight:400">(optional)</span></label>' +
                '<textarea id="lvReason" class="input lv-modal-textarea" rows="3" placeholder="Why do you need leave?"></textarea></div>' +
                '<p id="lvFormStatus" class="lv-modal-status"></p>' +
            '</div>' +
            '<div class="inv-modal-footer">' +
            '<button type="button" class="btn btn-outline" id="lvCancelModal">Cancel</button>' +
            '<button type="submit" class="btn btn-primary btn-lg" id="lvSubmitBtn">Apply Leave</button>' +
            '</div></form></div>';

        document.body.appendChild(overlay);

        var today = new Date().toISOString().split('T')[0];
        document.getElementById('lvStart').value = today;
        document.getElementById('lvEnd').value = today;
        document.getElementById('lvPermDate').value = today;
        document.getElementById('lvCompOff').value = today;
        document.getElementById('lvCompWork').value = today;
        // Compensate dates must be in the future / today — block past picks
        // in the picker itself so the user gets immediate feedback.
        document.getElementById('lvCompOff').min = today;
        document.getElementById('lvCompWork').min = today;

        function isCompensateSlug(slug) { return slug === 'compensate'; }
        function isWeekendIso(iso) {
            if (!iso) return false;
            // new Date('YYYY-MM-DD') parses as UTC midnight; getUTCDay avoids
            // off-by-one in IST without needing the time component.
            var d = new Date(iso + 'T00:00:00');
            var day = d.getDay();
            return day === 0 || day === 6;
        }
        function updateCompHint() {
            var hint = document.getElementById('lvCompHint');
            var offV = document.getElementById('lvCompOff').value;
            var workV = document.getElementById('lvCompWork').value;
            var msg = '';
            var ok = true;
            if (offV && isWeekendIso(offV)) { msg = 'Day off must be a weekday (Mon–Fri).'; ok = false; }
            else if (workV && !isWeekendIso(workV)) { msg = 'Compensation day must be a weekend (Sat or Sun).'; ok = false; }
            else if (offV && workV) { msg = 'You will be off on ' + offV + ' and work instead on ' + workV + '.'; ok = true; }
            hint.textContent = msg;
            hint.style.color = ok ? '#a1a1aa' : '#ef4444';
        }
        document.getElementById('lvCompOff').onchange = updateCompHint;
        document.getElementById('lvCompWork').onchange = updateCompHint;

        function updateHint() {
            var hint = document.getElementById('lvTypeHint');
            var sel = document.getElementById('lvType').value;
            var isHourly = hourlySlugs[sel];
            var isComp = isCompensateSlug(sel);
            if (isComp) {
                hint.textContent = 'Manager approval required. Counts as a working day, not a leave.';
                hint.style.color = '#0ea5e9';
            } else if (autoSlugs[sel]) {
                hint.textContent = 'This leave will be auto-approved instantly.';
                hint.style.color = '#22c55e';
            } else {
                hint.textContent = 'This leave requires manager approval.';
                hint.style.color = '#f59e0b';
            }
            document.getElementById('lvDateFields').style.display = (isHourly || isComp) ? 'none' : 'grid';
            document.getElementById('lvHoursFields').style.display = isHourly ? 'block' : 'none';
            document.getElementById('lvCompFields').style.display = isComp ? 'block' : 'none';
            document.getElementById('lvStart').required = !isHourly && !isComp;
            document.getElementById('lvEnd').required = !isHourly && !isComp;
            document.getElementById('lvPermDate').required = isHourly;
            document.getElementById('lvFromTime').required = isHourly;
            document.getElementById('lvToTime').required = isHourly;
            document.getElementById('lvCompOff').required = isComp;
            document.getElementById('lvCompWork').required = isComp;
            if (isHourly) updateHoursCalc();
            if (isComp) updateCompHint();
        }
        function updateHoursCalc() {
            var calc = document.getElementById('lvHoursCalc');
            var from = document.getElementById('lvFromTime').value;
            var to = document.getElementById('lvToTime').value;
            if (!from || !to) { calc.textContent = ''; return; }
            var fp = from.split(':'), tp = to.split(':');
            var diff = (parseInt(tp[0]) * 60 + parseInt(tp[1])) - (parseInt(fp[0]) * 60 + parseInt(fp[1]));
            if (diff <= 0) { calc.textContent = 'To time must be after From time'; calc.style.color = '#ef4444'; return; }
            var hrs = (diff / 60).toFixed(1).replace(/\.0$/, '');
            calc.textContent = hrs + ' hour' + (hrs === '1' ? '' : 's');
            calc.style.color = '#a1a1aa';
        }
        document.getElementById('lvFromTime').onchange = updateHoursCalc;
        document.getElementById('lvToTime').onchange = updateHoursCalc;
        document.getElementById('lvType').onchange = updateHint;
        updateHint();

        function close() { overlay.remove(); }
        overlay.addEventListener('click', function(e) { if (e.target === overlay) close(); });
        document.getElementById('lvCloseModal').onclick = close;
        document.getElementById('lvCancelModal').onclick = close;

        document.getElementById('lvApplyForm').onsubmit = async function(e) {
            e.preventDefault();
            var btn = document.getElementById('lvSubmitBtn');
            var status = document.getElementById('lvFormStatus');
            btn.disabled = true; btn.textContent = 'Applying...';
            status.textContent = '';

            try {
                var selectedType = document.getElementById('lvType').value;
                var isHourly = hourlySlugs[selectedType];
                var isComp = isCompensateSlug(selectedType);
                var payload = {
                    leave_type: selectedType,
                    reason: document.getElementById('lvReason').value || null
                };
                if (isComp) {
                    var offV = document.getElementById('lvCompOff').value;
                    var workV = document.getElementById('lvCompWork').value;
                    if (!offV || !workV) throw new Error('Pick both the day off and the compensation day.');
                    if (isWeekendIso(offV)) throw new Error('Day off must be a weekday (Mon–Fri).');
                    if (!isWeekendIso(workV)) throw new Error('Compensation day must be a weekend (Sat or Sun).');
                    payload.start_date = offV;
                    payload.end_date = offV;
                    payload.compensation_date = workV;
                } else if (isHourly) {
                    payload.start_date = document.getElementById('lvPermDate').value;
                    payload.from_time = document.getElementById('lvFromTime').value;
                    payload.to_time = document.getElementById('lvToTime').value;
                } else {
                    payload.start_date = document.getElementById('lvStart').value;
                    payload.end_date = document.getElementById('lvEnd').value;
                }
                var res = await requestJson('/api/leave/requests', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                status.style.color = '#22c55e';
                status.textContent = res.message || 'Leave applied!';
                leaveActiveTab = 'mine';   // land on My Leaves so the new request is visible
                setTimeout(function() { close(); renderLeave(); }, 1200);
            } catch(err) {
                status.style.color = '#ef4444';
                status.textContent = err.message || 'Failed to apply leave.';
                btn.disabled = false; btn.textContent = 'Apply Leave';
            }
        };
    }

    /* ── Export as window.HRModule ── */
    window.HRModule = {
        renderEmployees: renderEmployees,
        renderHrRecordsDocs: renderHrRecordsDocs,
        renderDriveBrowser: renderDriveBrowser,
        renderProfile: renderProfile,
        renderLeave: renderLeave,
        renderHRDashboard: renderHRDashboard,
        navigateToEditMember: navigateToEditMember,
        composeAddMemberFor: composeAddMemberFor,
        showApplyLeaveModal: showApplyLeaveModal
    };
})();
