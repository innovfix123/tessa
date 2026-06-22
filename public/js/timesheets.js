/**
 * Employee Timesheets — Manual + Chat tabs inside #timesheetsView.
 *
 * Exposes window.TessaTimesheets.render() — called by portal.js view dispatcher.
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
    if (!res.ok) throw new Error(json.error || 'Request failed');
    return json;
  }

  function el(tag, attrs, ...kids) {
    const e = document.createElement(tag);
    if (attrs) {
      for (const k in attrs) {
        if (k === 'style') Object.assign(e.style, attrs[k]);
        else if (k.startsWith('on') && typeof attrs[k] === 'function') e.addEventListener(k.slice(2), attrs[k]);
        else if (attrs[k] !== undefined && attrs[k] !== null) e.setAttribute(k, attrs[k]);
      }
    }
    for (const k of kids.flat(Infinity)) {
      if (k == null || k === false) continue;
      e.appendChild(typeof k === 'string' ? document.createTextNode(k) : k);
    }
    return e;
  }

  const styles = `
    .ts-shell { padding:24px 28px; color:#e4e4e7; max-width:1120px; margin:0 auto; font-size:13px; }
    .ts-tabs { display:flex; gap:2px; margin-bottom:20px; border-bottom:1px solid #27272a; }
    .ts-tab { padding:10px 18px; background:transparent; border:none; color:#a1a1aa; cursor:pointer; font-size:13px; font-weight:500; border-bottom:2px solid transparent; transition:color 0.15s, border-color 0.15s; margin-bottom:-1px; }
    .ts-tab:hover { color:#e4e4e7; }
    .ts-tab.active { color:#fafafa; border-bottom-color:#3b82f6; }
    .ts-card { background:#18181b; border:1px solid #27272a; border-radius:10px; padding:18px; margin-bottom:14px; }
    .ts-card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; gap:12px; }
    .ts-card h3 { margin:0; color:#fafafa; font-size:14px; font-weight:600; }
    .ts-stats { display:grid; grid-template-columns:repeat(4, 1fr); gap:10px; margin-bottom:18px; }
    .ts-stat { background:#18181b; border:1px solid #27272a; border-radius:10px; padding:14px 16px; }
    .ts-stat-label { color:#71717a; font-size:11px; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:8px; font-weight:500; }
    .ts-stat-value { color:#fafafa; font-size:22px; font-weight:600; line-height:1.2; }
    .ts-stat-sub { color:#71717a; font-size:11px; margin-top:4px; }
    .ts-strip { display:grid; grid-template-columns:repeat(7, 1fr); gap:8px; margin-bottom:18px; }
    .ts-strip-day { padding:10px 6px; text-align:center; background:#18181b; border:1px solid #27272a; border-radius:8px; cursor:pointer; transition:border-color 0.15s, background 0.15s; position:relative; }
    .ts-strip-day:hover { border-color:#3f3f46; }
    .ts-strip-day.today { border-color:#3b82f6; background:rgba(59,130,246,0.08); }
    .ts-strip-day.has { border-color:#22c55e; }
    .ts-strip-day.has::after { content:''; position:absolute; top:6px; right:6px; width:6px; height:6px; border-radius:50%; background:#22c55e; }
    .ts-strip-day.future { opacity:0.45; cursor:not-allowed; }
    .ts-strip-day.future:hover { border-color:#27272a; }
    .ts-strip-day-name { font-size:10px; color:#71717a; text-transform:uppercase; letter-spacing:0.06em; font-weight:600; }
    .ts-strip-day-num { font-size:18px; color:#fafafa; font-weight:600; margin-top:4px; line-height:1; }
    .ts-form-row { display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
    .ts-input, .ts-select { padding:8px 12px; background:#0f0f11; border:1px solid #27272a; border-radius:8px; color:#fafafa; font-size:13px; font-family:inherit; transition:border-color 0.15s; }
    .ts-input:focus, .ts-select:focus, .ts-textarea:focus { outline:none; border-color:#3b82f6; }
    .ts-textarea { width:100%; min-height:72px; padding:10px 12px; background:#0f0f11; border:1px solid #27272a; border-radius:8px; color:#fafafa; font-size:13px; font-family:inherit; resize:vertical; line-height:1.5; transition:border-color 0.15s; }
    .ts-textarea::placeholder, .ts-input::placeholder { color:#52525b; }
    .ts-label { color:#a1a1aa; font-size:12px; font-weight:500; }
    .ts-btn { padding:8px 16px; background:#3b82f6; color:#fff; border:1px solid #3b82f6; border-radius:8px; cursor:pointer; font-size:13px; font-weight:500; font-family:inherit; transition:background 0.15s, border-color 0.15s; line-height:1.4; display:inline-flex; align-items:center; gap:6px; }
    .ts-btn:hover { background:#2563eb; border-color:#2563eb; }
    .ts-btn:disabled { opacity:0.5; cursor:not-allowed; }
    .ts-btn-ghost { background:#18181b; color:#a1a1aa; border-color:#27272a; }
    .ts-btn-ghost:hover { background:#27272a; border-color:#3f3f46; color:#fafafa; }
    .ts-btn-danger { background:transparent; color:#f87171; border-color:#3f3f46; }
    .ts-btn-danger:hover { background:#7f1d1d; border-color:#991b1b; color:#fff; }
    .ts-btn-sm { padding:5px 12px; font-size:12px; }
    .ts-slot { background:#0f0f11; border:1px solid #27272a; border-radius:8px; padding:14px; margin-bottom:10px; }
    .ts-slot-row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:10px; }
    .ts-slot-counter { color:#71717a; font-size:11px; margin-top:6px; text-align:right; transition:color 0.15s; }
    .ts-slot-counter.ok { color:#4ade80; }
    .ts-locked-banner { background:rgba(239,68,68,0.1); color:#fca5a5; padding:10px 14px; border:1px solid rgba(239,68,68,0.3); border-radius:8px; margin-bottom:14px; font-size:12px; display:flex; align-items:center; gap:8px; }
    .ts-msg { padding:10px 14px; border-radius:8px; margin-top:12px; font-size:12px; }
    .ts-msg.success { background:rgba(34,197,94,0.1); color:#4ade80; border:1px solid rgba(34,197,94,0.25); }
    .ts-msg.error { background:rgba(239,68,68,0.1); color:#fca5a5; border:1px solid rgba(239,68,68,0.25); }
    .ts-week-list { display:flex; flex-direction:column; gap:8px; }
    .ts-week-item { background:#0f0f11; border:1px solid #27272a; border-radius:8px; padding:14px 16px; display:flex; justify-content:space-between; align-items:flex-start; gap:14px; }
    .ts-week-item-date { color:#fafafa; font-weight:600; font-size:13px; }
    .ts-week-item-meta { color:#a1a1aa; font-size:12px; margin-top:4px; line-height:1.5; }
    .ts-week-item-slot { color:#71717a; font-size:12px; margin-top:3px; padding-left:8px; border-left:2px solid #27272a; }
    .ts-week-item-amt { color:#4ade80; font-weight:600; font-size:14px; white-space:nowrap; }
    .ts-week-nav { display:flex; gap:8px; align-items:center; margin-bottom:18px; flex-wrap:wrap; }
    .ts-week-nav-label { color:#e4e4e7; font-weight:500; font-size:13px; padding:0 8px; }
    .ts-empty { color:#71717a; padding:24px; text-align:center; font-size:13px; }
    .ts-form-actions { display:flex; gap:8px; align-items:center; margin-top:14px; flex-wrap:wrap; }
    @media (max-width:760px) {
      .ts-shell { padding:18px 16px; }
      .ts-stats { grid-template-columns:repeat(2, 1fr); }
      .ts-strip { grid-template-columns:repeat(7, 1fr); gap:4px; }
      .ts-strip-day { padding:8px 2px; }
    }
  `;

  function ensureStyles() {
    if (document.getElementById('ts-styles')) return;
    const s = document.createElement('style');
    s.id = 'ts-styles';
    s.textContent = styles;
    document.head.appendChild(s);
  }

  function fmtDate(d) {
    // Local date parts — toISOString shifts to UTC and rolls back a day in IST.
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
  }
  function parseDate(s) {
    return new Date(s + 'T00:00:00');
  }
  function mondayOf(d) {
    const x = new Date(d);
    const day = (x.getDay() + 6) % 7; // 0 = Monday
    x.setDate(x.getDate() - day);
    return x;
  }
  function addDays(d, n) {
    const x = new Date(d);
    x.setDate(x.getDate() + n);
    return x;
  }

  let state = {
    weekStart: mondayOf(new Date()),
    weekData: null,
    summary: null,
    activeTab: 'manual',
    formDate: '',
    slots: [],
  };

  // The Monday we last identified as "this week". Updated on each render and
  // when the user navigates. Used to detect a calendar rollover so we can
  // auto-advance the view if (and only if) the user was still looking at it.
  let lastKnownCurrentMonday = fmtDate(state.weekStart);

  let container = null;

  async function loadWeek() {
    const result = await api('/timesheets/week?start=' + fmtDate(state.weekStart));
    state.weekData = result;
  }

  async function loadSummary() {
    state.summary = await api('/timesheets/summary');
  }

  function defaultFormDate() {
    // Suggest yesterday if no entry for it.
    const y = addDays(new Date(), -1);
    const yStr = fmtDate(y);
    const has = state.weekData?.timesheets?.some(t => t.work_date === yStr);
    if (!has) return yStr;
    return fmtDate(new Date());
  }

  function newSlot() {
    return { start_time: '09:00', end_time: '13:00', type: 'overtime', description: '' };
  }

  function render() {
    if (!container) return;
    ensureStyles();
    container.classList.remove('hidden');
    container.innerHTML = '';

    const shell = el('div', { class: 'ts-shell' });

    // Tabs
    const tabs = el('div', { class: 'ts-tabs' });
    const manualTab = el('button', { class: 'ts-tab' + (state.activeTab === 'manual' ? ' active' : '') }, 'Manual');
    const chatTab = el('button', { class: 'ts-tab' + (state.activeTab === 'chat' ? ' active' : '') }, 'Chat with Tessa');
    manualTab.addEventListener('click', () => { state.activeTab = 'manual'; render(); });
    chatTab.addEventListener('click', () => { state.activeTab = 'chat'; render(); });
    tabs.append(manualTab, chatTab);
    shell.append(tabs);

    if (state.activeTab === 'manual') {
      shell.append(renderManual());
    } else {
      const chatPanel = el('div', { class: 'ts-card' });
      shell.append(chatPanel);
      // Mount the assistant module into the panel
      if (window.TimesheetAssistant?.mount) {
        window.TimesheetAssistant.mount(chatPanel);
      } else {
        chatPanel.append(el('p', { class: 'ts-empty' }, 'Assistant module not loaded.'));
      }
    }

    container.append(shell);
  }

  function renderManual() {
    const wrap = el('div');

    // Stats
    if (state.summary) {
      const wkOt = state.summary.this_week.overtime_hours || 0;
      const wkAmt = state.summary.this_week.amount || 0;
      const wkDays = state.summary.this_week.days_worked || 0;
      const moHrs = state.summary.month.total_hours || 0;
      const moAmt = state.summary.month.amount || 0;
      const moDays = state.summary.month.days_worked || 0;
      const stats = el('div', { class: 'ts-stats' });
      stats.append(
        statCard('This week · overtime', wkOt.toFixed(1) + 'h', wkAmt > 0 ? '₹' + Math.round(wkAmt).toLocaleString('en-IN') : '—'),
        statCard('This week · days', String(wkDays), wkDays === 1 ? 'day worked' : 'days worked'),
        statCard('This month · total', moHrs.toFixed(1) + 'h', moDays + (moDays === 1 ? ' day' : ' days')),
        statCard('This month · earnings', '₹' + Math.round(moAmt).toLocaleString('en-IN'), moAmt > 0 ? 'earned so far' : 'no entries yet'),
      );
      wrap.append(stats);
    }

    // Strip showing Mon–Sun of the displayed week.
    const strip = el('div', { class: 'ts-strip' });
    const today = fmtDate(new Date());
    for (let i = 0; i < 7; i++) {
      const d = addDays(state.weekStart, i);
      const dStr = fmtDate(d);
      const isFuture = dStr > today;
      const has = state.weekData?.timesheets?.some(t => t.work_date === dStr) || false;
      const day = el('div', {
        class: 'ts-strip-day' + (has ? ' has' : '') + (dStr === today ? ' today' : '') + (isFuture ? ' future' : ''),
        title: isFuture ? 'Future date — cannot log yet' : (has ? 'Logged · click to edit' : 'Click to log this day'),
        onclick: () => {
          if (isFuture) return;
          state.formDate = dStr;
          const existing = state.weekData?.timesheets?.find(t => t.work_date === dStr);
          state.slots = existing ? existing.time_slots.map(s => ({ ...s })) : [newSlot()];
          render();
        },
      },
        el('div', { class: 'ts-strip-day-name' }, d.toLocaleDateString('en-US', { weekday: 'short' })),
        el('div', { class: 'ts-strip-day-num' }, String(d.getDate())),
      );
      strip.append(day);
    }
    wrap.append(strip);

    // Week navigator
    const nav = el('div', { class: 'ts-week-nav' });
    const weekEnd = addDays(state.weekStart, 6);
    nav.append(
      el('button', {
        class: 'ts-btn ts-btn-ghost ts-btn-sm',
        onclick: async () => { state.weekStart = addDays(state.weekStart, -7); await loadWeek(); render(); },
      }, '← Previous'),
      el('button', {
        class: 'ts-btn ts-btn-ghost ts-btn-sm',
        onclick: async () => { state.weekStart = addDays(state.weekStart, 7); await loadWeek(); render(); },
      }, 'Next →'),
      el('span', { class: 'ts-week-nav-label' },
        state.weekStart.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) +
        ' – ' +
        weekEnd.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })
      ),
      el('button', {
        class: 'ts-btn ts-btn-ghost ts-btn-sm',
        style: { marginLeft: 'auto' },
        onclick: async () => {
          state.weekStart = mondayOf(new Date());
          lastKnownCurrentMonday = fmtDate(state.weekStart);
          await loadWeek();
          render();
        },
      }, 'This week'),
    );
    wrap.append(nav);

    // Locked banner
    if (state.weekData?.locked) {
      wrap.append(el('div', { class: 'ts-locked-banner' },
        'This week is paid and locked. Contact admin if you need to edit.'
      ));
    }

    // Form
    if (!state.formDate) state.formDate = defaultFormDate();
    if (state.slots.length === 0) state.slots.push(newSlot());

    const form = el('div', { class: 'ts-card' });
    const formHead = el('div', { class: 'ts-card-header' });
    const dateLabel = new Date(state.formDate + 'T00:00:00').toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'short' });
    formHead.append(
      el('h3', null, 'Log time · ' + dateLabel),
      el('input', {
        class: 'ts-input',
        type: 'date',
        value: state.formDate,
        max: today,
        style: { padding: '6px 10px', fontSize: '12px' },
        onchange: (ev) => { state.formDate = ev.target.value; render(); },
      }),
    );
    form.append(formHead);

    const slotsWrap = el('div');
    state.slots.forEach((slot, idx) => slotsWrap.append(renderSlotRow(slot, idx)));
    form.append(slotsWrap);

    const msgEl = el('div');

    const actions = el('div', { class: 'ts-form-actions' });
    actions.append(
      el('button', {
        class: 'ts-btn ts-btn-ghost ts-btn-sm',
        onclick: () => { state.slots.push(newSlot()); render(); },
      }, '+ Add slot'),
    );
    const submit = el('button', {
      class: 'ts-btn',
      style: { marginLeft: 'auto' },
      onclick: async () => { await submitTimesheet(form, msgEl); },
    }, state.weekData?.locked ? 'Locked' : 'Save timesheet');
    if (state.weekData?.locked) submit.setAttribute('disabled', 'true');
    actions.append(submit);

    form.append(actions, msgEl);
    wrap.append(form);

    // Week list
    const list = el('div', { class: 'ts-card' });
    const listHead = el('div', { class: 'ts-card-header' });
    const entryCount = state.weekData?.timesheets?.length || 0;
    listHead.append(
      el('h3', null, 'This week'),
      el('span', { style: { color: '#71717a', fontSize: '12px' } },
        entryCount === 0 ? 'No entries yet' : entryCount + ' ' + (entryCount === 1 ? 'entry' : 'entries')),
    );
    list.append(listHead);
    if (entryCount > 0) {
      const ul = el('div', { class: 'ts-week-list' });
      state.weekData.timesheets.forEach(t => {
        const item = el('div', { class: 'ts-week-item' });
        const left = el('div', { style: { flex: '1', minWidth: '0' } });
        const slotCount = t.time_slots.length;
        const breakdown = [];
        if (Number(t.regular_hours) > 0) breakdown.push(`${t.regular_hours}h regular`);
        if (Number(t.overtime_hours) > 0) breakdown.push(`${t.overtime_hours}h OT`);
        left.append(
          el('div', { class: 'ts-week-item-date' },
            new Date(t.work_date + 'T00:00:00').toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'short' })
          ),
          el('div', { class: 'ts-week-item-meta' },
            `${t.total_hours}h total · ${breakdown.join(' + ') || '—'} · ${slotCount} slot${slotCount !== 1 ? 's' : ''}`
          ),
          ...t.time_slots.map(s => el('div', { class: 'ts-week-item-slot' },
            `${s.start_time}–${s.end_time} · ${s.type} · ${s.description.slice(0, 100)}${s.description.length > 100 ? '…' : ''}`
          )),
        );
        const right = el('div', { style: { textAlign: 'right', display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: '8px' } });
        right.append(el('div', { class: 'ts-week-item-amt' }, '₹' + Math.round(t.amount).toLocaleString('en-IN')));
        if (!t.locked) {
          const btnRow = el('div', { style: { display: 'flex', gap: '6px' } });
          btnRow.append(
            el('button', {
              class: 'ts-btn ts-btn-ghost ts-btn-sm',
              onclick: () => { state.formDate = t.work_date; state.slots = t.time_slots.map(s => ({ ...s })); render(); window.scrollTo(0, 0); },
            }, 'Edit'),
            el('button', {
              class: 'ts-btn ts-btn-danger ts-btn-sm',
              onclick: () => deleteEntry(t),
            }, 'Delete'),
          );
          right.append(btnRow);
        }
        item.append(left, right);
        ul.append(item);
      });
      list.append(ul);
    } else {
      list.append(el('div', { class: 'ts-empty' }, 'Nothing logged yet — tap a day above or use the form to add an entry.'));
    }
    wrap.append(list);

    return wrap;
  }

  function renderSlotRow(slot, idx) {
    const slotEl = el('div', { class: 'ts-slot' });
    const row1 = el('div', { class: 'ts-slot-row' });

    const startWrap = el('div', { style: { display: 'flex', flexDirection: 'column', gap: '4px' } },
      el('label', { class: 'ts-label' }, 'Start'),
      el('input', { class: 'ts-input', type: 'time', value: slot.start_time, onchange: (e) => slot.start_time = e.target.value }),
    );
    const endWrap = el('div', { style: { display: 'flex', flexDirection: 'column', gap: '4px' } },
      el('label', { class: 'ts-label' }, 'End'),
      el('input', { class: 'ts-input', type: 'time', value: slot.end_time, onchange: (e) => slot.end_time = e.target.value }),
    );
    const typeSel = (() => {
      const sel = el('select', { class: 'ts-select', onchange: (e) => slot.type = e.target.value });
      ['regular', 'overtime'].forEach(t => {
        const opt = el('option', { value: t }, t.charAt(0).toUpperCase() + t.slice(1));
        if (slot.type === t) opt.setAttribute('selected', 'true');
        sel.append(opt);
      });
      return sel;
    })();
    const typeWrap = el('div', { style: { display: 'flex', flexDirection: 'column', gap: '4px' } },
      el('label', { class: 'ts-label' }, 'Type'),
      typeSel,
    );

    row1.append(startWrap, endWrap, typeWrap);
    if (state.slots.length > 1) {
      row1.append(el('button', {
        class: 'ts-btn ts-btn-danger ts-btn-sm',
        style: { marginLeft: 'auto', alignSelf: 'flex-end' },
        onclick: () => { state.slots.splice(idx, 1); render(); },
      }, 'Remove'));
    }

    const descLen = (slot.description || '').length;
    const counter = el('div', { class: 'ts-slot-counter' + (descLen >= 50 ? ' ok' : '') },
      descLen + ' / 50 characters');
    const desc = el('textarea', {
      class: 'ts-textarea',
      placeholder: 'What did you work on? (minimum 50 characters)',
      oninput: (e) => {
        slot.description = e.target.value;
        const n = e.target.value.length;
        counter.textContent = n + ' / 50 characters';
        counter.classList.toggle('ok', n >= 50);
      },
    });
    desc.value = slot.description || '';

    slotEl.append(row1, desc, counter);
    return slotEl;
  }

  async function deleteEntry(t) {
    const dateLabel = new Date(t.work_date + 'T00:00:00').toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'short' });
    if (!confirm(`Delete timesheet for ${dateLabel}? This cannot be undone.`)) return;
    try {
      await api('/timesheets/' + t.id, { method: 'DELETE' });
      // If this entry was loaded into the form, reset it.
      if (state.formDate === t.work_date) {
        state.formDate = '';
        state.slots = [];
      }
      await Promise.all([loadWeek(), loadSummary()]);
      render();
    } catch (err) {
      alert(err.message || 'Failed to delete entry.');
    }
  }

  async function submitTimesheet(formEl, msgEl) {
    msgEl.innerHTML = '';
    try {
      await api('/timesheets', {
        method: 'POST',
        body: { work_date: state.formDate, slots: state.slots },
      });
      msgEl.append(el('div', { class: 'ts-msg success' }, 'Timesheet saved.'));
      state.slots = [];
      state.formDate = '';
      await loadWeek();
      await loadSummary();
      render();
    } catch (err) {
      msgEl.append(el('div', { class: 'ts-msg error' }, err.message || 'Failed to save.'));
    }
  }

  function statCard(label, value, sub) {
    const c = el('div', { class: 'ts-stat' });
    c.append(
      el('div', { class: 'ts-stat-label' }, label),
      el('div', { class: 'ts-stat-value' }, value),
    );
    if (sub) c.append(el('div', { class: 'ts-stat-sub' }, sub));
    return c;
  }

  async function entry(targetContainer) {
    container = targetContainer || document.getElementById('timesheetsView');
    if (!container) return;
    // Always anchor to the current ongoing week on entry — when this week
    // ends and the page is re-opened, the new current week is shown.
    state.weekStart = mondayOf(new Date());
    lastKnownCurrentMonday = fmtDate(state.weekStart);
    state.formDate = '';
    state.slots = [];
    try {
      await Promise.all([loadWeek(), loadSummary()]);
    } catch (err) {
      container.innerHTML = `<div class="ts-empty">Failed to load: ${err.message}</div>`;
      return;
    }
    state.formDate = defaultFormDate();
    render();
  }

  // Expose for portal.js dispatcher
  window.TessaTimesheets = { render: entry };

  // Refresh when assistant submits
  document.addEventListener('timesheet:saved', async () => {
    if (!container || container.classList.contains('hidden')) return;
    try {
      await Promise.all([loadWeek(), loadSummary()]);
      render();
    } catch (e) {}
  });

  // Auto-roll to the new current week when the calendar week ticks over
  // (e.g. user kept the tab open from Sunday into Monday). Only resets the
  // view if the user is still looking at what *was* the current week —
  // never blows away a deliberate browse to a past week.
  async function maybeRollToCurrentWeek() {
    if (!container || container.classList.contains('hidden')) return;
    const trueMonday = fmtDate(mondayOf(new Date()));
    if (trueMonday === lastKnownCurrentMonday) return;
    const wasOnCurrentWeek = fmtDate(state.weekStart) === lastKnownCurrentMonday;
    lastKnownCurrentMonday = trueMonday;
    if (!wasOnCurrentWeek) return;
    state.weekStart = mondayOf(new Date());
    state.formDate = '';
    state.slots = [];
    try {
      await Promise.all([loadWeek(), loadSummary()]);
      render();
    } catch (e) {}
  }

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) maybeRollToCurrentWeek();
  });
  window.addEventListener('focus', maybeRollToCurrentWeek);
})();
