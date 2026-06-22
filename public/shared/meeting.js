(function () {
    'use strict';

    var config = {};
    var meetings = [];
    var currentWeekStart = startOfWeek(new Date());
    var selectedMeeting = null;
    // Tracks the minutes content currently persisted on the server, so the
    // "Save Minutes" button can reflect whether there are unsaved edits.
    var savedNotesBaseline = '';
    // Meetings whose agenda we've already auto-extracted this page session, so
    // opening/switching back doesn't re-fire the AI fill.
    var autoFilledMeetings = {};
    // Keep the Save button in sync with the textarea's dirty state. When the
    // text matches what's saved, show a disabled "✓ Saved" — a persistent,
    // unmistakable confirmation instead of only a 2-second toast (which left
    // people unsure the save stuck, since the button never changed).
    function syncSaveNotesBtn() {
        var btn = document.getElementById('saveNotesBtn');
        var notesEl = document.getElementById('meetingNotes');
        if (!btn || !notesEl) return;
        if (notesEl.value !== savedNotesBaseline) {
            btn.disabled = false;
            btn.classList.remove('is-saved');
            btn.textContent = 'Save Minutes';
        } else if (savedNotesBaseline.trim()) {
            btn.disabled = true;
            btn.classList.add('is-saved');
            btn.textContent = '✓ Saved';
        } else {
            btn.disabled = true;
            btn.classList.remove('is-saved');
            btn.textContent = 'Save Minutes';
        }
    }

    var KPI_GROUPS = [];

    var MODAL_PEOPLE = [];

    /* ── date helpers ── */

    function startOfWeek(date) {
        var d = new Date(date);
        var day = d.getDay();
        var diff = day === 0 ? -6 : 1 - day;
        d.setDate(d.getDate() + diff);
        d.setHours(0, 0, 0, 0);
        return d;
    }

    function addDays(date, days) {
        var d = new Date(date);
        d.setDate(d.getDate() + days);
        return d;
    }

    function localDateStr(date) {
        var y = date.getFullYear();
        var m = date.getMonth() + 1;
        var d = date.getDate();
        return y + '-' + (m < 10 ? '0' : '') + m + '-' + (d < 10 ? '0' : '') + d;
    }
    function weekKey(date) { return localDateStr(date); }
    function dateKey(date) { return localDateStr(date); }

    function formatDate(date) {
        return date.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short', year: 'numeric' });
    }

    function escapeHtml(value) {
        return String(value || '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#39;');
    }

    /* ── API helper ── */

    async function requestJson(url, options) {
        var requestOptions = Object.assign({ credentials: 'same-origin' }, options || {});
        requestOptions.headers = Object.assign({
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }, requestOptions.headers || {});
        var res = await fetch(url, requestOptions);
        var data = await res.json().catch(function () { return {}; });
        if (!res.ok || (data && data.error)) {
            console.error('API request failed', url, res.status, data);
            var err = new Error(data.error || 'Request failed');
            err.status = res.status;
            err.data = data; // full response body (e.g. pending sign-off items) for callers
            throw err;
        }
        return data;
    }

    /* ── storage / meeting helpers ── */

    function portalPrefix() { return config.portal || 'ops'; }

    var MULTI_DAY_RECURRENCES = {
        daily_weekdays: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
        tue_to_fri:     ['Tuesday', 'Wednesday', 'Thursday', 'Friday'],
        mon_thu:        ['Monday', 'Thursday'],
        mon_wed_fri:    ['Monday', 'Wednesday', 'Friday']
    };

    function recurrenceLabel(value) {
        if (value === 'daily_weekdays') return 'Daily (Mon-Fri)';
        if (value === 'tue_to_fri') return 'Daily (Tue-Fri)';
        if (value === 'mon_thu') return 'Mon & Thu';
        if (value === 'mon_wed_fri') return 'Mon, Wed & Fri';
        if (value === 'weekly') return 'Recurring Weekly';
        if (value === 'monthly_first') return 'Monthly (1st weekday)';
        return 'One-time';
    }

    function isMultiDayRecurrence(value) {
        return Object.prototype.hasOwnProperty.call(MULTI_DAY_RECURRENCES, value);
    }

    function detailDayLabel(meeting) {
        if (!meeting) return '';
        if (meeting.recurrence === 'tue_to_fri') return 'Tue - Fri';
        if (meeting.recurrence === 'mon_thu') return 'Mon & Thu';
        if (meeting.recurrence === 'mon_wed_fri') return 'Mon, Wed & Fri';
        if (meeting.recurrence === 'monthly_first') return '1st ' + (meeting.day || '') + ' / month';
        return meeting.day || '';
    }

    function expandMeetings(items) {
        var list = [];
        items.forEach(function (item) {
            var base = {
                dbId: Number(item.id),
                meetingKey: String(item.meetingKey || ''),
                title: String(item.title || ''),
                owner: String(item.owner || ''),
                ownerId: item.ownerId != null ? Number(item.ownerId) : null,
                time: String(item.time || ''),
                recurrence: String(item.recurrence || 'none'),
                recurringLabel: recurrenceLabel(String(item.recurrence || 'none')),
                // YYYY-MM-DD for one-time meetings tied to a specific date; '' otherwise.
                // Used by renderMeetingList to hide the meeting on weeks that don't contain it.
                meetingDate: String(item.meetingDate || ''),
                attendees: Array.isArray(item.attendees) ? item.attendees : [],
                attendeeIds: Array.isArray(item.attendeeIds) ? item.attendeeIds.map(Number) : [],
                agendaTemplateId: item.agendaTemplateId || null,
                isGuest: Boolean(item.isGuest),
                canEdit: item.canEdit !== undefined ? Boolean(item.canEdit) : !Boolean(item.isGuest),
                portal: String(item.portal || ''),
                skipDates: Array.isArray(item.skipDates) ? item.skipDates : []
            };
            if (isMultiDayRecurrence(base.recurrence)) {
                var days = MULTI_DAY_RECURRENCES[base.recurrence];
                // For backwards-compat the very first daily_weekdays instance uses the bare meetingKey (no day suffix).
                var bareFirstDay = base.recurrence === 'daily_weekdays';
                days.forEach(function (day, idx) {
                    var suffix = (bareFirstDay && idx === 0) ? '' : '-' + day.slice(0, 3).toLowerCase();
                    list.push(Object.assign({}, base, {
                        id: base.meetingKey + suffix,
                        day: day
                    }));
                });
                return;
            }
            list.push(Object.assign({}, base, {
                id: base.meetingKey,
                day: String(item.dayOfWeek || 'Monday')
            }));
        });
        return list;
    }

    async function loadMeetings() {
        var data = await requestJson('/api/meetings?portal=' + encodeURIComponent(portalPrefix()));
        meetings = expandMeetings(data.items || []);
        if (!meetings.length) {
            selectedMeeting = null;
            return;
        }
        if (!selectedMeeting) {
            var todayName = new Date().toLocaleDateString('en-US', { timeZone: 'Asia/Kolkata', weekday: 'long' });
            var todayDaily = meetings.find(function (m) { return isMultiDayRecurrence(m.recurrence) && m.day === todayName; });
            selectedMeeting = todayDaily || meetings[0];
            return;
        }
        selectedMeeting = meetings.find(function (m) { return m.id === selectedMeeting.id; }) || meetings[0];
    }

    /* ── modal (add/edit meeting) ── */

    function timeOptions(selected) {
        var options = [];
        for (var h = 8; h <= 20; h++) {
            ['00', '30'].forEach(function (mins) {
                var suffix = h >= 12 ? 'PM' : 'AM';
                var hour12 = h % 12 === 0 ? 12 : h % 12;
                var value = String(hour12).padStart(2, '0') + ':' + mins + ' ' + suffix;
                options.push(value);
            });
        }
        if (selected && !options.includes(selected)) {
            options.unshift(selected);
        }
        return options.map(function (value) {
            return '<option value="' + escapeHtml(value) + '"' + (value === selected ? ' selected' : '') + '>' + escapeHtml(value) + '</option>';
        }).join('');
    }

    var agendaTemplatesCache = [];

    function closeTemplatesModal() {
        var el = document.getElementById('templatesModalOverlay');
        if (el) el.remove();
    }

    async function refreshMeetingModalTemplates() {
        var select = document.getElementById('meetingModalTemplate');
        if (!select) return;
        var currentVal = select.value;
        try {
            var templates = await loadAgendaTemplates();
            select.innerHTML = '<option value="">Custom (no template)</option>' + templates.map(function (t) {
                return '<option value="' + t.id + '">' + escapeHtml(t.name) + '</option>';
            }).join('');
            if (currentVal && templates.some(function (t) { return String(t.id) === currentVal; })) {
                select.value = currentVal;
            }
        } catch (err) {
            console.error('refreshMeetingModalTemplates failed', err);
        }
    }

    function buildTemplateSectionsHtml(items) {
        var sections = [];
        var currentSection = null;
        (items || []).forEach(function (it) {
            if (it.pointQuestion === null || it.pointQuestion === '') {
                currentSection = { title: it.sectionTitle, points: [] };
                sections.push(currentSection);
            } else if (currentSection) {
                currentSection.points.push(it.pointQuestion);
            }
        });
        return sections.map(function (s) {
            var pointsHtml = s.points.map(function (p) { return '<li>' + escapeHtml(p) + '</li>'; }).join('');
            return '<div class="mtg-tpl-section"><strong>' + escapeHtml(s.title) + '</strong><ul>' + pointsHtml + '</ul></div>';
        }).join('');
    }

    async function showTemplatesModal() {
        closeTemplatesModal();
        var templates = await loadAgendaTemplates();
        var overlay = document.createElement('div');
        overlay.id = 'templatesModalOverlay';
        overlay.className = 'mtg-modal-overlay';
        var listHtml = templates.length ? templates.map(function (t) {
            var sectionsHtml = buildTemplateSectionsHtml(t.items);
            return '<div class="mtg-tpl-card" data-id="' + t.id + '"><div class="mtg-tpl-card-head"><strong>' + escapeHtml(t.name) + '</strong><button type="button" class="mtg-tpl-del" data-id="' + t.id + '">&times;</button></div><div class="mtg-tpl-card-body">' + (sectionsHtml || '<em>No sections yet</em>') + '</div><div class="mtg-tpl-card-edit"><input type="text" class="mtg-tpl-add-section" placeholder="Section title..." data-id="' + t.id + '"><button type="button" class="mtg-tpl-add-section-btn" data-id="' + t.id + '">+ Section</button><input type="text" class="mtg-tpl-add-point" placeholder="Discussion point..." data-id="' + t.id + '"><button type="button" class="mtg-tpl-add-point-btn" data-id="' + t.id + '">+ Point</button></div></div>';
        }).join('') : '<p class="mtg-tpl-empty">No templates yet. Create one below.</p>';
        overlay.innerHTML =
            '<div class="mtg-modal mtg-modal-wide">' +
            '<div class="mtg-modal-header"><h3>Manage Agenda Templates</h3><button type="button" class="mtg-modal-close" id="templatesModalClose">&#x2715;</button></div>' +
            '<div class="mtg-modal-body">' +
            '<div class="mtg-tpl-list">' + listHtml + '</div>' +
            '<form id="templatesCreateForm" class="mtg-tpl-create"><input type="text" id="newTemplateName" placeholder="New template name..." required><button type="submit" class="mtg-btn mtg-btn-primary">Create Template</button></form>' +
            '</div>' +
            '<div class="mtg-modal-footer"><button type="button" class="mtg-modal-btn" id="templatesModalDone">Done</button></div>' +
            '</div>';
        document.body.appendChild(overlay);
        overlay.onclick = function (e) { if (e.target === overlay) closeTemplatesModal(); };
        document.getElementById('templatesModalClose').onclick = function () { closeTemplatesModal(); refreshMeetingModalTemplates(); };
        document.getElementById('templatesModalDone').onclick = function () { closeTemplatesModal(); refreshMeetingModalTemplates(); };
        document.getElementById('templatesCreateForm').onsubmit = async function (e) {
            e.preventDefault();
            var input = document.getElementById('newTemplateName');
            var name = String(input && input.value || '').trim();
            if (!name) return;
            try {
                await requestJson('/api/agenda-templates', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'create_template', name: name }) });
                if (input) input.value = '';
                await showTemplatesModal();
            } catch (err) { window.alert(err.message || 'Failed'); }
        };
        overlay.querySelectorAll('.mtg-tpl-del').forEach(function (btn) {
            btn.onclick = async function () {
                if (!confirm('Delete this template?')) return;
                try {
                    await requestJson('/api/agenda-templates', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete_template', id: Number(btn.getAttribute('data-id')) }) });
                    await showTemplatesModal();
                } catch (err) {
                    console.error('Delete template failed', err);
                }
            };
        });
        overlay.querySelectorAll('.mtg-tpl-add-section-btn').forEach(function (btn) {
            btn.onclick = async function () {
                var card = btn.closest('.mtg-tpl-card');
                var input = card && card.querySelector('.mtg-tpl-add-section');
                var title = input && String(input.value || '').trim();
                if (!title) return;
                try {
                    await requestJson('/api/agenda-templates', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'add_item', templateId: Number(btn.getAttribute('data-id')), sectionTitle: title }) });
                    if (input) input.value = '';
                    await showTemplatesModal();
                } catch (err) { window.alert(err.message || 'Failed'); }
            };
        });
        overlay.querySelectorAll('.mtg-tpl-add-point-btn').forEach(function (btn) {
            btn.onclick = async function () {
                var card = btn.closest('.mtg-tpl-card');
                var input = card && card.querySelector('.mtg-tpl-add-point');
                var question = input && String(input.value || '').trim();
                if (!question) return;
                try {
                    await requestJson('/api/agenda-templates', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'add_item', templateId: Number(btn.getAttribute('data-id')), pointQuestion: question }) });
                    if (input) input.value = '';
                    await showTemplatesModal();
                } catch (err) { window.alert(err.message || 'Failed'); }
            };
        });
    }

    function closeMeetingModal() {
        var modal = document.getElementById('meetingModalOverlay');
        if (modal) modal.remove();
    }

    async function loadAgendaTemplates() {
        try {
            var data = await requestJson('/api/agenda-templates');
            agendaTemplatesCache = data.items || [];
            return agendaTemplatesCache;
        } catch (err) {
            console.error('loadAgendaTemplates failed', err);
            return [];
        }
    }

    async function showMeetingModal(existing) {
        closeMeetingModal();
        var templates = await loadAgendaTemplates();
        var defaultTemplateId = existing && existing.agendaTemplateId ? String(existing.agendaTemplateId) : '';
        var templateOptions = '<option value="">Custom (no template)</option>' + templates.map(function (t) {
            return '<option value="' + t.id + '"' + (String(t.id) === defaultTemplateId ? ' selected' : '') + '>' + escapeHtml(t.name) + '</option>';
        }).join('');
        var modalTitle = existing ? 'Edit Meeting' : 'Add Meeting';
        var defaultTime = existing ? String(existing.time || '10:00 AM') : '10:00 AM';
        var defaultDay = existing ? String(existing.day || 'Monday') : 'Monday';
        var defaultRecurrence = existing ? String(existing.recurrence || 'weekly') : 'weekly';
        var defaultOwnerId = existing ? (existing.ownerId != null ? Number(existing.ownerId) : null) : (config.userId || (MODAL_PEOPLE[0] && MODAL_PEOPLE[0].id ? MODAL_PEOPLE[0].id : null));
        var selectedAttendeeIds = existing ? (existing.attendeeIds || []) : [];
        var ownerOptions = MODAL_PEOPLE.map(function (p) {
            var id = (p && typeof p === 'object' && 'id' in p) ? Number(p.id) : 0;
            var name = (p && typeof p === 'object' && 'name' in p) ? p.name : String(p || '');
            var isSelected = defaultOwnerId != null && id > 0 && id === Number(defaultOwnerId);
            return '<option value="' + id + '"' + (isSelected ? ' selected' : '') + '>' + escapeHtml(name) + '</option>';
        }).join('');
        var chipHtml = MODAL_PEOPLE.map(function (p) {
            var id = (p && typeof p === 'object' && 'id' in p) ? Number(p.id) : 0;
            var name = (p && typeof p === 'object' && 'name' in p) ? p.name : String(p || '');
            var sel = selectedAttendeeIds.some(function (aid) { return Number(aid) === id; });
            return '<button type="button" class="mtg-modal-chip' + (sel ? ' selected' : '') + '" data-id="' + id + '" data-name="' + escapeHtml(name) + '">' + escapeHtml(name) + '</button>';
        }).join('');
        var overlay = document.createElement('div');
        overlay.id = 'meetingModalOverlay';
        overlay.className = 'mtg-modal-overlay';
        overlay.innerHTML =
            '<div class="mtg-modal">' +
            '<div class="mtg-modal-header">' +
            '<h3 class="mtg-modal-title">' + escapeHtml(modalTitle) + '</h3>' +
            '<button type="button" class="mtg-modal-close" id="meetingModalClose">&#x2715;</button>' +
            '</div>' +
            '<form id="meetingModalForm">' +
            '<div class="mtg-modal-body">' +
            '<div class="mtg-modal-field mtg-modal-field-full"><label for="meetingModalTitle">Meeting Title</label><input id="meetingModalTitle" type="text" required placeholder="e.g. Weekly Sync" value="' + escapeHtml(existing ? existing.title : '') + '"></div>' +
            '<div class="mtg-modal-field"><label for="meetingModalOwner">Owner</label><select id="meetingModalOwner" required><option value="">Select owner...</option>' + ownerOptions + '</select></div>' +
            '<div class="mtg-modal-field"><label for="meetingModalRecurrence">Recurrence</label><select id="meetingModalRecurrence">' +
            '<option value="daily_weekdays"' + (defaultRecurrence === 'daily_weekdays' ? ' selected' : '') + '>Daily (Mon-Fri)</option>' +
            '<option value="tue_to_fri"' + (defaultRecurrence === 'tue_to_fri' ? ' selected' : '') + '>Daily (Tue-Fri)</option>' +
            '<option value="mon_thu"' + (defaultRecurrence === 'mon_thu' ? ' selected' : '') + '>Mon & Thu</option>' +
            '<option value="mon_wed_fri"' + (defaultRecurrence === 'mon_wed_fri' ? ' selected' : '') + '>Mon, Wed & Fri</option>' +
            '<option value="weekly"' + (defaultRecurrence === 'weekly' ? ' selected' : '') + '>Weekly</option>' +
            '<option value="monthly_first"' + (defaultRecurrence === 'monthly_first' ? ' selected' : '') + '>Monthly (1st weekday)</option>' +
            '<option value="none"' + (defaultRecurrence === 'none' ? ' selected' : '') + '>One-time</option>' +
            '</select></div>' +
            '<div class="mtg-modal-field"><label for="meetingModalDay">Day</label><select id="meetingModalDay">' +
            '<option value="Monday"' + (defaultDay === 'Monday' ? ' selected' : '') + '>Monday</option>' +
            '<option value="Tuesday"' + (defaultDay === 'Tuesday' ? ' selected' : '') + '>Tuesday</option>' +
            '<option value="Wednesday"' + (defaultDay === 'Wednesday' ? ' selected' : '') + '>Wednesday</option>' +
            '<option value="Thursday"' + (defaultDay === 'Thursday' ? ' selected' : '') + '>Thursday</option>' +
            '<option value="Friday"' + (defaultDay === 'Friday' ? ' selected' : '') + '>Friday</option>' +
            '</select></div>' +
            '<div class="mtg-modal-field"><label for="meetingModalTime">Time</label><select id="meetingModalTime">' + timeOptions(defaultTime) + '</select></div>' +
            '<div class="mtg-modal-field mtg-modal-field-full"><label for="meetingModalTemplate">Agenda Template</label><div class="mtg-modal-template-row"><select id="meetingModalTemplate">' + templateOptions + '</select></div><span class="mtg-modal-hint">Template auto-fills agenda each day/week</span></div>' +
            '<div class="mtg-modal-field mtg-modal-field-full"><label>Attendees <span class="mtg-modal-hint">— click names to select</span></label><div class="mtg-modal-chips" id="meetingModalAttendeesChips">' + chipHtml + '</div></div>' +
            '</div>' +
            '<div class="mtg-modal-footer">' +
            '<button type="button" class="mtg-modal-btn" id="meetingModalCancel">Cancel</button>' +
            '<button type="submit" class="mtg-modal-btn mtg-modal-btn-primary">' + (existing ? 'Save Changes' : 'Create Meeting') + '</button>' +
            '</div>' +
            '</form>' +
            '</div>';
        document.body.appendChild(overlay);

        var form = document.getElementById('meetingModalForm');
        var closeBtn = document.getElementById('meetingModalClose');
        var cancelBtn = document.getElementById('meetingModalCancel');
        var recurrenceEl = document.getElementById('meetingModalRecurrence');
        var dayEl = document.getElementById('meetingModalDay');
        var chipsContainer = document.getElementById('meetingModalAttendeesChips');

        function syncDayState() {
            if (!recurrenceEl || !dayEl) return;
            // Day picker is only meaningful for single-day (weekly/one-time) meetings.
            dayEl.disabled = isMultiDayRecurrence(recurrenceEl.value);
        }
        syncDayState();
        if (recurrenceEl) recurrenceEl.onchange = syncDayState;
        if (closeBtn) closeBtn.onclick = closeMeetingModal;
        if (cancelBtn) cancelBtn.onclick = closeMeetingModal;
        overlay.onclick = function (e) {
            if (e.target === overlay) closeMeetingModal();
        };
        if (chipsContainer) {
            chipsContainer.querySelectorAll('.mtg-modal-chip').forEach(function (chip) {
                chip.onclick = function () { chip.classList.toggle('selected'); };
            });
        }
        if (form) {
            form.onsubmit = async function (e) {
                e.preventDefault();
                var titleEl = document.getElementById('meetingModalTitle');
                var ownerEl = document.getElementById('meetingModalOwner');
                var timeEl = document.getElementById('meetingModalTime');
                var chips = document.querySelectorAll('#meetingModalAttendeesChips .mtg-modal-chip.selected');
                var templateEl = document.getElementById('meetingModalTemplate');
                var templateVal = templateEl && templateEl.value ? templateEl.value : '';
                var ownerId = Number(ownerEl && ownerEl.value || 0);
                var attendeeIds = Array.from(chips).map(function (c) { return Number(c.getAttribute('data-id') || 0); }).filter(function (id) { return id > 0; });
                var payload = {
                    action: existing ? 'update' : 'add',
                    portal: portalPrefix(),
                    title: String(titleEl && titleEl.value || '').trim(),
                    ownerId: ownerId,
                    dayOfWeek: String(dayEl && dayEl.value || 'Monday').trim(),
                    time: String(timeEl && timeEl.value || '').trim().toUpperCase(),
                    recurrence: String(recurrenceEl && recurrenceEl.value || 'weekly').trim(),
                    attendees: attendeeIds
                };
                if (templateVal) payload.agendaTemplateId = Number(templateVal);
                if (existing) payload.id = existing.dbId;
                try {
                    await requestJson('/api/meetings', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    closeMeetingModal();
                    await loadMeetings();
                    renderMeetingList();
                    renderMeetingDetail();
                } catch (err) {
                    window.alert(err && err.message ? err.message : 'Failed to save meeting');
                }
            };
        }
    }

    async function upsertMeeting(existing) {
        showMeetingModal(existing || null);
    }

    async function deleteMeeting(meeting) {
        if (!meeting) return;
        if (!window.confirm('Delete this meeting and linked agenda?')) return;
        await requestJson('/api/meetings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', id: meeting.dbId })
        });
        await loadMeetings();
        renderMeetingList();
        renderMeetingDetail();
    }

    /* ── render: meeting sidebar list ── */

    function renderMeetingList() {
        var list = document.getElementById('meetingList');
        var addMeetingWrap = document.getElementById('addMeetingWrap');
        if (!list) return;
        if (!meetings.length) {
            list.innerHTML = '<p class="mtg-agenda-empty">No meetings yet.</p>';
            if (addMeetingWrap) {
                addMeetingWrap.innerHTML = '<button class="mtg-btn mtg-btn-primary" id="addMeetingBtn" type="button">Add Meeting</button>';
            }
            var addOnlyBtn = document.getElementById('addMeetingBtn');
            if (addOnlyBtn) addOnlyBtn.onclick = function () { upsertMeeting(null).catch(function (err) { console.error('Upsert meeting failed', err); }); };
            return;
        }

        function listSlotMinutes(str) {
            if (!str) return -1;
            var m = String(str).match(/^(\d+):(\d+)\s*(AM|PM)$/i);
            if (!m) return -1;
            var h = parseInt(m[1], 10), mins = parseInt(m[2], 10);
            var ampm = m[3].toUpperCase();
            if (ampm === 'PM' && h !== 12) h += 12;
            if (ampm === 'AM' && h === 12) h = 0;
            return h * 60 + mins;
        }
        function fmtNowTime(d) {
            var h = d.getHours(), min = d.getMinutes();
            var ampm = h >= 12 ? 'PM' : 'AM';
            var hh = h % 12 || 12;
            return hh + ':' + (min < 10 ? '0' : '') + min + ' ' + ampm;
        }

        var now = new Date();
        var nowMinutes = now.getHours() * 60 + now.getMinutes();
        var todayWeekStart = startOfWeek(now);
        var isThisWeek = currentWeekStart.toDateString() === todayWeekStart.toDateString();
        var todayDayName = now.toLocaleDateString('en-US', { timeZone: 'Asia/Kolkata', weekday: 'long' });

        var dayOrder = { Monday: 1, Tuesday: 2, Wednesday: 3, Thursday: 4, Friday: 5, Saturday: 6, Sunday: 7 };
        var dayShort = { Monday: 'Mon', Tuesday: 'Tue', Wednesday: 'Wed', Thursday: 'Thu', Friday: 'Fri' };
        var dayOffset = { Monday: 0, Tuesday: 1, Wednesday: 2, Thursday: 3, Friday: 4 };

        function dateForDay(dayName) {
            var off = dayOffset[dayName];
            if (off === undefined) return '';
            return localDateStr(addDays(currentWeekStart, off));
        }

        function isSkipped(m) {
            if (!m.skipDates || !m.skipDates.length) return false;
            var d = dateForDay(m.day);
            return d && m.skipDates.indexOf(d) >= 0;
        }

        // A one-time meeting (recurrence='none') with a pinned calendar date should
        // only appear in the sidebar on the week that actually contains that date.
        // Without this filter the row leaks into every week the user browses.
        // One-time meetings without a meetingDate (legacy rows created before the
        // backend started persisting meeting_date) keep the old "show every week"
        // behavior so we don't silently hide existing data.
        function isOneTimeOutsideCurrentWeek(m) {
            if (m.recurrence !== 'none' || !m.meetingDate) return false;
            var weekStartStr = localDateStr(currentWeekStart);
            var weekEndStr = localDateStr(addDays(currentWeekStart, 4));
            return m.meetingDate < weekStartStr || m.meetingDate > weekEndStr;
        }

        var dailyGroupMap = {};
        meetings.forEach(function (m) {
            if (!isMultiDayRecurrence(m.recurrence)) return;
            var groupId = String(m.dbId || m.meetingKey || m.id);
            if (!dailyGroupMap[groupId]) dailyGroupMap[groupId] = [];
            dailyGroupMap[groupId].push(m);
        });
        Object.keys(dailyGroupMap).forEach(function (groupId) {
            dailyGroupMap[groupId].sort(function (a, b) {
                return (dayOrder[a.day] || 999) - (dayOrder[b.day] || 999);
            });
        });
        var dailyGroups = Object.keys(dailyGroupMap).map(function (groupId) {
            return { id: groupId, meetings: dailyGroupMap[groupId] };
        });
        var others = meetings.filter(function (m) {
            return !isMultiDayRecurrence(m.recurrence)
                && !isSkipped(m)
                && !isOneTimeOutsideCurrentWeek(m);
        });

        // Build sortable items: {html, sortDay, sortMinutes, isToday}
        var allItems = [];

        dailyGroups.forEach(function (group) {
            var dailyMeta = group.meetings[0] || null;
            if (!dailyMeta) return;
            var groupActive = !!(selectedMeeting && group.meetings.some(function (m) { return m.id === selectedMeeting.id; }));
            var dailyDayBadges = group.meetings.map(function (m) {
                var skipped = isSkipped(m);
                var isTodayBadge = m.day === todayDayName && !skipped;
                var isSelected = selectedMeeting && selectedMeeting.id === m.id;
                return '<span class="mtg-day-badge' + (skipped ? ' mtg-day-skipped' : '') + (isTodayBadge ? ' mtg-day-today' : '') + (isSelected ? ' mtg-day-active' : '') + '"' + (skipped ? '' : ' data-id="' + escapeHtml(m.id) + '"') + '>' + escapeHtml(dayShort[m.day] || m.day) + '</span>';
            }).join('');
            var dailyActions = dailyMeta.canEdit
                ? '<div class="meeting-actions"><button type="button" class="meeting-action-edit" data-id="' + escapeHtml(dailyMeta.id) + '">Edit</button><button type="button" class="meeting-action-delete" data-id="' + escapeHtml(dailyMeta.id) + '">Delete</button></div>'
                : (dailyMeta.isGuest
                    ? '<div class="mtg-guest-badge">From ' + escapeHtml((dailyMeta.portal || '').toUpperCase()) + '</div>'
                    : '');
            var html = (
                '<article class="mtg-card mtg-card-standup' + (groupActive ? ' active' : '') + '" data-group-id="' + escapeHtml(group.id) + '">' +
                '<div class="mtg-card-title">' + escapeHtml(dailyMeta.title) + '</div>' +
                '<div class="mtg-card-meta">' +
                '<span>' + escapeHtml(dailyMeta.time) + '</span>' +
                '<span class="mtg-card-dot"></span>' +
                '<span>' + escapeHtml(dailyMeta.owner) + ' + Team</span>' +
                '</div>' +
                '<div class="mtg-card-attendees">' + escapeHtml((dailyMeta.attendees || []).join(' · ')) + '</div>' +
                '<div class="mtg-day-badges">' + dailyDayBadges + '</div>' +
                '<div class="mtg-card-status">' + escapeHtml(dailyMeta.recurringLabel || 'Daily (Mon-Fri)') + '</div>' +
                dailyActions +
                '</article>'
            );
            var todayInstance = group.meetings.find(function (m) { return m.day === todayDayName && !isSkipped(m); }) || group.meetings.find(function (m) { return !isSkipped(m); }) || group.meetings[0];
            allItems.push({
                html: html,
                sortDay: dayOrder[todayInstance.day] || 999,
                sortMinutes: listSlotMinutes(todayInstance.time),
                isToday: !!group.meetings.find(function (m) { return m.day === todayDayName && !isSkipped(m); })
            });
        });

        others.forEach(function (m) {
            var actions = m.canEdit
                ? '<div class="meeting-actions"><button type="button" class="meeting-action-edit" data-id="' + escapeHtml(m.id) + '">Edit</button><button type="button" class="meeting-action-delete" data-id="' + escapeHtml(m.id) + '">Delete</button></div>'
                : (m.isGuest ? '<div class="mtg-guest-badge">From ' + escapeHtml((m.portal || '').toUpperCase()) + '</div>' : '');
            var html = (
                '<article class="mtg-card' + (selectedMeeting && selectedMeeting.id === m.id ? ' active' : '') + '" data-id="' + escapeHtml(m.id) + '">' +
                '<div class="mtg-card-title">' + escapeHtml(m.title) + '</div>' +
                '<div class="mtg-card-meta">' +
                '<span class="mtg-day-chip">' + escapeHtml(dayShort[m.day] || m.day) + '</span>' +
                '<span class="mtg-card-dot"></span>' +
                '<span>' + escapeHtml(m.time) + '</span>' +
                '<span class="mtg-card-dot"></span>' +
                '<span>' + escapeHtml(m.owner) + '</span>' +
                '</div>' +
                '<div class="mtg-card-status">' + escapeHtml(m.recurringLabel || 'Recurring Weekly') + '</div>' +
                actions +
                '</article>'
            );
            allItems.push({
                html: html,
                sortDay: dayOrder[m.day] || 999,
                sortMinutes: listSlotMinutes(m.time),
                isToday: m.day === todayDayName
            });
        });

        // Sort all items: by day order first, then by time within the day
        allItems.sort(function (a, b) {
            if (a.sortDay !== b.sortDay) return a.sortDay - b.sortDay;
            return a.sortMinutes - b.sortMinutes;
        });

        var parts = [];
        allItems.forEach(function (item) {
            parts.push(item.html);
        });

        list.innerHTML = parts.join('');
        if (addMeetingWrap) {
            addMeetingWrap.innerHTML = '<button class="mtg-btn mtg-btn-primary" id="addMeetingBtn" type="button">Add Meeting</button>';
        }

        list.querySelectorAll('.mtg-day-badge').forEach(function (badge) {
            badge.onclick = function (e) {
                e.stopPropagation();
                if (badge.classList.contains('mtg-day-skipped')) return;
                selectedMeeting = meetings.find(function (x) { return x.id === badge.getAttribute('data-id'); }) || null;
                renderMeetingList();
                renderMeetingDetail();
                syncHash();
            };
        });
        list.querySelectorAll('.mtg-card-standup').forEach(function (sCard) {
            sCard.onclick = function (e) {
                if (e.target.classList.contains('mtg-day-badge') || e.target.classList.contains('meeting-action-edit') || e.target.classList.contains('meeting-action-delete')) return;
                var groupId = sCard.getAttribute('data-group-id');
                var groupMeetings = dailyGroupMap[groupId] || [];
                var todayM = groupMeetings.find(function (m) { return m.day === todayDayName && !isSkipped(m); }) || groupMeetings.find(function (m) { return !isSkipped(m); }) || groupMeetings[0];
                selectedMeeting = todayM || selectedMeeting;
                renderMeetingList();
                renderMeetingDetail();
                syncHash();
            };
        });
        list.querySelectorAll('.mtg-card:not(.mtg-card-standup)').forEach(function (card) {
            card.onclick = function () {
                selectedMeeting = meetings.find(function (x) { return x.id === card.getAttribute('data-id'); }) || null;
                renderMeetingList();
                renderMeetingDetail();
                syncHash();
            };
        });
        list.querySelectorAll('.meeting-action-edit').forEach(function (btn) {
            btn.onclick = function (e) {
                e.stopPropagation();
                var meeting = meetings.find(function (m) { return m.id === btn.getAttribute('data-id'); });
                if (meeting) upsertMeeting(meeting).catch(function (err) { console.error('Upsert meeting failed', err); });
            };
        });
        list.querySelectorAll('.meeting-action-delete').forEach(function (btn) {
            btn.onclick = function (e) {
                e.stopPropagation();
                var meeting = meetings.find(function (m) { return m.id === btn.getAttribute('data-id'); });
                if (meeting) deleteMeeting(meeting).catch(function (err) { console.error('Delete meeting failed', err); });
            };
        });
        var addBtn = document.getElementById('addMeetingBtn');
        if (addBtn) addBtn.onclick = function () { upsertMeeting(null).catch(function (err) { console.error('Upsert meeting failed', err); }); };
    }

    /* ── render: agenda / discussion points ── */

    function renderPointHtml(p) {
        // Read-only: agenda points show as plain text (no answer box, no controls).
        return '<div class="mtg-agenda-item"><span class="mtg-agenda-question">' + escapeHtml(p.question) + '</span></div>';
    }

    function renderSectionHtml(s) {
        // Read-only agenda: the topic as plain text, no delete / no "add discussion point".
        var pointsHtml = (s.points || []).map(renderPointHtml).join('');
        return (
            '<div class="mtg-agenda-section" data-section-id="' + s.id + '">' +
            '<div class="mtg-agenda-section-header">' +
            '<h5 class="mtg-agenda-section-title">' + escapeHtml(s.title) + '</h5>' +
            '</div>' +
            (pointsHtml ? '<div class="mtg-agenda-section-points">' + pointsHtml + '</div>' : '') +
            '</div>'
        );
    }

    async function renderAgenda() {
        var root = document.getElementById('agendaSections');
        if (!root || !selectedMeeting) return;
        // Read-only agenda: hide the "+ Section" toolbar and relabel the hint.
        var toolbar = document.getElementById('agendaToolbar');
        if (toolbar) toolbar.style.display = 'none';
        var hint = document.querySelector('#tab-agenda .mtg-section-hint');
        if (hint) hint.textContent = 'Agenda from the Meeting System (meetings.html)';
        try {
            var data = await requestJson(
                '/api/agenda-sections?meeting_id=' + encodeURIComponent(selectedMeeting.id) +
                '&week_key=' + encodeURIComponent(weekKey(currentWeekStart))
            );
            var sections = data.sections || [];
            var unsectioned = data.unsectioned || [];
            if (!sections.length && !unsectioned.length) {
                root.innerHTML = '<div class="mtg-agenda-empty">No agenda set for this meeting.</div>';
                return;
            }
            var html = sections.map(renderSectionHtml).join('');
            if (unsectioned.length) {
                html += '<div class="mtg-agenda-section mtg-agenda-section-other"><div class="mtg-agenda-section-header"><h5 class="mtg-agenda-section-title">Other Points</h5></div><div class="mtg-agenda-section-points">' + unsectioned.map(renderPointHtml).join('') + '</div></div>';
            }
            root.innerHTML = html;
        } catch (err) {
            console.error('renderAgenda failed', err);
            root.innerHTML = '<div class="mtg-agenda-empty">Unable to load agenda.</div>';
        }
    }

    // When a meeting is opened and its minutes are present but the agenda is
    // still blank (no point answered, or only stale "Not discussed"), extract
    // the answers from the minutes automatically — so it "just happens" without
    // needing a manual Save. Runs at most once per meeting per page session.
    async function maybeAutoFillAgenda() {
        return; // Disabled — agendas are static plain text from meetings.html (no AI auto-fill).
        if (!selectedMeeting || !selectedMeeting.id) return;
        if (!savedNotesBaseline || !savedNotesBaseline.trim()) return;     // no minutes to extract from
        if (autoFilledMeetings[selectedMeeting.id]) return;                // already tried this session
        var root = document.getElementById('agendaSections');
        try {
            var data = await requestJson(
                '/api/agenda-sections?meeting_id=' + encodeURIComponent(selectedMeeting.id) +
                '&week_key=' + encodeURIComponent(weekKey(currentWeekStart))
            );
            var points = [];
            (data.sections || []).forEach(function (s) { (s.points || []).forEach(function (p) { points.push(p); }); });
            (data.unsectioned || []).forEach(function (p) { points.push(p); });
            if (!points.length) return;                                    // no agenda questions to fill
            var needsFill = points.some(function (p) {
                var a = String(p.answer || '').trim();
                return a === '' || a.toLowerCase().indexOf('not discussed') !== -1;
            });
            if (!needsFill) return;                                        // every point already has a real answer
            autoFilledMeetings[selectedMeeting.id] = true;                 // mark before await so it can't double-fire
            if (root) {
                var spinner = document.createElement('div');
                spinner.className = 'mtg-agenda-generating';
                spinner.innerHTML = '<span class="mtg-agenda-generating-dot"></span> Extracting agenda answers from the minutes...';
                root.prepend(spinner);
            }
            await requestJson('/api/agenda-sections', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'auto_fill',
                    meetingId: selectedMeeting.id,
                    weekKey: weekKey(currentWeekStart)
                })
            });
            await renderAgenda();
        } catch (err) {
            console.error('auto-fill agenda on open failed', err);
            if (root) { var sp = root.querySelector('.mtg-agenda-generating'); if (sp) sp.remove(); }
        }
    }

    function wireAgendaEvents(root) {
        root.querySelectorAll('.mtg-agenda-add-point').forEach(function (form) {
            form.onsubmit = async function (e) {
                e.preventDefault();
                var input = form.querySelector('input');
                var q = String(input && input.value || '').trim();
                if (!q) return;
                try {
                    await requestJson('/api/agenda-sections', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'add_point', sectionId: Number(form.getAttribute('data-section-id')), question: q })
                    });
                    if (input) input.value = '';
                    await renderAgenda();
                } catch (err) {
                    console.error('Agenda API failed', err);
                }
            };
        });
        root.querySelectorAll('.mtg-agenda-del-point').forEach(function (btn) {
            btn.onclick = async function () {
                if (!confirm('Remove this discussion point?')) return;
                try {
                    await requestJson('/api/agenda-sections', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'delete_point', id: Number(btn.getAttribute('data-point-id')) })
                    });
                    await renderAgenda();
                } catch (err) {
                    console.error('Agenda API failed', err);
                }
            };
        });
        root.querySelectorAll('.mtg-agenda-del-section').forEach(function (btn) {
            btn.onclick = async function () {
                if (!confirm('Delete this section? Discussion points will be kept as unsectioned.')) return;
                try {
                    await requestJson('/api/agenda-sections', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'delete_section', id: Number(btn.getAttribute('data-section-id')) })
                    });
                    await renderAgenda();
                } catch (err) {
                    console.error('Agenda API failed', err);
                }
            };
        });
    }

    async function addAgendaSection(title) {
        if (!selectedMeeting) return;
        await requestJson('/api/agenda-sections', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'add_section',
                meetingId: selectedMeeting.id,
                weekKey: weekKey(currentWeekStart),
                title: title
            })
        });
    }

    async function resetAgendaFromTemplate() {
        if (!selectedMeeting) return;
        await requestJson('/api/agenda-sections', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'clear_agenda',
                meetingId: selectedMeeting.id,
                weekKey: weekKey(currentWeekStart)
            })
        });
    }

    /* ── render: previous week minutes ── */

    function renderLastMinutes(value) {
        var el = document.getElementById('lastMomContent');
        if (!el) return;
        if (!value || !String(value).trim()) {
            el.innerHTML = '<div class="mom-scroll-inner"><p class="no-data">No notes from previous meeting.</p></div>';
            return;
        }

        function formatInline(text) {
            var normalized = String(text || '').replace(/:white_check_mark:/g, '✅');
            var safe = escapeHtml(normalized);
            safe = safe.replace(/(^|[^A-Za-z0-9_])(@[A-Za-z0-9_.-]+)/g, function (_, prefix, mention) {
                return prefix + '<span class="mom-mention">' + mention + '</span>';
            });
            safe = safe.replace(/\[([^\]]+)\]/g, '<span class="mom-timestamp">[$1]</span>');
            return safe;
        }

        var lines = String(value).split(/\r?\n/);
        var html = [];
        var listItems = [];

        function flushList() {
            if (!listItems.length) return;
            html.push('<ul class="mom-list">' + listItems.join('') + '</ul>');
            listItems = [];
        }

        lines.forEach(function (line) {
            var trimmed = String(line || '').trim();
            if (!trimmed) {
                flushList();
                return;
            }

            var isBullet = /^\*\s+/.test(trimmed);
            var isIndentedBullet = /^[\t ]+\*\s+/.test(String(line || ''));

            if (isBullet) {
                var content = trimmed.replace(/^\*\s+/, '');
                if (isIndentedBullet) {
                    listItems.push('<li class="mom-item">' + formatInline(content) + '</li>');
                } else {
                    flushList();
                    html.push('<div class="mom-section">' + formatInline(content) + '</div>');
                }
                return;
            }

            flushList();
            html.push('<div class="mom-divider">' + formatInline(trimmed) + '</div>');
        });

        flushList();
        el.innerHTML = html.length
            ? '<div class="mom-scroll-inner">' + html.join('') + '</div>'
            : '<div class="mom-scroll-inner"><p class="no-data">No notes from previous meeting.</p></div>';
    }

    async function loadMeetingNotes(includePrevious) {
        if (!selectedMeeting || !selectedMeeting.id) {
            return { note: '', previousNote: '' };
        }
        var url = '/api/meeting-notes?meeting_id=' + encodeURIComponent(selectedMeeting.id) +
            '&week_key=' + encodeURIComponent(weekKey(currentWeekStart));
        if (includePrevious) {
            url += '&include_previous=1';
            url += '&recurrence=' + encodeURIComponent(selectedMeeting.recurrence || '');
            url += '&day=' + encodeURIComponent(selectedMeeting.day || '');
        }
        return requestJson(url);
    }

    /* ── render: attendance tab ── */

    async function renderAttendance() {
        var el = document.getElementById('attendanceContent');
        if (!el || !selectedMeeting) return;

        var meetingDay = selectedMeeting.day || selectedMeeting.dayOfWeek || '';
        var dayOff = { Monday: 0, Tuesday: 1, Wednesday: 2, Thursday: 3, Friday: 4 };
        var off = dayOff[meetingDay];
        if (off === undefined) {
            el.innerHTML = '<p class="no-data">Cannot determine meeting date.</p>';
            return;
        }
        var occurrenceDate = localDateStr(addDays(currentWeekStart, off));

        el.innerHTML = '<p style="color:#94a3b8;font-size:13px">Loading attendance...</p>';

        try {
            var data = await requestJson(
                '/api/meeting-attendance?meeting_id=' + encodeURIComponent(selectedMeeting.id) +
                '&date=' + encodeURIComponent(occurrenceDate)
            );
            var attendance = data.attendance || [];
            var summary = data.summary || {};

            if (!attendance.length) {
                el.innerHTML = '<p class="no-data">No attendance data yet for this date. Attendance is auto-tracked from Slack Huddle AI notes after the meeting ends.</p>';
                return;
            }

            var rate = summary.total ? Math.round((summary.present / summary.total) * 100) : 0;
            var rateColor = rate >= 80 ? '#22c55e' : rate >= 50 ? '#f59e0b' : '#ef4444';

            var html = '<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">' +
                '<span style="font-size:20px;font-weight:700;color:' + rateColor + '">' + summary.present + '/' + summary.total + '</span>' +
                '<span style="font-size:13px;color:#94a3b8">present (' + rate + '%)</span>' +
                '</div>';

            html += '<div style="display:flex;flex-direction:column;gap:6px">';
            attendance.forEach(function (a) {
                var isPres = a.status === 'present';
                var badgeColor = isPres ? '#22c55e' : '#ef4444';
                var badgeText = isPres ? 'Present' : 'Absent';
                var sourceHint = a.source === 'manual' ? ' (manual)' : '';
                html += '<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;border-radius:8px;background:rgba(255,255,255,0.03)">' +
                    '<span style="font-size:13px;color:#e2e8f0">' + escapeHtml(a.userName) + '</span>' +
                    '<span style="font-size:11px;font-weight:600;color:' + badgeColor + ';padding:2px 8px;border-radius:4px;background:rgba(' + (isPres ? '34,197,94' : '239,68,68') + ',0.1)">' + badgeText + sourceHint + '</span>' +
                    '</div>';
            });
            html += '</div>';

            el.innerHTML = html;
        } catch (err) {
            el.innerHTML = '<p class="no-data">Failed to load attendance data.</p>';
        }
    }

    /* ── render: meeting detail (right pane) ── */

    async function renderMeetingDetail() {
        var empty = document.getElementById('emptyState');
        var detail = document.getElementById('meetingDetail');
        if (!selectedMeeting) {
            if (empty) empty.classList.remove('hidden');
            if (detail) detail.classList.add('hidden');
            return;
        }
        if (empty) empty.classList.add('hidden');
        if (detail) detail.classList.remove('hidden');

        var head = document.getElementById('detailHeader');
        if (head) {
            var weekStart = currentWeekStart;
            var weekEnd = addDays(weekStart, 4);
            var attendeeBadges = (selectedMeeting.attendees || []).map(function (a) {
                return '<span class="mtg-detail-badge">&#128100; ' + escapeHtml(a) + '</span>';
            }).join('');
            head.innerHTML =
                '<h2 class="mtg-detail-title">' + escapeHtml(selectedMeeting.title) + '</h2>' +
                '<div class="mtg-detail-sub">' +
                '<span class="mtg-detail-badge">&#128197; ' + escapeHtml(detailDayLabel(selectedMeeting)) + ', ' + escapeHtml(selectedMeeting.time) + '</span>' +
                '<span class="mtg-detail-badge">&#9733; ' + escapeHtml(selectedMeeting.owner) + '</span>' +
                attendeeBadges +
                '<span class="mtg-detail-badge">&#128197; ' + formatDate(weekStart) + ' — ' + formatDate(weekEnd) + '</span>' +
                '</div>';
        }

        var notes = document.getElementById('meetingNotes');
        if (notes) notes.value = '';
        try {
            var noteData = await loadMeetingNotes(Boolean(config.hasPreviousMinutes));
            if (notes) notes.value = noteData.note || '';
            if (config.hasPreviousMinutes) renderLastMinutes(noteData.previousNote || '');
        } catch (err) {
            console.error('loadMeetingNotes failed', err);
            if (notes) notes.value = '';
            if (config.hasPreviousMinutes) renderLastMinutes('');
        }
        savedNotesBaseline = notes ? notes.value : '';
        syncSaveNotesBtn();

        await renderAgenda();
        renderAttendance();
        maybeAutoFillAgenda();   // auto-extract agenda from the minutes if it's still blank (no manual Save needed)

        // Update Reset from Template button visibility
        var agendaToolbar = document.getElementById('agendaToolbar');
        var resetBtnId = 'resetAgendaFromTemplateBtn';
        var existingResetBtn = document.getElementById(resetBtnId);
        if (existingResetBtn) {
            existingResetBtn.remove();
        }
        
        if (agendaToolbar && selectedMeeting && selectedMeeting.agendaTemplateId) {
            var sectionAddForm = document.getElementById('sectionAddForm');
            var resetBtn = document.createElement('button');
            resetBtn.id = resetBtnId;
            resetBtn.type = 'button';
            resetBtn.className = 'mtg-btn mtg-btn-secondary mtg-btn-reset-template';
            resetBtn.textContent = 'Reset from Template';
            resetBtn.onclick = async function () {
                if (!window.confirm('This will erase the current agenda for this date and apply the template. Continue?')) {
                    return;
                }
                try {
                    await resetAgendaFromTemplate();
                    await renderAgenda();
                } catch (err) {
                    window.alert(err.message || 'Failed to reset agenda from template');
                }
            };
            if (sectionAddForm) {
                agendaToolbar.insertBefore(resetBtn, sectionAddForm);
            } else {
                agendaToolbar.appendChild(resetBtn);
            }
        }
    }

    /* ── week display ── */

    function updateWeekDisplay() {
        var weekLabel = document.getElementById('weekLabel');
        var weekRange = document.getElementById('weekRange');
        if (weekLabel) {
            var todayWeekStart = startOfWeek(new Date());
            var diffDays = Math.round((currentWeekStart - todayWeekStart) / (1000 * 60 * 60 * 24));
            var diffWeeks = Math.round(diffDays / 7);
            var label;
            if (diffWeeks === 0) label = 'This Week';
            else if (diffWeeks === -1) label = 'Last Week';
            else if (diffWeeks === 1) label = 'Next Week';
            else if (diffWeeks < 0) label = Math.abs(diffWeeks) + ' Weeks Ago';
            else label = diffWeeks + ' Weeks Ahead';
            weekLabel.textContent = label;
        }
        if (weekRange) weekRange.textContent = formatDate(currentWeekStart) + ' — ' + formatDate(addDays(currentWeekStart, 4));
    }

    /* ── hash state persistence ── */

    function syncHash() {
        var view = document.querySelector('.top-nav-link.active');
        var viewName = (view && view.getAttribute('data-view')) || 'meetings';
        var tab = document.querySelector('.mtg-tab.active');
        var tabName = (tab && tab.getAttribute('data-tab')) || '';
        var meetingId = selectedMeeting ? selectedMeeting.id : '';
        var weekKey = localDateStr(currentWeekStart);
        var params = ['view=' + encodeURIComponent(viewName)];
        if (tabName && tabName !== 'agenda') params.push('tab=' + encodeURIComponent(tabName));
        if (meetingId) params.push('mtg=' + encodeURIComponent(meetingId));
        if (weekKey && weekKey !== localDateStr(startOfWeek(new Date()))) {
            params.push('week=' + encodeURIComponent(weekKey));
        }
        location.hash = params.join('&');
    }

    function restoreFromHash() {
        var hash = (location.hash || '').replace(/^#/, '');
        if (!hash) return false;
        var params = {};
        hash.split('&').forEach(function (pair) {
            var parts = pair.split('=');
            if (parts.length === 2) {
                params[decodeURIComponent(parts[0])] = decodeURIComponent(parts[1]);
            }
        });
        var restored = false;
        if (params.view && params.view !== 'meetings') {
            switchView(params.view);
            restored = true;
        }
        if (params.week) {
            try {
                var weekDate = new Date(params.week + 'T00:00:00');
                if (!isNaN(weekDate.getTime())) {
                    currentWeekStart = startOfWeek(weekDate);
                    updateWeekDisplay();
                    restored = true;
                }
            } catch (e) {
                console.warn('Invalid week in hash:', params.week);
            }
        }
        if (params.mtg && meetings.length) {
            var meeting = meetings.find(function (m) { return m.id === params.mtg; });
            if (meeting) {
                selectedMeeting = meeting;
                renderMeetingList();
                renderMeetingDetail();
                restored = true;
            }
        }
        if (params.tab) {
            var tabEl = document.querySelector('.mtg-tab[data-tab="' + params.tab + '"]');
            if (tabEl) {
                document.querySelectorAll('.mtg-tab').forEach(function (x) { x.classList.remove('active'); });
                document.querySelectorAll('.mtg-tab-panel').forEach(function (x) { x.classList.remove('active'); });
                tabEl.classList.add('active');
                var content = document.getElementById('tab-' + params.tab);
                if (content) content.classList.add('active');
                restored = true;
            }
        }
        return restored;
    }

    /* ── view switching ── */

    function switchView(view, opts) {
        opts = opts || {};
        // Optional gate (e.g. the portal's daily sign-in lock): the host may
        // veto a target view and substitute another. Kept generic so this
        // module stays portal-agnostic.
        if (typeof config.guardSwitchView === 'function') {
            var guarded = config.guardSwitchView(view, opts);
            if (guarded && guarded !== view) {
                view = guarded;
            }
        }
        var views = config.views || ['meetings'];
        views.forEach(function (v) {
            var el = document.getElementById(v + 'View');
            if (el) el.classList.toggle('hidden', v !== view);
        });
        document.querySelectorAll('.top-nav-link').forEach(function (a) {
            a.classList.toggle('active', a.getAttribute('data-view') === view);
        });
        if (typeof config.onSwitchView === 'function') {
            config.onSwitchView(view);
        }
        if (view === 'meetings' && !opts.skipSync) {
            syncHash();
        }
    }

    /* ── init ── */

    function init(cfg) {
        config = cfg || {};
        if (!config.portal) config.portal = 'ops';
        if (!Array.isArray(config.views)) config.views = ['meetings'];

        if (config.KPI_GROUPS) KPI_GROUPS = config.KPI_GROUPS;
        if (config.MODAL_PEOPLE) MODAL_PEOPLE = config.MODAL_PEOPLE;

        var dateEl = document.getElementById('currentDate');
        if (dateEl) dateEl.textContent = new Date().toLocaleString('en-IN');

        updateWeekDisplay();

        loadMeetings().then(function () {
            var restored = restoreFromHash();
            if (!restored) {
                renderMeetingList();
                renderMeetingDetail();
            }
        }).catch(function (err) {
            console.error('Meeting init failed', err);
            var list = document.getElementById('meetingList');
            if (list) list.innerHTML = '<p class="mtg-agenda-empty">Unable to load meetings.</p>';
        });

        window.addEventListener('hashchange', function () {
            if (document.querySelector('.top-nav-link.active') && document.querySelector('.top-nav-link.active').getAttribute('data-view') === 'meetings') {
                restoreFromHash();
            }
        });

        document.querySelectorAll('.top-nav-link[data-view]').forEach(function (a) {
            a.onclick = function (e) {
                e.preventDefault();
                var moreWrap = document.querySelector('.top-nav-more');
                if (moreWrap) moreWrap.classList.remove('open');
                switchView(a.getAttribute('data-view') || 'meetings');
            };
        });

        var moreBtn = document.querySelector('.top-nav-more-btn');
        if (moreBtn) {
            moreBtn.onclick = function (e) {
                e.preventDefault();
                e.stopPropagation();
                var moreWrap = moreBtn.closest('.top-nav-more');
                if (moreWrap) moreWrap.classList.toggle('open');
            };
            document.addEventListener('click', function () {
                var moreWrap = document.querySelector('.top-nav-more');
                if (moreWrap) moreWrap.classList.remove('open');
            });
        }

        document.querySelectorAll('.mtg-tab').forEach(function (tab) {
            tab.onclick = function () {
                var id = tab.getAttribute('data-tab');
                document.querySelectorAll('.mtg-tab').forEach(function (x) { x.classList.remove('active'); });
                document.querySelectorAll('.mtg-tab-panel').forEach(function (x) { x.classList.remove('active'); });
                tab.classList.add('active');
                var content = document.getElementById('tab-' + id);
                if (content) content.classList.add('active');
                syncHash();
            };
        });

        var prev = document.getElementById('prevWeek');
        var next = document.getElementById('nextWeek');
        if (prev) prev.onclick = function () {
            currentWeekStart = addDays(currentWeekStart, -7);
            updateWeekDisplay();
            renderMeetingDetail();
            syncHash();
        };
        if (next) next.onclick = function () {
            currentWeekStart = addDays(currentWeekStart, 7);
            updateWeekDisplay();
            renderMeetingDetail();
            syncHash();
        };

        var sectionAddForm = document.getElementById('sectionAddForm');
        if (sectionAddForm) {
            sectionAddForm.onsubmit = async function (e) {
                e.preventDefault();
                var input = document.getElementById('meetingNewSectionTitle');
                var title = String(input && input.value || '').trim();
                if (!title) return;
                try {
                    await addAgendaSection(title);
                    if (input) input.value = '';
                    await renderAgenda();
                } catch (err) {
                    window.alert(err.message || 'Failed to add section');
                }
            };
        }

        var saveNotes = document.getElementById('saveNotesBtn');
        var notesInputEl = document.getElementById('meetingNotes');
        if (notesInputEl) notesInputEl.addEventListener('input', syncSaveNotesBtn);
        if (saveNotes) saveNotes.onclick = async function () {
            if (!selectedMeeting || !selectedMeeting.id) return;
            var notesEl = document.getElementById('meetingNotes');
            var st = document.getElementById('saveStatus');
            var notesContent = notesEl ? notesEl.value : '';
            saveNotes.disabled = true;
            saveNotes.classList.remove('is-saved');
            saveNotes.textContent = 'Saving…';
            try {
                await requestJson('/api/meeting-notes', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save',
                        meetingId: selectedMeeting.id,
                        weekKey: weekKey(currentWeekStart),
                        content: notesContent
                    })
                });
                savedNotesBaseline = notesContent;
                if (st) {
                    st.textContent = 'Saved successfully';
                    setTimeout(function () { st.textContent = ''; }, 2000);
                }
                syncSaveNotesBtn();
            } catch (err) {
                console.error('Save notes failed', err);
                if (st) st.textContent = err && err.message ? err.message : 'Failed to save notes';
                syncSaveNotesBtn();
                return;
            }

            if (notesContent.trim()) {
                var agendaRoot = document.getElementById('agendaSections');
                if (agendaRoot) {
                    var spinner = document.createElement('div');
                    spinner.className = 'mtg-agenda-generating';
                    spinner.innerHTML = '<span class="mtg-agenda-generating-dot"></span> Generating agenda answers from notes...';
                    agendaRoot.prepend(spinner);
                }
                try {
                    await requestJson('/api/agenda-sections', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'auto_fill',
                            meetingId: selectedMeeting.id,
                            weekKey: weekKey(currentWeekStart)
                        })
                    });
                    await renderAgenda();
                } catch (err) {
                    console.error('Auto-fill agenda failed', err);
                    if (agendaRoot) {
                        var sp = agendaRoot.querySelector('.mtg-agenda-generating');
                        if (sp) sp.remove();
                    }
                }
            }
        };

        var logoutBtn = document.getElementById('logoutBtn');
        if (logoutBtn) logoutBtn.onclick = async function () {
            try {
                await fetch('/api/auth/logout', { method: 'POST', credentials: 'same-origin' });
            } finally {
                window.location.href = '/login';
            }
        };

        var existingHash = (location.hash || '').replace(/^#/, '');
        var hashHasNonMeetingView = false;
        var hashHasMeetingState = false;
        if (existingHash) {
            existingHash.split('&').forEach(function (pair) {
                var kv = pair.split('=');
                if (kv.length === 2) {
                    var k = decodeURIComponent(kv[0]);
                    var v = kv[1] ? decodeURIComponent(kv[1]) : '';
                    if (k === 'view' && v !== 'meetings') hashHasNonMeetingView = true;
                    if ((k === 'tab' || k === 'mtg') && v) hashHasMeetingState = true;
                }
            });
        }
        if (!hashHasNonMeetingView) {
            switchView('meetings', hashHasMeetingState ? { skipSync: true } : {});
        }
    }

    /* ── public API ── */

    window.MeetingModule = {
        init: init,
        requestJson: requestJson,
        escapeHtml: escapeHtml,
        weekKey: function (d) { return d ? weekKey(d) : weekKey(currentWeekStart); },
        formatDate: formatDate,
        addDays: addDays,
        get currentWeekStart() { return currentWeekStart; },
        get meetings() { return meetings; },
        get selectedMeeting() { return selectedMeeting; },
        setCurrentWeekStart: function (date, options) {
            currentWeekStart = startOfWeek(date || new Date());
            updateWeekDisplay();
            if (options && options.reload === false) return Promise.resolve();
            return loadMeetings();
        },

        renderMeetingList: renderMeetingList,
        renderMeetingDetail: renderMeetingDetail,
        renderAgenda: renderAgenda,
        renderLastMinutes: renderLastMinutes,
        loadMeetings: loadMeetings,
        switchView: switchView,
        updateWeekDisplay: updateWeekDisplay,
        syncHash: syncHash,
        restoreFromHash: restoreFromHash,
        showAddMeetingModal: function () {
            upsertMeeting(null).catch(function (err) { console.error('Upsert meeting failed', err); });
        },
        openMeetingById: function (id) {
            var m = meetings.find(function (x) { return x.id === id; }) || null;
            if (!m) return;
            selectedMeeting = m;
            switchView('meetings');
            renderMeetingList();
            renderMeetingDetail();
        },

        get KPI_GROUPS() { return KPI_GROUPS; },
        set KPI_GROUPS(v) { KPI_GROUPS = v; }
    };
})();
