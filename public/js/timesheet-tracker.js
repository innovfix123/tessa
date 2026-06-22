/**
 * HR Timesheet Tracker — single week view, one card per OT-eligible person.
 *
 * Logging is voluntary and limited to the self-log allowlist
 * (config/timesheet_access.php → self_log_user_ids). The tracker does not
 * compute "missing" days or send Slack reminders — empty days are fine.
 *
 * Exposes window.TimesheetTracker.render(containerEl).
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
    .tt-shell { padding:24px 28px; color:#e4e4e7; max-width:1200px; margin:0 auto; font-size:13px; }
    .tt-head { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; flex-wrap:wrap; margin-bottom:14px; }
    .tt-title h2 { color:#fafafa; font-size:18px; font-weight:600; margin:0; letter-spacing:-0.3px; }
    .tt-title p { color:#a1a1aa; font-size:13px; margin:2px 0 0; }
    .tt-nav { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
    .tt-btn { padding:7px 14px; background:#3b82f6; color:#fff; border:1px solid #3b82f6; border-radius:6px; cursor:pointer; font-size:13px; font-weight:500; line-height:1.4; transition:background 0.15s, border-color 0.15s; }
    .tt-btn:hover { background:#2563eb; border-color:#2563eb; }
    .tt-btn-ghost { background:#18181b; color:#a1a1aa; border-color:#27272a; }
    .tt-btn-ghost:hover { background:#27272a; border-color:#3f3f46; color:#fafafa; }
    .tt-input { padding:7px 10px; background:#0f0f11; border:1px solid #27272a; border-radius:6px; color:#fafafa; font-size:13px; font-family:inherit; }
    .tt-input:focus { outline:none; border-color:#3b82f6; }
    .tt-summary { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:8px; margin-bottom:18px; }
    .tt-summary-pill { background:#18181b; border:1px solid #27272a; border-radius:8px; padding:12px 14px; }
    .tt-summary-pill .v { color:#fafafa; font-size:18px; font-weight:600; line-height:1.2; }
    .tt-summary-pill .l { color:#71717a; font-size:11px; text-transform:uppercase; letter-spacing:0.05em; margin-top:5px; font-weight:500; }
    .tt-cards { display:grid; grid-template-columns:repeat(auto-fill, minmax(420px, 1fr)); gap:12px; }
    .tt-card { background:#18181b; border:1px solid #27272a; border-radius:10px; padding:16px 18px; display:flex; flex-direction:column; gap:12px; }
    .tt-card-head { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; padding-bottom:12px; border-bottom:1px solid #27272a; }
    .tt-name-row { display:flex; align-items:center; gap:10px; }
    .tt-avatar { width:32px; height:32px; border-radius:50%; background:#3b82f6; color:#fff; display:flex; align-items:center; justify-content:center; font-size:11.5px; font-weight:600; flex-shrink:0; }
    .tt-name { color:#fafafa; font-size:14px; font-weight:600; line-height:1.2; }
    .tt-card-stats { text-align:right; line-height:1.3; }
    .tt-card-stats .v { color:#fafafa; font-weight:600; font-size:14px; }
    .tt-card-stats .l { color:#71717a; font-size:11.5px; margin-top:2px; }
    .tt-days { display:flex; flex-direction:column; gap:4px; }
    .tt-day { display:grid; grid-template-columns:100px 1fr auto; gap:12px; align-items:center; padding:8px 12px; background:#0f0f11; border:1px solid #27272a; border-radius:6px; }
    .tt-day-label { color:#e4e4e7; font-size:12.5px; font-weight:500; }
    .tt-day-meta { color:#a1a1aa; font-size:12px; }
    .tt-day-amt { color:#4ade80; font-weight:600; font-size:12.5px; }
    .tt-no-logs { color:#71717a; font-size:13px; padding:12px; text-align:center; font-style:italic; }
    .tt-empty { background:#18181b; border:1px solid #27272a; border-radius:10px; padding:40px 20px; text-align:center; color:#71717a; font-style:italic; font-size:13px; }
    .tt-msg { padding:10px 14px; border-radius:8px; margin-bottom:12px; font-size:12px; }
    .tt-msg.error { background:rgba(239,68,68,0.1); color:#fca5a5; border:1px solid rgba(239,68,68,0.25); }
    @media (max-width:760px) {
      .tt-summary { grid-template-columns:repeat(2,1fr); }
      .tt-cards { grid-template-columns:1fr; }
      .tt-day { grid-template-columns:90px 1fr auto; }
    }
  `;

  function ensureStyles() {
    if (document.getElementById('tt-styles')) return;
    const s = document.createElement('style');
    s.id = 'tt-styles';
    s.textContent = styles;
    document.head.appendChild(s);
  }

  function localDateStr(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
  }
  function mondayOf(d) {
    const x = new Date(d);
    const dow = (x.getDay() + 6) % 7;
    x.setDate(x.getDate() - dow);
    x.setHours(0, 0, 0, 0);
    return x;
  }
  function addDays(d, n) { const x = new Date(d); x.setDate(x.getDate() + n); return x; }
  function initials(name) {
    if (!name) return '?';
    const parts = name.trim().split(/\s+/);
    return ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase() || '?';
  }
  function dayLabel(isoDate) {
    const d = new Date(isoDate + 'T00:00:00');
    return d.toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' });
  }

  let state = {
    weekStart: mondayOf(new Date()),
    data: null,
    msg: null,
  };
  let container = null;

  async function load() {
    state.data = await api('/timesheet-tracker/weekly?week=' + localDateStr(state.weekStart));
  }

  function render() {
    if (!container) return;
    ensureStyles();
    container.classList.remove('hidden');
    container.innerHTML = '';

    const shell = el('div', { class: 'tt-shell' });
    const weekEnd = addDays(state.weekStart, 6);

    const head = el('div', { class: 'tt-head' });
    head.append(
      el('div', { class: 'tt-title' },
        el('h2', null, 'Timesheet Tracker'),
        el('p', null,
          'Week ' + state.weekStart.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) +
          ' – ' + weekEnd.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })
        ),
      ),
      el('div', { class: 'tt-nav' },
        el('input', {
          class: 'tt-input',
          type: 'date',
          value: localDateStr(state.weekStart),
          onchange: async (e) => {
            if (!e.target.value) return;
            state.weekStart = mondayOf(new Date(e.target.value + 'T00:00:00'));
            await load(); render();
          },
        }),
        el('button', { class: 'tt-btn tt-btn-ghost', onclick: async () => { state.weekStart = addDays(state.weekStart, -7); await load(); render(); } }, '← Prev'),
        el('button', { class: 'tt-btn tt-btn-ghost', onclick: async () => { state.weekStart = mondayOf(new Date()); await load(); render(); } }, 'This week'),
        el('button', { class: 'tt-btn tt-btn-ghost', onclick: async () => { state.weekStart = addDays(state.weekStart, 7); await load(); render(); } }, 'Next →'),
      ),
    );
    shell.append(head);

    if (state.msg) {
      shell.append(el('div', { class: 'tt-msg ' + state.msg.type }, state.msg.text));
    }

    if (!state.data) {
      shell.append(el('div', { class: 'tt-empty' }, 'Loading…'));
      container.append(shell); return;
    }

    const t = state.data.totals;
    const summary = el('div', { class: 'tt-summary' });
    summary.append(
      pill(String(t.total_users), 'People tracked'),
      pill(t.total_hours.toFixed(1) + 'h', 'Total hours'),
      pill(t.overtime_hours.toFixed(1) + 'h', 'Overtime'),
      pill('₹' + Math.round(t.amount).toLocaleString('en-IN'), 'Amount'),
    );
    shell.append(summary);

    if (state.data.rows.length === 0) {
      shell.append(el('div', { class: 'tt-empty' },
        'Nobody is on the OT-eligible allowlist. ' +
        'Add user IDs to config/timesheet_access.php → self_log_user_ids to track them.'));
    } else {
      const cards = el('div', { class: 'tt-cards' });
      state.data.rows.forEach(r => cards.append(renderCard(r)));
      shell.append(cards);
    }

    container.append(shell);
  }

  function renderCard(r) {
    const card = el('div', { class: 'tt-card' });

    const head = el('div', { class: 'tt-card-head' });
    head.append(
      el('div', { class: 'tt-name-row' },
        el('div', { class: 'tt-avatar' }, initials(r.user.name)),
        el('div', { class: 'tt-name' }, r.user.name),
      ),
      el('div', { class: 'tt-card-stats' },
        el('div', { class: 'v' }, r.total_hours.toFixed(1) + 'h'),
        el('div', { class: 'l' }, r.days_worked + (r.days_worked === 1 ? ' day · ' : ' days · ') + 'OT ' + r.overtime_hours.toFixed(1) + 'h'),
      ),
    );
    card.append(head);

    const daysWrap = el('div', { class: 'tt-days' });
    if (r.days.length === 0) {
      daysWrap.append(el('div', { class: 'tt-no-logs' }, 'No timesheets logged this week.'));
    } else {
      r.days.forEach(d => {
        const breakdown = [];
        if (d.regular_hours > 0) breakdown.push(d.regular_hours.toFixed(1) + 'h reg');
        if (d.overtime_hours > 0) breakdown.push(d.overtime_hours.toFixed(1) + 'h OT');
        daysWrap.append(el('div', { class: 'tt-day' },
          el('div', { class: 'tt-day-label' }, dayLabel(d.work_date)),
          el('div', { class: 'tt-day-meta' },
            d.total_hours.toFixed(1) + 'h · ' + (breakdown.join(' + ') || '—') + ' · ' + d.slot_count + ' slot' + (d.slot_count === 1 ? '' : 's')
          ),
          el('div', { class: 'tt-day-amt' }, d.amount > 0 ? '₹' + Math.round(d.amount).toLocaleString('en-IN') : ''),
        ));
      });
    }
    card.append(daysWrap);

    return card;
  }

  function pill(value, label) {
    const c = el('div', { class: 'tt-summary-pill' });
    c.append(el('div', { class: 'v' }, value), el('div', { class: 'l' }, label));
    return c;
  }

  async function entry(targetContainer) {
    container = targetContainer || document.getElementById('timesheetTrackerView');
    if (!container) return;
    try {
      await load();
    } catch (err) {
      container.innerHTML = `<div class="tt-empty">Failed to load: ${err.message}</div>`;
      return;
    }
    render();
  }

  window.TimesheetTracker = { render: entry };
})();
