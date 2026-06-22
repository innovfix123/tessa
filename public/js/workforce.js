/**
 * Workforce Admin — weekly payments dashboard for admin/finance.
 *
 * Exposes window.WorkforceAdmin.render(containerEl).
 */
(function () {
  'use strict';

  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

  async function api(url, opts = {}) {
    const isForm = opts.body instanceof FormData;
    // For FormData we must NOT set Content-Type — the browser has to add the
    // `multipart/form-data; boundary=…` itself. Setting it here (even to
    // `undefined`, which the Headers constructor coerces to the string
    // "undefined") strips the boundary and the server can't parse the upload.
    const headers = { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' };
    if (!isForm) headers['Content-Type'] = 'application/json';
    Object.assign(headers, opts.headers || {});

    const res = await fetch('/api' + url, {
      headers,
      credentials: 'same-origin',
      method: opts.method || 'GET',
      body: isForm ? opts.body : (opts.body ? JSON.stringify(opts.body) : undefined),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      // Surface the real reason: our APIs use `error`; Laravel validation/auth
      // use `message` + `errors{}`. Without this, every 422/403 reads as the
      // useless "Request failed".
      let msg = json.error || json.message;
      if (json.errors && typeof json.errors === 'object') {
        const first = Object.values(json.errors)[0];
        if (Array.isArray(first) && first.length) msg = first[0];
      }
      throw new Error(msg || 'Request failed');
    }
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
    .wf-shell { padding:24px 28px; color:#e4e4e7; max-width:1400px; margin:0 auto; font-size:13px; }
    .wf-head { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:18px; gap:14px; flex-wrap:wrap; }
    .wf-title h2 { color:#fafafa; font-size:18px; font-weight:600; margin:0; letter-spacing:-0.3px; }
    .wf-title p { color:#a1a1aa; font-size:13px; margin:2px 0 0; }
    .wf-summary-strip { display:flex; gap:8px; flex-wrap:wrap; }
    .wf-pill { background:#18181b; border:1px solid #27272a; border-radius:8px; padding:10px 14px; min-width:96px; text-align:center; }
    .wf-pill .v { color:#fafafa; font-size:15px; font-weight:600; line-height:1.2; }
    .wf-pill .l { color:#71717a; font-size:11px; text-transform:uppercase; letter-spacing:0.05em; margin-top:3px; font-weight:500; }
    .wf-pill.paid .v { color:#4ade80; }
    .wf-pill.pending .v { color:#fbbf24; }
    .wf-controls-row { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
    .wf-controls { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
    .wf-btn { padding:7px 14px; background:#3b82f6; color:#fff; border:1px solid #3b82f6; border-radius:6px; cursor:pointer; font-size:13px; font-weight:500; line-height:1.4; transition:background 0.15s, border-color 0.15s; }
    .wf-btn:hover { background:#2563eb; border-color:#2563eb; }
    .wf-btn-ghost { background:#18181b; color:#a1a1aa; border-color:#27272a; }
    .wf-btn-ghost:hover { background:#27272a; border-color:#3f3f46; color:#fafafa; }
    .wf-btn-success { background:#22c55e; border-color:#22c55e; }
    .wf-btn-success:hover { background:#16a34a; border-color:#16a34a; }
    .wf-input { padding:7px 10px; background:#0f0f11; border:1px solid #27272a; border-radius:6px; color:#fafafa; font-size:13px; font-family:inherit; }
    .wf-input:focus { outline:none; border-color:#3b82f6; }
    .wf-section-title { color:#71717a; font-size:11px; text-transform:uppercase; letter-spacing:0.05em; padding:12px 16px; background:#0f0f11; border:1px solid #27272a; border-bottom:none; border-radius:10px 10px 0 0; font-weight:500; }
    .wf-table-wrap { background:#18181b; border:1px solid #27272a; border-radius:0 0 10px 10px; overflow:hidden; }
    .wf-table { width:100%; border-collapse:collapse; }
    .wf-table th, .wf-table td { padding:12px 16px; text-align:left; border-bottom:1px solid #27272a; font-size:13px; }
    .wf-table th { background:#0f0f11; color:#71717a; font-weight:500; text-transform:uppercase; font-size:11px; letter-spacing:0.05em; }
    .wf-table td { color:#e4e4e7; }
    .wf-table tr:hover td { background:#1a1a1e; }
    .wf-table tr.wf-total-row td { background:#0f0f11; color:#fafafa; font-weight:600; border-top:2px solid #27272a; border-bottom:none; }
    .wf-table tr.wf-total-row:hover td { background:#0f0f11; }
    .wf-member { display:flex; align-items:center; gap:10px; }
    .wf-avatar { width:28px; height:28px; border-radius:50%; background:#3b82f6; color:#fff; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:600; flex-shrink:0; }
    .wf-status-badge { display:inline-block; padding:2px 9px; border-radius:4px; font-size:10.5px; text-transform:uppercase; font-weight:600; letter-spacing:0.04em; }
    .wf-status-paid { background:rgba(34,197,94,0.15); color:#4ade80; }
    .wf-status-pending { background:rgba(234,179,8,0.15); color:#fde68a; }
    .wf-paid-date { color:#a1a1aa; font-size:13px; cursor:pointer; }
    .wf-paid-date:hover { color:#fafafa; }
    .wf-modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.7); display:flex; align-items:center; justify-content:center; z-index:1000; }
    .wf-modal { background:#18181b; border:1px solid #27272a; border-radius:10px; padding:22px 24px; min-width:420px; max-width:540px; }
    .wf-modal h3 { color:#fafafa; margin:0 0 14px; font-size:15px; font-weight:600; }
    .wf-modal-row { display:flex; flex-direction:column; gap:6px; margin-bottom:12px; }
    .wf-modal-row label { color:#a1a1aa; font-size:12px; font-weight:500; }
    .wf-modal-actions { display:flex; gap:8px; justify-content:flex-end; margin-top:18px; }
    .wf-msg { padding:10px 14px; border-radius:8px; margin-bottom:12px; font-size:12px; }
    .wf-msg.success { background:rgba(34,197,94,0.1); color:#4ade80; border:1px solid rgba(34,197,94,0.25); }
    .wf-msg.error { background:rgba(239,68,68,0.1); color:#fca5a5; border:1px solid rgba(239,68,68,0.25); }
    .wf-empty { color:#71717a; padding:40px; text-align:center; font-style:italic; font-size:13px; }
  `;

  function ensureStyles() {
    if (document.getElementById('wf-styles')) return;
    const s = document.createElement('style');
    s.id = 'wf-styles';
    s.textContent = styles;
    document.head.appendChild(s);
  }

  function fmtDate(d) {
    // Use local date parts (NOT toISOString — that shifts to UTC and rolls back a day in IST).
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
  }
  function mondayOf(d) {
    const x = new Date(d);
    const day = (x.getDay() + 6) % 7;
    x.setDate(x.getDate() - day);
    x.setHours(0, 0, 0, 0);
    return x;
  }
  function addDays(d, n) { const x = new Date(d); x.setDate(x.getDate() + n); return x; }

  let state = {
    weekStart: mondayOf(new Date()),
    summary: null,
    msg: null,
  };
  let container = null;

  async function loadSummary() {
    state.summary = await api('/workforce/payments/week-summary?week=' + fmtDate(state.weekStart));
  }

  function initials(name) {
    if (!name) return '?';
    const parts = name.trim().split(/\s+/);
    return ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase() || '?';
  }

  function render() {
    if (!container) return;
    ensureStyles();
    container.classList.remove('hidden');
    container.innerHTML = '';

    const shell = el('div', { class: 'wf-shell' });
    const head = el('div', { class: 'wf-head' });
    const weekEnd = addDays(state.weekStart, 6);

    const totalHrs = state.summary
      ? Number(state.summary.total_hours ?? state.summary.rows.reduce((s, r) => s + Number(r.total_hours || 0), 0)).toFixed(1)
      : '0.0';
    const totalAmt = Math.round(state.summary?.total_amount || 0).toLocaleString('en-IN');
    const paidAmt = Math.round(state.summary?.paid_amount || 0).toLocaleString('en-IN');
    const pendingAmt = Math.round(state.summary?.pending_amount || 0).toLocaleString('en-IN');

    head.append(
      el('div', { class: 'wf-title' },
        el('h2', null, 'Weekly Summary'),
        el('p', null,
          state.weekStart.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) +
          ' – ' + weekEnd.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })
        ),
      ),
      el('div', { class: 'wf-summary-strip' },
        pill(totalHrs, 'Hours'),
        pill('₹' + totalAmt, 'Total'),
        pill('₹' + paidAmt, 'Paid', 'paid'),
        pill('₹' + pendingAmt, 'Pending', 'pending'),
      ),
    );
    shell.append(head);

    const controlsRow = el('div', { class: 'wf-controls-row' });
    controlsRow.append(
      el('div', { class: 'wf-controls' },
        el('label', { style: { color: '#94a3b8', fontSize: '0.85rem' } }, 'Select Week:'),
        el('input', {
          class: 'wf-input',
          type: 'date',
          value: fmtDate(state.weekStart),
          onchange: async (e) => {
            if (!e.target.value) return;
            state.weekStart = mondayOf(new Date(e.target.value + 'T00:00:00'));
            await loadSummary();
            render();
          },
        }),
        el('button', { class: 'wf-btn wf-btn-ghost', onclick: async () => { state.weekStart = mondayOf(new Date()); await loadSummary(); render(); } }, 'Today'),
      ),
      el('div', { class: 'wf-controls' },
        el('button', { class: 'wf-btn wf-btn-ghost', onclick: async () => { state.weekStart = addDays(state.weekStart, -7); await loadSummary(); render(); } }, '← Previous Week'),
        el('button', { class: 'wf-btn wf-btn-ghost', onclick: async () => { state.weekStart = addDays(state.weekStart, 7); await loadSummary(); render(); } }, 'Next Week →'),
        el('button', { class: 'wf-btn wf-btn-success', onclick: () => openBulkModal() }, 'Mark all paid'),
      ),
    );
    shell.append(controlsRow);

    if (state.msg) {
      const m = el('div', { class: 'wf-msg ' + state.msg.type }, state.msg.text);
      shell.append(m);
      setTimeout(() => { state.msg = null; if (container) render(); }, 5000);
    }

    shell.append(el('div', { class: 'wf-section-title' }, 'Team Member Summary'));

    const tableWrap = el('div', { class: 'wf-table-wrap' });
    if (!state.summary || state.summary.rows.length === 0) {
      tableWrap.append(el('div', { class: 'wf-empty' }, 'No overtime logged this week.'));
    } else {
      const table = el('table', { class: 'wf-table' });
      const thead = el('thead', null,
        el('tr', null,
          el('th', null, 'Team Member'),
          el('th', null, 'Days'),
          el('th', null, 'Regular'),
          el('th', null, 'Overtime'),
          el('th', null, 'Total Hours'),
          el('th', null, 'Amount'),
          el('th', null, 'Status'),
          el('th', null, 'Action'),
        )
      );
      const tbody = el('tbody');
      let totalHours = 0;
      let totalAmount = 0;
      state.summary.rows.forEach(r => {
        const tr = el('tr');
        const memberCell = el('td', null,
          el('div', { class: 'wf-member' },
            el('div', { class: 'wf-avatar' }, initials(r.user_name)),
            el('div', null, r.user_name || ('User #' + r.user_id)),
          )
        );
        const actionCell = el('td');
        if (r.status === 'paid') {
          const dateText = r.paid_at
            ? new Date(r.paid_at).toLocaleDateString('en-IN', { day: '2-digit', month: 'short' })
            : 'Paid';
          const span = el('span', {
            class: 'wf-paid-date',
            title: 'Click to update payment',
            onclick: () => openMarkPaidModal(r),
          }, dateText);
          actionCell.append(span);
          if (r.has_screenshot) {
            actionCell.append(el('a', {
              href: '/api/workforce/payments/' + r.id + '/screenshot',
              target: '_blank',
              style: { marginLeft: '8px', color: '#3b82f6', fontSize: '0.78rem' },
            }, 'Receipt'));
          }
        } else {
          actionCell.append(el('button', {
            class: 'wf-btn',
            style: { fontSize: '0.78rem', padding: '4px 12px' },
            onclick: () => openMarkPaidModal(r),
          }, 'Mark Paid'));
        }
        tr.append(
          memberCell,
          el('td', null, String(r.days_worked || 0)),
          el('td', null, Number(r.regular_hours || 0).toFixed(1) + 'h'),
          el('td', null, Number(r.total_overtime_hours || 0).toFixed(1) + 'h'),
          el('td', null, Number(r.total_hours || 0).toFixed(1) + 'h'),
          el('td', null, '₹' + Math.round(r.total_amount).toLocaleString('en-IN')),
          el('td', null, el('span', { class: 'wf-status-badge ' + (r.status === 'paid' ? 'wf-status-paid' : 'wf-status-pending') }, r.status)),
          actionCell,
        );
        tbody.append(tr);
        totalHours += Number(r.total_hours || 0);
        totalAmount += Number(r.total_amount || 0);
      });

      const totalRow = el('tr', { class: 'wf-total-row' },
        el('td', null, 'Total'),
        el('td', null, ''),
        el('td', null, ''),
        el('td', null, ''),
        el('td', null, totalHours.toFixed(1) + 'h'),
        el('td', null, '₹' + Math.round(totalAmount).toLocaleString('en-IN')),
        el('td', null, ''),
        el('td', null, ''),
      );
      tbody.append(totalRow);

      table.append(thead, tbody);
      tableWrap.append(table);
    }
    shell.append(tableWrap);

    container.append(shell);
  }

  function pill(value, label, kind) {
    const c = el('div', { class: 'wf-pill' + (kind ? ' ' + kind : '') });
    c.append(el('div', { class: 'v' }, value), el('div', { class: 'l' }, label));
    return c;
  }

  function openMarkPaidModal(row) {
    const overlay = el('div', { class: 'wf-modal-overlay' });
    const modal = el('div', { class: 'wf-modal' });
    modal.append(el('h3', null, (row.status === 'paid' ? 'Update payment for ' : 'Mark paid: ') + (row.user_name || 'user')));

    const summary = el('div', { style: { color: '#94a3b8', fontSize: '0.85rem', marginBottom: '14px' } },
      `${Number(row.total_overtime_hours).toFixed(2)}h overtime · ₹${Math.round(row.total_amount).toLocaleString('en-IN')}`
    );
    modal.append(summary);

    const utrRow = el('div', { class: 'wf-modal-row' });
    const utrInput = el('input', { class: 'wf-input', placeholder: 'UTR / transaction ref', value: row.utr_number || '' });
    utrRow.append(el('label', null, 'UTR Number'), utrInput);

    const fileRow = el('div', { class: 'wf-modal-row' });
    const fileInput = el('input', { class: 'wf-input', type: 'file', accept: 'image/*,.pdf' });
    fileRow.append(el('label', null, 'Payment screenshot (optional)'), fileInput);

    const noteRow = el('div', { class: 'wf-modal-row' });
    const noteInput = el('textarea', { class: 'wf-input', rows: 2, placeholder: 'Optional admin note', style: { resize: 'vertical', fontFamily: 'inherit' } });
    noteRow.append(el('label', null, 'Note'), noteInput);

    const errEl = el('div', { class: 'wf-msg', style: { display: 'none' } });
    const actions = el('div', { class: 'wf-modal-actions' });
    const cancel = el('button', { class: 'wf-btn wf-btn-ghost', onclick: () => overlay.remove() }, 'Cancel');
    const save = el('button', {
      class: 'wf-btn wf-btn-success',
      onclick: async () => {
        save.setAttribute('disabled', 'true');
        save.textContent = 'Saving…';
        const fd = new FormData();
        fd.append('user_id', String(row.user_id));
        fd.append('week_start', fmtDate(state.weekStart));
        if (utrInput.value.trim()) fd.append('utr_number', utrInput.value.trim());
        if (noteInput.value.trim()) fd.append('admin_note', noteInput.value.trim());
        if (fileInput.files[0]) fd.append('payment_screenshot', fileInput.files[0]);
        try {
          await api('/workforce/payments/mark-paid', { method: 'POST', body: fd });
          overlay.remove();
          state.msg = { type: 'success', text: `Marked ${row.user_name} as paid for the week.` };
          await loadSummary();
          render();
        } catch (err) {
          save.removeAttribute('disabled');
          save.textContent = 'Save';
          errEl.style.display = '';
          errEl.classList.add('error');
          errEl.textContent = err.message || 'Failed.';
        }
      },
    }, 'Save');
    actions.append(cancel, save);

    modal.append(utrRow, fileRow, noteRow, errEl, actions);
    overlay.append(modal);
    document.body.append(overlay);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
  }

  function openBulkModal() {
    const overlay = el('div', { class: 'wf-modal-overlay' });
    const modal = el('div', { class: 'wf-modal' });
    modal.append(el('h3', null, 'Mark all pending users paid for this week'));
    modal.append(el('div', { style: { color: '#94a3b8', fontSize: '0.85rem', marginBottom: '14px' } },
      'This will mark every pending user with overtime as paid. You can attach a single UTR/note that applies to all.'
    ));

    const utrRow = el('div', { class: 'wf-modal-row' });
    const utrInput = el('input', { class: 'wf-input', placeholder: 'Common UTR (optional)' });
    utrRow.append(el('label', null, 'UTR Number'), utrInput);

    const noteRow = el('div', { class: 'wf-modal-row' });
    const noteInput = el('textarea', { class: 'wf-input', rows: 2, placeholder: 'Optional note', style: { resize: 'vertical', fontFamily: 'inherit' } });
    noteRow.append(el('label', null, 'Note'), noteInput);

    const errEl = el('div', { class: 'wf-msg', style: { display: 'none' } });
    const actions = el('div', { class: 'wf-modal-actions' });
    const cancel = el('button', { class: 'wf-btn wf-btn-ghost', onclick: () => overlay.remove() }, 'Cancel');
    const save = el('button', {
      class: 'wf-btn wf-btn-success',
      onclick: async () => {
        save.setAttribute('disabled', 'true');
        save.textContent = 'Saving…';
        try {
          const result = await api('/workforce/payments/bulk-mark-paid', {
            method: 'POST',
            body: {
              week_start: fmtDate(state.weekStart),
              utr_number: utrInput.value.trim() || null,
              admin_note: noteInput.value.trim() || null,
            },
          });
          overlay.remove();
          state.msg = { type: 'success', text: `Marked ${result.marked} user(s) paid.` };
          await loadSummary();
          render();
        } catch (err) {
          save.removeAttribute('disabled');
          save.textContent = 'Confirm';
          errEl.style.display = '';
          errEl.classList.add('error');
          errEl.textContent = err.message || 'Failed.';
        }
      },
    }, 'Confirm');
    actions.append(cancel, save);

    modal.append(utrRow, noteRow, errEl, actions);
    overlay.append(modal);
    document.body.append(overlay);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
  }

  async function entry(targetContainer) {
    container = targetContainer || document.getElementById('workforceAdminView');
    if (!container) return;
    try {
      await loadSummary();
    } catch (err) {
      container.innerHTML = `<div class="wf-empty">Failed to load: ${err.message}</div>`;
      return;
    }
    render();
  }

  window.WorkforceAdmin = { render: entry };
})();
