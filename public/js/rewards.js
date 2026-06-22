/**
 * Rewards — JP-assigned reward tasks.
 *
 * - My Rewards (everyone, non-admin): wallet pills + filter chips + flat list
 *   of tasks. Click a row → inline expand (timeline + progress form / mark-done).
 * - Manage (JP only): always-visible "Assign reward task" form on top, filter
 *   chips, flat list. Click a row → inline expand (timeline + approve/reject).
 * - Pay (Ayush): pending withdrawals + recent paid. Mark-paid is inline.
 *
 * Backend: /api/rewards/*
 *
 * Exposes window.Rewards.render(containerEl).
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

  function fmtINR(n) {
    if (n == null) return '—';
    const num = Number(n);
    if (isNaN(num)) return '—';
    return '₹' + num.toLocaleString('en-IN', { maximumFractionDigits: 2, minimumFractionDigits: 0 });
  }

  function fmtDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
  }

  function fmtRelative(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const diffMs = Date.now() - d.getTime();
    const m = Math.floor(diffMs / 60000);
    if (m < 1) return 'just now';
    if (m < 60) return m + 'm ago';
    const h = Math.floor(m / 60);
    if (h < 24) return h + 'h ago';
    const days = Math.floor(h / 24);
    if (days < 30) return days + 'd ago';
    return fmtDate(iso);
  }

  function initials(name) {
    if (!name) return '?';
    return name.split(/\s+/).slice(0, 2).map(w => w[0] || '').join('').toUpperCase() || '?';
  }

  function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  // Render description text with clickable URLs (Google Forms, sheets, etc).
  function linkifyDescription(text) {
    return escapeHtml(text).replace(
      /(https?:\/\/[^\s<]+)/g,
      '<a href="$1" target="_blank" rel="noopener" style="color:#3b82f6;text-decoration:underline;word-break:break-all;">$1</a>'
    );
  }

  // ── Styles ────────────────────────────────────────────────────────────────
  const styles = `
    .rw-shell { padding:24px 28px; color:#e4e4e7; max-width:1100px; margin:0 auto; font-size:13px; }
    .rw-head { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; flex-wrap:wrap; margin-bottom:16px; }
    .rw-title h2 { color:#fafafa; font-size:18px; font-weight:600; margin:0; letter-spacing:-0.3px; }
    .rw-title p { color:#a1a1aa; font-size:13px; margin:2px 0 0; }
    .rw-tabs { display:flex; gap:4px; border-bottom:1px solid #27272a; margin-bottom:18px; }
    .rw-tab { background:transparent; color:#71717a; border:0; border-bottom:2px solid transparent; padding:9px 14px; cursor:pointer; font-size:13px; font-weight:500; font-family:inherit; transition:color .15s, border-color .15s; }
    .rw-tab:hover { color:#e4e4e7; }
    .rw-tab.active { color:#fafafa; border-bottom-color:#3b82f6; }
    .rw-tab-badge { display:inline-block; min-width:18px; padding:0 6px; height:18px; line-height:18px; text-align:center; background:#3b82f6; color:#fff; border-radius:9px; font-size:11px; font-weight:600; margin-left:6px; }

    .rw-summary-strip { display:flex; gap:8px; flex-wrap:wrap; }
    .rw-pill { background:#18181b; border:1px solid #27272a; border-radius:8px; padding:10px 14px; min-width:110px; }
    .rw-pill .v { color:#fafafa; font-size:17px; font-weight:600; line-height:1.2; }
    .rw-pill .l { color:#71717a; font-size:11px; text-transform:uppercase; letter-spacing:0.05em; margin-top:3px; font-weight:500; }
    .rw-pill.earned .v { color:#4ade80; }
    .rw-pill.awaiting .v { color:#fbbf24; }
    .rw-pill.paid .v { color:#a1a1aa; }

    .rw-btn { padding:8px 14px; background:#3b82f6; color:#fff; border:1px solid #3b82f6; border-radius:6px; cursor:pointer; font-size:13px; font-weight:500; line-height:1.4; font-family:inherit; }
    .rw-btn:hover:not(:disabled) { background:#2563eb; border-color:#2563eb; }
    .rw-btn:disabled { opacity:0.5; cursor:not-allowed; }
    .rw-btn-ghost { background:#18181b; color:#a1a1aa; border-color:#27272a; }
    .rw-btn-ghost:hover:not(:disabled) { background:#27272a; border-color:#3f3f46; color:#fafafa; }
    .rw-btn-success { background:#22c55e; border-color:#22c55e; }
    .rw-btn-success:hover:not(:disabled) { background:#16a34a; border-color:#16a34a; }
    .rw-btn-danger { background:#ef4444; border-color:#ef4444; }
    .rw-btn-danger:hover:not(:disabled) { background:#dc2626; border-color:#dc2626; }
    .rw-btn-sm { padding:5px 12px; font-size:12px; }

    /* Assign form (always visible) */
    .rw-assign { background:#18181b; border:1px solid #27272a; border-radius:10px; padding:18px 20px; margin-bottom:18px; }
    .rw-assign-title { color:#fafafa; font-size:13px; font-weight:600; margin:0 0 14px; display:flex; align-items:center; gap:8px; }
    .rw-assign-grid { display:grid; grid-template-columns: minmax(160px,1fr) minmax(220px,2fr) minmax(110px,1fr) minmax(130px,1fr) auto; gap:10px; }
    .rw-assign textarea.rw-input { grid-column: 1 / -1; min-height:64px; resize:vertical; }
    @media (max-width: 880px) { .rw-assign-grid { grid-template-columns: 1fr 1fr; } }
    .rw-input, .rw-select, .rw-textarea { padding:9px 11px; background:#0f0f11; border:1px solid #27272a; border-radius:6px; color:#fafafa; font-size:13px; font-family:inherit; width:100%; box-sizing:border-box; transition:border-color .12s; }
    .rw-input:hover, .rw-select:hover, .rw-textarea:hover { border-color:#3f3f46; }
    .rw-input:focus, .rw-select:focus, .rw-textarea:focus { outline:none; border-color:#3b82f6; }
    .rw-textarea { resize:vertical; min-height:70px; line-height:1.5; }

    /* Filter chips */
    .rw-filters { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px; }
    .rw-chip { background:#18181b; color:#a1a1aa; border:1px solid #27272a; border-radius:999px; padding:5px 12px; cursor:pointer; font-size:12px; font-weight:500; font-family:inherit; }
    .rw-chip:hover { color:#e4e4e7; }
    .rw-chip.active { background:#3b82f6; color:#fff; border-color:#3b82f6; }
    .rw-chip .ct { opacity:0.75; margin-left:4px; }

    /* Flat task list */
    .rw-tasklist { background:#18181b; border:1px solid #27272a; border-radius:10px; overflow:hidden; }
    .rw-task { border-bottom:1px solid #27272a; transition:background .12s; }
    .rw-task:last-child { border-bottom:0; }
    .rw-task.expanded { background:#13131a; }
    .rw-task-head { display:flex; gap:12px; align-items:flex-start; padding:16px 18px; cursor:pointer; transition:background .12s; }
    .rw-task-head:hover { background:#1a1a1e; }
    .rw-task.expanded > .rw-task-head { background:#1a1a1e; }
    .rw-twirl { color:#71717a; font-size:11px; user-select:none; padding-top:5px; flex-shrink:0; width:12px; }
    .rw-task-main { flex:1; min-width:0; }
    .rw-task-assignee-chip { display:inline-flex; align-items:center; gap:7px; background:#0f0f11; border:1px solid #27272a; border-radius:999px; padding:3px 11px 3px 4px; font-size:11.5px; color:#d4d4d8; margin-bottom:9px; }
    .rw-avatar { width:24px; height:24px; border-radius:50%; background:#3b82f6; color:#fff; display:flex; align-items:center; justify-content:center; font-size:10.5px; font-weight:600; flex-shrink:0; }
    .rw-task-title { display:block; color:#fafafa; font-weight:500; line-height:1.5; font-size:14px; word-break:break-word; overflow-wrap:anywhere; }
    .rw-task-meta { display:flex; gap:14px; align-items:center; margin-top:9px; flex-wrap:wrap; color:#a1a1aa; font-size:12.5px; }
    .rw-task-meta .amount { color:#fafafa; font-weight:600; }
    .rw-task-meta .amount .strike { color:#71717a; text-decoration:line-through; font-weight:400; font-size:11px; margin-left:5px; }
    .rw-task-meta .deadline { color:#a1a1aa; }
    .rw-task-meta .deadline.overdue { color:#fdba74; font-weight:600; }
    .rw-task-meta .sep { color:#3f3f46; }

    .rw-status { display:inline-block; padding:4px 10px; border-radius:4px; font-size:10px; text-transform:uppercase; font-weight:700; letter-spacing:0.06em; flex-shrink:0; align-self:flex-start; margin-top:1px; }
    .rw-status-assigned { background:rgba(59,130,246,0.15); color:#93c5fd; }
    .rw-status-submitted { background:rgba(234,179,8,0.15); color:#fde68a; }
    .rw-status-approved { background:rgba(34,197,94,0.15); color:#4ade80; }
    .rw-status-rejected { background:rgba(239,68,68,0.15); color:#fca5a5; }
    .rw-status-pending { background:rgba(234,179,8,0.15); color:#fde68a; }
    .rw-status-paid { background:rgba(34,197,94,0.15); color:#4ade80; }
    .rw-status-cancelled { background:rgba(113,113,122,0.18); color:#a1a1aa; }
    .rw-overdue-badge { display:inline-block; padding:2px 8px; border-radius:4px; font-size:10px; text-transform:uppercase; font-weight:600; letter-spacing:0.04em; background:rgba(251,146,60,0.15); color:#fdba74; }

    /* Expanded body */
    .rw-task-body { background:#0f0f11; border-top:1px solid #27272a; padding:20px 22px 22px; }
    .rw-section { margin-bottom:20px; }
    .rw-section:last-child { margin-bottom:0; }
    .rw-section-label { color:#71717a; font-size:10.5px; text-transform:uppercase; letter-spacing:0.08em; font-weight:600; margin-bottom:8px; }
    .rw-divider { border-top:1px solid #27272a; margin:20px 0; }
    .rw-desc { color:#d4d4d8; font-size:13px; line-height:1.6; white-space:pre-wrap; word-break:break-word; }

    .rw-timeline { border-left:2px solid #27272a; padding-left:16px; margin-left:5px; }
    .rw-timeline-item { position:relative; padding-bottom:14px; }
    .rw-timeline-item:last-child { padding-bottom:0; }
    .rw-timeline-item::before { content:''; position:absolute; left:-22px; top:5px; width:10px; height:10px; border-radius:50%; background:#3b82f6; border:2px solid #0f0f11; }
    .rw-timeline-item.submission::before { background:#fbbf24; }
    .rw-timeline-item.review::before { background:#4ade80; }
    .rw-timeline-item.review.rejected::before { background:#ef4444; }
    .rw-timeline-author { color:#fafafa; font-weight:600; font-size:12.5px; }
    .rw-timeline-when { color:#71717a; font-size:11px; margin-left:8px; }
    .rw-timeline-body { color:#e4e4e7; font-size:13px; line-height:1.55; margin-top:4px; white-space:pre-wrap; word-break:break-word; }
    .rw-evidence { color:#3b82f6; font-size:12px; text-decoration:none; margin-top:4px; display:inline-block; }
    .rw-evidence:hover { text-decoration:underline; }
    .rw-empty-timeline { color:#71717a; font-style:italic; font-size:12.5px; padding:4px 0; }

    /* Form-style action sections in expanded body */
    .rw-form { display:flex; flex-direction:column; gap:8px; }
    .rw-form .rw-textarea, .rw-form .rw-input { width:100%; }
    .rw-form-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:4px; }
    .rw-action-hint { color:#71717a; font-size:11.5px; margin-top:4px; }
    .rw-action-stack-h { display:flex; gap:8px; flex-wrap:wrap; margin-top:4px; }

    .rw-empty { color:#71717a; padding:30px; text-align:center; font-style:italic; font-size:13px; }
    .rw-msg { padding:9px 12px; border-radius:6px; margin-bottom:12px; font-size:12px; display:none; }
    .rw-msg.error { background:rgba(239,68,68,0.1); color:#fca5a5; border:1px solid rgba(239,68,68,0.25); display:block; }
    .rw-msg.success { background:rgba(34,197,94,0.1); color:#4ade80; border:1px solid rgba(34,197,94,0.25); display:block; }
    .rw-flash { padding:10px 14px; border-radius:6px; margin-bottom:12px; font-size:12.5px; }
    .rw-flash.success { background:rgba(34,197,94,0.1); color:#4ade80; border:1px solid rgba(34,197,94,0.25); }
    .rw-flash.error { background:rgba(239,68,68,0.1); color:#fca5a5; border:1px solid rgba(239,68,68,0.25); }

    /* Pay tab list */
    .rw-pay-row { display:grid; grid-template-columns: minmax(140px,1.5fr) 1fr 100px 100px auto; gap:10px; align-items:center; padding:12px 14px; border-bottom:1px solid #27272a; }
    .rw-pay-row:last-child { border-bottom:0; }
    .rw-pay-row:hover { background:#1a1a1e; }
  `;

  function ensureStyles() {
    if (document.getElementById('rw-styles')) return;
    const s = document.createElement('style');
    s.id = 'rw-styles';
    s.textContent = styles;
    document.head.appendChild(s);
  }

  // ── State ─────────────────────────────────────────────────────────────────
  const state = {
    tab: 'mine',            // 'mine' | 'manage' | 'pool' | 'pay'
    manageFilter: 'all',  // 'all' | 'submitted' | 'in_progress' | 'reviewed'
    mineFilter: 'all',    // 'all' | 'active' | 'closed'
    wallet: null,
    myTasks: [],
    myWithdrawals: [],
    manageTasks: [],
    payQueue: { pending: [], recent_paid: [] },
    poolMine: [],            // creator's own reward pools (Krishnan)
    poolQueue: { pending: [], recent_paid: [] }, // payer's pool queue (Ayush)
    flash: null,
    expanded: new Set(),    // task ids currently expanded inline
    // Inline action sub-form expansions: keys like "approve_reduced:42", "reject:42", "submit:42"
    actionOpen: new Set(),
    payExpanded: new Set(),
    poolExpanded: new Set(),
  };
  let container = null;

  // ── Loaders ───────────────────────────────────────────────────────────────
  async function loadWallet() {
    state.wallet = await api('/rewards/wallet');
  }

  async function loadMine() {
    const [w, t, withs] = await Promise.all([
      api('/rewards/wallet'),
      api('/rewards/tasks/mine'),
      api('/rewards/withdrawals/me'),
    ]);
    state.wallet = w;
    state.myTasks = t.tasks || [];
    state.myWithdrawals = withs.withdrawals || [];
  }

  async function loadManage() {
    const r = await api('/rewards/tasks/manage/all');
    state.manageTasks = r.tasks || [];
  }

  async function loadPoolMine() {
    const r = await api('/rewards/pools/mine');
    state.poolMine = r.pools || [];
  }

  async function loadPayQueue() {
    const [w, p] = await Promise.all([
      api('/rewards/withdrawals/pending'),
      api('/rewards/pools/pending').catch(() => ({ pending: [], recent_paid: [] })),
    ]);
    state.payQueue = { pending: w.pending || [], recent_paid: w.recent_paid || [] };
    state.poolQueue = { pending: p.pending || [], recent_paid: p.recent_paid || [] };
  }

  async function reloadCurrentTab() {
    try {
      if (state.tab === 'mine') await loadMine();
      else if (state.tab === 'manage') await loadManage();
      else if (state.tab === 'pool') await loadPoolMine();
      else if (state.tab === 'pay') await loadPayQueue();
    } catch (e) { setFlash('error', e.message); }
    render();
  }

  function setFlash(type, text) {
    state.flash = { type, text };
    setTimeout(() => { if (state.flash && state.flash.text === text) { state.flash = null; render(); } }, 4000);
  }

  function isReviewer() { return !!state.wallet?.roles?.is_reviewer; }
  function isPayer() { return !!state.wallet?.roles?.is_payer; }
  function isAdmin() { return isReviewer() || isPayer(); }
  function isPoolCreator() { return !!state.wallet?.roles?.is_pool_creator; }

  function toggleExpand(id) {
    if (state.expanded.has(id)) state.expanded.delete(id);
    else state.expanded.add(id);
    render();
  }

  function toggleAction(key) {
    if (state.actionOpen.has(key)) state.actionOpen.delete(key);
    else state.actionOpen.add(key);
    render();
  }

  // ── Top-level render ─────────────────────────────────────────────────────
  function render() {
    if (!container) return;
    ensureStyles();
    container.classList.remove('hidden');
    container.innerHTML = '';

    const shell = el('div', { class: 'rw-shell' });

    shell.appendChild(el('div', { class: 'rw-head' },
      el('div', { class: 'rw-title' },
        el('h2', null, isAdmin() ? 'Reward Pool' : 'Rewards'),
        el('p', null, headerSubtitle()),
      ),
      isAdmin() ? null : renderBalanceStrip(),
    ));

    if (state.flash) {
      shell.appendChild(el('div', { class: 'rw-flash ' + state.flash.type }, state.flash.text));
    }

    shell.appendChild(renderTabs());

    if (state.tab === 'mine') shell.appendChild(renderMineTab());
    else if (state.tab === 'manage') shell.appendChild(renderManageTab());
    else if (state.tab === 'pool') shell.appendChild(renderPoolTab());
    else if (state.tab === 'pay') shell.appendChild(renderPayTab());

    container.appendChild(shell);
  }

  function headerSubtitle() {
    if (state.tab === 'manage') return 'Assign reward tasks and review what comes back.';
    if (state.tab === 'pool') return 'Set a team reward pool and send it to Finance to pay.';
    if (state.tab === 'pay') return 'Mark approved-task payouts as paid.';
    return 'Reward tasks assigned to you and your earnings.';
  }

  function renderBalanceStrip() {
    const b = state.wallet?.balance || {};
    return el('div', { class: 'rw-summary-strip' },
      pill(fmtINR(b.earned_total || 0), 'Earned', 'earned'),
      pill(fmtINR(b.awaiting_payment || 0), 'Awaiting', 'awaiting'),
      pill(fmtINR(b.paid_total || 0), 'Paid', 'paid'),
    );
  }

  function pill(value, label, kind) {
    return el('div', { class: 'rw-pill ' + (kind || '') },
      el('div', { class: 'v' }, value),
      el('div', { class: 'l' }, label),
    );
  }

  function renderTabs() {
    const tabs = el('div', { class: 'rw-tabs' });
    if (!isAdmin()) {
      const activeMine = state.myTasks.filter(t => t.status === 'assigned' || t.status === 'submitted').length;
      tabs.appendChild(tabBtn('mine', 'My Rewards', activeMine));
    }
    if (isPoolCreator()) {
      tabs.appendChild(tabBtn('pool', 'Reward Pool', 0));
    }
    if (isReviewer()) {
      const pending = state.manageTasks.filter(t => t.status === 'submitted').length;
      tabs.appendChild(tabBtn('manage', 'Manage', pending));
    }
    if (isPayer()) {
      const payCount = state.payQueue.pending.length + state.poolQueue.pending.length;
      tabs.appendChild(tabBtn('pay', 'Pay', payCount));
    }
    return tabs;
  }

  function tabBtn(id, label, badge) {
    const t = el('button', {
      class: 'rw-tab' + (state.tab === id ? ' active' : ''),
      onclick: async () => {
        state.tab = id;
        state.expanded.clear();
        state.actionOpen.clear();
        render();
        await reloadCurrentTab();
      },
    }, label);
    if (badge > 0) t.appendChild(el('span', { class: 'rw-tab-badge' }, String(badge)));
    return t;
  }

  // ── Manage tab (JP) ───────────────────────────────────────────────────────
  function renderManageTab() {
    const wrap = el('div');
    wrap.appendChild(renderAssignForm());

    const tasks = state.manageTasks;
    const counts = {
      all: tasks.length,
      submitted: tasks.filter(t => t.status === 'submitted').length,
      in_progress: tasks.filter(t => t.status === 'assigned').length,
      reviewed: tasks.filter(t => t.status === 'approved' || t.status === 'rejected').length,
    };
    wrap.appendChild(renderFilters('manageFilter', [
      { id: 'all', label: 'All', count: counts.all },
      { id: 'submitted', label: 'Submitted', count: counts.submitted },
      { id: 'in_progress', label: 'In progress', count: counts.in_progress },
      { id: 'reviewed', label: 'Reviewed', count: counts.reviewed },
    ]));

    const filtered = tasks.filter(t => {
      if (state.manageFilter === 'all') return true;
      if (state.manageFilter === 'submitted') return t.status === 'submitted';
      if (state.manageFilter === 'in_progress') return t.status === 'assigned';
      if (state.manageFilter === 'reviewed') return t.status === 'approved' || t.status === 'rejected';
      return true;
    });

    wrap.appendChild(renderTaskList(filtered, true));
    return wrap;
  }

  function renderAssignForm() {
    const card = el('div', { class: 'rw-assign' });
    card.appendChild(el('div', { class: 'rw-assign-title' }, '➕ Assign a reward task'));

    const msg = el('div', { class: 'rw-msg' });
    card.appendChild(msg);

    const people = (window.__PORTAL_CONFIG?.MODAL_PEOPLE || []).slice().sort((a, b) => a.name.localeCompare(b.name));

    const assigneeSel = el('select', { class: 'rw-select' },
      el('option', { value: '' }, '— Assignee —'),
      ...people.map(p => el('option', { value: p.id }, p.name)),
    );
    const titleInput = el('input', { class: 'rw-input', type: 'text', maxlength: 200, placeholder: 'Task title (e.g., Edit hero video)' });
    const amountInput = el('input', { class: 'rw-input', type: 'number', min: 1, max: 9999999, step: 1, placeholder: '₹ Amount' });
    const deadlineInput = el('input', { class: 'rw-input', type: 'date', title: 'Deadline (optional)' });
    const descInput = el('textarea', { class: 'rw-input', placeholder: 'Details (optional)', maxlength: 5000 });

    const submitBtn = el('button', { class: 'rw-btn' }, 'Assign');
    submitBtn.addEventListener('click', async () => {
      const assigneeId = Number(assigneeSel.value);
      const title = titleInput.value.trim();
      const amount = Number(amountInput.value);
      if (!assigneeId) return showMsg(msg, 'error', 'Pick an assignee.');
      if (!title) return showMsg(msg, 'error', 'Add a title.');
      if (!amount || amount <= 0) return showMsg(msg, 'error', 'Amount must be greater than zero.');
      submitBtn.disabled = true;
      try {
        await api('/rewards/tasks', {
          method: 'POST',
          body: {
            assigned_to_id: assigneeId,
            title,
            description: descInput.value.trim() || null,
            amount,
            deadline: deadlineInput.value || null,
          },
        });
        assigneeSel.value = ''; titleInput.value = ''; amountInput.value = ''; deadlineInput.value = ''; descInput.value = '';
        msg.style.display = 'none';
        setFlash('success', `Assigned to ${people.find(p => p.id === assigneeId)?.name || 'user'}. Slack DM sent.`);
        await loadManage();
        render();
      } catch (e) {
        showMsg(msg, 'error', e.message);
      } finally {
        submitBtn.disabled = false;
      }
    });

    const grid = el('div', { class: 'rw-assign-grid' },
      assigneeSel, titleInput, amountInput, deadlineInput, submitBtn,
      descInput,
    );
    card.appendChild(grid);
    return card;
  }

  function renderFilters(stateKey, chips) {
    const wrap = el('div', { class: 'rw-filters' });
    chips.forEach(c => {
      const active = state[stateKey] === c.id;
      const btn = el('button', {
        class: 'rw-chip' + (active ? ' active' : ''),
        onclick: () => { state[stateKey] = c.id; render(); },
      }, c.label);
      if (c.count > 0) btn.appendChild(el('span', { class: 'ct' }, '(' + c.count + ')'));
      wrap.appendChild(btn);
    });
    return wrap;
  }

  function renderTaskList(tasks, forJp) {
    if (!tasks.length) {
      const list = el('div', { class: 'rw-tasklist' });
      list.appendChild(el('div', { class: 'rw-empty' }, emptyMessageFor(forJp)));
      return list;
    }
    const list = el('div', { class: 'rw-tasklist' });
    tasks.forEach(t => list.appendChild(renderTaskRow(t, forJp)));
    return list;
  }

  function emptyMessageFor(forJp) {
    if (forJp) {
      if (state.manageFilter === 'submitted') return 'Nothing waiting on you.';
      if (state.manageFilter === 'in_progress') return 'No active tasks. Assign one above.';
      if (state.manageFilter === 'reviewed') return 'No reviewed tasks yet.';
      return 'No tasks yet. Assign one above to get started.';
    }
    if (state.mineFilter === 'active') return 'No active reward tasks. JP will notify you when you have one.';
    if (state.mineFilter === 'closed') return 'No closed tasks yet.';
    return 'No reward tasks yet.';
  }

  function renderTaskRow(task, forJp) {
    const expanded = state.expanded.has(task.id);
    const row = el('div', { class: 'rw-task' + (expanded ? ' expanded' : '') });

    const head = el('div', { class: 'rw-task-head', onclick: () => toggleExpand(task.id) });
    head.appendChild(el('span', { class: 'rw-twirl' }, expanded ? '▾' : '▸'));

    const main = el('div', { class: 'rw-task-main' });

    if (forJp && task.assignee) {
      main.appendChild(el('div', { class: 'rw-task-assignee-chip' },
        el('span', { class: 'rw-avatar' }, initials(task.assignee.name)),
        el('span', null, task.assignee.name),
      ));
    }

    main.appendChild(el('div', { class: 'rw-task-title' }, task.title));

    // Meta line: amount · deadline
    const meta = el('div', { class: 'rw-task-meta' });
    const amountSpan = el('span', { class: 'amount' });
    if (task.status === 'approved' && task.final_amount != null) {
      amountSpan.appendChild(document.createTextNode(fmtINR(task.final_amount)));
      if (Number(task.final_amount) < Number(task.amount)) {
        amountSpan.appendChild(el('span', { class: 'strike' }, fmtINR(task.amount)));
      }
    } else if (task.status === 'rejected') {
      amountSpan.appendChild(document.createTextNode(fmtINR(0)));
      amountSpan.appendChild(el('span', { class: 'strike' }, fmtINR(task.amount)));
    } else {
      amountSpan.appendChild(document.createTextNode(fmtINR(task.amount)));
    }
    meta.appendChild(amountSpan);

    if (task.deadline) {
      meta.appendChild(el('span', { class: 'sep' }, '·'));
      const dl = el('span', { class: 'deadline' + (task.is_overdue ? ' overdue' : '') },
        (task.is_overdue ? '⚠ Due ' : 'Due ') + fmtDate(task.deadline),
      );
      meta.appendChild(dl);
    }
    main.appendChild(meta);

    head.appendChild(main);

    head.appendChild(el('span', {
      class: 'rw-status rw-status-' + task.status,
    }, task.status.replace('_', ' ')));

    row.appendChild(head);

    if (expanded) {
      row.appendChild(renderTaskBody(task, forJp));
    }
    return row;
  }

  function renderTaskBody(task, forJp) {
    const body = el('div', { class: 'rw-task-body' });

    if (task.description) {
      const section = el('div', { class: 'rw-section' });
      section.appendChild(el('div', { class: 'rw-section-label' }, 'Details'));
      const desc = el('div', { class: 'rw-desc' });
      desc.innerHTML = linkifyDescription(task.description);
      section.appendChild(desc);
      body.appendChild(section);
    }

    const timelineSection = el('div', { class: 'rw-section' });
    timelineSection.appendChild(el('div', { class: 'rw-section-label' }, 'Activity'));
    timelineSection.appendChild(renderTimeline(task));
    body.appendChild(timelineSection);

    const actions = renderTaskActions(task, forJp);
    if (actions) body.appendChild(actions);
    return body;
  }

  function renderTimeline(task) {
    const events = [];
    (task.updates || []).forEach(u => events.push({
      kind: 'update',
      at: u.created_at,
      author: u.user?.name || 'Employee',
      body: u.note,
      link: u.evidence_url,
    }));
    if (task.submitted_at) events.push({
      kind: 'submission',
      at: task.submitted_at,
      author: task.assignee?.name || 'Assignee',
      body: task.submission_note || '(marked as done)',
      link: task.submission_evidence_url,
    });
    if (task.reviewed_at) events.push({
      kind: 'review',
      at: task.reviewed_at,
      author: task.reviewer?.name || 'JP',
      body: (task.status === 'approved'
        ? `✅ Approved at ${fmtINR(task.final_amount)}`
        : '❌ Rejected') + (task.review_note ? '\n' + task.review_note : ''),
      rejected: task.status === 'rejected',
    });
    events.sort((a, b) => new Date(a.at) - new Date(b.at));

    if (!events.length) {
      return el('div', { class: 'rw-empty-timeline', style: { marginBottom: '14px' } }, 'No activity yet. Post an update as you make progress.');
    }
    const timeline = el('div', { class: 'rw-timeline' });
    events.forEach(ev => {
      timeline.appendChild(el('div', {
        class: 'rw-timeline-item ' + (ev.kind === 'submission' ? 'submission' : '') + (ev.kind === 'review' ? ' review' : '') + (ev.rejected ? ' rejected' : ''),
      },
        el('div', null,
          el('span', { class: 'rw-timeline-author' }, ev.author),
          el('span', { class: 'rw-timeline-when' }, fmtRelative(ev.at)),
        ),
        el('div', { class: 'rw-timeline-body' }, ev.body),
        ev.link ? el('a', { class: 'rw-evidence', href: ev.link, target: '_blank', rel: 'noopener' }, '🔗 Open link') : null,
      ));
    });
    return timeline;
  }

  function renderTaskActions(task, forJp) {
    const isAssignee = task.assignee?.id ? task.assignee.id === window.__PORTAL_CONFIG?.userId : !forJp;
    const container = el('div');
    let added = false;

    if (isAssignee && (task.status === 'assigned' || task.status === 'submitted')) {
      container.appendChild(el('div', { class: 'rw-divider' }));
      container.appendChild(renderProgressUpdateAction(task));
      added = true;
    }
    if (isAssignee && task.status === 'assigned') {
      container.appendChild(el('div', { class: 'rw-divider' }));
      container.appendChild(renderMarkDoneAction(task));
      added = true;
    }
    if (forJp && (task.status === 'assigned' || task.status === 'submitted')) {
      container.appendChild(el('div', { class: 'rw-divider' }));
      container.appendChild(renderReviewActions(task));
      added = true;
    }
    return added ? container : null;
  }

  function renderProgressUpdateAction(task) {
    const section = el('div', { class: 'rw-section' });
    section.appendChild(el('div', { class: 'rw-section-label' }, 'Post a progress update'));
    const msg = el('div', { class: 'rw-msg' });
    section.appendChild(msg);
    const form = el('div', { class: 'rw-form' });
    const noteInput = el('textarea', { class: 'rw-textarea', maxlength: 2000, placeholder: 'What did you work on?' });
    const urlInput = el('input', { class: 'rw-input', type: 'text', maxlength: 500, placeholder: 'Optional link (Drive, GitHub, Notion…)' });
    const btn = el('button', { class: 'rw-btn rw-btn-sm' }, 'Post update');
    btn.addEventListener('click', async () => {
      const note = noteInput.value.trim();
      if (!note) return showMsg(msg, 'error', 'Note required.');
      btn.disabled = true;
      try {
        await api(`/rewards/tasks/${task.id}/updates`, { method: 'POST', body: { note, evidence_url: urlInput.value.trim() || null } });
        await refreshTask(task.id);
      } catch (e) { showMsg(msg, 'error', e.message); btn.disabled = false; }
    });
    form.appendChild(noteInput);
    form.appendChild(urlInput);
    form.appendChild(el('div', { class: 'rw-form-actions' }, btn));
    section.appendChild(form);
    return section;
  }

  function renderMarkDoneAction(task) {
    const section = el('div', { class: 'rw-section' });
    section.appendChild(el('div', { class: 'rw-section-label' }, 'Mark as done'));
    const msg = el('div', { class: 'rw-msg' });
    section.appendChild(msg);
    const form = el('div', { class: 'rw-form' });
    const noteInput = el('textarea', { class: 'rw-textarea', maxlength: 2000, placeholder: 'Final note for JP (optional)' });
    const urlInput = el('input', { class: 'rw-input', type: 'text', maxlength: 500, placeholder: 'Final evidence link (optional)' });
    const btn = el('button', { class: 'rw-btn rw-btn-success rw-btn-sm' }, 'Submit for review');
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      try {
        await api(`/rewards/tasks/${task.id}/submit`, { method: 'POST', body: { note: noteInput.value.trim() || null, evidence_url: urlInput.value.trim() || null } });
        setFlash('success', 'Submitted. JP has been notified.');
        await refreshTask(task.id);
      } catch (e) { showMsg(msg, 'error', e.message); btn.disabled = false; }
    });
    form.appendChild(noteInput);
    form.appendChild(urlInput);
    form.appendChild(el('div', { class: 'rw-form-actions' }, btn));
    section.appendChild(form);
    return section;
  }

  function renderReviewActions(task) {
    const section = el('div', { class: 'rw-section' });
    section.appendChild(el('div', { class: 'rw-section-label' }, 'Review'));
    const msg = el('div', { class: 'rw-msg' });
    section.appendChild(msg);

    const reducedKey = 'reduced:' + task.id;
    const rejectKey = 'reject:' + task.id;
    const reducedOpen = state.actionOpen.has(reducedKey);
    const rejectOpen = state.actionOpen.has(rejectKey);

    const approveFullBtn = el('button', { class: 'rw-btn rw-btn-success rw-btn-sm' }, `Approve full (${fmtINR(task.amount)})`);
    approveFullBtn.addEventListener('click', async () => {
      approveFullBtn.disabled = true;
      try {
        await api(`/rewards/tasks/${task.id}/approve`, { method: 'POST', body: { final_amount: Number(task.amount) } });
        setFlash('success', `Approved at ${fmtINR(task.amount)}. Withdrawal added to Pay queue.`);
        await refreshTask(task.id);
      } catch (e) { showMsg(msg, 'error', e.message); approveFullBtn.disabled = false; }
    });
    const reducedBtn = el('button', { class: 'rw-btn rw-btn-ghost rw-btn-sm' }, reducedOpen ? 'Cancel reduced' : 'Pay reduced…');
    reducedBtn.addEventListener('click', () => toggleAction(reducedKey));
    const rejectBtn = el('button', { class: 'rw-btn rw-btn-danger rw-btn-sm' }, rejectOpen ? 'Cancel reject' : 'Reject…');
    rejectBtn.addEventListener('click', () => toggleAction(rejectKey));

    section.appendChild(el('div', { class: 'rw-action-stack-h' }, approveFullBtn, reducedBtn, rejectBtn));

    if (reducedOpen) {
      const form = el('div', { class: 'rw-form', style: { marginTop: '12px' } });
      const amtInput = el('input', { class: 'rw-input', type: 'number', min: 0, max: task.amount, step: 1, value: Math.floor(Number(task.amount) / 2), placeholder: `Final ₹ (0 – ${fmtINR(task.amount)})` });
      const noteInput = el('textarea', { class: 'rw-textarea', maxlength: 2000, placeholder: 'Reason (e.g., missed deadline)' });
      const confirmBtn = el('button', { class: 'rw-btn rw-btn-sm' }, 'Confirm reduced amount');
      confirmBtn.addEventListener('click', async () => {
        const amt = Number(amtInput.value);
        if (isNaN(amt) || amt < 0) return showMsg(msg, 'error', 'Amount must be 0 or greater.');
        if (amt > Number(task.amount)) return showMsg(msg, 'error', `Cannot exceed ${fmtINR(task.amount)}.`);
        confirmBtn.disabled = true;
        try {
          await api(`/rewards/tasks/${task.id}/approve`, { method: 'POST', body: { final_amount: amt, note: noteInput.value.trim() || null } });
          setFlash('success', amt > 0 ? `Approved at ${fmtINR(amt)}.` : 'Forfeited (₹0 payout).');
          state.actionOpen.delete(reducedKey);
          await refreshTask(task.id);
        } catch (e) { showMsg(msg, 'error', e.message); confirmBtn.disabled = false; }
      });
      form.appendChild(amtInput);
      form.appendChild(noteInput);
      form.appendChild(el('div', { class: 'rw-action-hint' }, `Original offer ${fmtINR(task.amount)}. Setting 0 = forfeit (no payout).`));
      form.appendChild(el('div', { class: 'rw-form-actions' }, confirmBtn));
      section.appendChild(form);
    }

    if (rejectOpen) {
      const form = el('div', { class: 'rw-form', style: { marginTop: '12px' } });
      const reasonInput = el('textarea', { class: 'rw-textarea', maxlength: 2000, placeholder: 'Required: explain the rejection' });
      const confirmBtn = el('button', { class: 'rw-btn rw-btn-danger rw-btn-sm' }, 'Confirm reject');
      confirmBtn.addEventListener('click', async () => {
        const reason = reasonInput.value.trim();
        if (!reason) return showMsg(msg, 'error', 'Reason is required.');
        confirmBtn.disabled = true;
        try {
          await api(`/rewards/tasks/${task.id}/reject`, { method: 'POST', body: { reason } });
          setFlash('success', 'Rejected.');
          state.actionOpen.delete(rejectKey);
          await refreshTask(task.id);
        } catch (e) { showMsg(msg, 'error', e.message); confirmBtn.disabled = false; }
      });
      form.appendChild(reasonInput);
      form.appendChild(el('div', { class: 'rw-form-actions' }, confirmBtn));
      section.appendChild(form);
    }

    return section;
  }

  // ── My Rewards tab (employee) ─────────────────────────────────────────────
  function renderMineTab() {
    const wrap = el('div');
    const tasks = state.myTasks;
    const counts = {
      all: tasks.length,
      active: tasks.filter(t => t.status === 'assigned' || t.status === 'submitted').length,
      closed: tasks.filter(t => t.status === 'approved' || t.status === 'rejected').length,
    };
    wrap.appendChild(renderFilters('mineFilter', [
      { id: 'all', label: 'All', count: counts.all },
      { id: 'active', label: 'Active', count: counts.active },
      { id: 'closed', label: 'Past', count: counts.closed },
    ]));
    const filtered = tasks.filter(t => {
      if (state.mineFilter === 'all') return true;
      if (state.mineFilter === 'active') return t.status === 'assigned' || t.status === 'submitted';
      if (state.mineFilter === 'closed') return t.status === 'approved' || t.status === 'rejected';
      return true;
    });
    wrap.appendChild(renderTaskList(filtered, false));

    if (state.myWithdrawals.length) {
      wrap.appendChild(el('div', { style: { marginTop: '18px' } }));
      wrap.appendChild(renderPaymentHistory());
    }
    return wrap;
  }

  function renderPaymentHistory() {
    const wrap = el('div');
    wrap.appendChild(el('div', { class: 'rw-assign-title', style: { marginBottom: '8px' } }, '💰 Payment history'));
    const list = el('div', { class: 'rw-tasklist' });
    state.myWithdrawals.forEach(w => {
      list.appendChild(el('div', { class: 'rw-pay-row' },
        el('div', null, w.reward_task?.title || '—'),
        el('div', { style: { color: '#a1a1aa', fontSize: '12px' } }, w.utr_number ? 'UTR ' + w.utr_number : ''),
        el('div', { style: { color: '#fafafa', fontWeight: 600 } }, fmtINR(w.amount)),
        el('div', null, el('span', { class: 'rw-status rw-status-' + w.status }, w.status)),
        el('div', { style: { color: '#a1a1aa', fontSize: '12px', textAlign: 'right' } }, w.paid_at ? fmtDate(w.paid_at) : '—'),
      ));
    });
    wrap.appendChild(list);
    return wrap;
  }

  // ── Reward Pool tab (Krishnan) ────────────────────────────────────────────
  // Krishnan runs his team's weekly performance reward off-Tessa (winners
  // decided orally) and only logs the pool here — title + amount + optional
  // note → straight to Ayush's Pay queue. He sees each pool's status below.
  function renderPoolTab() {
    const wrap = el('div');
    wrap.appendChild(renderPoolCreateForm());

    const pools = state.poolMine;
    const list = el('div', { class: 'rw-tasklist' });
    if (!pools.length) {
      list.appendChild(el('div', { class: 'rw-empty' }, 'No reward pools yet. Set one above to send it to Finance.'));
    } else {
      pools.forEach(p => list.appendChild(renderPoolRow(p)));
    }
    wrap.appendChild(list);
    return wrap;
  }

  function renderPoolCreateForm() {
    const card = el('div', { class: 'rw-assign' });
    card.appendChild(el('div', { class: 'rw-assign-title' }, '🏆 Set a team reward pool'));
    const msg = el('div', { class: 'rw-msg' });
    card.appendChild(msg);

    const titleInput = el('input', { class: 'rw-input', type: 'text', maxlength: 200, placeholder: 'Title (e.g., Week 22 performance reward)' });
    const amountInput = el('input', { class: 'rw-input', type: 'number', min: 1, max: 9999999, step: 1, placeholder: '₹ Amount' });
    const descInput = el('textarea', { class: 'rw-input', placeholder: 'Details (optional)', maxlength: 5000 });
    const submitBtn = el('button', { class: 'rw-btn' }, 'Send to Finance');

    submitBtn.addEventListener('click', async () => {
      const title = titleInput.value.trim();
      const amount = Number(amountInput.value);
      if (!title) return showMsg(msg, 'error', 'Add a title.');
      if (!amount || amount <= 0) return showMsg(msg, 'error', 'Amount must be greater than zero.');
      submitBtn.disabled = true;
      try {
        await api('/rewards/pools', { method: 'POST', body: { title, description: descInput.value.trim() || null, amount } });
        titleInput.value = ''; amountInput.value = ''; descInput.value = '';
        msg.style.display = 'none';
        setFlash('success', `Sent ${fmtINR(amount)} to Finance. Ayush has been notified.`);
        await loadPoolMine();
        render();
      } catch (e) {
        showMsg(msg, 'error', e.message);
      } finally {
        submitBtn.disabled = false;
      }
    });

    const row = el('div', { style: { display: 'grid', gridTemplateColumns: 'minmax(220px,2fr) minmax(120px,1fr) auto', gap: '10px' } },
      titleInput, amountInput, submitBtn,
    );
    card.appendChild(row);
    card.appendChild(el('div', { style: { marginTop: '10px' } }, descInput));
    return card;
  }

  function renderPoolRow(pool) {
    const expanded = state.poolExpanded.has(pool.id);
    const row = el('div', { class: 'rw-task' + (expanded ? ' expanded' : '') });
    const head = el('div', {
      class: 'rw-task-head',
      onclick: () => { if (state.poolExpanded.has(pool.id)) state.poolExpanded.delete(pool.id); else state.poolExpanded.add(pool.id); render(); },
    });
    head.appendChild(el('span', { class: 'rw-twirl' }, expanded ? '▾' : '▸'));
    const main = el('div', { class: 'rw-task-main' });
    main.appendChild(el('div', { class: 'rw-task-title' }, pool.title));
    const meta = el('div', { class: 'rw-task-meta' });
    meta.appendChild(el('span', { class: 'amount' }, fmtINR(pool.amount)));
    meta.appendChild(el('span', { class: 'sep' }, '·'));
    meta.appendChild(el('span', { class: 'deadline' }, fmtRelative(pool.created_at)));
    main.appendChild(meta);
    head.appendChild(main);
    head.appendChild(el('span', { class: 'rw-status rw-status-' + pool.status }, pool.status));
    row.appendChild(head);
    if (expanded) row.appendChild(renderPoolBody(pool));
    return row;
  }

  function renderPoolBody(pool) {
    const body = el('div', { class: 'rw-task-body' });
    if (pool.description) {
      const section = el('div', { class: 'rw-section' });
      section.appendChild(el('div', { class: 'rw-section-label' }, 'Details'));
      const desc = el('div', { class: 'rw-desc' });
      desc.innerHTML = linkifyDescription(pool.description);
      section.appendChild(desc);
      body.appendChild(section);
    }
    const status = el('div', { class: 'rw-section' });
    status.appendChild(el('div', { class: 'rw-section-label' }, 'Status'));
    if (pool.status === 'paid') {
      let txt = '✅ Paid' + (pool.paid_by ? ' by ' + pool.paid_by.name : '') + (pool.paid_at ? ' on ' + fmtDate(pool.paid_at) : '');
      if (pool.utr_number) txt += ' · UTR ' + pool.utr_number;
      status.appendChild(el('div', { class: 'rw-desc' }, txt));
      if (pool.admin_note) status.appendChild(el('div', { class: 'rw-action-hint' }, pool.admin_note));
    } else {
      status.appendChild(el('div', { class: 'rw-desc' }, '⏳ Pending — waiting for Finance to pay.'));
    }
    body.appendChild(status);
    return body;
  }

  // Pool rows on the payer (Ayush) side — distinct from withdrawal rows.
  function buildPoolPayList(pools, withAction) {
    const list = el('div', { class: 'rw-tasklist' });
    if (!pools.length) {
      list.appendChild(el('div', { class: 'rw-empty' }, withAction ? 'No team reward pools pending.' : 'No recent reward pools.'));
      return list;
    }
    pools.forEach(p => {
      const key = 'pool:' + p.id;
      const expanded = state.poolExpanded.has(key);
      const row = el('div', { class: 'rw-task' + (expanded ? ' expanded' : '') });
      const head = el('div', {
        class: 'rw-task-head',
        onclick: withAction ? () => { if (state.poolExpanded.has(key)) state.poolExpanded.delete(key); else state.poolExpanded.add(key); render(); } : null,
      });
      head.appendChild(el('span', { class: 'rw-twirl' }, withAction ? (expanded ? '▾' : '▸') : ''));
      const main = el('div', { class: 'rw-task-main' });
      main.appendChild(el('div', { class: 'rw-task-assignee-chip' },
        el('span', { class: 'rw-avatar' }, initials(p.created_by?.name)),
        el('span', null, (p.created_by?.name || '—') + ' · team pool'),
      ));
      main.appendChild(el('div', { class: 'rw-task-title' }, p.title));
      const meta = el('div', { class: 'rw-task-meta' });
      meta.appendChild(el('span', { class: 'amount' }, fmtINR(p.amount)));
      meta.appendChild(el('span', { class: 'sep' }, '·'));
      meta.appendChild(el('span', { class: 'deadline' }, fmtRelative(withAction ? p.created_at : p.paid_at)));
      if (!withAction && p.utr_number) {
        meta.appendChild(el('span', { class: 'sep' }, '·'));
        meta.appendChild(el('span', { class: 'deadline' }, 'UTR ' + p.utr_number));
      }
      main.appendChild(meta);
      head.appendChild(main);
      head.appendChild(el('span', { class: 'rw-status rw-status-' + p.status }, p.status));
      row.appendChild(head);
      if (withAction && expanded) row.appendChild(renderPoolMarkPaidPanel(p));
      list.appendChild(row);
    });
    return list;
  }

  function renderPoolMarkPaidPanel(p) {
    const body = el('div', { class: 'rw-task-body' });
    if (p.description) {
      const section = el('div', { class: 'rw-section' });
      section.appendChild(el('div', { class: 'rw-section-label' }, 'Details'));
      const desc = el('div', { class: 'rw-desc' });
      desc.innerHTML = linkifyDescription(p.description);
      section.appendChild(desc);
      body.appendChild(section);
    }
    const action = el('div', { class: 'rw-section' });
    action.appendChild(el('div', { class: 'rw-section-label' }, `Mark ${fmtINR(p.amount)} paid (${p.created_by?.name || 'team'} pool)`));
    const msg = el('div', { class: 'rw-msg' });
    action.appendChild(msg);
    const form = el('div', { class: 'rw-form' });
    const utrInput = el('input', { class: 'rw-input', type: 'text', maxlength: 60, placeholder: 'Bank UTR / reference (optional)' });
    const noteInput = el('textarea', { class: 'rw-textarea', maxlength: 1000, placeholder: 'Internal note (optional)' });
    const btn = el('button', { class: 'rw-btn rw-btn-success rw-btn-sm' }, 'Mark paid');
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      try {
        await api(`/rewards/pools/${p.id}/mark-paid`, { method: 'POST', body: { utr_number: utrInput.value.trim() || null, note: noteInput.value.trim() || null } });
        setFlash('success', 'Reward pool marked as paid. Creator notified.');
        state.poolExpanded.delete('pool:' + p.id);
        await loadPayQueue();
        render();
      } catch (e) { showMsg(msg, 'error', e.message); btn.disabled = false; }
    });
    form.appendChild(utrInput);
    form.appendChild(noteInput);
    form.appendChild(el('div', { class: 'rw-form-actions' }, btn));
    action.appendChild(form);
    body.appendChild(action);
    return body;
  }

  // ── Pay tab (Ayush) ───────────────────────────────────────────────────────
  function renderPayTab() {
    const wrap = el('div');
    const { pending, recent_paid } = state.payQueue;
    const poolPending = state.poolQueue.pending;
    const poolPaid = state.poolQueue.recent_paid;

    wrap.appendChild(el('div', { class: 'rw-assign-title', style: { marginBottom: '8px' } }, `Pending payouts (${pending.length})`));
    wrap.appendChild(buildPayList(pending, true));

    wrap.appendChild(el('div', { style: { marginTop: '18px' } }));
    wrap.appendChild(el('div', { class: 'rw-assign-title', style: { marginBottom: '8px' } }, `Team reward pools (${poolPending.length})`));
    wrap.appendChild(buildPoolPayList(poolPending, true));

    if (recent_paid.length) {
      wrap.appendChild(el('div', { style: { marginTop: '18px' } }));
      wrap.appendChild(el('div', { class: 'rw-assign-title', style: { marginBottom: '8px' } }, 'Recently paid'));
      wrap.appendChild(buildPayList(recent_paid, false));
    }
    if (poolPaid.length) {
      wrap.appendChild(el('div', { style: { marginTop: '18px' } }));
      wrap.appendChild(el('div', { class: 'rw-assign-title', style: { marginBottom: '8px' } }, 'Recently paid pools'));
      wrap.appendChild(buildPoolPayList(poolPaid, false));
    }
    return wrap;
  }

  function buildPayList(rows, withAction) {
    const list = el('div', { class: 'rw-tasklist' });
    if (!rows.length) {
      list.appendChild(el('div', { class: 'rw-empty' }, withAction ? 'Inbox zero. Nothing pending.' : 'No recent payments.'));
      return list;
    }
    rows.forEach(w => {
      const expanded = state.payExpanded.has(w.id);
      const row = el('div', { class: 'rw-task' + (expanded ? ' expanded' : '') });
      const head = el('div', {
        class: 'rw-task-head',
        onclick: withAction ? () => { if (state.payExpanded.has(w.id)) state.payExpanded.delete(w.id); else state.payExpanded.add(w.id); render(); } : null,
      });
      head.appendChild(el('span', { class: 'rw-twirl' }, withAction ? (expanded ? '▾' : '▸') : ''));

      const main = el('div', { class: 'rw-task-main' });
      main.appendChild(el('div', { class: 'rw-task-assignee-chip' },
        el('span', { class: 'rw-avatar' }, initials(w.user?.name)),
        el('span', null, w.user?.name || '—'),
      ));
      main.appendChild(el('div', { class: 'rw-task-title' }, w.reward_task?.title || '(no task)'));
      const meta = el('div', { class: 'rw-task-meta' });
      meta.appendChild(el('span', { class: 'amount' }, fmtINR(w.amount)));
      meta.appendChild(el('span', { class: 'sep' }, '·'));
      meta.appendChild(el('span', { class: 'deadline' }, fmtRelative(withAction ? (w.requested_at || w.created_at) : w.paid_at)));
      if (!withAction && w.utr_number) {
        meta.appendChild(el('span', { class: 'sep' }, '·'));
        meta.appendChild(el('span', { class: 'deadline' }, 'UTR ' + w.utr_number));
      }
      main.appendChild(meta);
      head.appendChild(main);

      head.appendChild(el('span', { class: 'rw-status rw-status-' + w.status }, w.status));
      row.appendChild(head);

      if (withAction && expanded) {
        row.appendChild(renderMarkPaidPanel(w));
      }
      list.appendChild(row);
    });
    return list;
  }

  function renderMarkPaidPanel(w) {
    const body = el('div', { class: 'rw-task-body' });
    const action = el('div', { class: 'rw-action' });
    action.appendChild(el('div', { class: 'rw-action-label' }, `Mark ${fmtINR(w.amount)} paid to ${w.user?.name || 'employee'}`));
    const msg = el('div', { class: 'rw-msg' });
    action.appendChild(msg);
    const utrInput = el('input', { class: 'rw-input', type: 'text', maxlength: 60, placeholder: 'Bank UTR / reference (optional)' });
    const noteInput = el('textarea', { class: 'rw-textarea', maxlength: 1000, placeholder: 'Internal note (optional)' });
    const btn = el('button', { class: 'rw-btn rw-btn-success rw-btn-sm rw-act-btn' }, 'Mark paid');
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      try {
        await api(`/rewards/withdrawals/${w.id}/mark-paid`, { method: 'POST', body: { utr_number: utrInput.value.trim() || null, note: noteInput.value.trim() || null } });
        setFlash('success', 'Marked as paid. Employee notified.');
        state.payExpanded.delete(w.id);
        await loadPayQueue();
        render();
      } catch (e) { showMsg(msg, 'error', e.message); btn.disabled = false; }
    });
    action.appendChild(el('div', { class: 'rw-action-row' }, utrInput, noteInput, btn));
    body.appendChild(action);
    return body;
  }

  // ── Helpers ──────────────────────────────────────────────────────────────
  function showMsg(el, type, text) {
    el.className = 'rw-msg ' + type;
    el.style.display = 'block';
    el.textContent = text;
  }

  async function refreshTask(taskId) {
    try {
      const r = await api(`/rewards/tasks/${taskId}`);
      const updated = r.task;
      // Replace in whichever list it's in
      ['myTasks', 'manageTasks'].forEach(key => {
        const idx = state[key].findIndex(t => t.id === updated.id);
        if (idx >= 0) state[key][idx] = updated;
      });
      // Also refresh wallet for the My tab so pills move on approve.
      if (state.tab === 'mine') {
        try { state.wallet = await api('/rewards/wallet'); } catch (e) { /* ignore */ }
      }
      render();
    } catch (e) {
      // Fall back to a full reload of the current tab.
      await reloadCurrentTab();
    }
  }

  // ── Entry ─────────────────────────────────────────────────────────────────
  async function entry(target) {
    container = target;
    if (!container) return;
    try {
      await loadWallet();
      const tasks = [];
      if (isAdmin()) {
        state.tab = isReviewer() ? 'manage' : 'pay';
        if (isReviewer()) tasks.push(loadManage());
        if (isPayer()) tasks.push(loadPayQueue());
      } else if (isPoolCreator()) {
        state.tab = 'pool';
        tasks.push(loadPoolMine());
      } else {
        state.tab = 'mine';
        tasks.push(loadMine());
      }
      await Promise.all(tasks);
    } catch (e) {
      setFlash('error', e.message);
    }
    render();
  }

  window.Rewards = { render: entry };
})();
