/**
 * Offer / Appointment Letter generator.
 * Exposes window.LettersModule with a render() entry point that paints
 * the list view into #lettersView. Create-letter flow lives in a
 * cu-slideover overlay shared with the Tasks UI.
 */
(function () {
    'use strict';

    var QUILL_CSS = 'https://cdn.jsdelivr.net/npm/quill@2/dist/quill.snow.css';
    var QUILL_JS = 'https://cdn.jsdelivr.net/npm/quill@2/dist/quill.js';

    // Sensible default seeded into the (editable) AI-responsibilities clause
    // the moment HR enters a Position Designation for an intern letter.
    var AI_RESP_DEFAULT = 'As part of this internship, you may use artificial-intelligence tools, models, and datasets to assist with your assigned work. You are expected to use such tools responsibly and ethically, comply with all applicable data-protection and confidentiality policies, and independently review and validate any AI-generated output before relying on or submitting it. You must not input confidential, personal, or proprietary company information into external or third-party AI systems without prior written approval. All work products, including those created with the assistance of AI tools, remain the sole property of Innovfix Private Limited.';

    var state = {
        letters: [],
        variantsByKey: {},
        currentVariant: null,
        values: {},
        bodyOverridden: false,
        bodyHtmlOverride: '',
        previewTimer: null,
        breakupTimer: null,
        quill: null,
        quillLoaded: false,
        aiClauseAutoSeeded: false,
        // Draft auto-save bookkeeping.
        draftId: null,        // id of the draft row being edited (null until first save)
        saveTimer: null,      // debounce timer for autosave
        saving: false,        // a save request is in flight (coalesces concurrent saves)
        dirtySinceSave: false,// unsaved edits exist
        released: false,      // letter finalized — stop autosaving this row
        sessionId: 0,         // bumped each open; stale save responses are ignored
        editingIssued: false, // editing an already-issued letter (autosave off; saves on Update)
    };

    function $(id) { return document.getElementById(id); }
    function el(tag, attrs, children) {
        var n = document.createElement(tag);
        if (attrs) Object.keys(attrs).forEach(function (k) {
            if (k === 'style') Object.assign(n.style, attrs[k]);
            else if (k === 'class') n.className = attrs[k];
            else if (k === 'html') n.innerHTML = attrs[k];
            else if (k.indexOf('on') === 0) n.addEventListener(k.substring(2), attrs[k]);
            else n.setAttribute(k, attrs[k]);
        });
        (children || []).forEach(function (c) {
            if (c == null) return;
            if (typeof c === 'string') n.appendChild(document.createTextNode(c));
            else n.appendChild(c);
        });
        return n;
    }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    }); }

    function request(url, opts) {
        opts = opts || {};
        opts.credentials = 'same-origin';
        opts.headers = Object.assign({
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        }, opts.headers || {});
        if (opts.body && typeof opts.body !== 'string') {
            opts.body = JSON.stringify(opts.body);
            opts.headers['Content-Type'] = 'application/json';
        }
        return fetch(url, opts).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                if (!r.ok) throw new Error((j && j.error) || ('Request failed: ' + r.status));
                return j;
            });
        });
    }

    function loadQuill(cb) {
        if (state.quillLoaded) { cb(); return; }
        if (!document.querySelector('link[data-quill]')) {
            var link = document.createElement('link');
            link.rel = 'stylesheet'; link.href = QUILL_CSS; link.setAttribute('data-quill', '1');
            document.head.appendChild(link);
        }
        if (typeof window.Quill !== 'undefined') { state.quillLoaded = true; cb(); return; }
        var script = document.createElement('script');
        script.src = QUILL_JS; script.async = true;
        script.onload = function () { state.quillLoaded = true; cb(); };
        document.head.appendChild(script);
    }

    function categoryLabel(c) {
        return { freelancer: 'Freelancer', intern: 'Intern', fulltime: 'Full-time' }[c] || c;
    }
    function letterTypeLabel(t) {
        if (t === 'offer') return 'Offer Letter';
        if (t === 'probation') return 'Probation Letter';
        if (t === 'relieving') return 'Relieving Letter';
        if (t === 'experience') return 'Experience Certificate';
        return 'Appointment Letter';
    }

    /* ─────────────  List view ─────────────  */

    async function render() {
        var root = $('lettersView');
        if (!root) return;
        root.classList.remove('hidden');
        root.innerHTML = '<div class="lt-wrap"><div class="lt-loading">Loading letters…</div></div>';

        try {
            var [listRes, cfgRes] = await Promise.all([
                request('/api/letters'),
                request('/api/letters/template-config'),
            ]);
            state.letters = listRes.letters || [];
            state.variantsByKey = {};
            (cfgRes.variants || []).forEach(function (v) { state.variantsByKey[v.key] = v; });
            paintList(root);
        } catch (e) {
            root.innerHTML = '<div class="lt-wrap"><div class="lt-error">Failed to load letters: ' + esc(e.message) + '</div></div>';
        }
    }

    function paintList(root) {
        var html = '<div class="lt-wrap">';
        html += '<div class="lt-header">';
        html += '<h2 class="lt-title">Offer Letters</h2>';
        html += '<button class="btn btn-primary" id="ltCreateBtn">+ Create Letter</button>';
        html += '</div>';

        if (state.letters.length === 0) {
            html += '<div class="lt-empty">No letters issued yet. Click "Create Letter" to draft your first one.</div>';
        } else {
            html += '<div class="lt-table-wrap"><table class="lt-table">';
            html += '<thead><tr>' +
                '<th>Recipient</th><th>Type</th><th>Category</th><th>Role</th>' +
                '<th>Issued</th><th>Actions</th>' +
                '</tr></thead><tbody>';
            state.letters.forEach(function (l) {
                var isDraft = l.status === 'draft';
                html += '<tr>';
                html += '<td><div class="lt-cell-strong">' + esc(l.recipient_name || (isDraft ? 'Untitled draft' : '—')) + '</div>' +
                        '<div class="lt-cell-sub">' + esc(l.recipient_email || '') + '</div></td>';
                html += '<td><span class="lt-chip lt-chip-' + l.letter_type + '">' + esc(letterTypeLabel(l.letter_type)) + '</span></td>';
                html += '<td><span class="lt-chip">' + esc(categoryLabel(l.employee_category)) + '</span></td>';
                html += '<td>' + esc(l.role_title || '—') + '</td>';
                if (isDraft) {
                    html += '<td><span class="lt-chip lt-chip-draft">Draft</span>' +
                            '<div class="lt-cell-sub">edited ' + esc(formatDateTime(l.updated_at)) + '</div></td>';
                } else {
                    html += '<td><div>' + esc(formatDateTime(l.issued_at)) + '</div>' +
                            '<div class="lt-cell-sub">by ' + esc(l.issued_by || '—') + '</div></td>';
                }
                html += '<td><div class="lt-actions">';
                if (isDraft) {
                    html += '<button class="btn btn-sm btn-primary" data-action="edit" data-id="' + l.id + '">Edit</button>' +
                            '<button class="btn btn-sm btn-outline" data-action="delete" data-id="' + l.id + '">Delete</button>';
                } else {
                    html += '<button class="btn btn-sm btn-primary" data-action="edit" data-id="' + l.id + '">Edit</button>' +
                            '<a class="btn btn-sm btn-outline" href="' + esc(l.download_url) + '" download>Download</a>' +
                            '<button class="btn btn-sm btn-outline" data-action="gmail" data-id="' + l.id + '">Gmail</button>' +
                            '<button class="btn btn-sm btn-outline" data-action="whatsapp" data-id="' + l.id + '">WhatsApp</button>' +
                            '<button class="btn btn-sm btn-outline" data-action="delete" data-id="' + l.id + '">Delete</button>';
                }
                html += '</div></td>';
                html += '</tr>';
            });
            html += '</tbody></table></div>';
        }

        html += '</div>';
        root.innerHTML = html;

        $('ltCreateBtn').addEventListener('click', function () { openCreateSlideover(); });
        root.querySelectorAll('button[data-action]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = parseInt(btn.getAttribute('data-id'), 10);
                var letter = state.letters.find(function (l) { return l.id === id; });
                if (!letter) return;
                var action = btn.getAttribute('data-action');
                if (action === 'gmail') openGmail(letter);
                else if (action === 'whatsapp') openWhatsApp(letter);
                else if (action === 'edit') editLetter(id);
                else if (action === 'delete') deleteLetter(letter);
            });
        });
    }

    function formatDateTime(iso) {
        if (!iso) return '—';
        var d = new Date(iso);
        if (isNaN(d.getTime())) return iso;
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) +
            ' ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    }

    function editLetter(id) {
        request('/api/letters/' + id).then(function (letter) {
            openCreateSlideover(letter);
        }).catch(function (e) {
            alert('Could not open letter: ' + e.message);
        });
    }

    function deleteLetter(letter) {
        var msg = letter.status === 'draft'
            ? 'Discard this draft? This cannot be undone.'
            : 'Delete this issued letter? This removes the PDF and disables the public share link permanently.';
        if (!window.confirm(msg)) return;
        request('/api/letters/' + letter.id, { method: 'DELETE' }).then(function () {
            // If we just deleted the draft that's open in the slide-over, stop its
            // autosave and close the panel before repainting the list.
            if (state.draftId === letter.id) { state.released = true; closeSlideover(); }
            render();
        }).catch(function (e) {
            alert('Delete failed: ' + e.message);
        });
    }

    /* ─────────────  Create slide-over ─────────────  */

    function openCreateSlideover(draft) {
        var existing = $('ltSlideoverOverlay');
        if (existing) existing.remove();

        if (state.saveTimer) { clearTimeout(state.saveTimer); state.saveTimer = null; }
        state.released = false;
        state.saving = false;
        state.dirtySinceSave = false;
        state.aiClauseAutoSeeded = false;
        state.sessionId++;

        // Resume an existing draft (or edit an issued letter) when its variant config
        // is available; otherwise start fresh (also the "+ Create Letter" path).
        var resuming = !!(draft && draft.id
            && state.variantsByKey[draft.letter_type + '.' + draft.employee_category]);
        if (resuming) {
            state.draftId = draft.id;
            state.editingIssued = draft.status === 'issued';
            state.currentVariant = state.variantsByKey[draft.letter_type + '.' + draft.employee_category];
            state.values = draft.payload || {};
            state.bodyOverridden = !!draft.body_overridden;
            state.bodyHtmlOverride = draft.body_html || '';
        } else {
            state.draftId = null;
            state.editingIssued = false;
            state.currentVariant = null;
            state.values = { letter_date: todayISO(), work_mode: 'Work From Office' };
            state.bodyOverridden = false;
            state.bodyHtmlOverride = '';
        }

        var overlay = el('div', { id: 'ltSlideoverOverlay', class: 'lt-overlay' });
        overlay.innerHTML =
            '<div class="lt-panel">' +
                '<div class="lt-panel-header">' +
                    '<div class="lt-panel-head-left">' +
                        '<div class="lt-panel-title" id="ltPanelTitle">Create Letter</div>' +
                        '<span class="lt-save-status" id="ltSaveStatus"></span>' +
                    '</div>' +
                    '<button class="lt-close" id="ltCloseBtn" aria-label="Close">×</button>' +
                '</div>' +
                '<div class="lt-panel-body" id="ltPanelBody"></div>' +
            '</div>';
        document.body.appendChild(overlay);
        var titleEl = $('ltPanelTitle');
        if (titleEl) titleEl.textContent = state.editingIssued ? 'Edit Letter' : (resuming ? 'Edit Draft' : 'Create Letter');
        requestAnimationFrame(function () { overlay.classList.add('lt-overlay-open'); });

        $('ltCloseBtn').addEventListener('click', closeSlideover);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) closeSlideover(); });

        if (resuming) paintFieldsAndPreview();
        else paintVariantPicker();
    }

    function closeSlideover() {
        var overlay = $('ltSlideoverOverlay');
        if (!overlay) return;
        // Flush a pending autosave so closing mid-edit never drops the last keystrokes.
        if (!state.released && (state.dirtySinceSave || state.saveTimer)) {
            if (state.saveTimer) { clearTimeout(state.saveTimer); state.saveTimer = null; }
            saveDraft();
        }
        overlay.classList.remove('lt-overlay-open');
        setTimeout(function () { overlay.remove(); }, 180);
    }

    function paintVariantPicker() {
        var body = $('ltPanelBody');
        if (!body) return;
        body.innerHTML =
            '<div class="lt-step-1">' +
                '<div class="lt-field">' +
                    '<label class="lt-label">Letter Type</label>' +
                    '<select class="lt-input" id="ltType">' +
                        '<option value="">— Select —</option>' +
                        '<option value="offer">Offer Letter</option>' +
                        '<option value="appointment">Appointment Letter</option>' +
                        '<option value="probation">Probation Letter</option>' +
                        '<option value="relieving">Relieving Letter</option>' +
                        '<option value="experience">Experience Certificate</option>' +
                    '</select>' +
                '</div>' +
                '<div class="lt-field">' +
                    '<label class="lt-label">Employee Category</label>' +
                    '<select class="lt-input" id="ltCat">' +
                        '<option value="">— Select —</option>' +
                        '<option value="freelancer">Freelancer</option>' +
                        '<option value="intern">Intern</option>' +
                        '<option value="fulltime">Full-time</option>' +
                    '</select>' +
                '</div>' +
                '<button class="btn btn-primary" id="ltVariantNext" disabled>Continue</button>' +
            '</div>';

        var tSel = $('ltType'), cSel = $('ltCat'), next = $('ltVariantNext');
        var catField = cSel.closest('.lt-field');
        function recheck() {
            // Relieving / Experience letters don't vary by engagement type —
            // hide the category picker and pin it to full-time.
            var offboarding = tSel.value === 'relieving' || tSel.value === 'experience';
            if (catField) catField.style.display = offboarding ? 'none' : '';
            if (offboarding) cSel.value = 'fulltime';
            next.disabled = !(tSel.value && cSel.value);
        }
        tSel.addEventListener('change', recheck);
        cSel.addEventListener('change', recheck);
        next.addEventListener('click', function () {
            var key = tSel.value + '.' + cSel.value;
            state.currentVariant = state.variantsByKey[key];
            if (!state.currentVariant) {
                alert('That combination isn’t available — Probation Letters apply to Interns and Full-time only.');
                return;
            }
            paintFieldsAndPreview();
        });
    }

    function paintFieldsAndPreview() {
        var body = $('ltPanelBody');
        var variant = state.currentVariant;
        var releaseLabel = state.editingIssued ? 'Update Letter' : 'Release Letter';
        body.innerHTML =
            '<div class="lt-two-col">' +
                '<div class="lt-form-col">' +
                    '<div class="lt-variant-pill">' + esc(variant.label) + ' <button class="lt-pill-edit" id="ltChangeVariant">change</button></div>' +
                    '<div id="ltFieldsForm" class="lt-fields-form"></div>' +
                    '<div class="lt-footer-actions">' +
                        '<button class="btn btn-outline" id="ltCancelBtn">Cancel</button>' +
                        '<button class="btn btn-primary" id="ltReleaseBtn">' + releaseLabel + '</button>' +
                    '</div>' +
                '</div>' +
                '<div class="lt-preview-col">' +
                    '<div class="lt-preview-header">' +
                        '<div class="lt-preview-title">Preview</div>' +
                        '<label class="lt-toggle"><input type="checkbox" id="ltOverrideToggle"> Edit body manually</label>' +
                    '</div>' +
                    '<div class="lt-preview-area" id="ltPreviewArea">' +
                        '<iframe id="ltPreviewIframe" sandbox=""></iframe>' +
                    '</div>' +
                '</div>' +
            '</div>';

        $('ltChangeVariant').addEventListener('click', paintVariantPicker);
        $('ltCancelBtn').addEventListener('click', closeSlideover);
        $('ltReleaseBtn').addEventListener('click', releaseLetter);
        $('ltOverrideToggle').addEventListener('change', onOverrideToggle);

        renderFieldsForm();
        schedulePreview(0);

        // Editing an already-issued letter saves only on explicit Update (autosave
        // is off), so tell the user instead of leaving the status blank.
        if (state.editingIssued) {
            setSaveStatus('note', 'Released letter — changes save when you click Update');
        }

        // Resuming a draft that used manual body editing: re-check the toggle and
        // swap in the Quill editor seeded with the saved body HTML.
        if (state.bodyOverridden) {
            var tog = $('ltOverrideToggle');
            if (tog) { tog.checked = true; onOverrideToggle({ target: tog }); }
        }
    }

    function renderFieldsForm() {
        var variant = state.currentVariant;
        var holder = $('ltFieldsForm');
        var fieldDefs = variant.fields || {};

        // Group fields into sections.
        var positionFields = ['recipient_name', 'recipient_email', 'recipient_phone', 'gender', 'role_title', 'position_designation', 'department', 'start_date', 'last_working_date', 'letter_date', 'work_mode'];
        var roleFields = ['role_overview', 'responsibilities', 'ai_responsibilities', 'tenure_summary', 'conduct_summary'];
        var compFields = Object.keys(fieldDefs).filter(function (k) {
            return positionFields.indexOf(k) === -1 && roleFields.indexOf(k) === -1
                && (fieldDefs[k].group || 'meta') !== 'annexure';
        });
        var annexureFields = Object.keys(fieldDefs).filter(function (k) {
            return (fieldDefs[k].group || '') === 'annexure';
        });

        var html = '';
        html += sectionHtml('Recipient & Position', positionFields, fieldDefs);
        html += sectionHtml('Role Content', roleFields, fieldDefs);
        if (compFields.length) html += sectionHtml('Compensation', compFields, fieldDefs);
        if (annexureFields.length) html += sectionHtml('Annexure-I — Salary Breakdown (auto-filled from CTC, editable)', annexureFields, fieldDefs);

        holder.innerHTML = html;

        // Wire change events.
        holder.querySelectorAll('input, textarea, select').forEach(function (input) {
            var name = input.getAttribute('data-field');
            if (!name) return;
            // Apply defaults from schema on first paint.
            if (state.values[name] === undefined && fieldDefs[name].default !== undefined) {
                input.value = fieldDefs[name].default;
                state.values[name] = fieldDefs[name].default;
            }
            input.addEventListener('input', function () { onFieldChange(name, input.value); });
            input.addEventListener('change', function () { onFieldChange(name, input.value); });
            input.addEventListener('blur', function () { schedulePreview(0); });
        });
    }

    function sectionHtml(title, names, fieldDefs) {
        var rows = '';
        names.forEach(function (name) {
            var def = fieldDefs[name];
            if (!def) return;
            var value = state.values[name];
            if (value === undefined && def.default_today) value = todayISO();
            if (value === undefined) value = '';
            rows += '<div class="lt-field">';
            rows += '<label class="lt-label">' + esc(def.label) + (def.required ? ' *' : '') + '</label>';
            if (def.type === 'textarea') {
                rows += '<textarea class="lt-input" rows="' + (def.rows || 3) + '" data-field="' + name + '">' + esc(value) + '</textarea>';
            } else if (def.type === 'select') {
                rows += '<select class="lt-input" data-field="' + name + '">';
                (def.options || []).forEach(function (opt) {
                    rows += '<option value="' + esc(opt) + '"' + (String(opt) === String(value) ? ' selected' : '') + '>' + esc(opt) + '</option>';
                });
                rows += '</select>';
            } else {
                var type = def.type === 'email' ? 'email' :
                          def.type === 'tel' ? 'tel' :
                          def.type === 'date' ? 'date' :
                          def.type === 'number' ? 'number' : 'text';
                rows += '<input type="' + type + '" class="lt-input" data-field="' + name + '"' +
                    ' value="' + esc(value) + '"' +
                    (def.placeholder ? ' placeholder="' + esc(def.placeholder) + '"' : '') +
                    '>';
            }
            rows += '</div>';
        });
        // Suppress a section whose fields don't apply to this variant (e.g. the
        // "Role Content" group for a relieving letter), so no empty header shows.
        if (!rows) return '';
        return '<div class="lt-section"><div class="lt-section-title">' + esc(title) + '</div>' + rows + '</div>';
    }

    function onFieldChange(name, value) {
        state.values[name] = value;
        if (name === 'position_designation') syncAiClause(value);
        if (name === 'ai_responsibilities') state.aiClauseAutoSeeded = false;
        schedulePreview(350);
        scheduleSave(1000);
        if (name === 'annual_ctc' && state.currentVariant
            && state.currentVariant.employee_category === 'fulltime') {
            scheduleBreakup(300);
        }
    }

    // Designating an AI-intern position seeds an editable default clause.
    // Manual edits are never overwritten; clearing the designation reverts
    // only our own still-untouched auto-seed so a plain intern letter stays
    // structurally identical to before.
    function syncAiClause(designation) {
        var ta = document.querySelector('#ltFieldsForm [data-field="ai_responsibilities"]');
        var current = state.values.ai_responsibilities || '';
        if (String(designation || '').trim() !== '') {
            if (current === '' || (state.aiClauseAutoSeeded && current === AI_RESP_DEFAULT)) {
                state.values.ai_responsibilities = AI_RESP_DEFAULT;
                if (ta) ta.value = AI_RESP_DEFAULT;
                state.aiClauseAutoSeeded = true;
            }
        } else if (state.aiClauseAutoSeeded && current === AI_RESP_DEFAULT) {
            state.values.ai_responsibilities = '';
            if (ta) ta.value = '';
            state.aiClauseAutoSeeded = false;
        }
    }

    function schedulePreview(delay) {
        if (state.previewTimer) clearTimeout(state.previewTimer);
        state.previewTimer = setTimeout(refreshPreview, delay);
    }

    function scheduleBreakup(delay) {
        if (state.breakupTimer) clearTimeout(state.breakupTimer);
        state.breakupTimer = setTimeout(autofillBreakup, delay);
    }

    // Reflects the otherwise-silent autosave in the panel header so HR can see
    // their progress is being saved. Kinds: 'saving' | 'saved' | 'error' | 'note'
    // | '' (hidden).
    function setSaveStatus(kind, noteText) {
        var elx = $('ltSaveStatus');
        if (!elx) return;
        var labels = { saving: 'Saving…', saved: 'All changes saved', error: 'Couldn’t save — will retry' };
        elx.className = 'lt-save-status' + (kind ? ' lt-save-' + kind : '');
        elx.textContent = kind === 'note' ? (noteText || '') : (labels[kind] || '');
    }

    function scheduleSave(delay) {
        if (state.editingIssued) return; // issued letters save only on explicit Update
        state.dirtySinceSave = true;
        if (state.saveTimer) clearTimeout(state.saveTimer);
        setSaveStatus('saving');
        state.saveTimer = setTimeout(saveDraft, delay);
    }

    // Auto-save the in-progress letter as a server-side draft. Best-effort and
    // silent — never alerts. Coalesces concurrent saves so a null draftId can't
    // create two rows, and ignores responses from a superseded editing session.
    function saveDraft() {
        if (state.saveTimer) { clearTimeout(state.saveTimer); state.saveTimer = null; }
        if (state.editingIssued) return; // issued letters save only on explicit Update
        if (state.released || !state.currentVariant) return;

        // Empty-draft suppression: state.values is pre-seeded with letter_date /
        // work_mode, so gate on a real identifying field before creating a row.
        var v = state.values;
        var hasContent = (v.recipient_name && String(v.recipient_name).trim())
            || (v.recipient_email && String(v.recipient_email).trim())
            || (v.role_title && String(v.role_title).trim());
        if (!hasContent) { setSaveStatus(''); return; }

        if (state.saving) { state.dirtySinceSave = true; return; }
        state.saving = true;
        state.dirtySinceSave = false;
        var mySession = state.sessionId;

        request('/api/letters/draft', {
            method: 'POST',
            body: {
                id: state.draftId,
                letter_type: state.currentVariant.letter_type,
                employee_category: state.currentVariant.employee_category,
                fields: state.values,
                body_overridden: state.bodyOverridden,
                body_override_html: state.bodyOverridden ? state.bodyHtmlOverride : null,
            },
        }).then(function (res) {
            if (state.sessionId !== mySession) return; // a newer session took over
            state.saving = false;
            if (res && res.id && !state.released) state.draftId = res.id;
            // Flush any edits that landed while this save was in flight, else mark saved.
            if (state.dirtySinceSave && !state.released) saveDraft();
            else setSaveStatus('saved');
        }).catch(function () {
            if (state.sessionId !== mySession) return;
            state.saving = false;
            state.dirtySinceSave = true; // retry on the next change
            setSaveStatus('error');
        });
    }

    async function autofillBreakup() {
        if (!state.currentVariant) return;
        if (state.currentVariant.employee_category !== 'fulltime') return;
        var ctc = parseFloat(state.values.annual_ctc);
        if (!isFinite(ctc) || ctc <= 0) return;
        try {
            var res = await request('/api/letters/preview-breakup', {
                method: 'POST',
                body: {
                    annual_ctc: ctc,
                    employee_category: state.currentVariant.employee_category,
                },
            });
            var b = res.breakup || {};
            // Annexure schema keys land in state.values + their DOM inputs. Skip
            // the headline CTC fields HR already entered.
            var skip = { annual_ctc: 1, monthly_ctc: 1 };
            Object.keys(b).forEach(function (k) {
                if (skip[k]) return;
                state.values[k] = b[k];
                var input = document.querySelector('#ltFieldsForm [data-field="' + k + '"]');
                if (input) input.value = b[k];
            });
            schedulePreview(0);
            scheduleSave(1000); // persist the derived annexure values into the draft
        } catch (e) {
            console.error('Breakup autofill failed', e);
        }
    }

    async function refreshPreview() {
        if (!state.currentVariant) return;
        var body = {
            letter_type: state.currentVariant.letter_type,
            employee_category: state.currentVariant.employee_category,
            fields: state.values,
        };
        if (state.bodyOverridden && state.bodyHtmlOverride) {
            body.body_override_html = state.bodyHtmlOverride;
        }
        try {
            var res = await request('/api/letters/preview', { method: 'POST', body: body });
            if (!state.bodyOverridden && res.body_html) {
                state.bodyHtmlOverride = res.body_html;
            }
            var iframe = $('ltPreviewIframe');
            if (iframe && !state.bodyOverridden) {
                iframe.srcdoc = res.html || '';
            }
        } catch (e) {
            console.error('Preview failed', e);
        }
    }

    function onOverrideToggle(e) {
        state.bodyOverridden = e.target.checked;
        var area = $('ltPreviewArea');
        if (state.bodyOverridden) {
            // Swap iframe → Quill editor seeded with current body HTML.
            loadQuill(function () {
                area.innerHTML = '<div id="ltQuillHost" class="lt-quill-host"></div>' +
                    '<div class="lt-override-note">Body is now manually edited. Field edits below won\'t affect the body until you toggle this off.</div>';
                state.quill = new window.Quill('#ltQuillHost', {
                    theme: 'snow',
                    modules: {
                        toolbar: [
                            [{ header: [false, 3, 4] }],
                            ['bold', 'italic', 'underline'],
                            [{ list: 'ordered' }, { list: 'bullet' }],
                            ['clean'],
                        ],
                    },
                });
                state.quill.root.innerHTML = state.bodyHtmlOverride || '';
                state.quill.on('text-change', function () {
                    state.bodyHtmlOverride = state.quill.root.innerHTML;
                    scheduleSave(1000);
                });
            });
        } else {
            // Swap back to iframe preview.
            state.bodyHtmlOverride = '';
            area.innerHTML = '<iframe id="ltPreviewIframe" sandbox=""></iframe>';
            refreshPreview();
        }
        scheduleSave(1000); // persist the override on/off flag
    }

    async function releaseLetter() {
        if (!state.currentVariant) return;
        // Minimal client-side validation.
        var fieldDefs = state.currentVariant.fields || {};
        var missing = Object.keys(fieldDefs).filter(function (k) {
            if (!fieldDefs[k].required) return false;
            var v = state.values[k];
            return v === undefined || v === null || String(v).trim() === '';
        });
        if (missing.length) {
            alert('Please fill the required fields:\n• ' + missing.map(function (k) { return fieldDefs[k].label; }).join('\n• '));
            return;
        }

        // Stop autosave from racing the finalize; the draft id (if any) tells the
        // backend to finalize that row in place rather than create a new one.
        if (state.saveTimer) { clearTimeout(state.saveTimer); state.saveTimer = null; }

        var btn = $('ltReleaseBtn');
        var isEdit = state.editingIssued;
        btn.disabled = true; btn.textContent = isEdit ? 'Updating…' : 'Releasing…';

        try {
            var res = await request('/api/letters', {
                method: 'POST',
                body: {
                    id: state.draftId,
                    letter_type: state.currentVariant.letter_type,
                    employee_category: state.currentVariant.employee_category,
                    fields: state.values,
                    body_overridden: state.bodyOverridden,
                    body_override_html: state.bodyOverridden ? state.bodyHtmlOverride : null,
                    // Editing an already-issued letter re-issues it in place (same
                    // share link, regenerated PDF) instead of an idempotent no-op.
                    reissue: isEdit,
                },
            });
            // The row is now issued — never autosave it again.
            state.released = true;
            state.editingIssued = false;
            state.draftId = null;
            paintReleaseSuccess(res.letter);
            // Refresh list in background.
            request('/api/letters').then(function (r) { state.letters = r.letters || []; }).catch(function () {});
        } catch (e) {
            alert((isEdit ? 'Update' : 'Release') + ' failed: ' + e.message);
            btn.disabled = false; btn.textContent = isEdit ? 'Update Letter' : 'Release Letter';
        }
    }

    function paintReleaseSuccess(letter) {
        var body = $('ltPanelBody');
        var recipient = state.values.recipient_name || 'the recipient';
        var hasPhone = !!state.values.recipient_phone;
        body.innerHTML =
            '<div class="lt-success">' +
                '<div class="lt-success-icon">✓</div>' +
                '<h3>Letter released</h3>' +
                '<p>The PDF is saved and ready to share with ' + esc(recipient) + '.</p>' +
                '<div class="lt-success-actions">' +
                    '<a class="btn btn-primary" href="' + esc(letter.download_url) + '" download>Download PDF</a>' +
                    '<button class="btn btn-outline" id="ltShareGmail">Share via Gmail</button>' +
                    '<button class="btn btn-outline" id="ltShareWA"' + (hasPhone ? '' : ' disabled title="Add recipient phone first"') + '>Share via WhatsApp</button>' +
                '</div>' +
                '<div class="lt-success-link"><label>Share link</label>' +
                    '<input class="lt-input" readonly value="' + esc(absoluteUrl(letter.share_url)) + '" onclick="this.select();"></div>' +
                '<button class="btn btn-outline lt-success-done" id="ltSuccessDone">Done</button>' +
            '</div>';

        $('ltShareGmail').addEventListener('click', function () {
            openGmail({
                recipient_email: state.values.recipient_email,
                recipient_name: recipient,
                role_title: state.values.role_title,
                letter_type: state.currentVariant.letter_type,
                share_url: letter.share_url,
            });
        });
        var waBtn = $('ltShareWA');
        if (waBtn) waBtn.addEventListener('click', function () {
            openWhatsApp({
                recipient_phone: state.values.recipient_phone,
                recipient_name: recipient,
                role_title: state.values.role_title,
                letter_type: state.currentVariant.letter_type,
                share_url: letter.share_url,
            });
        });
        $('ltSuccessDone').addEventListener('click', function () {
            closeSlideover();
            render();
        });
    }

    /* ─────────────  Share helpers ─────────────  */

    function absoluteUrl(path) {
        if (/^https?:\/\//i.test(path)) return path;
        return window.location.origin + path;
    }

    function openGmail(letter) {
        var url = absoluteUrl(letter.share_url || ('/letters/share/' + (letter.share_token || '')));
        var subject = (letter.letter_type === 'appointment' ? 'Appointment Letter — ' : 'Offer Letter — ') +
            (letter.role_title || '');
        var lines = [
            'Hi ' + (letter.recipient_name || '') + ',',
            '',
            'Please find your ' + (letter.letter_type === 'appointment' ? 'appointment' : 'offer') + ' letter for the role of ' + (letter.role_title || '') + ' attached at the link below.',
            '',
            url,
            '',
            'Looking forward to working with you.',
            '',
            'Regards,',
            'HR, Innovfix Pvt Ltd',
        ];
        var gmail = 'https://mail.google.com/mail/?view=cm&fs=1' +
            '&to=' + encodeURIComponent(letter.recipient_email || '') +
            '&su=' + encodeURIComponent(subject) +
            '&body=' + encodeURIComponent(lines.join('\n'));
        window.open(gmail, '_blank', 'noopener');
    }

    function openWhatsApp(letter) {
        var phone = (letter.recipient_phone || '').replace(/[^\d]/g, '');
        if (!phone) { alert('Add a recipient phone number to share via WhatsApp.'); return; }
        var url = absoluteUrl(letter.share_url || ('/letters/share/' + (letter.share_token || '')));
        var text = 'Hi ' + (letter.recipient_name || '') + ', here is your ' +
            (letter.letter_type === 'appointment' ? 'appointment' : 'offer') +
            ' letter from Innovfix Pvt Ltd: ' + url;
        var wa = 'https://wa.me/' + phone + '?text=' + encodeURIComponent(text);
        window.open(wa, '_blank', 'noopener');
    }

    function todayISO() {
        var d = new Date();
        var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }

    // Open the create slide-over prefilled with the given field values — used by
    // the Hiring/ATS flow to hand an accepted candidate into an offer letter.
    // Prefill keys are the composer field keys (recipient_name, recipient_email,
    // recipient_phone, role_title, …).
    async function composeFor(prefill) {
        if (!state.variantsByKey || !Object.keys(state.variantsByKey).length) {
            await render();
        }
        openCreateSlideover();
        if (prefill && typeof prefill === 'object') {
            Object.assign(state.values, prefill);
            // When the prefill names a concrete variant, jump straight to the
            // fields form instead of leaving HR on the type/category picker.
            var key = (prefill.letter_type && prefill.employee_category)
                ? prefill.letter_type + '.' + prefill.employee_category : null;
            if (key && state.variantsByKey[key]) {
                state.currentVariant = state.variantsByKey[key];
                paintFieldsAndPreview();
            }
        }
    }

    // Prefill the composer for an existing user (e.g. the probation-ending
    // "Release Letter" deep link). Fetches the server-side prefill, then opens
    // the composer on the chosen letter type/category. Falls back to an empty
    // composer rather than blocking if the lookup fails.
    async function composeForUser(userId, letterType, category) {
        if (!userId) return composeFor({});
        var prefill = {};
        try {
            var res = await request('/api/letters/prefill?user_id=' + encodeURIComponent(userId));
            prefill = (res && res.prefill) ? res.prefill : {};
        } catch (e) {
            console.error('Letter prefill failed', e);
        }
        prefill.letter_type = letterType || 'offer';
        prefill.employee_category = category || prefill.suggested_category || 'fulltime';
        return composeFor(prefill);
    }

    window.LettersModule = { render: render, composeFor: composeFor, composeForUser: composeForUser };
})();
