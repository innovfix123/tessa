/**
 * Hiring / Recruitment (ATS) — Phase 1: Job Descriptions + recruiter assignment.
 *
 * Three roles, decided server-side and reflected in the payload flags:
 *  - HR / management (is_hr): see ALL JDs, create them, assign to freelancers.
 *  - Panel member (can_create, not is_hr): create + see their own JDs.
 *  - Freelance recruiter (is_freelancer): read-only list of JDs assigned to them
 *    (candidate upload lands in the next phase).
 *
 * Backend: /api/hiring/*    Exposes window.Hiring.render(containerEl).
 */
(function () {
  'use strict';

  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

  async function api(url, opts = {}) {
    const res = await fetch('/api' + url, {
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrf(),
        Accept: 'application/json',
        ...(opts.headers || {}),
      },
      credentials: 'same-origin',
      method: opts.method || 'GET',
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.error || json.message || 'Request failed');
    return json;
  }

  // Multipart — branch on res.status BEFORE res.json() so a 413/504 (HTML, not
  // JSON) from nginx/php-fpm doesn't read as a generic network error.
  async function apiForm(url, formData, opts = {}) {
    const res = await fetch('/api' + url, {
      method: opts.method || 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: formData,
    });
    if (res.status === 413) throw new Error('File too large for the server (limit ~10 MB).');
    if (res.status === 504 || res.status === 502) throw new Error('Upload timed out at the server. Try a smaller file.');
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      const firstErr = json.errors && Object.values(json.errors)[0] && Object.values(json.errors)[0][0];
      throw new Error(json.error || firstErr || json.message || 'Request failed');
    }
    return json;
  }

  function el(tag, attrs, ...kids) {
    const e = document.createElement(tag);
    if (attrs) {
      for (const k in attrs) {
        if (k === 'style') Object.assign(e.style, attrs[k]);
        else if (k === 'html') e.innerHTML = attrs[k];
        else if (k.startsWith('on') && typeof attrs[k] === 'function') e.addEventListener(k.slice(2), attrs[k]);
        else if (attrs[k] !== undefined && attrs[k] !== null && attrs[k] !== false) e.setAttribute(k, attrs[k]);
      }
    }
    for (const k of kids.flat(Infinity)) {
      if (k == null || k === false) continue;
      e.appendChild(typeof k === 'string' ? document.createTextNode(k) : k);
    }
    return e;
  }

  function fmtDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
  }

  const STATUS_LABEL = { draft: 'Draft', open: 'Open', assigned: 'Assigned', closed: 'Closed' };
  const STAGE_LABEL = {
    sourced: 'New', panel_review: 'In review', tech_round: 'Tech round', hr_round: 'HR round',
    accepted: 'Accepted', provisioning: 'Provisioning', offer: 'Offer', onboarding: 'Onboarding',
    hired: 'Hired', rejected: 'Rejected', withdrawn: 'Withdrawn',
  };

  // ── Styles ──────────────────────────────────────────────────────────────────
  const STYLE_ID = 'hire-styles';
  const styles = `
    .hire-shell { padding:24px 28px; color:#e4e4e7; max-width:1040px; margin:0 auto; font-size:13px; }
    .hire-head { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; flex-wrap:wrap; margin-bottom:18px; }
    .hire-title h2 { color:#fafafa; font-size:18px; font-weight:600; margin:0; letter-spacing:-0.3px; }
    .hire-title p { color:#a1a1aa; font-size:13px; margin:3px 0 0; }
    .hire-btn { padding:8px 14px; background:#3b82f6; color:#fff; border:1px solid #3b82f6; border-radius:6px; cursor:pointer; font-size:13px; font-weight:500; font-family:inherit; }
    .hire-btn:hover:not(:disabled) { background:#2563eb; border-color:#2563eb; }
    .hire-btn:disabled { opacity:.55; cursor:not-allowed; }
    .hire-btn.ghost { background:transparent; color:#a1a1aa; border-color:#3f3f46; }
    .hire-btn.ghost:hover:not(:disabled) { color:#e4e4e7; border-color:#52525b; background:#27272a; }
    .hire-btn.sm { padding:5px 10px; font-size:12px; }

    .hire-list { display:flex; flex-direction:column; gap:12px; }
    .hire-card { border:1px solid #27272a; background:#18181b; border-radius:10px; padding:16px 18px; }
    .hire-card-top { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }
    .hire-card h3 { margin:0; font-size:15px; color:#fafafa; font-weight:600; }
    .hire-card-meta { color:#a1a1aa; font-size:12px; margin-top:3px; }
    .hire-badge { display:inline-block; padding:2px 9px; border-radius:999px; font-size:11px; font-weight:600; white-space:nowrap; }
    .hire-badge.open { background:#1e3a8a33; color:#93c5fd; }
    .hire-badge.assigned { background:#14532d33; color:#86efac; }
    .hire-badge.draft { background:#3f3f4633; color:#d4d4d8; }
    .hire-badge.closed { background:#7f1d1d33; color:#fca5a5; }
    .hire-fields { margin-top:10px; display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:8px 18px; }
    .hire-field-k { color:#71717a; font-size:11px; text-transform:uppercase; letter-spacing:.4px; }
    .hire-field-v { color:#e4e4e7; font-size:13px; margin-top:1px; white-space:pre-wrap; }
    .hire-desc { margin-top:10px; color:#d4d4d8; font-size:13px; line-height:1.5; white-space:pre-wrap; }
    .hire-card-actions { margin-top:12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .hire-chip { display:inline-flex; align-items:center; gap:5px; padding:3px 9px; border-radius:999px; background:#27272a; color:#d4d4d8; font-size:12px; }
    .hire-chip .dot { width:6px; height:6px; border-radius:50%; background:#86efac; }
    .hire-chip .dot.pending { background:#fbbf24; }
    .hire-link { color:#93c5fd; text-decoration:none; font-size:12px; }
    .hire-link:hover { text-decoration:underline; }
    .hire-empty { border:1px dashed #3f3f46; border-radius:10px; padding:34px; text-align:center; color:#a1a1aa; }

    .hire-overlay { position:fixed; inset:0; background:rgba(0,0,0,.6); display:flex; align-items:center; justify-content:center; z-index:1000; padding:20px; }
    .hire-modal { width:100%; max-width:520px; max-height:90vh; overflow:auto; background:#18181b; border:1px solid #27272a; border-radius:12px; padding:22px 24px; }
    .hire-modal h3 { margin:0 0 16px; color:#fafafa; font-size:16px; }
    .hire-row { margin-bottom:14px; }
    .hire-row label { display:block; color:#a1a1aa; font-size:12px; margin-bottom:5px; }
    .hire-input, .hire-textarea, .hire-file { width:100%; box-sizing:border-box; background:#0f0f11; border:1px solid #3f3f46; border-radius:6px; color:#e4e4e7; padding:8px 10px; font-size:13px; font-family:inherit; }
    .hire-textarea { min-height:84px; resize:vertical; }
    .hire-input:focus, .hire-textarea:focus { outline:none; border-color:#3b82f6; }
    .hire-seg { display:inline-flex; border:1px solid #3f3f46; border-radius:6px; overflow:hidden; }
    .hire-seg button { background:transparent; color:#a1a1aa; border:0; padding:7px 14px; cursor:pointer; font-size:12px; font-family:inherit; }
    .hire-seg button.active { background:#3b82f6; color:#fff; }
    .hire-checks { display:flex; flex-direction:column; gap:8px; max-height:240px; overflow:auto; }
    .hire-check { display:flex; align-items:center; gap:9px; padding:8px 10px; border:1px solid #27272a; border-radius:6px; cursor:pointer; }
    .hire-check input { accent-color:#3b82f6; }
    .hire-modal-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:18px; }
    .hire-err { color:#fca5a5; font-size:12px; margin-top:8px; min-height:16px; }
    .hire-toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%); background:#27272a; color:#fafafa; border:1px solid #3f3f46; padding:10px 16px; border-radius:8px; font-size:13px; z-index:1100; opacity:0; transition:opacity .2s; }
    .hire-toast.show { opacity:1; }
    .hire-toast.err { border-color:#7f1d1d; }

    .hire-modal.lg { max-width:640px; }
    .hire-cand-list { display:flex; flex-direction:column; gap:10px; }
    .hire-cand { border:1px solid #27272a; background:#0f0f11; border-radius:8px; padding:12px 14px; }
    .hire-cand-top { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; }
    .hire-cand h4 { margin:0; font-size:14px; color:#fafafa; font-weight:600; }
    .hire-cand-meta { color:#a1a1aa; font-size:12px; margin-top:2px; }
    .hire-cand-skills { color:#d4d4d8; font-size:12px; margin-top:6px; }
    .hire-stage { display:inline-block; padding:2px 9px; border-radius:999px; font-size:11px; font-weight:600; white-space:nowrap; background:#27272a; color:#d4d4d8; }
    .hire-stage.sourced, .hire-stage.panel_review { background:#1e3a8a33; color:#93c5fd; }
    .hire-stage.tech_round, .hire-stage.hr_round, .hire-stage.accepted, .hire-stage.hired { background:#14532d33; color:#86efac; }
    .hire-stage.rejected, .hire-stage.withdrawn { background:#7f1d1d33; color:#fca5a5; }
    .hire-ex { font-size:11px; color:#71717a; margin-top:6px; }
    .hire-ex.failed { color:#fca5a5; }
    .hire-cand-actions { margin-top:10px; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .hire-btn.ok { background:#16a34a; border-color:#16a34a; }
    .hire-btn.ok:hover:not(:disabled) { background:#15803d; border-color:#15803d; }
    .hire-btn.danger { background:transparent; color:#fca5a5; border-color:#7f1d1d; }
    .hire-btn.danger:hover:not(:disabled) { background:#7f1d1d33; }

    .hire-sel { background:#0f0f11; border:1px solid #3f3f46; border-radius:6px; color:#e4e4e7; padding:8px 10px; font-size:13px; font-family:inherit; cursor:pointer; }
    .hire-sel:focus { outline:none; border-color:#3b82f6; }
    .hire-dt { display:flex; gap:8px; flex-wrap:wrap; }
    .hire-dt .hire-sel { flex:1; min-width:64px; }
    .hire-lock { color:#a1a1aa; font-size:12px; background:#0f0f11; border:1px dashed #3f3f46; border-radius:6px; padding:11px 13px; }
    .hire-steps { display:flex; align-items:center; flex-wrap:wrap; row-gap:8px; margin-top:10px; }
    .hire-step { display:inline-flex; align-items:center; gap:6px; }
    .hire-step-dot { width:20px; height:20px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; background:#27272a; color:#a1a1aa; border:1px solid #3f3f46; flex-shrink:0; }
    .hire-step-lbl { font-size:11px; color:#71717a; white-space:nowrap; }
    .hire-step.done .hire-step-dot { background:#14532d33; color:#86efac; border-color:#14532d; }
    .hire-step.done .hire-step-lbl { color:#a1a1aa; }
    .hire-step.cur .hire-step-dot { background:#3b82f6; color:#fff; border-color:#3b82f6; box-shadow:0 0 0 3px #3b82f633; }
    .hire-step.cur .hire-step-lbl { color:#e4e4e7; font-weight:600; }
    .hire-step.rej .hire-step-dot { background:#7f1d1d33; color:#fca5a5; border-color:#7f1d1d; }
    .hire-step.rej .hire-step-lbl { color:#fca5a5; }
    .hire-step-sep { flex:0 0 16px; height:2px; background:#3f3f46; margin:0 5px; }
    .hire-step-sep.on { background:#14532d; }
  `;

  function ensureStyles() {
    if (document.getElementById(STYLE_ID)) return;
    document.head.appendChild(el('style', { id: STYLE_ID, html: styles }));
  }

  let _toastTimer = null;
  function toast(msg, isErr) {
    let t = document.getElementById('hireToast');
    if (!t) { t = el('div', { id: 'hireToast', class: 'hire-toast' }); document.body.appendChild(t); }
    t.textContent = msg;
    t.className = 'hire-toast show' + (isErr ? ' err' : '');
    if (_toastTimer) clearTimeout(_toastTimer);
    _toastTimer = setTimeout(() => { t.className = 'hire-toast'; }, 3200);
  }

  function modal(node, opts) {
    opts = opts || {};
    // noBackdropClose: the interview modal opts out of click-outside-to-close so a stray
    // backdrop click (or moving focus) can't discard an in-progress interview.
    const overlay = el('div', { class: 'hire-overlay', onclick: (e) => { if (e.target === overlay && !opts.noBackdropClose) overlay.remove(); } });
    overlay.appendChild(node);
    document.body.appendChild(overlay);
    return overlay;
  }

  // ── Create JD modal ─────────────────────────────────────────────────────────
  function openCreateModal(onDone) {
    let source = 'form';
    const errEl = el('div', { class: 'hire-err' });

    const title = el('input', { class: 'hire-input', type: 'text', placeholder: 'e.g. Senior React Developer', maxlength: '200' });
    const desc = el('textarea', { class: 'hire-textarea', placeholder: 'Role summary and responsibilities…', maxlength: '5000' });
    const skills = el('textarea', { class: 'hire-textarea', placeholder: 'React, TypeScript, REST APIs…', maxlength: '2000' });
    const exp = el('input', { class: 'hire-input', type: 'text', placeholder: 'e.g. 3-5 years', maxlength: '120' });
    const salary = el('input', { class: 'hire-input', type: 'text', placeholder: 'e.g. ₹12-18 LPA', maxlength: '120' });
    const file = el('input', { class: 'hire-file', type: 'file', accept: 'application/pdf' });

    const formFields = el('div', {},
      el('div', { class: 'hire-row' }, el('label', {}, 'Description *'), desc),
      el('div', { class: 'hire-row' }, el('label', {}, 'Required skills'), skills),
      el('div', { class: 'hire-row' }, el('label', {}, 'Experience level'), exp),
      el('div', { class: 'hire-row' }, el('label', {}, 'Salary range'), salary),
    );
    const pdfFields = el('div', { style: { display: 'none' } },
      el('div', { class: 'hire-row' }, el('label', {}, 'Job description PDF *'), file),
    );

    const segForm = el('button', { class: 'active', onclick: () => setSource('form') }, 'Use template');
    const segPdf = el('button', { onclick: () => setSource('pdf') }, 'Upload PDF');
    function setSource(s) {
      source = s;
      segForm.classList.toggle('active', s === 'form');
      segPdf.classList.toggle('active', s === 'pdf');
      formFields.style.display = s === 'form' ? '' : 'none';
      pdfFields.style.display = s === 'pdf' ? '' : 'none';
    }

    const saveBtn = el('button', { class: 'hire-btn', onclick: submit }, 'Save job description');

    async function submit() {
      errEl.textContent = '';
      const t = title.value.trim();
      if (!t) { errEl.textContent = 'Add a job title.'; return; }
      if (source === 'form' && !desc.value.trim()) { errEl.textContent = 'Add a description (or switch to Upload PDF).'; return; }
      if (source === 'pdf' && !file.files[0]) { errEl.textContent = 'Choose a PDF to upload.'; return; }

      const fd = new FormData();
      fd.append('title', t);
      fd.append('source_type', source);
      if (source === 'form') {
        fd.append('description', desc.value.trim());
        if (skills.value.trim()) fd.append('required_skills', skills.value.trim());
        if (exp.value.trim()) fd.append('experience_level', exp.value.trim());
        if (salary.value.trim()) fd.append('salary_range', salary.value.trim());
      } else {
        fd.append('jd_file', file.files[0]);
        // carry optional template metadata even on a PDF JD
        if (desc.value.trim()) fd.append('description', desc.value.trim());
        if (skills.value.trim()) fd.append('required_skills', skills.value.trim());
        if (exp.value.trim()) fd.append('experience_level', exp.value.trim());
        if (salary.value.trim()) fd.append('salary_range', salary.value.trim());
      }

      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving…';
      try {
        await apiForm('/hiring/job-descriptions', fd);
        overlay.remove();
        toast('Job description saved. HR notified.');
        onDone();
      } catch (e) {
        errEl.textContent = e.message;
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save job description';
      }
    }

    const card = el('div', { class: 'hire-modal' },
      el('h3', {}, 'New job description'),
      el('div', { class: 'hire-row' }, el('label', {}, 'Job title *'), title),
      el('div', { class: 'hire-row' }, el('label', {}, 'How would you like to add it?'), el('div', { class: 'hire-seg' }, segForm, segPdf)),
      formFields,
      pdfFields,
      errEl,
      el('div', { class: 'hire-modal-actions' },
        el('button', { class: 'hire-btn ghost', onclick: () => overlay.remove() }, 'Cancel'),
        saveBtn,
      ),
    );
    const overlay = modal(card);
  }

  // ── Assign recruiters modal ─────────────────────────────────────────────────
  function openAssignModal(jd, freelancers, onDone) {
    const errEl = el('div', { class: 'hire-err' });
    const assignedIds = new Set((jd.recruiters || []).map((r) => r.id));

    if (!freelancers.length) {
      modal(el('div', { class: 'hire-modal' },
        el('h3', {}, 'Assign recruiters'),
        el('p', { style: { color: '#a1a1aa' } }, 'No freelance recruiters exist yet. Add them under Team with the “Freelance Recruiter” role first.'),
        el('div', { class: 'hire-modal-actions' }, el('button', { class: 'hire-btn ghost', onclick: (e) => e.target.closest('.hire-overlay').remove() }, 'Close')),
      ));
      return;
    }

    const checks = freelancers.map((f) => {
      const cb = el('input', { type: 'checkbox', value: f.id });
      if (assignedIds.has(f.id)) { cb.checked = true; }
      return el('label', { class: 'hire-check' }, cb, el('span', {}, f.name), assignedIds.has(f.id) ? el('span', { style: { color: '#71717a', 'font-size': '11px', 'margin-left': 'auto' } }, 'already assigned') : null);
    });

    const saveBtn = el('button', { class: 'hire-btn', onclick: submit }, 'Assign');

    async function submit() {
      errEl.textContent = '';
      const ids = checks.map((c) => c.querySelector('input')).filter((i) => i.checked).map((i) => Number(i.value));
      if (!ids.length) { errEl.textContent = 'Pick at least one recruiter.'; return; }
      saveBtn.disabled = true;
      saveBtn.textContent = 'Assigning…';
      try {
        const r = await api('/hiring/job-descriptions/' + jd.id + '/assign', { method: 'POST', body: { recruiter_ids: ids } });
        overlay.remove();
        toast(r.message || 'Assigned.');
        onDone();
      } catch (e) {
        errEl.textContent = e.message;
        saveBtn.disabled = false;
        saveBtn.textContent = 'Assign';
      }
    }

    const card = el('div', { class: 'hire-modal' },
      el('h3', {}, 'Assign “' + jd.title + '”'),
      el('div', { class: 'hire-checks' }, ...checks),
      errEl,
      el('div', { class: 'hire-modal-actions' },
        el('button', { class: 'hire-btn ghost', onclick: () => overlay.remove() }, 'Cancel'),
        saveBtn,
      ),
    );
    const overlay = modal(card);
  }

  // ── Add-candidate modal (freelancer) ────────────────────────────────────────
  function openAddCandidateModal(jd, onDone) {
    const errEl = el('div', { class: 'hire-err' });
    const file = el('input', { class: 'hire-file', type: 'file', accept: '.pdf,.doc,.docx,application/pdf' });
    const name = el('input', { class: 'hire-input', type: 'text', placeholder: '(optional) candidate name', maxlength: '150' });
    const email = el('input', { class: 'hire-input', type: 'email', placeholder: '(optional) email', maxlength: '150' });
    const phone = el('input', { class: 'hire-input', type: 'text', placeholder: '(optional) phone', maxlength: '40' });
    const saveBtn = el('button', { class: 'hire-btn', onclick: submit }, 'Upload candidate');

    async function submit() {
      errEl.textContent = '';
      if (!file.files[0]) { errEl.textContent = 'Choose a résumé (PDF or Word).'; return; }
      const fd = new FormData();
      fd.append('resume', file.files[0]);
      if (name.value.trim()) fd.append('name', name.value.trim());
      if (email.value.trim()) fd.append('email', email.value.trim());
      if (phone.value.trim()) fd.append('phone', phone.value.trim());
      saveBtn.disabled = true; saveBtn.textContent = 'Uploading…';
      try {
        const r = await apiForm('/hiring/job-descriptions/' + jd.id + '/candidates', fd);
        overlay.remove();
        toast(r.duplicate_warning || 'Candidate added. Hiring team notified.', !!r.duplicate_warning);
        onDone();
      } catch (e) {
        errEl.textContent = e.message; saveBtn.disabled = false; saveBtn.textContent = 'Upload candidate';
      }
    }

    const card = el('div', { class: 'hire-modal' },
      el('h3', {}, 'Add candidate'),
      el('div', { class: 'hire-row' }, el('label', {}, 'Résumé (PDF or Word) *'), file),
      el('p', { style: { color: '#71717a', 'font-size': '12px', margin: '-6px 0 12px' } }, 'Tessa auto-reads the name, email, phone, experience and skills from the résumé.'),
      el('div', { class: 'hire-row' }, el('label', {}, 'Name'), name),
      el('div', { class: 'hire-row' }, el('label', {}, 'Email'), email),
      el('div', { class: 'hire-row' }, el('label', {}, 'Phone'), phone),
      errEl,
      el('div', { class: 'hire-modal-actions' },
        el('button', { class: 'hire-btn ghost', onclick: () => overlay.remove() }, 'Cancel'),
        saveBtn,
      ),
    );
    const overlay = modal(card);
  }

  // ── Candidate review ────────────────────────────────────────────────────────
  async function reviewCandidate(c, action, reason, reload) {
    try {
      const r = await api('/hiring/candidates/' + c.id + '/review', { method: 'POST', body: { action, reason } });
      toast(r.message || 'Done.');
      reload();
    } catch (e) { toast(e.message, true); }
  }

  function promptReject(c, reload) {
    const reason = window.prompt('Reason for rejecting ' + (c.name || 'this candidate') + '?');
    if (reason == null) return;
    if (!reason.trim()) { toast('A reason is required to reject.', true); return; }
    reviewCandidate(c, 'reject', reason.trim(), reload);
  }

  // ── Pipeline stepper (which round a candidate has cleared) ──
  // Derived purely from candidate.stage + interview outcomes — no schema change. Several
  // backend stages collapse into one visible step (accepted/provisioning/offer → "Offer",
  // onboarding/hired → "Hired").
  const PIPELINE = [
    { key: 'sourced', label: 'Sourced' },
    { key: 'panel_review', label: 'Panel' },
    { key: 'tech_round', label: 'Technical' },
    { key: 'hr_round', label: 'HR' },
    { key: 'offer', label: 'Offer' },
    { key: 'hired', label: 'Hired' },
  ];
  const STAGE_STEP = { sourced: 0, panel_review: 1, tech_round: 2, hr_round: 3, accepted: 4, provisioning: 4, offer: 4, onboarding: 5, hired: 5 };
  // For a rejected/withdrawn candidate, infer where they stopped from interview outcomes.
  function rejectedStopStep(c) {
    const ivs = c.interviews || [];
    const hr = ivs.find((i) => i.round === 'hr');
    const tech = ivs.find((i) => i.round === 'technical');
    if (hr && hr.outcome === 'failed') return 3;
    if (tech && tech.outcome === 'failed') return 2;
    return 1;
  }
  function renderStepper(c) {
    const rejected = c.stage === 'rejected' || c.stage === 'withdrawn';
    const cur = rejected ? rejectedStopStep(c) : (STAGE_STEP[c.stage] != null ? STAGE_STEP[c.stage] : 0);
    const wrap = el('div', { class: 'hire-steps' });
    PIPELINE.forEach((st, i) => {
      if (i > 0) wrap.appendChild(el('span', { class: 'hire-step-sep' + (i <= cur ? ' on' : '') }));
      let cls = 'hire-step', mark = String(i + 1);
      if (rejected && i === cur) { cls += ' rej'; mark = '✕'; }
      else if (i < cur) { cls += ' done'; mark = '✓'; }
      else if (i === cur && !rejected) { cls += ' cur'; }
      wrap.appendChild(el('span', { class: cls }, el('span', { class: 'hire-step-dot' }, mark), el('span', { class: 'hire-step-lbl' }, st.label)));
    });
    return wrap;
  }

  function candidateRow(c, data, reload, jd) {
    const meta = [];
    if (c.email) meta.push(c.email);
    if (c.phone) meta.push(c.phone);
    if (c.experience_years != null) meta.push(c.experience_years + ' yrs exp');

    const actions = [];
    if (c.resume_url) actions.push(el('a', { class: 'hire-link', href: c.resume_url, target: '_blank', rel: 'noopener' }, '📄 Open résumé'));
    if (c.can_approve && (c.stage === 'sourced' || c.stage === 'panel_review')) {
      actions.push(el('button', { class: 'hire-btn ok sm', onclick: () => reviewCandidate(c, 'approve', null, reload) }, 'Approve'));
      actions.push(el('button', { class: 'hire-btn danger sm', onclick: () => promptReject(c, reload) }, 'Reject'));
    }
    if (c.can_approve && (c.stage === 'tech_round' || c.stage === 'hr_round')) {
      actions.push(el('button', { class: 'hire-btn sm', onclick: () => openInterviewModal(c, data, reload) },
        c.stage === 'hr_round' ? 'HR interview' : 'Technical interview'));
    }
    if (c.can_approve && ['accepted', 'provisioning', 'offer', 'hired'].indexOf(c.stage) !== -1) {
      actions.push(el('button', { class: 'hire-btn sm', onclick: () => openProvisioningModal(c, data, reload, jd) }, 'Provisioning & Offer'));
    }
    // Offer issued, awaiting the candidate's reply — HR fallback if Gmail didn't catch it.
    if (c.can_approve && c.stage === 'offer' && !c.offer_accepted) {
      actions.push(el('button', { class: 'hire-btn ghost sm', onclick: () => markAcceptedAction(c, reload) }, '✅ Mark accepted'));
    }
    // Accepted → the next step is adding them to the team (prefilled form).
    if (c.can_approve && c.stage === 'offer' && c.offer_accepted && !c.hired_user_id) {
      actions.push(el('button', { class: 'hire-btn sm', onclick: () => addToTeamMember(c, jd) }, '➕ Add to Team'));
    }

    const exNote = c.extraction_status === 'failed'
      ? 'Tessa could not read this résumé — open it to review manually.'
      : (c.extraction_status === 'pending' ? 'Extraction pending…' : '');

    // Technical-round panel feedback, surfaced read-only so HR sees it for every
    // candidate in the list — including rejected ones (Feature 9A).
    const techFeedback = (c.interviews || []).find((i) => i.round === 'technical' && i.feedback);

    return el('div', { class: 'hire-cand' },
      el('div', { class: 'hire-cand-top' },
        el('div', {},
          el('h4', {}, c.name || 'Unnamed candidate'),
          meta.length ? el('div', { class: 'hire-cand-meta' }, meta.join(' · ')) : null,
        ),
        el('span', { class: 'hire-stage ' + c.stage }, STAGE_LABEL[c.stage] || c.stage),
      ),
      renderStepper(c),
      c.skills ? el('div', { class: 'hire-cand-skills' }, '🛠 ' + c.skills) : null,
      c.offer_accepted ? el('div', { class: 'hire-cand-meta', style: { color: '#86efac', 'margin-top': '4px' } }, '✅ Offer accepted' + (c.offer_accepted_via === 'auto' ? ' (detected from Gmail)' : '')) : null,
      c.rejected_reason ? el('div', { class: 'hire-cand-meta', style: { color: '#fca5a5' } }, 'Reason: ' + c.rejected_reason) : null,
      techFeedback ? el('div', { class: 'hire-cand-meta', style: { 'white-space': 'pre-wrap', 'margin-top': '4px' } }, '🗒 Panel feedback (technical): ' + techFeedback.feedback) : null,
      exNote ? el('div', { class: 'hire-ex' + (c.extraction_status === 'failed' ? ' failed' : '') }, exNote) : null,
      actions.length ? el('div', { class: 'hire-cand-actions' }, ...actions) : null,
    );
  }

  // ── Candidates modal (HR/panel review + freelancer's own uploads) ────────────
  function openCandidatesModal(jd, data, reloadJds) {
    const body = el('div', { class: 'hire-cand-list' });
    const addBtn = jd.can_upload
      ? el('button', { class: 'hire-btn', onclick: () => openAddCandidateModal(jd, load) }, '+ Add candidate')
      : null;

    const card = el('div', { class: 'hire-modal lg' },
      el('div', { style: { display: 'flex', 'justify-content': 'space-between', 'align-items': 'center', gap: '12px', 'margin-bottom': '14px' } },
        el('h3', { style: { margin: 0 } }, 'Candidates — ' + jd.title),
        addBtn,
      ),
      body,
      el('div', { class: 'hire-modal-actions' },
        el('button', { class: 'hire-btn ghost', onclick: () => { overlay.remove(); if (reloadJds) reloadJds(); } }, 'Close'),
      ),
    );
    const overlay = modal(card);

    async function load() {
      body.innerHTML = '';
      body.appendChild(el('div', { style: { color: '#a1a1aa' } }, 'Loading…'));
      let res;
      try { res = await api('/hiring/job-descriptions/' + jd.id + '/candidates'); }
      catch (e) { body.innerHTML = ''; body.appendChild(el('div', { class: 'hire-empty' }, e.message)); return; }
      body.innerHTML = '';
      if (!res.candidates.length) {
        body.appendChild(el('div', { class: 'hire-empty' }, jd.can_upload ? 'No candidates yet. Add your first.' : 'No candidates uploaded yet.'));
        return;
      }
      res.candidates.forEach((c) => body.appendChild(candidateRow(c, data, load, jd)));
    }
    load();
  }

  // ── Interview modal (stages 5–6) ─────────────────────────────────────────────
  function gmailComposeUrl(to, subject, bodyText) {
    return 'https://mail.google.com/mail/?view=cm&fs=1'
      + '&to=' + encodeURIComponent(to || '')
      + '&su=' + encodeURIComponent(subject || '')
      + '&body=' + encodeURIComponent(bodyText || '');
  }

  function openInterviewModal(candidate, data, reloadList) {
    const round = candidate.stage === 'hr_round' ? 'hr' : 'technical';
    const roundLabel = round === 'hr' ? 'HR' : 'Technical';
    const existing = (candidate.interviews || []).find((i) => i.round === round) || {};
    const prev = round === 'hr' ? (candidate.interviews || []).find((i) => i.round === 'technical') : null;
    const errEl = el('div', { class: 'hire-err' });

    // Draft restore: a local (unsaved) draft from a closed/discarded session wins over the
    // server copy only when newer. We clear it once the server confirms a save, so a local
    // draft only lingers when a save never completed.
    const DRAFT_KEY = 'hire_draft:' + candidate.id + ':' + round;
    function readLocalDraft() {
      try { const o = JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null'); return (o && o.payload) ? o : null; } catch (e) { return null; }
    }
    function clearLocalDraft() { try { localStorage.removeItem(DRAFT_KEY); } catch (e) {} }
    function writeLocalDraft() {
      try { localStorage.setItem(DRAFT_KEY, JSON.stringify({ savedAt: Date.now(), payload: buildPayload() })); } catch (e) {}
    }
    const _local = readLocalDraft();
    const _serverTs = existing.updated_at ? Date.parse(existing.updated_at) : 0;
    const init = (_local && _local.savedAt > _serverTs) ? Object.assign({}, existing, _local.payload) : existing;

    // ── Date & time — friendly dropdowns (Day/Month/Year + Hour/Minute). `when` is the
    // hidden source of truth (YYYY-MM-DDTHH:MM) that draft()/save()/slots read. ──
    const when = el('input', { type: 'hidden' });
    when.value = init.scheduled_at ? String(init.scheduled_at).slice(0, 16) : '';
    const initDate = when.value ? when.value.slice(0, 10) : '';
    const initTime = when.value ? when.value.slice(11, 16) : '';
    const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const thisYear = new Date().getFullYear();
    function mkOpt(v, label) { return el('option', { value: v }, label); }
    const daySel = el('select', { class: 'hire-sel' }, mkOpt('', 'Day'));
    for (let d = 1; d <= 31; d++) { const v = (d < 10 ? '0' : '') + d; daySel.appendChild(mkOpt(v, String(d))); }
    const monthSel = el('select', { class: 'hire-sel' }, mkOpt('', 'Month'));
    MONTHS.forEach((m, i) => monthSel.appendChild(mkOpt((i < 9 ? '0' : '') + (i + 1), m)));
    const yearSel = el('select', { class: 'hire-sel' }, mkOpt('', 'Year'));
    for (let y = thisYear; y <= thisYear + 1; y++) yearSel.appendChild(mkOpt(String(y), String(y)));
    const hourSel = el('select', { class: 'hire-sel' }, mkOpt('', 'Hr'));
    for (let h = 9; h <= 18; h++) { const v = (h < 10 ? '0' : '') + h; hourSel.appendChild(mkOpt(v, v)); }
    const minSel = el('select', { class: 'hire-sel' }, mkOpt('', 'Min'));
    ['00', '15', '30', '45'].forEach((m) => minSel.appendChild(mkOpt(m, m)));
    if (initDate) { yearSel.value = initDate.slice(0, 4); monthSel.value = initDate.slice(5, 7); daySel.value = initDate.slice(8, 10); }
    if (initTime) { hourSel.value = initTime.slice(0, 2); minSel.value = initTime.slice(3, 5); }
    function dateValue() { return (daySel.value && monthSel.value && yearSel.value) ? (yearSel.value + '-' + monthSel.value + '-' + daySel.value) : ''; }
    function timeValue() { return hourSel.value ? (hourSel.value + ':' + (minSel.value || '00')) : ''; }
    function composeWhen() { const dv = dateValue(), tv = timeValue(); when.value = (dv && tv) ? (dv + 'T' + tv) : ''; }
    const dateRow = el('div', { class: 'hire-dt' }, daySel, monthSel, yearSel);
    const timeRow = el('div', { class: 'hire-dt' }, hourSel, minSel);

    // Calendar slot picker — pick a date, click a free 1-hour slot (busy = from Google
    // Calendar). Clicking a slot fills the Hour/Minute dropdowns. Manual fallback otherwise.
    const slotsBox = el('div', { style: { display: 'flex', 'flex-wrap': 'wrap', gap: '6px', 'margin-top': '6px' } });
    const slotsNote = el('div', { class: 'hire-cand-meta', style: { 'margin-top': '4px' } }, 'Pick a date to see 1-hour slots.');
    async function loadSlots() {
      const dv = dateValue();
      if (!dv) { slotsBox.textContent = ''; slotsNote.textContent = 'Pick a date to see 1-hour slots.'; return; }
      slotsBox.textContent = ''; slotsNote.textContent = 'Loading slots…';
      try {
        const r = await api('/hiring/calendar-slots?date=' + encodeURIComponent(dv));
        slotsNote.textContent = r.calendar_connected
          ? 'Busy slots are from your Google Calendar — click a free slot.'
          : 'Connect Google for live availability. Click a slot to set the time.';
        slotsBox.textContent = '';
        (r.slots || []).forEach(function (s) {
          const selected = when.value === (dv + 'T' + s.start);
          const btn = el('button', {
            type: 'button',
            class: 'hire-btn sm' + (selected ? ' ok' : ' ghost'),
            style: s.busy ? { opacity: '0.45' } : {},
            title: s.busy ? 'Busy on your calendar' : 'Free',
            onclick: function () {
              if (s.busy && !confirm('That slot is busy on your calendar. Use it anyway?')) return;
              hourSel.value = s.start.slice(0, 2);
              minSel.value = (['00', '15', '30', '45'].indexOf(s.start.slice(3, 5)) !== -1) ? s.start.slice(3, 5) : '00';
              composeWhen(); renderGate(); loadSlots(); scheduleDraftSave();
            },
          }, s.label + (s.busy ? ' • busy' : ''));
          slotsBox.appendChild(btn);
        });
      } catch (e) { slotsNote.textContent = 'Could not load slots — set the hour/minute below.'; }
    }
    [daySel, monthSel, yearSel].forEach((n) => n.addEventListener('change', function () { composeWhen(); renderGate(); loadSlots(); scheduleDraftSave(); }));
    [hourSel, minSel].forEach((n) => n.addEventListener('change', function () { composeWhen(); renderGate(); scheduleDraftSave(); }));

    const meet = el('input', { class: 'hire-input', type: 'url', placeholder: 'https://meet.google.com/…', value: init.meet_link || '' });
    const subject = el('input', { class: 'hire-input', type: 'text', placeholder: 'Email subject', value: init.email_subject || '' });
    const body = el('textarea', { class: 'hire-textarea', style: { 'min-height': '160px' } }, init.email_body || '');
    const sent = el('input', { type: 'checkbox' });
    if (init.email_status === 'sent') sent.checked = true;
    const recording = el('input', { class: 'hire-input', type: 'url', placeholder: 'https://… recording link', value: init.recording_link || '' });
    // Panel feedback — editable only for the technical round. On the HR round the technical
    // feedback is shown read-only via `prev` below.
    const feedbackEl = round === 'technical'
      ? el('textarea', { class: 'hire-textarea', style: { 'min-height': '90px' }, placeholder: 'How did the candidate perform? Strengths, weaknesses, overall assessment… (min 50 characters)' }, init.feedback || '')
      : null;

    const draftBtn = el('button', { class: 'hire-btn ghost sm', onclick: draft }, '✨ Draft with AI');
    async function draft() {
      errEl.textContent = '';
      draftBtn.disabled = true; draftBtn.textContent = 'Drafting…';
      try {
        const r = await api('/hiring/candidates/' + candidate.id + '/interviews/draft', {
          method: 'POST', body: { round, scheduled_at: when.value || null, meet_link: meet.value || null },
        });
        subject.value = r.subject || subject.value;
        body.value = r.body || body.value;
        scheduleDraftSave();
      } catch (e) { errEl.textContent = e.message; }
      draftBtn.disabled = false; draftBtn.textContent = '✨ Draft with AI';
    }

    const copyBtn = el('button', { class: 'hire-btn ghost sm', onclick: () => {
      const text = (subject.value ? 'Subject: ' + subject.value + '\n\n' : '') + body.value;
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(() => toast('Email copied.'));
    } }, 'Copy');
    const gmailBtn = el('button', { class: 'hire-btn ghost sm', onclick: () => {
      window.open(gmailComposeUrl(candidate.email, subject.value, body.value), '_blank', 'noopener');
    } }, 'Open in Gmail');

    // Payload shared by the manual Save and the debounced auto-save. `email_status`
    // tracks the checkbox, so auto-save never flips an email sent↔unsent.
    function buildPayload(extra) {
      return Object.assign({
        round,
        scheduled_at: when.value || null,
        meet_link: meet.value || null,
        email_subject: subject.value || null,
        email_body: body.value || null,
        email_status: sent.checked ? 'sent' : 'draft',
        recording_link: recording.value || null,
        feedback: feedbackEl ? feedbackEl.value : undefined,
      }, extra || {});
    }
    // Keep the in-memory candidate in sync so reopening the modal restores the draft
    // (it reads `existing` from candidate.interviews).
    function mergeInterview(iv) {
      if (!iv || !iv.round) return;
      candidate.interviews = candidate.interviews || [];
      const idx = candidate.interviews.findIndex((i) => i.round === iv.round);
      if (idx >= 0) candidate.interviews[idx] = iv; else candidate.interviews.push(iv);
    }

    // Auto-save what HR types as a draft (debounced) so nothing is lost. Silent: no toast,
    // and `silent:true` tells the server to skip the audit row. We also mirror every edit
    // to localStorage synchronously so a tab discard before the debounce loses nothing.
    const draftStatus = el('span', { class: 'hire-cand-meta', style: { 'margin-left': '8px', 'align-self': 'center' } }, '');
    let draftTimer = null, draftInFlight = false, draftPending = false;
    const snapshot = () => JSON.stringify(buildPayload());
    let lastSavedSnapshot = snapshot();
    const pad2 = (n) => (n < 10 ? '0' : '') + n;
    async function saveDraft() {
      const snap = snapshot();
      if (snap === lastSavedSnapshot) return;             // nothing changed
      if (draftInFlight) { draftPending = true; return; } // serialize concurrent edits
      draftInFlight = true;
      draftStatus.textContent = 'Saving draft…';
      try {
        const r = await api('/hiring/candidates/' + candidate.id + '/interviews', { method: 'POST', body: buildPayload({ silent: true }) });
        lastSavedSnapshot = snap;
        mergeInterview(r && r.interview);
        clearLocalDraft();
        const d = new Date();
        draftStatus.textContent = 'Draft saved ✓ ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
      } catch (e) {
        draftStatus.textContent = 'Couldn’t save draft (kept on this device)';
      }
      draftInFlight = false;
      if (draftPending) { draftPending = false; scheduleDraftSave(50); }
    }
    function scheduleDraftSave(delay) {
      writeLocalDraft();   // instant local mirror — survives a tab discard before the debounce
      if (draftTimer) clearTimeout(draftTimer);
      draftTimer = setTimeout(() => { draftTimer = null; saveDraft(); }, delay == null ? 750 : delay);
    }
    function flushDraft() {
      if (draftTimer) { clearTimeout(draftTimer); draftTimer = null; }
      saveDraft();
    }
    [meet, subject, body, recording, feedbackEl].forEach((node) => {
      if (node) node.addEventListener('input', () => scheduleDraftSave());
    });
    sent.addEventListener('change', () => scheduleDraftSave());

    // Don't lose work when the tab is hidden/closed (Chrome may discard a backgrounded tab):
    // flush to the server on hide. The Close button / Esc are the only ways to dismiss the
    // modal (backdrop click is disabled below).
    function onVisibility() { if (document.hidden) flushDraft(); }
    function onUnload() { writeLocalDraft(); }
    function onKey(e) { if (e.key === 'Escape') closeModal(); }
    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('beforeunload', onUnload);
    document.addEventListener('keydown', onKey);
    function teardown() {
      document.removeEventListener('visibilitychange', onVisibility);
      window.removeEventListener('beforeunload', onUnload);
      document.removeEventListener('keydown', onKey);
    }
    function closeModal() { flushDraft(); teardown(); overlay.remove(); }

    const saveBtn = el('button', { class: 'hire-btn', onclick: save }, 'Save');
    async function save() {
      errEl.textContent = '';
      saveBtn.disabled = true; saveBtn.textContent = 'Saving…';
      try {
        const r = await api('/hiring/candidates/' + candidate.id + '/interviews', { method: 'POST', body: buildPayload() });
        lastSavedSnapshot = snapshot();
        mergeInterview(r && r.interview);
        clearLocalDraft();
        toast('Interview saved.');
      } catch (e) { errEl.textContent = e.message; }
      saveBtn.disabled = false; saveBtn.textContent = 'Save';
    }

    async function outcome(value) {
      errEl.textContent = '';
      flushDraft();
      if (!when.value || new Date(when.value).getTime() > Date.now()) {
        errEl.textContent = 'You can record the decision only after the interview time.';
        return;
      }
      if (round === 'technical') {
        const fb = ((feedbackEl && feedbackEl.value) || '').trim();
        if (fb.length < 50) {
          errEl.textContent = 'Please add panel feedback (at least 50 characters) before deciding.';
          if (feedbackEl) feedbackEl.focus();
          return;
        }
      }
      try {
        await api('/hiring/candidates/' + candidate.id + '/interviews/outcome', {
          method: 'POST',
          body: { round, outcome: value, feedback: feedbackEl ? feedbackEl.value : undefined },
        });
        clearLocalDraft();
        const accepted = value === 'passed';
        toast(round === 'technical' ? (accepted ? 'Candidate accepted.' : 'Candidate rejected.') : (accepted ? 'Marked passed.' : 'Marked failed.'));
        teardown(); overlay.remove(); reloadList();
      } catch (e) { errEl.textContent = e.message; }
    }

    const outcomeBadge = el('span', { class: 'hire-stage ' + (existing.outcome === 'passed' ? 'tech_round' : (existing.outcome === 'failed' ? 'rejected' : 'sourced')) },
      existing.outcome ? existing.outcome.charAt(0).toUpperCase() + existing.outcome.slice(1) : 'Pending');
    const outcomeRow = el('div', { class: 'hire-cand-actions' },
      el('span', { class: 'hire-field-k' }, 'Outcome:'), outcomeBadge,
      el('button', { class: 'hire-btn ok sm', onclick: () => outcome('passed') }, round === 'technical' ? '✓ Accept' : 'Passed'),
      el('button', { class: 'hire-btn danger sm', onclick: () => outcome('failed') }, round === 'technical' ? '✗ Reject' : 'Failed'),
    );
    // After a passed HR round, HR issues the probation letter to the candidate.
    const sendRow = (round === 'hr' && existing.outcome === 'passed' && data && data.is_hr)
      ? el('div', { class: 'hire-cand-actions', style: { 'margin-top': '6px' } },
          el('button', { class: 'hire-btn', onclick: () => { teardown(); overlay.remove(); issueOffer(candidate, reloadList); } }, '📄 Issue probation letter'))
      : null;
    const feedbackRow = feedbackEl
      ? el('div', { class: 'hire-row', style: { 'margin-bottom': '10px' } },
          el('label', {}, 'Panel Feedback (required — min 50 characters)'),
          feedbackEl,
          el('div', { class: 'hire-cand-meta', style: { 'margin-top': '4px' } }, 'Mention strengths, weaknesses, and your overall assessment. Visible to HR.'))
      : null;
    const recordingRow = el('div', { class: 'hire-row', style: { 'margin-bottom': '10px' } }, el('label', {}, 'Recording link'), recording);
    const jd = candidate.jd || {};
    const jdRow = round === 'technical'
      ? el('div', { class: 'hire-row' },
          el('label', {}, 'Job description (for this role)'),
          el('div', { class: 'hire-cand-meta', style: { 'white-space': 'pre-wrap', 'line-height': '1.5' } },
            jd.title ? el('div', { style: { 'font-weight': '600', color: '#e4e4e7' } }, jd.title) : null,
            jd.jd_file_url ? el('div', { style: { 'margin-top': '4px' } },
              el('a', { href: jd.jd_file_url, target: '_blank', rel: 'noopener', style: { color: '#60a5fa', 'text-decoration': 'underline' } }, '📄 Open JD')) : null,
            jd.description ? el('div', { style: { 'margin-top': '6px' } }, jd.description) : null,
            (!jd.jd_file_url && !jd.description) ? el('div', {}, 'No JD file or description on this job.') : null),
          el('div', { class: 'hire-cand-meta', style: { 'margin-top': '4px' } }, 'A link to this JD is added to the invite email when you draft it.'))
      : null;

    // Post-interview block (recording link + feedback + Accept/Reject) — gated until the
    // scheduled time has passed, so decisions are only recorded after the meeting.
    const postBox = el('div', { style: { 'border-top': '1px solid #27272a', margin: '14px 0 0', 'padding-top': '12px' } });
    function fmtWhen(v) { try { return new Date(v).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); } catch (e) { return v; } }
    function renderGate() {
      postBox.innerHTML = '';
      const passed = when.value && (new Date(when.value).getTime() <= Date.now());
      if (!passed) {
        postBox.appendChild(el('div', { class: 'hire-lock' },
          '🔒 Recording link, panel feedback and the Accept / Reject decision unlock after the interview'
          + (when.value ? ' — scheduled for ' + fmtWhen(when.value) + '.' : '.')));
        return;
      }
      postBox.appendChild(recordingRow);
      if (feedbackRow) postBox.appendChild(feedbackRow);
      postBox.appendChild(outcomeRow);
      if (sendRow) postBox.appendChild(sendRow);
    }

    const card = el('div', { class: 'hire-modal lg' },
      el('h3', {}, roundLabel + ' interview — ' + (candidate.name || 'Candidate')),
      renderStepper(candidate),
      prev ? el('div', { class: 'hire-cand-meta', style: { 'margin-top': '10px', 'margin-bottom': '10px' } },
        el('div', {}, 'Technical round: ' + (prev.outcome || 'pending')),
        prev.feedback ? el('div', { style: { 'margin-top': '4px', 'white-space': 'pre-wrap' } }, '🗒 Panel feedback: ' + prev.feedback) : null,
      ) : el('div', { style: { height: '10px' } }),
      el('div', { class: 'hire-row' }, el('label', {}, 'Interview date'), dateRow, slotsNote, slotsBox),
      el('div', { class: 'hire-row' }, el('label', {}, 'Interview time'), timeRow),
      el('div', { class: 'hire-row' }, el('label', {}, 'Google Meet link'), meet),
      jdRow,
      el('div', { class: 'hire-row' },
        el('label', { style: { display: 'flex', 'justify-content': 'space-between', 'align-items': 'center' } }, el('span', {}, 'Invite email'), draftBtn),
        subject,
        el('div', { style: { height: '8px' } }),
        body,
        el('div', { class: 'hire-cand-actions' }, copyBtn, gmailBtn,
          el('label', { style: { display: 'flex', 'align-items': 'center', gap: '6px', color: '#a1a1aa', 'font-size': '12px' } }, sent, el('span', {}, 'Email sent'))),
      ),
      errEl,
      el('div', { class: 'hire-modal-actions' },
        el('button', { class: 'hire-btn ghost', onclick: () => closeModal() }, 'Close'),
        saveBtn,
        draftStatus,
      ),
      postBox,
    );
    const overlay = modal(card, { noBackdropClose: true });
    renderGate();
    if (dateValue()) loadSlots();
  }

  // ── Provisioning & Offer modal (stages 7–8) ─────────────────────────────────
  async function issueOffer(candidate, reload) {
    try {
      const r = await api('/hiring/candidates/' + candidate.id + '/issue-offer', { method: 'POST', body: {} });
      if (window.MeetingModule && MeetingModule.switchView) MeetingModule.switchView('letters');
      if (window.LettersModule && LettersModule.composeFor) {
        setTimeout(() => LettersModule.composeFor(r.prefill || {}), 200);
      } else {
        toast('Open the Letters tab to draft the probation letter.');
      }
      if (reload) reload();
    } catch (e) { toast(e.message, true); }
  }

  // Acceptance fallback — HR marks the offer accepted when Gmail didn't catch the
  // reply. Sets the same flag the auto-detector does, which surfaces "Add to Team".
  async function markAcceptedAction(candidate, reload) {
    try {
      const r = await api('/hiring/candidates/' + candidate.id + '/mark-accepted', { method: 'POST', body: {} });
      toast(r.message || 'Marked accepted.');
      if (reload) reload();
    } catch (e) { toast(e.message, true); }
  }

  // Add to Team — hand the accepted candidate to the real Team → Add Member form,
  // prefilled (work email = the reserved firstname@innovfix.in). On submit the
  // server links the candidate, opens provisioning, and pings Fida + Yuvanesh.
  function addToTeamMember(candidate, jd) {
    const prefill = {
      candidate_id: candidate.id,
      name: candidate.name || '',
      work_email: candidate.generated_email || '',
      personal_email: candidate.email || '',
      personal_mobile: candidate.phone || '',
      designation: (jd && jd.title) || '',
    };
    // Close any open hiring modal(s) so the Team section opens ON TOP, not behind them.
    document.querySelectorAll('.hire-overlay').forEach((o) => o.remove());
    if (window.HRModule && HRModule.composeAddMemberFor) {
      HRModule.composeAddMemberFor(prefill);
    } else {
      toast('Open the Team tab to add this member.', true);
    }
  }

  function openProvisioningModal(candidate, data, reloadList, jd) {
    const prov = candidate.provisioning || {};
    const hasProv = !!candidate.provisioning;
    const accepted = !!candidate.offer_accepted;
    const badgeClass = (s) => 'hire-stage ' + (s === 'done' ? 'hired' : (s === 'partial' ? 'sourced' : 'rejected'));
    const cap = (s) => s ? s.charAt(0).toUpperCase() + s.slice(1) : 'Pending';
    const statusBadge = el('span', { class: badgeClass(prov.status) }, cap(prov.status));

    function taskRow(label, who, doneKey, canKey, task) {
      const cb = el('input', { type: 'checkbox' });
      if (prov[doneKey]) cb.checked = true;
      cb.disabled = !prov[canKey];
      cb.addEventListener('change', async () => {
        try {
          const r = await api('/hiring/candidates/' + candidate.id + '/provisioning/mark', { method: 'POST', body: { task, done: cb.checked } });
          const np = (r.candidate && r.candidate.provisioning) || {};
          statusBadge.textContent = cap(np.status);
          statusBadge.className = badgeClass(np.status);
          toast('Updated.');
          if (reloadList) reloadList();
        } catch (e) { cb.checked = !cb.checked; toast(e.message, true); }
      });
      return el('label', { class: 'hire-check' }, cb, el('span', {}, label + ' '),
        el('span', { class: 'hire-field-k' }, who),
        !prov[canKey] ? el('span', { style: { 'margin-left': 'auto', color: '#71717a', 'font-size': '11px' } }, 'view only') : null);
    }

    const offerBtn = (data && data.is_hr && (candidate.stage === 'provisioning' || candidate.stage === 'offer'))
      ? el('button', { class: 'hire-btn', onclick: () => { overlay.remove(); issueOffer(candidate, reloadList); } }, '📄 Issue probation letter')
      : null;
    const acceptBtn = (data && data.is_hr && candidate.stage === 'offer' && !accepted)
      ? el('button', { class: 'hire-btn ghost', onclick: () => { overlay.remove(); markAcceptedAction(candidate, reloadList); } }, '✅ Mark accepted')
      : null;
    const addBtn = (data && data.is_hr && candidate.stage === 'offer' && accepted && !candidate.hired_user_id)
      ? el('button', { class: 'hire-btn', onclick: () => { overlay.remove(); addToTeamMember(candidate, jd); } }, '➕ Add to Team')
      : null;
    const stageNote = candidate.stage === 'onboarding'
      ? el('div', { class: 'hire-cand-meta', style: { color: '#86efac', 'margin-bottom': '10px' } }, 'Tessa account created — the new hire is completing their onboarding checklist.')
      : (candidate.stage === 'hired' ? el('div', { class: 'hire-cand-meta', style: { color: '#86efac', 'margin-bottom': '10px' } }, '✅ Hired — onboarding complete.') : null);
    const acceptedNote = (accepted && candidate.stage === 'offer')
      ? el('div', { class: 'hire-cand-meta', style: { color: '#86efac', 'margin-bottom': '10px' } }, '✅ Offer accepted' + (candidate.offer_accepted_via === 'auto' ? ' (detected from Gmail)' : '') + ' — add them to the team.')
      : null;

    const card = el('div', { class: 'hire-modal' },
      el('h3', {}, 'Provisioning & Offer — ' + (candidate.name || 'Candidate')),
      stageNote,
      acceptedNote,
      el('div', { class: 'hire-row' },
        el('label', {}, 'Generated login id'),
        el('div', { style: { 'font-size': '14px', color: '#93c5fd' } }, (candidate.generated_email || prov.generated_email) || '— (reserved when you issue the letter)')),
      // Account setup (Fida / Yuvanesh) only appears once provisioning has been
      // opened — i.e. after the candidate is added to the team.
      hasProv ? el('div', { class: 'hire-row' },
        el('label', { style: { display: 'flex', 'justify-content': 'space-between', 'align-items': 'center' } }, el('span', {}, 'Account setup'), statusBadge),
        el('div', { class: 'hire-checks' },
          taskRow('Tessa login', '(Fida)', 'tessa_done', 'can_mark_tessa', 'tessa'),
          taskRow('Gmail + Slack', '(Yuvanesh)', 'workspace_done', 'can_mark_workspace', 'workspace'))) : null,
      el('div', { class: 'hire-modal-actions' },
        el('button', { class: 'hire-btn ghost', onclick: () => overlay.remove() }, 'Close'),
        acceptBtn,
        addBtn,
        offerBtn),
    );
    const overlay = modal(card);
  }

  // ── Onboard modal (stage 9) — HR creates the Tessa account ───────────────────
  async function openOnboardModal(candidate, reload, cfg) {
    cfg = cfg || {};
    const endpoint = cfg.endpoint || ('/hiring/candidates/' + candidate.id + '/onboard');
    const saveLabel = cfg.saveLabel || 'Create account';
    const errEl = el('div', { class: 'hire-err' });
    let opts = { roles: [], managers: [] };
    try { opts = await api('/hiring/onboard-options'); } catch (e) { errEl.textContent = e.message; }

    const roleSel = el('select', { class: 'hire-input' }, el('option', { value: '' }, 'Select role…'),
      ...opts.roles.map((r) => el('option', { value: r.id }, r.name)));
    const empSel = el('select', { class: 'hire-input' },
      el('option', { value: 'full_time' }, 'Full-time'),
      el('option', { value: 'internship' }, 'Internship'),
      el('option', { value: 'freelancer' }, 'Freelancer'));
    const mgrSel = el('select', { class: 'hire-input' }, el('option', { value: '' }, 'No manager'),
      ...opts.managers.map((m) => el('option', { value: m.id }, m.name)));
    const joining = el('input', { class: 'hire-input', type: 'date' });
    const desig = el('input', { class: 'hire-input', type: 'text', placeholder: '(defaults to the JD role)' });

    const saveBtn = el('button', { class: 'hire-btn', onclick: submit }, saveLabel);
    async function submit() {
      errEl.textContent = '';
      if (!roleSel.value) { errEl.textContent = 'Pick a role.'; return; }
      saveBtn.disabled = true; saveBtn.textContent = 'Creating…';
      try {
        const r = await api(endpoint, { method: 'POST', body: {
          role_id: Number(roleSel.value),
          employment_type: empSel.value,
          reporting_manager_id: mgrSel.value ? Number(mgrSel.value) : null,
          joining_date: joining.value || null,
          designation: desig.value || null,
        } });
        overlay.remove();
        toast(r.message || 'Tessa account created.');
        if (reload) reload();
      } catch (e) { errEl.textContent = e.message; saveBtn.disabled = false; saveBtn.textContent = saveLabel; }
    }

    const loginId = (candidate.provisioning && candidate.provisioning.generated_email) || '—';
    const title = cfg.title || ('Create Tessa account — ' + (candidate.name || 'Candidate'));
    const intro = cfg.intro || ('Login id: ' + loginId + ' · default password 12345678. The new hire logs in and finishes onboarding (profile + documents).');
    const card = el('div', { class: 'hire-modal' },
      el('h3', {}, title),
      el('p', { style: { color: '#71717a', 'font-size': '12px', margin: '-6px 0 12px' } }, intro),
      el('div', { class: 'hire-row' }, el('label', {}, 'Role *'), roleSel),
      el('div', { class: 'hire-row' }, el('label', {}, 'Employment type *'), empSel),
      el('div', { class: 'hire-row' }, el('label', {}, 'Reporting manager'), mgrSel),
      el('div', { class: 'hire-row' }, el('label', {}, 'Joining date'), joining),
      el('div', { class: 'hire-row' }, el('label', {}, 'Designation'), desig),
      errEl,
      el('div', { class: 'hire-modal-actions' },
        el('button', { class: 'hire-btn ghost', onclick: () => overlay.remove() }, 'Cancel'),
        saveBtn),
    );
    const overlay = modal(card);
  }

  // ── JD card ─────────────────────────────────────────────────────────────────
  function jdCard(jd, data, reload) {
    const fields = [];
    if (jd.experience_level) fields.push(['Experience', jd.experience_level]);
    if (jd.salary_range) fields.push(['Salary', jd.salary_range]);
    if (jd.required_skills) fields.push(['Skills', jd.required_skills]);

    const recruiterChips = (jd.recruiters || []).map((r) =>
      el('span', { class: 'hire-chip', title: r.notified_at ? ('Notified ' + fmtDate(r.notified_at)) : 'Not yet notified' },
        el('span', { class: 'dot' + (r.notified_at ? '' : ' pending') }), r.name));

    const actions = [];
    if (jd.jd_file_url) {
      actions.push(el('a', { class: 'hire-link', href: jd.jd_file_url, target: '_blank', rel: 'noopener' }, '📄 Open JD PDF'));
    }
    if (data.can_assign) {
      actions.push(el('button', { class: 'hire-btn ghost sm', onclick: () => openAssignModal(jd, data.freelancers || [], reload) },
        jd.recruiters && jd.recruiters.length ? 'Manage recruiters' : 'Assign recruiter'));
    }
    if (jd.can_view_candidates) {
      actions.push(el('button', { class: 'hire-btn ghost sm', onclick: () => openCandidatesModal(jd, data, reload) },
        'Candidates' + (jd.candidate_count ? ' (' + jd.candidate_count + ')' : '')));
    }

    return el('div', { class: 'hire-card' },
      el('div', { class: 'hire-card-top' },
        el('div', {},
          el('h3', {}, jd.title),
          el('div', { class: 'hire-card-meta' },
            (jd.creator ? 'By ' + jd.creator.name + ' · ' : '') + 'Created ' + fmtDate(jd.created_at)),
        ),
        el('span', { class: 'hire-badge ' + jd.status }, STATUS_LABEL[jd.status] || jd.status),
      ),
      fields.length ? el('div', { class: 'hire-fields' },
        ...fields.map(([k, v]) => el('div', {}, el('div', { class: 'hire-field-k' }, k), el('div', { class: 'hire-field-v' }, v)))) : null,
      jd.description ? el('div', { class: 'hire-desc' }, jd.description) : null,
      (recruiterChips.length || actions.length) ? el('div', { class: 'hire-card-actions' }, ...recruiterChips, ...actions) : null,
    );
  }

  // ── Recruiters modal (HR): permanent open-portal links + performance ─────────
  function openRecruitersModal() {
    const body = el('div', {});
    const card = el('div', { class: 'hire-modal lg' },
      el('h3', { style: { margin: '0 0 4px' } }, 'Freelance recruiters'),
      el('div', { class: 'hire-cand-meta', style: { 'margin-bottom': '14px' } },
        'Send each recruiter their permanent link. They open it (no login) to view assigned JDs and upload résumés.'),
      body,
      el('button', { class: 'hire-btn ghost', style: { 'margin-top': '8px' }, onclick: () => overlay.remove() }, 'Close'),
    );
    const overlay = modal(card);

    async function load() {
      body.innerHTML = '';
      body.appendChild(el('div', { class: 'hire-cand-meta' }, 'Loading…'));
      let res;
      try { res = await api('/hiring/recruiters'); }
      catch (e) { body.innerHTML = ''; body.appendChild(el('div', { class: 'hire-empty' }, e.message)); return; }
      body.innerHTML = '';
      if (!res.recruiters || !res.recruiters.length) {
        body.appendChild(el('div', { class: 'hire-empty' }, 'No freelance recruiters yet.'));
        return;
      }
      res.recruiters.forEach((r) => {
        const linkInput = el('input', { class: 'hire-input', type: 'text', readonly: 'readonly', value: r.portal_link, style: { flex: '1', 'min-width': '180px' }, onclick: (e) => e.target.select() });
        const copyBtn = el('button', { class: 'hire-btn ghost sm', onclick: async () => {
          try { await navigator.clipboard.writeText(r.portal_link); toast('Link copied.'); }
          catch (e) { linkInput.select(); toast('Press Ctrl/Cmd-C to copy.', true); }
        } }, 'Copy link');
        const openLink = el('a', { class: 'hire-btn ghost sm', href: r.portal_link, target: '_blank', rel: 'noopener' }, 'Open ↗');
        const regenBtn = el('button', { class: 'hire-btn ghost sm', onclick: async () => {
          if (!window.confirm('Regenerate ' + r.name + '’s link? The old link stops working immediately.')) return;
          try {
            const rr = await api('/hiring/recruiters/' + r.id + '/regenerate-link', { method: 'POST', body: {} });
            r.portal_link = rr.portal_link; linkInput.value = rr.portal_link; openLink.href = rr.portal_link; toast('New link generated.');
          } catch (e) { toast(e.message, true); }
        } }, 'Regenerate');
        body.appendChild(el('div', { class: 'hire-card', style: { 'margin-bottom': '12px' } },
          el('div', { class: 'hire-card-top' },
            el('h3', { style: { margin: 0 } }, r.name),
            el('span', { class: 'hire-cand-meta' }, 'Submitted ' + r.submitted + ' · Selected ' + r.selected)),
          el('div', { class: 'hire-cand-meta', style: { 'margin-top': '10px', 'margin-bottom': '4px' } },
            'Open-portal link — share with the recruiter (no login needed)'),
          el('div', { style: { display: 'flex', gap: '8px', 'flex-wrap': 'wrap', 'align-items': 'center' } },
            linkInput, copyBtn, openLink, regenBtn)));
      });
    }
    load();
  }

  // ── Render ──────────────────────────────────────────────────────────────────
  async function render(container) {
    if (!container) return;
    ensureStyles();
    container.classList.remove('hidden');
    container.innerHTML = '';
    const shell = el('div', { class: 'hire-shell' }, el('div', { style: { color: '#a1a1aa', padding: '40px', 'text-align': 'center' } }, 'Loading…'));
    container.appendChild(shell);

    let data;
    try {
      data = await api('/hiring/job-descriptions');
    } catch (e) {
      shell.innerHTML = '';
      shell.appendChild(el('div', { class: 'hire-empty' }, e.message || 'Could not load hiring.'));
      return;
    }

    const reload = () => render(container);

    const subtitle = data.is_freelancer
      ? 'Job descriptions assigned to you. Open each to review what to source for.'
      : (data.is_hr ? 'Create job descriptions and assign them to freelance recruiters.'
        : 'Create job descriptions for your open roles. HR assigns recruiters.');

    const head = el('div', { class: 'hire-head' },
      el('div', { class: 'hire-title' }, el('h2', {}, 'Hiring'), el('p', {}, subtitle)),
      el('div', { style: { display: 'flex', gap: '8px', 'flex-wrap': 'wrap' } },
        data.is_hr ? el('button', { class: 'hire-btn ghost', onclick: () => openRecruitersModal() }, '👥 Recruiters') : null,
        data.can_create ? el('button', { class: 'hire-btn', onclick: () => openCreateModal(reload) }, '+ New job description') : null,
      ),
    );

    const list = el('div', { class: 'hire-list' });
    if (!data.jds || !data.jds.length) {
      list.appendChild(el('div', { class: 'hire-empty' },
        data.is_freelancer ? 'No job descriptions assigned to you yet.'
          : 'No job descriptions yet. Create your first one to get started.'));
    } else {
      data.jds.forEach((jd) => list.appendChild(jdCard(jd, data, reload)));
    }

    shell.innerHTML = '';
    shell.appendChild(head);
    shell.appendChild(list);
  }

  window.Hiring = { render };
})();
