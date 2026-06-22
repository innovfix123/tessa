/**
 * Weekly Timesheet — company-wide Friday work record inside #weeklyTimesheetView.
 *
 * Two tabs:
 *   • My Timesheet — fill one weekly summary (regular hrs + what you worked,
 *     overtime hrs + what you worked incl. weekend).
 *   • Team — managers see their direct reports; HR/leadership see everyone,
 *     with a submitted / pending / on-leave tracker.
 *
 * Exposes window.WeeklyTimesheet.render() — called by portal.js view dispatcher.
 * Self-contained styles (injected once), mirroring js/timesheets.js.
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
    if (!res.ok) {
      // Surface the first Laravel validation message when present.
      const firstErr = json.errors ? json.errors[Object.keys(json.errors)[0]]?.[0] : null;
      throw new Error(firstErr || json.message || json.error || 'Request failed');
    }
    return json;
  }

  function el(tag, attrs, ...kids) {
    const e = document.createElement(tag);
    if (attrs) {
      for (const k in attrs) {
        if (k === 'style') Object.assign(e.style, attrs[k]);
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

  const styles = `
    .wts-shell { padding:24px 28px; color:#e4e4e7; max-width:1040px; margin:0 auto; font-size:13px; }
    .wts-head { margin-bottom:18px; }
    .wts-head h2 { margin:0 0 4px; color:#fafafa; font-size:18px; font-weight:600; }
    .wts-head p { margin:0; color:#71717a; font-size:12px; line-height:1.5; }
    .wts-tabs { display:flex; gap:2px; margin-bottom:20px; border-bottom:1px solid #27272a; }
    .wts-tab { padding:10px 18px; background:transparent; border:none; color:#a1a1aa; cursor:pointer; font-size:13px; font-weight:500; border-bottom:2px solid transparent; margin-bottom:-1px; transition:color .15s, border-color .15s; }
    .wts-tab:hover { color:#e4e4e7; }
    .wts-tab.active { color:#fafafa; border-bottom-color:#3b82f6; }
    .wts-nav { display:flex; gap:8px; align-items:center; margin-bottom:18px; flex-wrap:wrap; }
    .wts-nav-label { color:#e4e4e7; font-weight:600; font-size:13px; padding:0 6px; }
    .wts-card { background:#18181b; border:1px solid #27272a; border-radius:10px; padding:18px; margin-bottom:14px; }
    .wts-sec { margin-bottom:18px; }
    .wts-sec:last-child { margin-bottom:0; }
    .wts-sec-title { display:flex; align-items:center; gap:8px; color:#fafafa; font-size:13px; font-weight:600; margin-bottom:10px; }
    .wts-sec-title small { color:#71717a; font-weight:500; font-size:11px; }
    .wts-dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
    .wts-dot.reg { background:#3b82f6; }
    .wts-dot.ot { background:#f59e0b; }
    .wts-field { margin-bottom:10px; }
    .wts-label { display:block; color:#a1a1aa; font-size:12px; font-weight:500; margin-bottom:6px; }
    .wts-hours { width:120px; padding:8px 12px; background:#0f0f11; border:1px solid #27272a; border-radius:8px; color:#fafafa; font-size:14px; font-family:inherit; }
    .wts-textarea { width:100%; min-height:72px; padding:10px 12px; background:#0f0f11; border:1px solid #27272a; border-radius:8px; color:#fafafa; font-size:13px; font-family:inherit; resize:vertical; line-height:1.5; box-sizing:border-box; }
    .wts-hours:focus, .wts-textarea:focus { outline:none; border-color:#3b82f6; }
    .wts-textarea::placeholder, .wts-hours::placeholder { color:#52525b; }
    .wts-days { display:flex; gap:10px; flex-wrap:wrap; }
    .wts-day { display:inline-flex; align-items:center; gap:7px; padding:7px 13px; background:#0f0f11; border:1px solid #27272a; border-radius:8px; color:#e4e4e7; font-size:13px; cursor:pointer; user-select:none; transition:border-color .15s, background .15s, color .15s; }
    .wts-day:hover { border-color:#3f3f46; }
    .wts-day input { width:15px; height:15px; margin:0; accent-color:#f59e0b; cursor:pointer; }
    .wts-day:has(input:checked) { border-color:#f59e0b; background:rgba(245,158,11,.08); color:#fafafa; }
    .wts-day input:disabled { cursor:not-allowed; }
    .wts-btn { padding:9px 18px; background:#3b82f6; color:#fff; border:1px solid #3b82f6; border-radius:8px; cursor:pointer; font-size:13px; font-weight:500; font-family:inherit; transition:background .15s; }
    .wts-btn:hover { background:#2563eb; }
    .wts-btn:disabled { opacity:.5; cursor:not-allowed; }
    .wts-btn-ghost { background:#18181b; color:#a1a1aa; border-color:#27272a; }
    .wts-btn-ghost:hover { background:#27272a; color:#fafafa; }
    .wts-actions { display:flex; gap:10px; align-items:center; margin-top:4px; flex-wrap:wrap; }
    .wts-saved { color:#4ade80; font-size:12px; }
    .wts-draft-hint { display:flex; align-items:center; gap:10px; flex-wrap:wrap; background:rgba(245,158,11,.08); color:#fbbf24; padding:9px 13px; border:1px solid rgba(245,158,11,.25); border-radius:8px; margin-bottom:14px; font-size:12px; }
    .wts-draft-hint .wts-draft-dot { color:#f59e0b; }
    .wts-draft-discard { background:transparent; border:none; color:#fca5a5; cursor:pointer; font-size:12px; font-weight:600; padding:0; text-decoration:underline; font-family:inherit; }
    .wts-draft-discard:hover { color:#f87171; }
    .wts-msg { padding:10px 14px; border-radius:8px; margin-bottom:14px; font-size:12px; }
    .wts-msg.success { background:rgba(34,197,94,.1); color:#4ade80; border:1px solid rgba(34,197,94,.25); }
    .wts-msg.error { background:rgba(239,68,68,.1); color:#fca5a5; border:1px solid rgba(239,68,68,.25); }
    .wts-banner { background:rgba(59,130,246,.08); color:#93c5fd; padding:10px 14px; border:1px solid rgba(59,130,246,.25); border-radius:8px; margin-bottom:14px; font-size:12px; }
    .wts-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:18px; }
    .wts-stat { background:#18181b; border:1px solid #27272a; border-radius:10px; padding:14px 16px; }
    .wts-stat-label { color:#71717a; font-size:11px; text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px; font-weight:500; }
    .wts-stat-value { color:#fafafa; font-size:22px; font-weight:600; line-height:1.2; }
    .wts-row { background:#0f0f11; border:1px solid #27272a; border-radius:9px; padding:14px 16px; margin-bottom:8px; }
    .wts-row-top { display:flex; align-items:center; justify-content:space-between; gap:12px; }
    .wts-row-name { color:#fafafa; font-weight:600; font-size:13px; display:flex; align-items:center; gap:10px; }
    .wts-avatar { width:26px; height:26px; border-radius:50%; object-fit:cover; background:#27272a; display:inline-flex; align-items:center; justify-content:center; color:#a1a1aa; font-size:11px; font-weight:600; }
    .wts-badge { font-size:11px; font-weight:600; padding:3px 10px; border-radius:999px; white-space:nowrap; }
    .wts-badge.submitted { background:rgba(34,197,94,.12); color:#4ade80; }
    .wts-badge.pending { background:rgba(245,158,11,.12); color:#fbbf24; }
    .wts-badge.on_leave { background:rgba(113,113,122,.15); color:#a1a1aa; }
    .wts-row-hours { color:#a1a1aa; font-size:12px; margin-top:10px; display:flex; gap:18px; flex-wrap:wrap; }
    .wts-row-hours b { color:#fafafa; }
    .wts-row-sum { color:#a1a1aa; font-size:12px; margin-top:8px; line-height:1.55; padding-left:10px; border-left:2px solid #27272a; white-space:pre-wrap; }
    .wts-row-sum .tag { color:#71717a; font-weight:600; }
    .wts-empty { color:#71717a; padding:28px; text-align:center; font-size:13px; }
    @media (max-width:760px) {
      .wts-shell { padding:18px 16px; }
      .wts-stats { grid-template-columns:repeat(2,1fr); }
    }
  `;

  function ensureStyles() {
    if (document.getElementById('wts-styles')) return;
    const s = document.createElement('style');
    s.id = 'wts-styles';
    s.textContent = styles;
    document.head.appendChild(s);
  }

  // ─── Date helpers (local-date safe for IST; toISOString would roll back a day) ──
  function fmtDate(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
  }
  function mondayOf(d) {
    const x = new Date(d);
    const off = (x.getDay() + 6) % 7; // 0 = Monday
    x.setDate(x.getDate() - off);
    x.setHours(0, 0, 0, 0);
    return x;
  }
  function addDays(d, n) {
    const x = new Date(d);
    x.setDate(x.getDate() + n);
    return x;
  }
  function initials(name) {
    return (name || '?').trim().split(/\s+/).slice(0, 2).map((p) => p[0]).join('').toUpperCase();
  }

  // ─── Module state ───────────────────────────────────────────────
  const state = { tab: 'mine', week: mondayOf(new Date()), root: null };

  function cfg() {
    return (window.__PORTAL_CONFIG || {}).weeklyTimesheet || {};
  }

  // ─── Local draft (survives the portal's tab-switch / heartbeat auto-reload) ──
  // The portal reloads the page when you tab away and return (to surface deploys),
  // and the session heartbeat reloads on an expired session. Either one rebuilds
  // this form empty from the server, wiping anything you typed but hadn't yet
  // submitted. We mirror the My-Timesheet fields to localStorage as you type,
  // keyed per user + week, and restore them on render until you actually submit.
  function draftKey(weekStart) {
    const uid = (window.__PORTAL_CONFIG || {}).userId || '';
    return 'wtsDraft:' + uid + ':' + weekStart;
  }
  function readDraft(weekStart) {
    try { return JSON.parse(localStorage.getItem(draftKey(weekStart)) || 'null'); } catch (e) { return null; }
  }
  function writeDraft(weekStart, d) {
    try { localStorage.setItem(draftKey(weekStart), JSON.stringify(d)); } catch (e) {}
  }
  function clearDraft(weekStart) {
    try { localStorage.removeItem(draftKey(weekStart)); } catch (e) {}
  }
  function draftHasContent(d) {
    if (!d) return false;
    return (parseFloat(d.regular_hours) > 0) || (parseFloat(d.overtime_hours) > 0)
      || (d.regular_summary || '').trim() !== '' || (d.overtime_summary || '').trim() !== ''
      || !!d.overtime_saturday || !!d.overtime_sunday;
  }

  function render(root) {
    if (!root) return;
    ensureStyles();
    state.root = root;
    const c = cfg();
    // JP (canFill=false, canReview=true) lands straight on the Team tab.
    if (!c.canFill && c.canReview) state.tab = 'team';
    if (state.tab === 'team' && !c.canReview) state.tab = 'mine';
    paintShell();
    renderActive();
  }

  function paintShell() {
    const c = cfg();
    const tabs = el('div', { class: 'wts-tabs' });
    if (c.canFill) {
      tabs.appendChild(tabBtn('mine', 'My Timesheet'));
    }
    if (c.canReview) {
      tabs.appendChild(tabBtn('team', 'Team'));
    }

    const head = el('div', { class: 'wts-head' },
      el('h2', null, 'Weekly Timesheet'),
      el('p', null, 'Every Friday, log what you worked this week — your regular hours and any overtime (including weekend work), with a short note on what you did.')
    );

    state.root.innerHTML = '';
    state.root.appendChild(el('div', { class: 'wts-shell' },
      head,
      // Tabs only matter when there's a Team view to switch to. A pure filler
      // (canReview=false) just sees the form with no tab bar.
      c.canReview ? tabs : null,
      el('div', { id: 'wts-content' })
    ));
  }

  function tabBtn(key, label) {
    return el('button', {
      class: 'wts-tab' + (state.tab === key ? ' active' : ''),
      onclick: () => { if (state.tab !== key) { state.tab = key; state.week = mondayOf(new Date()); render(state.root); } },
    }, label);
  }

  function renderActive() {
    const host = document.getElementById('wts-content');
    if (!host) return;
    host.innerHTML = '<div class="wts-empty">Loading…</div>';
    if (state.tab === 'team') renderTeam(host);
    else renderMine(host);
  }

  function weekNav(label, onPrev, onNext, onThis) {
    return el('div', { class: 'wts-nav' },
      el('button', { class: 'wts-btn wts-btn-ghost', onclick: onPrev }, '‹ Prev'),
      el('span', { class: 'wts-nav-label' }, label || ''),
      el('button', { class: 'wts-btn wts-btn-ghost', onclick: onNext }, 'Next ›'),
      el('button', { class: 'wts-btn wts-btn-ghost', onclick: onThis }, 'This week')
    );
  }

  // ─── My Timesheet ───────────────────────────────────────────────
  async function renderMine(host) {
    let data;
    try {
      data = await api('/weekly-timesheet/mine?week=' + fmtDate(state.week));
    } catch (e) {
      host.innerHTML = '';
      host.appendChild(el('div', { class: 'wts-msg error' }, e.message));
      return;
    }
    const entry = data.entry || {};
    const editable = !!data.editable;

    const nav = weekNav(
      data.label + (data.is_current ? '  ·  this week' : ''),
      () => { state.week = addDays(state.week, -7); renderActive(); },
      () => { state.week = addDays(state.week, 7); renderActive(); },
      () => { state.week = mondayOf(new Date()); renderActive(); }
    );

    const regHours = el('input', { class: 'wts-hours', type: 'number', min: '0', max: '168', step: '0.5', placeholder: '0', value: entry.regular_hours != null ? entry.regular_hours : '' });
    const regSum = el('textarea', { class: 'wts-textarea', placeholder: 'What you worked on during regular hours this week…' }, entry.regular_summary || '');
    const otHours = el('input', { class: 'wts-hours', type: 'number', min: '0', max: '168', step: '0.5', placeholder: '0', value: entry.overtime_hours != null ? entry.overtime_hours : '' });
    const otSum = el('textarea', { class: 'wts-textarea', placeholder: 'Overtime / weekend work — what you did and when (e.g. "Sat: prod hotfix")…' }, entry.overtime_summary || '');
    const otSat = el('input', { type: 'checkbox', checked: entry.overtime_saturday ? 'checked' : undefined });
    const otSun = el('input', { type: 'checkbox', checked: entry.overtime_sunday ? 'checked' : undefined });

    if (!editable) {
      [regHours, regSum, otHours, otSum, otSat, otSun].forEach((i) => i.setAttribute('disabled', 'disabled'));
    }

    // Snapshot of the current field values, in the same shape as a stored draft.
    function snapshot() {
      return {
        regular_hours: regHours.value,
        regular_summary: regSum.value,
        overtime_hours: otHours.value,
        overtime_summary: otSum.value,
        overtime_saturday: otSat.checked,
        overtime_sunday: otSun.checked,
      };
    }
    // A draft only matters when it differs from what's already saved server-side —
    // otherwise restoring it is a no-op and the "unsaved" hint would be misleading.
    function sameAsServer(d) {
      const n = (v) => parseFloat(v || 0) || 0;
      return n(d.regular_hours) === n(entry.regular_hours)
        && n(d.overtime_hours) === n(entry.overtime_hours)
        && (d.regular_summary || '').trim() === (entry.regular_summary || '').trim()
        && (d.overtime_summary || '').trim() === (entry.overtime_summary || '').trim()
        && !!d.overtime_saturday === !!entry.overtime_saturday
        && !!d.overtime_sunday === !!entry.overtime_sunday;
    }

    // Restore an unsubmitted draft over the server values (editable weeks only).
    if (editable) {
      const draft = readDraft(data.week_start);
      if (draftHasContent(draft) && !sameAsServer(draft)) {
        regHours.value = draft.regular_hours != null ? draft.regular_hours : '';
        regSum.value = draft.regular_summary || '';
        otHours.value = draft.overtime_hours != null ? draft.overtime_hours : '';
        otSum.value = draft.overtime_summary || '';
        otSat.checked = !!draft.overtime_saturday;
        otSun.checked = !!draft.overtime_sunday;
      } else if (draft) {
        clearDraft(data.week_start); // stale draft that already matches the server
      }
    }

    // Amber "unsaved draft" hint + discard; visibility tracks whether a meaningful
    // (server-differing) draft currently exists.
    const draftHint = el('div', { class: 'wts-draft-hint' },
      el('span', { class: 'wts-draft-dot' }, '●'),
      el('span', null, 'Unsaved draft — kept on this device, but not submitted yet.'),
      el('button', { class: 'wts-draft-discard', onclick: () => { clearDraft(data.week_start); renderActive(); } }, 'Discard draft')
    );
    function refreshDraftHint() {
      const snap = snapshot();
      draftHint.style.display = (draftHasContent(snap) && !sameAsServer(snap)) ? '' : 'none';
    }

    // Autosave the draft as the user types (editable weeks only), debounced so a
    // tab-switch / heartbeat reload restores it instead of wiping it.
    let saveTimer = null;
    function scheduleSave() {
      clearTimeout(saveTimer);
      saveTimer = setTimeout(() => {
        const snap = snapshot();
        if (draftHasContent(snap) && !sameAsServer(snap)) writeDraft(data.week_start, snap);
        else clearDraft(data.week_start);
        refreshDraftHint();
      }, 400);
    }
    if (editable) {
      [regHours, regSum, otHours, otSum].forEach((i) => i.addEventListener('input', scheduleSave));
      [otSat, otSun].forEach((i) => i.addEventListener('change', scheduleSave));
    }

    const msg = el('div');
    const saved = entry.submitted_at
      ? el('span', { class: 'wts-saved' }, '✓ Submitted ' + new Date(entry.submitted_at).toLocaleString())
      : null;

    const submitBtn = el('button', { class: 'wts-btn' + (editable ? '' : ' '), onclick: doSubmit }, entry.submitted_at ? 'Update timesheet' : 'Submit timesheet');
    if (!editable) submitBtn.setAttribute('disabled', 'disabled');

    async function doSubmit() {
      msg.className = '';
      msg.textContent = '';
      submitBtn.setAttribute('disabled', 'disabled');
      try {
        const res = await api('/weekly-timesheet', {
          method: 'POST',
          body: {
            week_start: data.week_start,
            regular_hours: parseFloat(regHours.value || '0') || 0,
            regular_summary: regSum.value.trim(),
            overtime_hours: parseFloat(otHours.value || '0') || 0,
            overtime_summary: otSum.value.trim(),
            overtime_saturday: otSat.checked,
            overtime_sunday: otSun.checked,
          },
        });
        void res;
        clearDraft(data.week_start); // submitted — server is now the source of truth
        renderActive(); // reload to reflect the saved state + fresh timestamp
      } catch (e) {
        submitBtn.removeAttribute('disabled');
        msg.className = 'wts-msg error';
        msg.textContent = e.message;
      }
    }

    host.innerHTML = '';
    host.appendChild(nav);
    if (!data.can_fill) {
      host.appendChild(el('div', { class: 'wts-banner' }, 'You are not required to fill a weekly timesheet.'));
      return;
    }
    if (!editable) {
      host.appendChild(el('div', { class: 'wts-banner' }, 'This week hasn’t started yet — you can fill it from Monday onwards.'));
    }
    host.appendChild(msg);
    host.appendChild(draftHint);
    refreshDraftHint();
    host.appendChild(el('div', { class: 'wts-card' },
      el('div', { class: 'wts-sec' },
        el('div', { class: 'wts-sec-title' }, el('span', { class: 'wts-dot reg' }), 'Regular work'),
        el('div', { class: 'wts-field' }, el('label', { class: 'wts-label' }, 'Regular hours'), regHours),
        el('div', { class: 'wts-field' }, el('label', { class: 'wts-label' }, 'What you worked on'), regSum)
      ),
      el('div', { class: 'wts-sec' },
        el('div', { class: 'wts-sec-title' }, el('span', { class: 'wts-dot ot' }), 'Overtime ', el('small', null, '(includes weekend work)')),
        el('div', { class: 'wts-field' }, el('label', { class: 'wts-label' }, 'Overtime hours'), otHours),
        el('div', { class: 'wts-field' },
          el('label', { class: 'wts-label' }, 'Weekend day(s) worked'),
          el('div', { class: 'wts-days' },
            el('label', { class: 'wts-day' }, otSat, el('span', null, 'Saturday')),
            el('label', { class: 'wts-day' }, otSun, el('span', null, 'Sunday'))
          )
        ),
        el('div', { class: 'wts-field' }, el('label', { class: 'wts-label' }, 'What you worked on'), otSum)
      ),
      el('div', { class: 'wts-actions' }, submitBtn, saved)
    ));
  }

  // ─── Team review + tracker ──────────────────────────────────────
  async function renderTeam(host) {
    let data;
    try {
      data = await api('/weekly-timesheet/team?week=' + fmtDate(state.week));
    } catch (e) {
      host.innerHTML = '';
      host.appendChild(el('div', { class: 'wts-msg error' }, e.message));
      return;
    }

    const nav = weekNav(
      data.label + (data.is_current ? '  ·  this week' : ''),
      () => { state.week = addDays(state.week, -7); renderActive(); },
      () => { state.week = addDays(state.week, 7); renderActive(); },
      () => { state.week = mondayOf(new Date()); renderActive(); }
    );

    const s = data.summary || {};
    const stats = el('div', { class: 'wts-stats' },
      stat('Submitted', s.submitted || 0),
      stat('Pending', s.pending || 0),
      stat('On leave', s.on_leave || 0),
      stat(data.scope === 'company' ? 'Everyone' : 'Team', s.total || 0)
    );

    host.innerHTML = '';
    host.appendChild(nav);
    host.appendChild(stats);

    const rows = data.rows || [];
    if (!rows.length) {
      host.appendChild(el('div', { class: 'wts-empty' }, 'No employees in scope for this view.'));
      return;
    }
    rows.forEach((r) => host.appendChild(teamRow(r)));
  }

  function stat(label, value) {
    return el('div', { class: 'wts-stat' },
      el('div', { class: 'wts-stat-label' }, label),
      el('div', { class: 'wts-stat-value' }, String(value))
    );
  }

  function avatar(u) {
    if (u.photo) return el('img', { class: 'wts-avatar', src: u.photo, alt: '' });
    return el('span', { class: 'wts-avatar' }, initials(u.name));
  }

  function teamRow(r) {
    const badgeLabel = { submitted: 'Submitted', pending: 'Pending', on_leave: 'On leave' }[r.status] || r.status;
    const top = el('div', { class: 'wts-row-top' },
      el('div', { class: 'wts-row-name' }, avatar(r.user), r.user.name),
      el('span', { class: 'wts-badge ' + r.status }, badgeLabel)
    );
    const kids = [top];
    if (r.status === 'submitted') {
      kids.push(el('div', { class: 'wts-row-hours' },
        el('span', null, 'Regular: ', el('b', null, num(r.regular_hours) + 'h')),
        el('span', null, 'Overtime: ', el('b', null, num(r.overtime_hours) + 'h'), weekendDays(r)),
        el('span', null, 'Total: ', el('b', null, num(r.total_hours) + 'h'))
      ));
      if (r.regular_summary) kids.push(el('div', { class: 'wts-row-sum' }, el('span', { class: 'tag' }, 'Regular — '), r.regular_summary));
      if (r.overtime_summary) kids.push(el('div', { class: 'wts-row-sum' }, el('span', { class: 'tag' }, 'Overtime — '), r.overtime_summary));
    }
    return el('div', { class: 'wts-row' }, kids);
  }

  function num(v) {
    if (v == null) return '0';
    return (Math.round(v * 100) / 100).toString();
  }

  // " (Sat, Sun)" suffix for the reviewer's overtime line; '' when neither set.
  function weekendDays(r) {
    const d = [];
    if (r.overtime_saturday) d.push('Sat');
    if (r.overtime_sunday) d.push('Sun');
    return d.length ? '  (' + d.join(', ') + ')' : '';
  }

  window.WeeklyTimesheet = { render };
})();
