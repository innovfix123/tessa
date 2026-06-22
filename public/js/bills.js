/**
 * Bills & Reimbursements — direct employee → admin (Ayush / Shoyab) flow.
 *
 * - My Bills / My Reimbursements (anyone who can submit, incl. admins):
 *   "Request payment" / "Request reimbursement" → modal with file upload +
 *   description; own request history with live status; cancel while pending.
 * - Pay Queue (admins): pending requests (both types) → Mark Paid (UTR + proof
 *   screenshot + note) or Reject (reason). An admin's OWN request is shown but
 *   not payable by them — the other admin settles it. Plus recently paid.
 * - Records (admins): paid-only accounts ledger, filters + client-side CSV.
 *
 * Backend: /api/bills/*    Exposes window.Bills.render(containerEl).
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

  // Multipart upload — branch on res.status BEFORE res.json() so a 413/504 from
  // nginx/php-fpm (which returns HTML, not JSON) doesn't look like a network error.
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

  function fmtINR(n, currency) {
    if (n == null) return '—';
    const num = Number(n);
    if (isNaN(num)) return '—';
    const sym = currency && currency !== 'INR' ? (currency + ' ') : '₹';
    return sym + num.toLocaleString('en-IN', { maximumFractionDigits: 2, minimumFractionDigits: 0 });
  }

  function fmtDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
  }

  // Absolute date + time pinned to IST, e.g. "03 Jun 2026, 14:30 IST".
  function fmtDateTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return '—';
    const date = d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'Asia/Kolkata' });
    const time = d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'Asia/Kolkata' });
    return date + ', ' + time + ' IST';
  }

  function fmtRelative(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const m = Math.floor((Date.now() - d.getTime()) / 60000);
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

  // App runs UTC but employees are IST — anchor "today"/"this month" to IST so the
  // Add-Trip date defaults/maxes and the ledger month line up with the server.
  function istTodayStr() { return new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Kolkata' }); } // YYYY-MM-DD
  function istMonthStr() { return istTodayStr().slice(0, 7); }                                            // YYYY-MM
  function monthLabelFromKey(key) {
    const d = new Date((key || istMonthStr()) + '-01T00:00:00');
    if (isNaN(d.getTime())) return key || '';
    return d.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });
  }

  const TYPE_LABEL = { bill: 'Bill', reimbursement: 'Reimbursement', travel: 'Travel' };
  const SUBMIT_VERB = { bill: 'Request payment', reimbursement: 'Request reimbursement', travel: 'Submit travel sheet' };

  // ── Styles ────────────────────────────────────────────────────────────────
  const styles = `
    .bl-shell { padding:24px 28px; color:#e4e4e7; max-width:1100px; margin:0 auto; font-size:13px; }
    .bl-head { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; flex-wrap:wrap; margin-bottom:16px; }
    .bl-title h2 { color:#fafafa; font-size:18px; font-weight:600; margin:0; letter-spacing:-0.3px; }
    .bl-title p { color:#a1a1aa; font-size:13px; margin:2px 0 0; }
    .bl-tabs { display:flex; gap:4px; border-bottom:1px solid #27272a; margin-bottom:18px; flex-wrap:wrap; }
    .bl-tab { background:transparent; color:#71717a; border:0; border-bottom:2px solid transparent; padding:9px 14px; cursor:pointer; font-size:13px; font-weight:500; font-family:inherit; }
    .bl-tab:hover { color:#e4e4e7; }
    .bl-tab.active { color:#fafafa; border-bottom-color:#3b82f6; }
    .bl-tab-badge { display:inline-block; min-width:18px; padding:0 6px; height:18px; line-height:18px; text-align:center; background:#3b82f6; color:#fff; border-radius:9px; font-size:11px; font-weight:600; margin-left:6px; }

    .bl-btn { padding:8px 14px; background:#3b82f6; color:#fff; border:1px solid #3b82f6; border-radius:6px; cursor:pointer; font-size:13px; font-weight:500; line-height:1.4; font-family:inherit; }
    .bl-btn:hover:not(:disabled) { background:#2563eb; border-color:#2563eb; }
    .bl-btn:disabled { opacity:0.5; cursor:not-allowed; }
    .bl-btn-ghost { background:#18181b; color:#a1a1aa; border-color:#27272a; }
    .bl-btn-ghost:hover:not(:disabled) { background:#27272a; border-color:#3f3f46; color:#fafafa; }
    .bl-btn-success { background:#22c55e; border-color:#22c55e; }
    .bl-btn-success:hover:not(:disabled) { background:#16a34a; border-color:#16a34a; }
    .bl-btn-danger { background:#ef4444; border-color:#ef4444; }
    .bl-btn-danger:hover:not(:disabled) { background:#dc2626; border-color:#dc2626; }
    .bl-btn-sm { padding:5px 12px; font-size:12px; }

    .bl-toolbar { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
    .bl-input, .bl-select, .bl-textarea { padding:9px 11px; background:#0f0f11; border:1px solid #27272a; border-radius:6px; color:#fafafa; font-size:13px; font-family:inherit; width:100%; box-sizing:border-box; }
    .bl-input:focus, .bl-select:focus, .bl-textarea:focus { outline:none; border-color:#3b82f6; }
    .bl-textarea { resize:vertical; min-height:70px; line-height:1.5; }

    .bl-list { background:#18181b; border:1px solid #27272a; border-radius:10px; overflow:hidden; }
    .bl-row { border-bottom:1px solid #27272a; }
    .bl-row:last-child { border-bottom:0; }
    .bl-row.expanded { background:#13131a; }
    .bl-row-head { display:flex; gap:12px; align-items:flex-start; padding:14px 18px; }
    .bl-row-head.clickable { cursor:pointer; }
    .bl-row-head.clickable:hover { background:#1a1a1e; }
    .bl-twirl { color:#71717a; font-size:11px; user-select:none; padding-top:4px; width:12px; flex-shrink:0; }
    .bl-row-main { flex:1; min-width:0; }
    .bl-chip { display:inline-flex; align-items:center; gap:7px; background:#0f0f11; border:1px solid #27272a; border-radius:999px; padding:3px 11px 3px 4px; font-size:11.5px; color:#d4d4d8; margin-bottom:8px; }
    .bl-avatar { width:22px; height:22px; border-radius:50%; background:#3b82f6; color:#fff; display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:600; flex-shrink:0; }
    .bl-row-title { display:block; color:#fafafa; font-weight:500; line-height:1.5; font-size:14px; word-break:break-word; }
    .bl-row-meta { display:flex; gap:12px; align-items:center; margin-top:7px; flex-wrap:wrap; color:#a1a1aa; font-size:12.5px; }
    .bl-row-meta .amount { color:#fafafa; font-weight:600; }
    .bl-row-meta .sep { color:#3f3f46; }
    .bl-type-tag { display:inline-block; padding:2px 8px; border-radius:4px; font-size:10px; text-transform:uppercase; font-weight:700; letter-spacing:0.05em; }
    .bl-type-bill { background:rgba(59,130,246,0.15); color:#93c5fd; }
    .bl-type-reimbursement { background:rgba(168,85,247,0.15); color:#d8b4fe; }
    .bl-type-travel { background:rgba(20,184,166,0.15); color:#5eead4; }
    .bl-link { color:#3b82f6; text-decoration:none; }
    .bl-link:hover { text-decoration:underline; }

    .bl-status { display:inline-block; padding:4px 10px; border-radius:4px; font-size:10px; text-transform:uppercase; font-weight:700; letter-spacing:0.06em; flex-shrink:0; align-self:flex-start; margin-top:1px; }
    .bl-status-pending { background:rgba(234,179,8,0.15); color:#fde68a; }
    .bl-status-paid { background:rgba(34,197,94,0.15); color:#4ade80; }
    .bl-status-rejected { background:rgba(239,68,68,0.15); color:#fca5a5; }

    .bl-body { background:#0f0f11; border-top:1px solid #27272a; padding:18px 22px 20px; }
    .bl-section-label { color:#71717a; font-size:10.5px; text-transform:uppercase; letter-spacing:0.08em; font-weight:600; margin-bottom:8px; }
    .bl-desc { color:#d4d4d8; font-size:13px; line-height:1.6; white-space:pre-wrap; word-break:break-word; margin-bottom:14px; }
    .bl-form { display:flex; flex-direction:column; gap:8px; }
    .bl-form-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:4px; }
    .bl-note { color:#a1a1aa; font-size:12px; margin-top:6px; }

    .bl-cap { background:#18181b; border:1px solid #27272a; border-radius:10px; padding:14px 16px; margin-bottom:14px; }
    .bl-cap-top { display:flex; justify-content:space-between; align-items:baseline; gap:10px; margin-bottom:8px; }
    .bl-cap-used { color:#fafafa; font-weight:600; font-size:14px; }
    .bl-cap-left { color:#a1a1aa; font-size:12.5px; }
    .bl-cap-bar { height:8px; background:#0f0f11; border:1px solid #27272a; border-radius:999px; overflow:hidden; }
    .bl-cap-fill { height:100%; background:#14b8a6; }
    .bl-cap-fill.full { background:#ef4444; }
    .bl-cap-note { color:#71717a; font-size:11.5px; margin-top:8px; }

    .bl-empty { color:#71717a; padding:30px; text-align:center; font-style:italic; font-size:13px; }
    .bl-msg { padding:9px 12px; border-radius:6px; margin:0 0 12px; font-size:12px; display:none; }
    .bl-msg.error { background:rgba(239,68,68,0.1); color:#fca5a5; border:1px solid rgba(239,68,68,0.25); display:block; }
    .bl-msg.success { background:rgba(34,197,94,0.1); color:#4ade80; border:1px solid rgba(34,197,94,0.25); display:block; }
    .bl-flash { padding:10px 14px; border-radius:6px; margin-bottom:12px; font-size:12.5px; }
    .bl-flash.success { background:rgba(34,197,94,0.1); color:#4ade80; border:1px solid rgba(34,197,94,0.25); }
    .bl-flash.error { background:rgba(239,68,68,0.1); color:#fca5a5; border:1px solid rgba(239,68,68,0.25); }

    /* Reimbursement-process announcement (yellow), shown atop each My-* tab */
    .bl-announce { background:rgba(234,179,8,0.08); border:1px solid rgba(234,179,8,0.35); border-left:3px solid #eab308; border-radius:8px; padding:14px 16px; margin-bottom:16px; }
    .bl-announce-title { color:#fde047; font-weight:700; font-size:13.5px; margin-bottom:10px; }
    .bl-announce-sub { color:#fde047; font-weight:600; font-size:12.5px; margin:10px 0 4px; }
    .bl-announce p { color:#e4e4e7; font-size:12.5px; line-height:1.55; margin:4px 0; }
    .bl-announce ol { color:#e4e4e7; font-size:12.5px; line-height:1.55; margin:4px 0; padding-left:20px; }
    .bl-announce .bl-announce-note { color:#a1a1aa; font-size:12px; font-style:italic; margin-top:6px; border-left:2px solid rgba(234,179,8,0.5); padding-left:8px; }

    .bl-rec-filters { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:14px; }
    .bl-rec-filters .bl-input, .bl-rec-filters .bl-select { width:auto; flex:1 1 130px; min-width:110px; }
    .bl-rec-filters .bl-btn { flex:0 0 auto; }

    /* Dropzone / file picker */
    .bl-drop { border:1px dashed #3f3f46; border-radius:8px; padding:16px; text-align:center; color:#a1a1aa; cursor:pointer; background:#0f0f11; transition:border-color .12s, background .12s; }
    .bl-drop:hover, .bl-drop.over { border-color:#3b82f6; background:#13131a; color:#e4e4e7; }
    .bl-drop b { color:#e4e4e7; }
    .bl-file-list { display:flex; flex-direction:column; gap:4px; margin-top:8px; }
    .bl-file-item { color:#4ade80; font-size:12.5px; word-break:break-all; display:flex; align-items:center; justify-content:space-between; gap:8px; }
    .bl-file-remove { background:none; border:none; color:#a1a1aa; cursor:pointer; font-size:15px; line-height:1; padding:0 4px; flex-shrink:0; }
    .bl-file-remove:hover { color:#f87171; }
    .bl-file-name { color:#4ade80; font-size:12.5px; margin-top:8px; word-break:break-all; display:flex; align-items:center; justify-content:space-between; gap:8px; }
    .bl-file-rm { background:none; border:none; color:#a1a1aa; cursor:pointer; font-size:16px; line-height:1; padding:0 4px; flex-shrink:0; }
    .bl-file-rm:hover { color:#f87171; }

    /* Modal */
    .bl-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.6); display:flex; align-items:center; justify-content:center; z-index:9000; padding:20px; }
    .bl-modal { background:#18181b; border:1px solid #27272a; border-radius:12px; width:100%; max-width:460px; max-height:90vh; overflow:auto; box-shadow:0 24px 60px rgba(0,0,0,0.5); }
    .bl-modal-head { display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid #27272a; }
    .bl-modal-head h3 { margin:0; color:#fafafa; font-size:15px; font-weight:600; }
    .bl-modal-close { background:transparent; border:0; color:#71717a; font-size:22px; line-height:1; cursor:pointer; }
    .bl-modal-close:hover { color:#fafafa; }
    .bl-modal-body { padding:18px 20px; display:flex; flex-direction:column; gap:12px; }
    .bl-field label { display:block; color:#a1a1aa; font-size:12px; margin-bottom:5px; }
    .bl-modal-foot { display:flex; justify-content:flex-end; gap:8px; padding:14px 20px; border-top:1px solid #27272a; }
  `;

  function ensureStyles() {
    if (document.getElementById('bl-styles')) return;
    const s = document.createElement('style');
    s.id = 'bl-styles';
    s.textContent = styles;
    document.head.appendChild(s);
  }

  // ── State ─────────────────────────────────────────────────────────────────
  const state = {
    tab: null,
    userId: null,
    flags: { can_submit_bill: false, can_submit_reimbursement: false, can_submit_travel: false, is_admin: false },
    travel: null,        // { cap, used, remaining, month_label }
    travelTrips: [],     // Travel Allowance tab: this + last IST month's logged trips
    travelMeta: null,    // { cap, used, remaining, month_key, month_label } for the current month
    routePresets: [],    // [{from,to}] quick-picks for the Add-Trip route field
    ledger: { trips: [], uploaders: [], months: [], total: 0 },  // admin Travel Ledger
    ledgerFilter: { month: '', uploader: '', search: '' },
    reimbursementCategories: [],            // fixed list for the mandatory category dropdown
    wifiCategory: 'Wifi reimbursement',     // the one category with a hard ₹ ceiling
    wifiCap: 700,                           // Wi-Fi monthly broadband budget
    wifiClaimStatus: null,                  // 'paid' | 'pending' | null — once-a-month lock for this user
    wifiMonthLabel: '',                     // e.g. "June 2026"
    mine: [],
    queue: { pending: [] },     // Pay Queue is pending-only; paid items live in Records
    queueUploaders: [],
    queuePendingTotal: 0,
    queueFilter: { type: '', uploader: '', search: '', from: '', to: '', sort: 'asc' },
    records: [],
    recordsUploaders: [],
    recFilter: { type: '', from: '', to: '', search: '', uploader: '', sort: 'desc' },
    expanded: new Set(),     // pay-queue rows expanded for the mark-paid panel
    queueSelected: new Set(),   // pay-queue bill IDs ticked for Excel export
    actionOpen: new Set(),   // reject sub-forms: "reject:<id>"
    flash: null,
  };
  let container = null;
  let searchDebounce = null;     // shared debounce timer for the live filter search boxes

  // ── Loaders ───────────────────────────────────────────────────────────────
  async function loadMine() {
    const r = await api('/bills');
    state.flags = {
      can_submit_bill: !!r.can_submit_bill,
      can_submit_reimbursement: !!r.can_submit_reimbursement,
      can_submit_travel: !!r.can_submit_travel,
      is_admin: !!r.is_admin,
    };
    state.travel = r.travel || null;
    if (Array.isArray(r.reimbursement_categories)) state.reimbursementCategories = r.reimbursement_categories;
    if (r.wifi_reimbursement_category) state.wifiCategory = r.wifi_reimbursement_category;
    if (r.wifi_reimbursement_cap != null) state.wifiCap = Number(r.wifi_reimbursement_cap);
    state.wifiClaimStatus = r.wifi_claim_status || null;
    state.wifiMonthLabel = r.wifi_month_label || '';
    state.userId = r.user_id;
    state.mine = r.mine || [];
  }

  async function loadQueue() {
    const r = await api('/bills/queue' + queueQuery());
    state.queue = { pending: r.pending || [] };
    if (r.uploaders) state.queueUploaders = r.uploaders;
    if (r.total_pending != null) state.queuePendingTotal = r.total_pending;
    if (r.user_id != null) state.userId = r.user_id;
  }

  // Shared query string for the pay-queue filters.
  function queueQuery() {
    const p = new URLSearchParams();
    const f = state.queueFilter;
    if (f.type) p.set('type', f.type);
    if (f.uploader) p.set('uploader', f.uploader);
    if (f.from) p.set('from', f.from);
    if (f.to) p.set('to', f.to);
    if (f.search) p.set('search', f.search);
    if (f.sort) p.set('sort', f.sort);
    const qs = p.toString();
    return qs ? '?' + qs : '';
  }

  async function loadRecords() {
    const r = await api('/bills/records' + recordsQuery());
    state.records = r.records || [];
    if (r.uploaders) state.recordsUploaders = r.uploaders;
  }

  // Shared query string for the records endpoint + the Excel export link.
  function recordsQuery() {
    const p = new URLSearchParams();
    const f = state.recFilter;
    if (f.type) p.set('type', f.type);
    if (f.uploader) p.set('uploader', f.uploader);
    if (f.from) p.set('from', f.from);
    if (f.to) p.set('to', f.to);
    if (f.search) p.set('search', f.search);
    if (f.sort) p.set('sort', f.sort);
    const qs = p.toString();
    return qs ? '?' + qs : '';
  }

  // Travel Allowance (employee): own trips + monthly cap + route presets.
  async function loadTravelTrips() {
    const r = await api('/travel-trips');
    state.travelTrips = r.trips || [];
    state.travelMeta = r.travel || null;
    state.routePresets = r.route_presets || [];
    if (r.is_admin != null) state.flags.is_admin = !!r.is_admin;
    if (r.user_id != null) state.userId = r.user_id;
  }

  // Travel Ledger (admin): cross-employee trips for one month (or all).
  async function loadTravelLedger() {
    const r = await api('/travel-trips/ledger' + ledgerQuery());
    state.ledger = { trips: r.trips || [], uploaders: r.uploaders || [], months: r.months || [], total: r.total || 0, sync: r.sync || null };
  }

  function ledgerQuery() {
    const p = new URLSearchParams();
    const f = state.ledgerFilter;
    p.set('month', f.month || istMonthStr());   // always explicit so the server doesn't default differently
    if (f.uploader) p.set('uploader', f.uploader);
    if (f.search) p.set('search', f.search);
    const qs = p.toString();
    return qs ? '?' + qs : '';
  }

  async function reloadCurrentTab() {
    try {
      if (state.tab === 'bill' || state.tab === 'reimbursement') await loadMine();
      else if (state.tab === 'travel') await loadTravelTrips();
      else if (state.tab === 'travelLedger') await loadTravelLedger();
      else if (state.tab === 'queue') await loadQueue();
      else if (state.tab === 'records') await loadRecords();
    } catch (e) { setFlash('error', e.message); }
    render();
  }

  function setFlash(type, text) {
    state.flash = { type, text };
    setTimeout(() => { if (state.flash && state.flash.text === text) { state.flash = null; render(); } }, 4000);
  }

  function availableTabs() {
    const tabs = [];
    if (state.flags.can_submit_bill) tabs.push({ id: 'bill', label: 'My Bills' });
    if (state.flags.can_submit_reimbursement) tabs.push({ id: 'reimbursement', label: 'My Reimbursements' });
    if (state.flags.can_submit_travel) tabs.push({ id: 'travel', label: 'Travel Allowance' });
    if (state.flags.is_admin) {
      tabs.push({ id: 'queue', label: 'Pay Queue', badge: state.queuePendingTotal });
      tabs.push({ id: 'records', label: 'Records' });
      tabs.push({ id: 'travelLedger', label: 'Travel Ledger' });
    }
    return tabs;
  }

  // ── Render ─────────────────────────────────────────────────────────────────
  function render() {
    if (!container) return;
    ensureStyles();

    // Build the whole view BEFORE touching the container, then swap it in one
    // shot. The old code cleared the container first and built in place, so any
    // throw mid-build (a bad row, an unexpected payload) left it cleared-but-
    // empty — that is exactly what made the tab "disappear". Build-then-swap +
    // a fallback shell guarantees the container is never left blank.
    let shell;
    try {
      shell = buildShell();
    } catch (e) {
      try { console.error('[Bills] render failed — showing fallback', e); } catch (_) {}
      shell = el('div', { class: 'bl-shell' },
        el('div', { class: 'bl-head' }, el('div', { class: 'bl-title' }, el('h2', null, 'Bills & Reimbursements'))),
        el('div', { class: 'bl-flash error' }, 'Couldn’t render this view. Please refresh the page — your data is safe.'));
    }

    container.classList.remove('hidden');
    container.innerHTML = '';
    container.appendChild(shell);
  }

  function buildShell() {
    const shell = el('div', { class: 'bl-shell' });
    shell.appendChild(el('div', { class: 'bl-head' },
      el('div', { class: 'bl-title' },
        el('h2', null, 'Bills & Reimbursements'),
        el('p', null, headerSubtitle()),
      ),
    ));

    if (state.flash) shell.appendChild(el('div', { class: 'bl-flash ' + state.flash.type }, state.flash.text));

    const tabs = availableTabs();
    if (!tabs.length) {
      shell.appendChild(el('div', { class: 'bl-empty' }, 'You are not enabled for Bills yet.'));
      return shell;
    }
    if (!state.tab || !tabs.find(t => t.id === state.tab)) state.tab = tabs[0].id;

    shell.appendChild(renderTabs(tabs));

    if (state.tab === 'bill' || state.tab === 'reimbursement') shell.appendChild(renderMyTab(state.tab));
    else if (state.tab === 'travel') shell.appendChild(renderTravelTab());
    else if (state.tab === 'travelLedger') shell.appendChild(renderTravelLedgerTab());
    else if (state.tab === 'queue') shell.appendChild(renderQueueTab());
    else if (state.tab === 'records') shell.appendChild(renderRecordsTab());

    return shell;
  }

  function headerSubtitle() {
    if (state.tab === 'queue') return 'Verify and pay requests, attaching proof. You settle each other’s — not your own.';
    if (state.tab === 'records') return 'Settled (paid) requests for accounts reconciliation.';
    if (state.tab === 'travel') return 'Log each commute in seconds — date, route, amount, screenshot. Finance pays the monthly total.';
    if (state.tab === 'travelLedger') return 'Every logged trip with its screenshot, by employee and month — for travel reimbursement.';
    return 'Upload an invoice or receipt and it goes straight to finance.';
  }

  function renderTabs(tabs) {
    const wrap = el('div', { class: 'bl-tabs' });
    tabs.forEach(t => {
      const btn = el('button', {
        class: 'bl-tab' + (state.tab === t.id ? ' active' : ''),
        onclick: async () => {
          clearTimeout(searchDebounce);   // drop any pending search reload from the tab we're leaving
          state.tab = t.id;
          state.expanded.clear();
          state.actionOpen.clear();
          render();
          await reloadCurrentTab();
        },
      }, t.label);
      if (t.badge > 0) btn.appendChild(el('span', { class: 'bl-tab-badge' }, String(t.badge)));
      wrap.appendChild(btn);
    });
    return wrap;
  }

  // Reimbursement-process notice — same content on bill / reimbursement / travel.
  function renderAnnouncement() {
    return el('div', { class: 'bl-announce' },
      el('div', { class: 'bl-announce-title' }, '📣 Reimbursement Process — Please Read'),
      el('div', { class: 'bl-announce-sub' }, '🧳 Travel Allowance (reimbursed once a month)'),
      el('p', null, "If you're eligible to claim travel expenses, please follow these steps:"),
      el('ol', null,
        el('li', null, 'Upload your screenshots / bills to Google Drive in the date-wise folders.'),
        el('li', null, 'Attach the Drive link in the Google Sheet.'),
        el('li', null, 'In the sheet, enter the amounts and totals along with the Drive link to your submitted proofs.'),
      ),
      el('p', null, '📩 For any queries or the format, please reach out to Tiyasa — she’s coordinating this.'),
      el('p', { class: 'bl-announce-note' }, 'Note: Travel allowance reimbursements are processed once a month.'),
      el('div', { class: 'bl-announce-sub' }, '💸 All Other Reimbursements (weekly)'),
      el('p', null, 'We follow a Weekly Reimbursement Program.'),
    );
  }

  // ── My Bills / My Reimbursements ───────────────────────────────────────────
  function renderMyTab(type) {
    const wrap = el('div');
    wrap.appendChild(renderAnnouncement());
    const isTravel = type === 'travel';

    const addBtn = el('button', { class: 'bl-btn', onclick: () => openSubmitModal(type) }, '+ ' + SUBMIT_VERB[type]);
    wrap.appendChild(el('div', { class: 'bl-toolbar' },
      el('div', { style: { color: '#a1a1aa' } }, isTravel ? 'Your travel sheets' : (TYPE_LABEL[type] + ' requests you’ve raised')),
      addBtn));

    const rows = state.mine.filter(b => b.type === type);
    if (!rows.length) {
      wrap.appendChild(el('div', { class: 'bl-list' },
        el('div', { class: 'bl-empty' }, 'No ' + (isTravel ? 'travel sheets' : type + ' requests') + ' yet. Click “' + SUBMIT_VERB[type] + '”.')));
      return wrap;
    }
    const list = el('div', { class: 'bl-list' });
    rows.forEach(b => list.appendChild(renderMyRow(b)));
    wrap.appendChild(list);
    return wrap;
  }


  function renderMyRow(b) {
    const row = el('div', { class: 'bl-row' });
    const head = el('div', { class: 'bl-row-head' });
    head.appendChild(el('span', { class: 'bl-twirl' }, ''));

    const isTravel = b.type === 'travel';
    const main = el('div', { class: 'bl-row-main' });
    main.appendChild(el('div', { class: 'bl-row-title' }, b.title));
    const meta = el('div', { class: 'bl-row-meta' });
    // Travel has no amount until the admin pays it — show a status word, not ₹0.
    if (isTravel && b.status !== 'paid') {
      meta.appendChild(el('span', { style: { color: '#a1a1aa' } }, 'Awaiting payment'));
    } else {
      meta.appendChild(el('span', { class: 'amount' }, fmtINR(b.amount, b.currency)));
    }
    if (isTravel) appendSheetLink(meta, b, false);
    else appendFileLinks(meta, b, false);
    meta.appendChild(el('span', { class: 'sep' }, '·'));
    meta.appendChild(el('span', null, fmtRelative(b.created_at)));
    main.appendChild(meta);

    // Outcome line
    if (b.status === 'paid') {
      const paid = el('div', { class: 'bl-note', style: { color: '#4ade80' } },
        '✅ Paid by ' + (b.reviewer?.name || 'Finance')
        + (b.transaction_id ? ' · txn ' + b.transaction_id : '')
        + (b.reviewed_at ? ' · ' + fmtDate(b.reviewed_at) : ''));
      main.appendChild(paid);
      if (b.proof_url) {
        main.appendChild(el('div', { class: 'bl-note' },
          el('a', { class: 'bl-link', href: b.proof_url, target: '_blank', rel: 'noopener' }, '🧾 Payment proof')));
      }
    } else if (b.status === 'rejected') {
      main.appendChild(el('div', { class: 'bl-note', style: { color: '#fca5a5' } },
        '✕ Not approved' + (b.rejection_reason ? ': ' + b.rejection_reason : '')));
    }

    head.appendChild(main);

    const right = el('div', { style: { display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: '8px' } });
    right.appendChild(el('span', { class: 'bl-status bl-status-' + b.status }, b.status));
    if (b.status === 'pending') {
      // Edit the request's details. Travel only edits the sheet link; bills/
      // reimbursements edit the amount (e.g. when the proof shows a new total).
      right.appendChild(el('button', { class: 'bl-btn bl-btn-ghost bl-btn-sm', onclick: () => openEditModal(b) }, isTravel ? '✏️ Edit link' : '✏️ Edit'));
      // Add more attachments to an open request (e.g. a forgotten invoice).
      // Locked once paid/rejected — the backend enforces the same rule. Travel
      // is link-only, so there is nothing to attach.
      if (!isTravel) {
        const addInput = el('input', { type: 'file', accept: '.pdf,.jpg,.jpeg,.png,.webp', multiple: true, style: { display: 'none' } });
        addInput.addEventListener('change', async () => {
          const files = Array.from(addInput.files || []);
          addInput.value = '';
          if (!files.length) return;
          const existing = (b.files && b.files.length) ? b.files.length : (b.file_url ? 1 : 0);
          if (existing + files.length > 6) { setFlash('error', 'A request can have at most 6 files.'); return; }
          const fd = new FormData();
          files.forEach((f) => fd.append('files[]', f, f.name || 'upload'));
          try { await apiForm('/bills/' + b.id + '/files', fd); setFlash('success', files.length > 1 ? 'Files added.' : 'File added.'); await loadMine(); render(); }
          catch (e) { setFlash('error', e.message); }
        });
        right.appendChild(el('button', { class: 'bl-btn bl-btn-ghost bl-btn-sm', onclick: () => addInput.click() }, '➕ Add files'));
        right.appendChild(addInput);
      }
      right.appendChild(el('button', {
        class: 'bl-btn bl-btn-ghost bl-btn-sm',
        onclick: async () => {
          if (!confirm('Cancel this pending request?')) return;
          try { await api('/bills/' + b.id, { method: 'DELETE' }); setFlash('success', 'Request cancelled.'); await loadMine(); render(); }
          catch (e) { setFlash('error', e.message); }
        },
      }, 'Cancel'));
    }
    head.appendChild(right);

    row.appendChild(head);
    return row;
  }

  // ── Travel Allowance (employee: log trips) ──────────────────────────────────
  // One row per commute (date, route, amount, payment screenshot). Tessa keeps
  // the screenshot + an in-portal ledger; each month's trips roll up into one
  // pending travel bill that Finance pays from the Pay Queue.
  function renderTravelTab() {
    const wrap = el('div');

    wrap.appendChild(el('div', { class: 'bl-announce' },
      el('div', { class: 'bl-announce-title' }, '🧳 Travel Allowance — just log each trip'),
      el('p', null, 'After a commute, tap “Add trip”: pick the date + route, enter what you paid, and attach the payment screenshot. That’s it — no Drive folders, no sheet. Finance reviews your month and reimburses the total.')));

    if (state.travelMeta) wrap.appendChild(renderTravelCap(state.travelMeta));

    const addBtn = el('button', { class: 'bl-btn', onclick: () => openTripModal() }, '+ Add trip');
    wrap.appendChild(el('div', { class: 'bl-toolbar' },
      el('div', { style: { color: '#a1a1aa' } }, 'Your logged trips'),
      addBtn));

    const trips = state.travelTrips || [];
    if (!trips.length) {
      wrap.appendChild(el('div', { class: 'bl-list' },
        el('div', { class: 'bl-empty' }, 'No trips yet. Click “Add trip” after your next commute.')));
      return wrap;
    }
    const list = el('div', { class: 'bl-list' });
    trips.forEach(t => {
      try { list.appendChild(renderTripRow(t)); }
      catch (e) { try { console.error('[Bills] trip row failed', t && t.id, e); } catch (_) {} }
    });
    wrap.appendChild(list);
    return wrap;
  }

  function renderTravelCap(m) {
    const cap = Number(m.cap || 0);
    const used = Number(m.used || 0);
    const pct = cap > 0 ? Math.min(100, Math.round((used / cap) * 100)) : 0;
    const full = cap > 0 && used >= cap;
    return el('div', { class: 'bl-cap' },
      el('div', { class: 'bl-cap-top' },
        el('span', { class: 'bl-cap-used' }, fmtINR(used) + ' this month'),
        el('span', { class: 'bl-cap-left' }, (m.month_label || '') + ' · ' + (full ? 'over the ' + fmtINR(cap) + ' guide' : (fmtINR(m.remaining) + ' of ' + fmtINR(cap) + ' left')))),
      el('div', { class: 'bl-cap-bar' }, el('div', { class: 'bl-cap-fill' + (full ? ' full' : ''), style: { width: pct + '%' } })),
      el('div', { class: 'bl-cap-note' }, 'A soft monthly guide of ' + fmtINR(cap) + ' — Finance reviews the total at payout.'));
  }

  function renderTripRow(t) {
    const row = el('div', { class: 'bl-row' });
    const head = el('div', { class: 'bl-row-head' });
    head.appendChild(el('span', { class: 'bl-twirl' }, ''));

    const main = el('div', { class: 'bl-row-main' });
    main.appendChild(el('div', { class: 'bl-row-title' }, t.route_label));
    const meta = el('div', { class: 'bl-row-meta' });
    meta.appendChild(el('span', { class: 'amount' }, fmtINR(t.amount)));
    meta.appendChild(el('span', { class: 'sep' }, '·'));
    meta.appendChild(el('span', null, fmtDate(t.trip_date)));
    const ssUrls = t.screenshot_urls || (t.screenshot_url ? [{ url: t.screenshot_url, name: 'Screenshot', synced: t.synced }] : []);
    ssUrls.forEach((s, i) => {
      meta.appendChild(el('span', { class: 'sep' }, '·'));
      meta.appendChild(el('a', { class: 'bl-link', href: s.url, target: '_blank', rel: 'noopener' }, '📷 ' + (ssUrls.length > 1 ? (i + 1) : 'Screenshot')));
    });
    main.appendChild(meta);
    if (t.note) main.appendChild(el('div', { class: 'bl-note' }, t.note));
    head.appendChild(main);

    const right = el('div', { style: { display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: '8px' } });
    right.appendChild(el('span', { class: 'bl-status bl-status-' + t.status }, t.status));
    if (!t.locked) {
      right.appendChild(el('button', { class: 'bl-btn bl-btn-ghost bl-btn-sm', onclick: () => openTripModal(t) }, '✏️ Edit'));
      right.appendChild(el('button', {
        class: 'bl-btn bl-btn-ghost bl-btn-sm',
        onclick: async () => {
          if (!confirm('Delete this trip?')) return;
          try { await api('/travel-trips/' + t.id, { method: 'DELETE' }); setFlash('success', 'Trip deleted.'); await loadTravelTrips(); render(); }
          catch (e) { setFlash('error', e.message); }
        },
      }, 'Delete'));
    } else if (t.status === 'paid') {
      right.appendChild(el('span', { class: 'bl-note', style: { color: '#4ade80' } }, '✅ Reimbursed'));
    }
    head.appendChild(right);

    row.appendChild(head);
    return row;
  }

  // Add / edit one trip. Screenshot is required on add (and not editable here —
  // the row stays linked to its original proof). Route is a quick-pick preset or
  // a free-text custom from/to.
  function openTripModal(trip) {
    const editing = !!trip;
    const overlay = el('div', { class: 'bl-overlay' });
    const onPaste = (e) => {
      const items = (e.clipboardData && e.clipboardData.items) || [];
      for (const it of items) { if (it.kind === 'file') { const f = it.getAsFile(); if (f) addFiles([f]); } }
    };
    const close = () => { document.removeEventListener('paste', onPaste); overlay.remove(); };
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

    const msg = el('div', { class: 'bl-msg' });
    const today = istTodayStr();
    const dateInput = el('input', { class: 'bl-input', type: 'date', max: today, value: editing ? t_dateStr(trip.trip_date) : today });
    const amountInput = el('input', { class: 'bl-input', type: 'number', min: 1, step: '0.01', placeholder: 'Amount (₹)', value: editing ? String(trip.amount) : '' });

    const presets = state.routePresets || [];
    const presetSel = el('select', { class: 'bl-select' },
      ...presets.map(p => el('option', { value: p.from + '|' + p.to }, p.from + ' → ' + p.to)),
      el('option', { value: '__custom' }, 'Custom…'));
    const fromInput = el('input', { class: 'bl-input', type: 'text', maxlength: 120, placeholder: 'From' });
    const toInput = el('input', { class: 'bl-input', type: 'text', maxlength: 120, placeholder: 'To' });
    const customWrap = el('div', { style: { gridTemplateColumns: '1fr 1fr', gap: '10px' } }, fromInput, toInput);
    const showCustom = (on) => { customWrap.style.display = on ? 'grid' : 'none'; };
    presetSel.addEventListener('change', () => showCustom(presetSel.value === '__custom'));
    // Preselect: a saved trip whose route matches a preset uses it, else custom.
    if (editing) {
      const match = presets.find(p => p.from === trip.from_label && p.to === trip.to_label);
      if (match) { presetSel.value = match.from + '|' + match.to; showCustom(false); }
      else { presetSel.value = '__custom'; fromInput.value = trip.from_label || ''; toInput.value = trip.to_label || ''; showCustom(true); }
    } else {
      showCustom(presets.length === 0);
      if (!presets.length) presetSel.value = '__custom';
    }

    const noteInput = el('input', { class: 'bl-input', type: 'text', maxlength: 300, placeholder: 'Note e.g. auto, metro (optional)', value: editing ? (trip.note || '') : '' });

    let pickedFiles = [];
    const fileList = el('div', { class: 'bl-file-list' });
    const fileInput = el('input', { type: 'file', accept: '.jpg,.jpeg,.png,.webp,.pdf', multiple: true, style: { display: 'none' } });
    function renderFileList() {
      fileList.innerHTML = '';
      pickedFiles.forEach((f, i) => {
        fileList.appendChild(el('div', { class: 'bl-file-item' },
          el('span', null, '📎 ' + (f.name || 'screenshot')),
          el('button', { type: 'button', class: 'bl-file-remove', onclick: () => { pickedFiles.splice(i, 1); renderFileList(); } }, '×')));
      });
    }
    const MAX_FILES = 20;
    function addFiles(files) {
      let capped = false;
      for (const f of files) {
        if (!f) continue;
        if (pickedFiles.length >= MAX_FILES) { capped = true; break; }
        pickedFiles.push(f);
      }
      renderFileList();
      if (capped) showMsg(msg, 'error', 'You can attach up to ' + MAX_FILES + ' screenshots per trip.');
    }
    fileInput.addEventListener('change', () => { addFiles(Array.from(fileInput.files)); fileInput.value = ''; });
    const drop = el('div', { class: 'bl-drop', onclick: () => fileInput.click() },
      el('div', null, el('b', null, 'Click to upload'), ' or paste the payment screenshot'),
      el('div', { style: { fontSize: '11px', marginTop: '4px' } }, 'JPG / PNG / WebP / PDF · up to 20 files · 10 MB each'));
    drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('over'); });
    drop.addEventListener('dragleave', () => drop.classList.remove('over'));
    drop.addEventListener('drop', (e) => { e.preventDefault(); drop.classList.remove('over'); if (e.dataTransfer.files) addFiles(Array.from(e.dataTransfer.files)); });
    document.addEventListener('paste', onPaste);

    const submitBtn = el('button', { class: 'bl-btn' }, editing ? 'Save changes' : 'Log trip');
    submitBtn.addEventListener('click', async () => {
      const date = dateInput.value;
      let from, to;
      if (presetSel.value === '__custom') { from = fromInput.value.trim(); to = toInput.value.trim(); }
      else { const parts = presetSel.value.split('|'); from = parts[0]; to = parts[1]; }
      const amount = Number(amountInput.value);
      if (!date) return showMsg(msg, 'error', 'Pick the trip date.');
      if (!from || !to) return showMsg(msg, 'error', 'Enter both From and To.');
      if (!amount || amount <= 0) return showMsg(msg, 'error', 'Enter a valid amount.');
      if (!editing && pickedFiles.length === 0) return showMsg(msg, 'error', 'Attach the payment screenshot.');
      submitBtn.disabled = true;
      try {
        if (editing) {
          await api('/travel-trips/' + trip.id, { method: 'PUT', body: { trip_date: date, from_label: from, to_label: to, amount: amount, note: noteInput.value.trim() } });
        } else {
          const fd = new FormData();
          fd.append('trip_date', date);
          fd.append('from_label', from);
          fd.append('to_label', to);
          fd.append('amount', String(amount));
          if (noteInput.value.trim()) fd.append('note', noteInput.value.trim());
          pickedFiles.forEach(f => fd.append('screenshots[]', f, f.name || 'screenshot'));
          await apiForm('/travel-trips', fd);
        }
        close();
        setFlash('success', editing ? 'Trip updated.' : 'Trip logged.');
        await loadTravelTrips();
        render();
      } catch (e) { showMsg(msg, 'error', e.message); submitBtn.disabled = false; }
    });

    const field = (label, control) => el('div', { class: 'bl-field' }, el('label', null, label), control);
    overlay.appendChild(el('div', { class: 'bl-modal' },
      el('div', { class: 'bl-modal-head' },
        el('h3', null, editing ? 'Edit trip' : 'Add trip'),
        el('button', { class: 'bl-modal-close', onclick: close }, '×')),
      el('div', { class: 'bl-modal-body' },
        msg,
        el('div', { style: { display: 'grid', gridTemplateColumns: '1fr 130px', gap: '10px' } },
          field('Date', dateInput), field('Amount', amountInput)),
        field('Route', el('div', null, presetSel, customWrap)),
        field('Note', noteInput),
        editing ? null : field('Payment screenshot', el('div', null, drop, fileInput, fileList))),
      el('div', { class: 'bl-modal-foot' },
        el('button', { class: 'bl-btn bl-btn-ghost', onclick: close }, 'Cancel'),
        submitBtn)));

    document.body.appendChild(overlay);
    dateInput.focus();
  }

  // The trip_date may arrive as 'YYYY-MM-DD' (date cast) — keep just the date part.
  function t_dateStr(v) { return (v == null ? '' : String(v)).slice(0, 10); }

  // ── Travel Ledger (admins) ──────────────────────────────────────────────────
  function renderTravelLedgerTab() {
    const wrap = el('div');
    const f = state.ledgerFilter;
    if (!f.month) f.month = istMonthStr();

    // Drive/Sheet auto-sync off → nudge the writer (Shoyab/Ayush) to (re)connect Google.
    const sync = state.ledger.sync;
    if (sync && !sync.enabled) {
      wrap.appendChild(el('div', {
        style: { background: 'rgba(234,179,8,0.10)', border: '1px solid rgba(234,179,8,0.4)', borderRadius: '8px', padding: '10px 13px', marginBottom: '12px', fontSize: '12.5px', lineHeight: '1.5' },
      },
        el('strong', { style: { color: '#fde047' } }, '⚠ Drive & Sheet auto-sync is off. '),
        el('span', { style: { color: '#e4e4e7' } }, sync.reason || 'Connect Google to enable.'),
        el('span', { style: { color: '#a1a1aa' } }, ' Trips still log here; they’ll back-fill to Drive once connected.')));
    }

    const apply = async () => { try { await loadTravelLedger(); } catch (e) { setFlash('error', e.message); } render(); };

    const searchInput = el('input', { class: 'bl-input', type: 'text', id: 'bl-ledger-search', placeholder: 'Search route / note / person', value: f.search, autocomplete: 'off' });
    searchInput.addEventListener('input', () => {
      f.search = searchInput.value;
      const caret = searchInput.selectionStart;
      clearTimeout(searchDebounce);
      searchDebounce = setTimeout(async () => { await apply(); refocusSearch('bl-ledger-search', caret); }, 250);
    });

    // Month options: All + current (always) + every month present.
    const months = state.ledger.months || [];
    const curKey = istMonthStr();
    const opts = [{ key: 'all', label: 'All months' }];
    if (!months.some(m => m.key === curKey)) opts.push({ key: curKey, label: monthLabelFromKey(curKey) });
    months.forEach(m => opts.push(m));
    const monthSel = el('select', { class: 'bl-select', title: 'Month', onchange: () => { f.month = monthSel.value; apply(); } },
      ...opts.map(o => el('option', { value: o.key, selected: String(f.month) === String(o.key) || undefined }, o.label)));

    const uploaderSel = el('select', { class: 'bl-select', title: 'Filter by employee', onchange: () => { f.uploader = uploaderSel.value; apply(); } },
      el('option', { value: '' }, 'All employees'),
      ...(state.ledger.uploaders || []).map(u => el('option', { value: u.id, selected: String(f.uploader) === String(u.id) || undefined }, u.name)));

    const excelBtn = el('button', { class: 'bl-btn bl-btn-ghost bl-btn-sm', onclick: () => exportLedgerExcel() }, '⬇ Excel');
    const clearBtn = el('button', { class: 'bl-btn bl-btn-ghost bl-btn-sm', onclick: async () => { state.ledgerFilter = { month: curKey, uploader: '', search: '' }; await apply(); } }, 'Clear');

    wrap.appendChild(el('div', { class: 'bl-rec-filters' }, searchInput, monthSel, uploaderSel, excelBtn, clearBtn));

    const trips = state.ledger.trips || [];
    wrap.appendChild(el('div', { class: 'bl-section-label' }, trips.length + ' trip' + (trips.length === 1 ? '' : 's') + ' · ' + fmtINR(state.ledger.total)));

    if (!trips.length) {
      wrap.appendChild(el('div', { class: 'bl-list' }, el('div', { class: 'bl-empty' }, 'No trips match.')));
      return wrap;
    }
    const list = el('div', { class: 'bl-list' });
    trips.forEach(t => {
      const row = el('div', { class: 'bl-row' });
      const head = el('div', { class: 'bl-row-head' });
      head.appendChild(el('span', { class: 'bl-twirl' }, ''));
      const main = el('div', { class: 'bl-row-main' });
      main.appendChild(el('div', { class: 'bl-chip' },
        el('span', { class: 'bl-avatar' }, initials(t.submitter && t.submitter.name)),
        el('span', null, (t.submitter && t.submitter.name) || '—')));
      main.appendChild(el('div', { class: 'bl-row-title' }, t.route_label));
      const meta = el('div', { class: 'bl-row-meta' });
      meta.appendChild(el('span', { class: 'amount' }, fmtINR(t.amount)));
      meta.appendChild(el('span', { class: 'sep' }, '·'));
      meta.appendChild(el('span', null, fmtDate(t.trip_date)));
      const ledgerSsUrls = t.screenshot_urls || (t.screenshot_url ? [{ url: t.screenshot_url, name: 'Screenshot', synced: t.synced }] : []);
      ledgerSsUrls.forEach((s, i) => {
        meta.appendChild(el('span', { class: 'sep' }, '·'));
        meta.appendChild(el('a', { class: 'bl-link', href: s.url, target: '_blank', rel: 'noopener', title: s.synced ? 'Google Drive copy' : 'Local upload' }, '📷 ' + (ledgerSsUrls.length > 1 ? (i + 1) : 'Screenshot')));
      });
      if (t.synced) {
        meta.appendChild(el('span', { class: 'sep' }, '·'));
        meta.appendChild(el('span', { style: { color: '#5eead4' }, title: 'Synced to the writer’s Google Drive + master ledger' }, '☁ synced'));
      }
      if (t.note) { meta.appendChild(el('span', { class: 'sep' }, '·')); meta.appendChild(el('span', null, t.note)); }
      main.appendChild(meta);
      head.appendChild(main);
      head.appendChild(el('span', { class: 'bl-status bl-status-' + t.status }, t.status));
      row.appendChild(head);
      list.appendChild(row);
    });
    wrap.appendChild(list);
    return wrap;
  }

  function exportLedgerExcel() {
    const a = el('a', { href: '/api/travel-trips/ledger/export' + ledgerQuery() });
    document.body.appendChild(a); a.click(); a.remove();
  }

  // ── Submit modal (bills + reimbursements; travel uses openTripModal) ─────────
  function openSubmitModal(type) {
    let pickedFiles = [];
    const MAX_FILES = 6;
    const isTravel = type === 'travel';
    const travelRemaining = isTravel ? Number((state.travel || {}).remaining || 0) : null;

    const overlay = el('div', { class: 'bl-overlay' });
    const close = () => { document.removeEventListener('paste', onPaste); overlay.remove(); };
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

    const msg = el('div', { class: 'bl-msg' });
    const titleInput = el('input', { class: 'bl-input', type: 'text', maxlength: 200, placeholder: isTravel ? 'e.g. Auto to office — today' : (type === 'bill' ? 'e.g. Figma subscription — May' : 'e.g. PG rent — June') });
    const amountInput = el('input', { class: 'bl-input', type: 'number', min: 1, step: '0.01', max: isTravel ? travelRemaining : undefined, placeholder: isTravel ? ('Amount (₹' + travelRemaining + ' left)') : 'Amount' });
    const currencySel = el('select', { class: 'bl-select' },
      el('option', { value: 'INR' }, '₹ INR'), el('option', { value: 'USD' }, '$ USD'), el('option', { value: 'EUR' }, '€ EUR'));
    const vendorInput = el('input', { class: 'bl-input', type: 'text', maxlength: 200, placeholder: 'Vendor / agency (optional)' });

    // Category: reimbursements MUST pick from a fixed list (PG / Room rent /
    // Wi-Fi); Bills + Travel keep an optional free-text category. Wi-Fi is a
    // ₹700-or-less benefit claimable ONCE per month — so picking it constrains
    // the amount field and, if already claimed this month, blocks submission
    // (the server is authoritative; this is just a friendly pre-check).
    const reimbCats = state.reimbursementCategories || [];
    const wifiCat = state.wifiCategory || 'Wifi reimbursement';
    const wifiCap = Number(state.wifiCap || 700);
    const wifiLocked = !!state.wifiClaimStatus;          // already paid/pending this month
    const wifiLockMsg = state.wifiClaimStatus === 'paid'
      ? ('You’ve already claimed your Wi-Fi reimbursement for ' + (state.wifiMonthLabel || 'this month') + '. It resets on the 1st.')
      : ('You already have a Wi-Fi reimbursement pending for ' + (state.wifiMonthLabel || 'this month') + ' — only one per month.');
    let catInput, wifiNote = null;
    if (type === 'reimbursement') {
      catInput = el('select', { class: 'bl-select' },
        el('option', { value: '' }, 'Select a category…'),
        ...reimbCats.map((c) => el('option', { value: c }, c)));
      wifiNote = el('div', { class: 'bl-note', style: { display: 'none' } });
      catInput.addEventListener('change', () => {
        if (catInput.value === wifiCat) {
          amountInput.max = wifiCap;
          if (wifiLocked) { wifiNote.textContent = wifiLockMsg; wifiNote.style.color = '#fca5a5'; submitBtn.disabled = true; }
          else { wifiNote.textContent = 'Wi-Fi reimbursement is ₹' + wifiCap + ' or less, once a month.'; wifiNote.style.color = '#fde68a'; submitBtn.disabled = false; }
          wifiNote.style.display = 'block';
        } else {
          amountInput.removeAttribute('max');
          wifiNote.style.display = 'none';
          submitBtn.disabled = false;
        }
      });
    } else {
      catInput = el('input', { class: 'bl-input', type: 'text', maxlength: 60, placeholder: isTravel ? 'Mode e.g. auto, metro (optional)' : 'Category e.g. subscription (optional)' });
    }
    const descInput = el('textarea', { class: 'bl-textarea', maxlength: 2000, placeholder: 'Short description (what is this for?)' });

    const fileListEl = el('div', { class: 'bl-file-list' });
    const fileInput = el('input', { type: 'file', accept: '.pdf,.jpg,.jpeg,.png,.webp', multiple: true, style: { display: 'none' } });
    function renderFileList() {
      fileListEl.textContent = '';
      pickedFiles.forEach((f, i) => {
        fileListEl.appendChild(el('div', { class: 'bl-file-name' },
          el('span', null, '📎 ' + (f.name || 'pasted-image')),
          el('button', { type: 'button', class: 'bl-file-rm', title: 'Remove', onclick: () => { pickedFiles.splice(i, 1); renderFileList(); } }, '×')));
      });
    }
    const addFiles = (fileList) => {
      for (const f of Array.from(fileList || [])) {
        if (pickedFiles.length >= MAX_FILES) { showMsg(msg, 'error', 'Up to ' + MAX_FILES + ' files per request.'); break; }
        if (!pickedFiles.some((p) => p.name === f.name && p.size === f.size)) pickedFiles.push(f);
      }
      renderFileList();
    };
    fileInput.addEventListener('change', () => { addFiles(fileInput.files); fileInput.value = ''; });
    const drop = el('div', { class: 'bl-drop', onclick: () => fileInput.click() },
      el('div', null, el('b', null, 'Click to upload'), ' or paste a screenshot'),
      el('div', { style: { fontSize: '11px', marginTop: '4px' } }, 'PDF / JPG / PNG / WebP · up to 10 MB each · add the invoice + payment QR'));
    drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('over'); });
    drop.addEventListener('dragleave', () => drop.classList.remove('over'));
    drop.addEventListener('drop', (e) => {
      e.preventDefault(); drop.classList.remove('over');
      if (e.dataTransfer.files && e.dataTransfer.files.length) addFiles(e.dataTransfer.files);
    });
    const onPaste = (e) => {
      const items = (e.clipboardData && e.clipboardData.items) || [];
      const imgs = [];
      for (const it of items) { if (it.kind === 'file') { const f = it.getAsFile(); if (f) imgs.push(f); } }
      if (imgs.length) addFiles(imgs);
    };
    document.addEventListener('paste', onPaste);

    const submitBtn = el('button', { class: 'bl-btn' }, SUBMIT_VERB[type]);
    submitBtn.addEventListener('click', async () => {
      const title = titleInput.value.trim();
      const amount = Number(amountInput.value);
      const category = (catInput.value || '').trim();
      if (!title) return showMsg(msg, 'error', 'Add a title.');
      if (type === 'reimbursement' && !category) return showMsg(msg, 'error', 'Select a reimbursement category.');
      if (!amount || amount <= 0) return showMsg(msg, 'error', 'Enter a valid amount.');
      if (isTravel && amount > travelRemaining) return showMsg(msg, 'error', travelRemaining <= 0 ? 'You’ve used your full travel allowance this month.' : ('Only ₹' + travelRemaining + ' left this month.'));
      if (type === 'reimbursement' && category === wifiCat && amount > wifiCap) return showMsg(msg, 'error', 'Wi-Fi reimbursement can’t exceed ₹' + wifiCap + ' (monthly budget).');
      if (type === 'reimbursement' && category === wifiCat && wifiLocked) return showMsg(msg, 'error', wifiLockMsg);
      if (!pickedFiles.length) return showMsg(msg, 'error', isTravel ? 'Attach the payment screenshot.' : 'Attach the invoice / receipt.');
      submitBtn.disabled = true;
      const fd = new FormData();
      fd.append('type', type);
      fd.append('title', title);
      fd.append('amount', String(amount));
      fd.append('currency', currencySel.value);
      if (vendorInput.value.trim()) fd.append('vendor_name', vendorInput.value.trim());
      if (category) fd.append('category', category);
      if (descInput.value.trim()) fd.append('description', descInput.value.trim());
      pickedFiles.forEach((f) => fd.append('files[]', f, f.name || 'upload'));
      try {
        const r = await apiForm('/bills', fd);
        close();
        setFlash('success', (r && r.message) || 'Request submitted.');
        await loadMine();
        render();
      } catch (e) { showMsg(msg, 'error', e.message); submitBtn.disabled = false; }
    });

    const field = (label, control) => el('div', { class: 'bl-field' }, el('label', null, label), control);

    overlay.appendChild(el('div', { class: 'bl-modal' },
      el('div', { class: 'bl-modal-head' },
        el('h3', null, SUBMIT_VERB[type]),
        el('button', { class: 'bl-modal-close', onclick: close }, '×')),
      el('div', { class: 'bl-modal-body' },
        msg,
        field('Title', titleInput),
        isTravel
          ? field('Amount', amountInput)
          : el('div', { style: { display: 'grid', gridTemplateColumns: '1fr 110px', gap: '10px' } },
              field('Amount', amountInput), field('Currency', currencySel)),
        type === 'bill' ? field('Vendor / agency', vendorInput) : null,
        field('Category', wifiNote ? el('div', null, catInput, wifiNote) : catInput),
        field('Description', descInput),
        field(isTravel ? 'Payment screenshot / receipt' : 'Invoice / receipt (add the QR too)', el('div', null, drop, fileInput, fileListEl))),
      el('div', { class: 'bl-modal-foot' },
        el('button', { class: 'bl-btn bl-btn-ghost', onclick: close }, 'Cancel'),
        submitBtn)));

    document.body.appendChild(overlay);
    titleInput.focus();
  }

  // ── Edit modal (pending requests only) ───────────────────────────────────────
  // Lets the owner fix a pending request's details — most often the amount,
  // once the uploaded proof shows the real total. Mirrors the submit modal,
  // minus the file picker (attachments have their own Add-files flow) and the
  // type (fixed at creation). Travel trips have their own Add/Edit modal.
  function openEditModal(b) {
    const isTravel = false; // bill/reimbursement only here
    const travelRoom = null;

    const overlay = el('div', { class: 'bl-overlay' });
    const close = () => overlay.remove();
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

    const msg = el('div', { class: 'bl-msg' });
    const titleInput = el('input', { class: 'bl-input', type: 'text', maxlength: 200, value: b.title || '' });
    const amountInput = el('input', { class: 'bl-input', type: 'number', min: 1, step: '0.01', max: isTravel ? travelRoom : undefined, value: b.amount != null ? String(b.amount) : '' });
    const currencySel = el('select', { class: 'bl-select' },
      el('option', { value: 'INR' }, '₹ INR'), el('option', { value: 'USD' }, '$ USD'), el('option', { value: 'EUR' }, '€ EUR'));
    currencySel.value = b.currency || 'INR';
    const vendorInput = el('input', { class: 'bl-input', type: 'text', maxlength: 200, value: b.vendor_name || '' });

    // Category: same fixed mandatory dropdown for reimbursements as the submit
    // modal (preselecting the current value); free-text for Bills + Travel.
    const reimbCats = state.reimbursementCategories || [];
    const wifiCat = state.wifiCategory || 'Wifi reimbursement';
    const wifiCap = Number(state.wifiCap || 700);
    let catInput, wifiNote = null;
    if (b.type === 'reimbursement') {
      catInput = el('select', { class: 'bl-select' },
        el('option', { value: '' }, 'Select a category…'),
        ...reimbCats.map((c) => el('option', { value: c, selected: (b.category === c) || undefined }, c)));
      const startWifi = b.category === wifiCat;
      if (startWifi) amountInput.max = wifiCap;
      wifiNote = el('div', { class: 'bl-note', style: { display: startWifi ? 'block' : 'none', color: '#fde68a' } },
        'Wi-Fi reimbursement is ₹' + wifiCap + ' or less, once a month.');
      catInput.addEventListener('change', () => {
        if (catInput.value === wifiCat) { amountInput.max = wifiCap; wifiNote.style.display = 'block'; }
        else { amountInput.removeAttribute('max'); wifiNote.style.display = 'none'; }
      });
    } else {
      catInput = el('input', { class: 'bl-input', type: 'text', maxlength: 60, value: b.category || '' });
    }
    const descInput = el('textarea', { class: 'bl-textarea', maxlength: 2000 }, b.description || '');

    const saveBtn = el('button', { class: 'bl-btn' }, 'Save changes');
    saveBtn.addEventListener('click', async () => {
      const title = titleInput.value.trim();
      const amount = Number(amountInput.value);
      const category = (catInput.value || '').trim();
      if (!title) return showMsg(msg, 'error', 'Add a title.');
      if (b.type === 'reimbursement' && !category) return showMsg(msg, 'error', 'Select a reimbursement category.');
      if (!amount || amount <= 0) return showMsg(msg, 'error', 'Enter a valid amount.');
      if (isTravel && amount > travelRoom) return showMsg(msg, 'error', travelRoom <= 0 ? 'You’ve used your full travel allowance this month.' : ('Only ₹' + travelRoom + ' available this month.'));
      if (b.type === 'reimbursement' && category === wifiCat && amount > wifiCap) return showMsg(msg, 'error', 'Wi-Fi reimbursement can’t exceed ₹' + wifiCap + ' (monthly budget).');
      saveBtn.disabled = true;
      try {
        const r = await api('/bills/' + b.id, { method: 'PUT', body: {
          title: title,
          amount: amount,
          currency: currencySel.value,
          vendor_name: vendorInput.value.trim(),
          category: category,
          description: descInput.value.trim(),
        } });
        close();
        setFlash('success', (r && r.message) || 'Request updated.');
        await loadMine();
        render();
      } catch (e) { showMsg(msg, 'error', e.message); saveBtn.disabled = false; }
    });

    const field = (label, control) => el('div', { class: 'bl-field' }, el('label', null, label), control);

    overlay.appendChild(el('div', { class: 'bl-modal' },
      el('div', { class: 'bl-modal-head' },
        el('h3', null, 'Edit request'),
        el('button', { class: 'bl-modal-close', onclick: close }, '×')),
      el('div', { class: 'bl-modal-body' },
        msg,
        field('Title', titleInput),
        isTravel
          ? field('Amount', amountInput)
          : el('div', { style: { display: 'grid', gridTemplateColumns: '1fr 110px', gap: '10px' } },
              field('Amount', amountInput), field('Currency', currencySel)),
        b.type === 'bill' ? field('Vendor / agency', vendorInput) : null,
        field('Category', wifiNote ? el('div', null, catInput, wifiNote) : catInput),
        field('Description', descInput)),
      el('div', { class: 'bl-modal-foot' },
        el('button', { class: 'bl-btn bl-btn-ghost', onclick: close }, 'Cancel'),
        saveBtn)));

    document.body.appendChild(overlay);
    titleInput.focus();
  }

  // ── Pay Queue (admins) ──────────────────────────────────────────────────────
  function renderQueueTab() {
    const wrap = el('div');
    wrap.appendChild(renderQueueFilters());
    const { pending } = state.queue;

    // Pay Queue is pending-only — it's an action inbox. Settled requests live in
    // the Records tab, not here.
    const f = state.queueFilter;
    const filtered = !!(f.type || f.uploader || f.search || f.from || f.to);
    wrap.appendChild(el('div', { class: 'bl-section-label' },
      filtered ? ('Pending — showing ' + pending.length + ' of ' + state.queuePendingTotal) : ('Pending (' + pending.length + ')')));

    // Selection bar — tick rows (or "Select all") then export to Excel.
    if (pending.length) {
      const selAll = el('input', { type: 'checkbox', id: 'bl-queue-select-all', style: { width: 'auto', cursor: 'pointer' } });
      const allChecked = pending.every(b => state.queueSelected.has(b.id));
      selAll.checked = allChecked;
      selAll.indeterminate = !allChecked && pending.some(b => state.queueSelected.has(b.id));
      selAll.onchange = () => {
        pending.forEach(b => selAll.checked ? state.queueSelected.add(b.id) : state.queueSelected.delete(b.id));
        render();
      };
      const n = state.queueSelected.size;
      const excelBtn = el('button', { class: 'bl-btn bl-btn-ghost bl-btn-sm', id: 'bl-queue-excel', onclick: () => exportQueueExcel() },
        n ? ('⬇ Excel (' + n + ')') : '⬇ Excel');
      wrap.appendChild(el('div', { style: { display: 'flex', alignItems: 'center', gap: '10px', margin: '6px 0' } },
        el('label', { style: { display: 'flex', alignItems: 'center', gap: '6px', color: '#a1a1aa', fontSize: '13px', cursor: 'pointer' } },
          selAll, el('span', null, 'Select all')),
        el('span', { style: { flex: '1' } }),
        excelBtn));
    }

    wrap.appendChild(buildQueueList(pending, true));

    return wrap;
  }

  function renderQueueFilters() {
    const f = state.queueFilter;

    // Live filters — no Apply button. The search box reloads (debounced) as you
    // type; every dropdown / date change reloads immediately on select.
    const applyQueue = async () => {
      state.expanded.clear();
      state.queueSelected.clear();
      try { await loadQueue(); } catch (e) { setFlash('error', e.message); }
      render();
    };

    const searchInput = el('input', { class: 'bl-input', type: 'text', id: 'bl-queue-search', placeholder: 'Search title / vendor / person', value: f.search, autocomplete: 'off', list: 'bl-queue-suggest' });
    searchInput.addEventListener('input', () => {
      f.search = searchInput.value;
      const caret = searchInput.selectionStart;
      clearTimeout(searchDebounce);
      searchDebounce = setTimeout(async () => { await applyQueue(); refocusSearch('bl-queue-search', caret); }, 250);
    });
    const typeSel = el('select', { class: 'bl-select', onchange: () => { f.type = typeSel.value; applyQueue(); } },
      el('option', { value: '' }, 'All types'),
      el('option', { value: 'bill', selected: f.type === 'bill' || undefined }, 'Bills'),
      el('option', { value: 'reimbursement', selected: f.type === 'reimbursement' || undefined }, 'Reimbursements'),
      el('option', { value: 'travel', selected: f.type === 'travel' || undefined }, 'Travel'));
    const uploaderSel = el('select', { class: 'bl-select', title: 'Filter by who submitted', onchange: () => { f.uploader = uploaderSel.value; applyQueue(); } },
      el('option', { value: '' }, 'All uploaders'),
      ...(state.queueUploaders || []).map(u => el('option', { value: u.id, selected: String(f.uploader) === String(u.id) || undefined }, u.name)));
    const sortSel = el('select', { class: 'bl-select', title: 'Order by submitted date', onchange: () => { f.sort = sortSel.value; applyQueue(); } },
      el('option', { value: 'asc', selected: f.sort !== 'desc' || undefined }, 'Oldest first'),
      el('option', { value: 'desc', selected: f.sort === 'desc' || undefined }, 'Newest first'));
    const fromInput = el('input', { class: 'bl-input', type: 'date', value: f.from, title: 'Submitted from', onchange: () => { f.from = fromInput.value; applyQueue(); } });
    const toInput = el('input', { class: 'bl-input', type: 'date', value: f.to, title: 'Submitted to', onchange: () => { f.to = toInput.value; applyQueue(); } });
    const clearBtn = el('button', { class: 'bl-btn bl-btn-ghost bl-btn-sm', onclick: async () => {
      state.queueFilter = { type: '', uploader: '', search: '', from: '', to: '', sort: 'asc' };
      await applyQueue();
    } }, 'Clear');

    return el('div', { class: 'bl-rec-filters' },
      searchInput, buildSuggestList('bl-queue-suggest', queueSuggestions()),
      typeSel, uploaderSel, sortSel, fromInput, toInput, clearBtn);
  }

  // Append a "📎 <name>" link per attachment (invoice + payment QR + extra
  // pages), each preceded by a separator. Falls back to the legacy single
  // file_url for requests created before multi-file support.
  function appendFileLinks(container, b, stopProp) {
    const files = (b.files && b.files.length) ? b.files
      : (b.file_url ? [{ url: b.file_url, name: b.file_name || 'Invoice' }] : []);
    files.forEach((f) => {
      container.appendChild(el('span', { class: 'sep' }, '·'));
      const attrs = { class: 'bl-link', href: f.url, target: '_blank', rel: 'noopener' };
      if (stopProp) attrs.onclick = (e) => e.stopPropagation();
      const nm = String(f.name || 'Attachment');
      container.appendChild(el('a', attrs, '📎 ' + (nm.length > 22 ? nm.slice(0, 19) + '…' : nm)));
    });
  }

  // Travel rows carry a single sheet LINK instead of uploaded attachments.
  function appendSheetLink(container, b, stopProp) {
    if (!b.sheet_url) return;
    container.appendChild(el('span', { class: 'sep' }, '·'));
    const attrs = { class: 'bl-link', href: b.sheet_url, target: '_blank', rel: 'noopener' };
    if (stopProp) attrs.onclick = (e) => e.stopPropagation();
    container.appendChild(el('a', attrs, '📄 Open sheet'));
  }

  function buildQueueList(rows, withAction) {
    const list = el('div', { class: 'bl-list' });
    if (!rows.length) {
      list.appendChild(el('div', { class: 'bl-empty' }, withAction ? 'Inbox zero. Nothing pending.' : 'No recent payments.'));
      return list;
    }
    rows.forEach(b => {
      const isOwn = withAction && b.submitter && Number(b.submitter.id) === Number(state.userId);
      const expanded = state.expanded.has(b.id);
      const row = el('div', { class: 'bl-row' + (expanded ? ' expanded' : '') });

      const canOpen = withAction && !isOwn;
      const head = el('div', {
        class: 'bl-row-head' + (canOpen ? ' clickable' : ''),
        onclick: canOpen ? () => { if (state.expanded.has(b.id)) state.expanded.delete(b.id); else state.expanded.add(b.id); render(); } : null,
      });
      const selBox = el('input', { type: 'checkbox', class: 'bl-queue-select', style: { width: 'auto', cursor: 'pointer', marginRight: '2px' } });
      selBox.checked = state.queueSelected.has(b.id);
      selBox.onclick = (e) => e.stopPropagation();
      selBox.onchange = () => {
        selBox.checked ? state.queueSelected.add(b.id) : state.queueSelected.delete(b.id);
        syncQueueSelectionUI();
      };
      head.appendChild(selBox);
      head.appendChild(el('span', { class: 'bl-twirl' }, canOpen ? (expanded ? '▾' : '▸') : ''));

      const main = el('div', { class: 'bl-row-main' });
      main.appendChild(el('div', { class: 'bl-chip' },
        el('span', { class: 'bl-avatar' }, initials(b.submitter?.name)),
        el('span', null, b.submitter?.name || '—')));
      main.appendChild(el('div', { class: 'bl-row-title' }, b.title));
      const isTravelRow = b.type === 'travel';
      const meta = el('div', { class: 'bl-row-meta' });
      meta.appendChild(el('span', { class: 'bl-type-tag bl-type-' + b.type }, TYPE_LABEL[b.type]));
      // Travel has no amount until paid — show a hint, not ₹0; link, not files.
      if (isTravelRow && b.status !== 'paid') {
        meta.appendChild(el('span', { style: { color: '#a1a1aa' } }, 'amount at pay'));
      } else {
        meta.appendChild(el('span', { class: 'amount' }, fmtINR(b.amount, b.currency)));
      }
      if (isTravelRow) appendSheetLink(meta, b, true);
      else appendFileLinks(meta, b, true);
      meta.appendChild(el('span', { class: 'sep' }, '·'));
      if (withAction) {
        meta.appendChild(el('span', { title: fmtRelative(b.created_at) }, fmtDateTime(b.created_at)));
      } else {
        meta.appendChild(el('span', null, fmtRelative(b.reviewed_at)));
      }
      if (!withAction && b.transaction_id) {
        meta.appendChild(el('span', { class: 'sep' }, '·'));
        meta.appendChild(el('span', null, 'txn ' + b.transaction_id));
      }
      main.appendChild(meta);
      if (b.description) main.appendChild(el('div', { class: 'bl-note' }, b.description));
      if (isOwn) main.appendChild(el('div', { class: 'bl-note', style: { color: '#fde68a' } }, 'Your own request — the other admin settles it.'));
      head.appendChild(main);

      head.appendChild(el('span', { class: 'bl-status bl-status-' + b.status }, b.status));
      row.appendChild(head);

      if (withAction && !isOwn && expanded) row.appendChild(renderMarkPaidPanel(b));
      list.appendChild(row);
    });
    return list;
  }

  function renderMarkPaidPanel(b) {
    const body = el('div', { class: 'bl-body' });
    const msg = el('div', { class: 'bl-msg' });
    body.appendChild(msg);

    const rejectKey = 'reject:' + b.id;
    const rejectOpen = state.actionOpen.has(rejectKey);

    // Mark-paid form. Travel rows have no amount yet — the admin types what
    // they're paying (read off the sheet) before settling.
    const isTravel = b.type === 'travel';
    const needsAmount = isTravel && Number(b.amount) <= 0;
    body.appendChild(el('div', { class: 'bl-section-label' },
      needsAmount
        ? ('Enter the amount and mark paid to ' + (b.submitter?.name || 'employee'))
        : ('Mark ' + fmtINR(b.amount, b.currency) + ' paid to ' + (b.submitter?.name || 'employee'))));
    const amountInput = needsAmount
      ? el('input', { class: 'bl-input', type: 'number', min: 1, step: '0.01', placeholder: 'Amount paid (₹)' })
      : null;
    const txnInput = el('input', { class: 'bl-input', type: 'text', maxlength: 80, placeholder: 'Transaction / UTR / UPI ref' });
    const noteInput = el('textarea', { class: 'bl-textarea', maxlength: 1000, placeholder: 'Note (optional)' });

    let proofFile = null;
    const proofName = el('div', { class: 'bl-file-name' });
    const proofInput = el('input', { type: 'file', accept: '.pdf,.jpg,.jpeg,.png,.webp', style: { display: 'none' } });
    proofInput.addEventListener('change', () => { proofFile = proofInput.files[0] || null; proofName.textContent = proofFile ? ('Proof: ' + proofFile.name) : ''; });
    const proofDrop = el('div', { class: 'bl-drop', onclick: () => proofInput.click() }, 'Attach payment screenshot (optional if you add a txn ID)');

    const payBtn = el('button', { class: 'bl-btn bl-btn-success bl-btn-sm' }, 'Mark paid');
    payBtn.addEventListener('click', async () => {
      const txn = txnInput.value.trim();
      let amt = null;
      if (needsAmount) {
        amt = Number(amountInput.value);
        if (!amt || amt <= 0) return showMsg(msg, 'error', 'Enter the amount you’re paying.');
      }
      if (!txn && !proofFile) return showMsg(msg, 'error', 'Add a transaction ID or attach a payment screenshot.');
      payBtn.disabled = true;
      const fd = new FormData();
      if (needsAmount) fd.append('amount', String(amt));
      if (txn) fd.append('transaction_id', txn);
      if (noteInput.value.trim()) fd.append('note', noteInput.value.trim());
      if (proofFile) fd.append('proof_file', proofFile, proofFile.name);
      try {
        await apiForm('/bills/' + b.id + '/mark-paid', fd);
        setFlash('success', 'Marked as paid. Employee notified.');
        state.expanded.delete(b.id);
        await loadQueue();
        render();
      } catch (e) { showMsg(msg, 'error', e.message); payBtn.disabled = false; }
    });

    const rejectBtn = el('button', { class: 'bl-btn bl-btn-danger bl-btn-sm', onclick: () => toggleAction(rejectKey) }, rejectOpen ? 'Cancel reject' : 'Reject…');

    body.appendChild(el('div', { class: 'bl-form' },
      amountInput, txnInput, noteInput, proofDrop, proofInput, proofName,
      el('div', { class: 'bl-form-actions' }, rejectBtn, payBtn)));

    if (rejectOpen) {
      const reasonInput = el('textarea', { class: 'bl-textarea', maxlength: 1000, placeholder: 'Required: reason for rejection' });
      const confirmBtn = el('button', { class: 'bl-btn bl-btn-danger bl-btn-sm' }, 'Confirm reject');
      confirmBtn.addEventListener('click', async () => {
        const reason = reasonInput.value.trim();
        if (!reason) return showMsg(msg, 'error', 'Reason is required.');
        confirmBtn.disabled = true;
        try {
          await api('/bills/' + b.id + '/reject', { method: 'POST', body: { rejection_reason: reason } });
          setFlash('success', 'Rejected. Employee notified.');
          state.actionOpen.delete(rejectKey);
          state.expanded.delete(b.id);
          await loadQueue();
          render();
        } catch (e) { showMsg(msg, 'error', e.message); confirmBtn.disabled = false; }
      });
      body.appendChild(el('div', { class: 'bl-form', style: { marginTop: '12px' } },
        reasonInput, el('div', { class: 'bl-form-actions' }, confirmBtn)));
    }

    return body;
  }

  // Per-user "Travel paid" notification (admin, Records). Posts a personal
  // dashboard card to the submitter; idempotent via paid_announced_at.
  function announceButton(b) {
    if (b.paid_announced_at) {
      return el('span', { class: 'bl-note', style: { color: '#4ade80' }, title: 'In-portal notification sent ' + fmtDate(b.paid_announced_at) }, '✓ Announced');
    }
    const btn = el('button', {
      class: 'bl-btn bl-btn-ghost bl-btn-sm',
      title: 'Post an in-portal “paid” notification to ' + (b.submitter?.name || 'the employee'),
    }, '📣 Announce paid');
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      try {
        const r = await api('/bills/' + b.id + '/announce-paid', { method: 'POST' });
        setFlash('success', (r && r.message) || 'Notification posted.');
        await loadRecords();
        render();
      } catch (e) { btn.disabled = false; setFlash('error', e.message); }
    });
    return btn;
  }

  // ── Records (admins, paid-only) ─────────────────────────────────────────────
  function renderRecordsTab() {
    const wrap = el('div');

    const f = state.recFilter;

    // Live filters — no Apply button (mirrors the Pay Queue). Excel exports the
    // current filtered set.
    const applyRecords = async () => {
      try { await loadRecords(); } catch (e) { setFlash('error', e.message); }
      render();
    };

    const searchInput = el('input', { class: 'bl-input', type: 'text', id: 'bl-records-search', placeholder: 'Search title / vendor / txn / person', value: f.search, autocomplete: 'off', list: 'bl-records-suggest' });
    searchInput.addEventListener('input', () => {
      f.search = searchInput.value;
      const caret = searchInput.selectionStart;
      clearTimeout(searchDebounce);
      searchDebounce = setTimeout(async () => { await applyRecords(); refocusSearch('bl-records-search', caret); }, 250);
    });
    const typeSel = el('select', { class: 'bl-select', onchange: () => { f.type = typeSel.value; applyRecords(); } },
      el('option', { value: '' }, 'All types'),
      el('option', { value: 'bill', selected: f.type === 'bill' || undefined }, 'Bills'),
      el('option', { value: 'reimbursement', selected: f.type === 'reimbursement' || undefined }, 'Reimbursements'),
      el('option', { value: 'travel', selected: f.type === 'travel' || undefined }, 'Travel'));
    const uploaderSel = el('select', { class: 'bl-select', title: 'Filter by who submitted', onchange: () => { f.uploader = uploaderSel.value; applyRecords(); } },
      el('option', { value: '' }, 'All uploaders'),
      ...(state.recordsUploaders || []).map(u => el('option', { value: u.id, selected: String(f.uploader) === String(u.id) || undefined }, u.name)));
    const sortSel = el('select', { class: 'bl-select', title: 'Order by paid date', onchange: () => { f.sort = sortSel.value; applyRecords(); } },
      el('option', { value: 'desc', selected: f.sort !== 'asc' || undefined }, 'Newest first'),
      el('option', { value: 'asc', selected: f.sort === 'asc' || undefined }, 'Oldest first'));
    const fromInput = el('input', { class: 'bl-input', type: 'date', value: f.from, title: 'Paid from', onchange: () => { f.from = fromInput.value; applyRecords(); } });
    const toInput = el('input', { class: 'bl-input', type: 'date', value: f.to, title: 'Paid to', onchange: () => { f.to = toInput.value; applyRecords(); } });
    const excelBtn = el('button', { class: 'bl-btn bl-btn-ghost bl-btn-sm', onclick: () => exportRecordsExcel() }, '⬇ Excel');

    wrap.appendChild(el('div', { class: 'bl-rec-filters' },
      searchInput, buildSuggestList('bl-records-suggest', recordsSuggestions()),
      typeSel, uploaderSel, sortSel, fromInput, toInput, excelBtn));

    const total = state.records.reduce((s, r) => s + Number(r.amount || 0), 0);
    wrap.appendChild(el('div', { class: 'bl-section-label' }, state.records.length + ' paid · ' + fmtINR(total)));

    if (!state.records.length) {
      wrap.appendChild(el('div', { class: 'bl-list' }, el('div', { class: 'bl-empty' }, 'No paid records match.')));
      return wrap;
    }
    const list = el('div', { class: 'bl-list' });
    state.records.forEach(b => {
      const row = el('div', { class: 'bl-row' });
      const head = el('div', { class: 'bl-row-head' });
      head.appendChild(el('span', { class: 'bl-twirl' }, ''));
      const main = el('div', { class: 'bl-row-main' });
      main.appendChild(el('div', { class: 'bl-row-title' }, b.title));
      const meta = el('div', { class: 'bl-row-meta' });
      meta.appendChild(el('span', { class: 'bl-type-tag bl-type-' + b.type }, TYPE_LABEL[b.type]));
      meta.appendChild(el('span', { class: 'amount' }, fmtINR(b.amount, b.currency)));
      meta.appendChild(el('span', { class: 'sep' }, '·'));
      meta.appendChild(el('span', null, b.submitter?.name || '—'));
      meta.appendChild(el('span', { class: 'sep' }, '·'));
      meta.appendChild(el('span', null, 'paid ' + fmtDate(b.reviewed_at) + ' by ' + (b.reviewer?.name || '—')));
      main.appendChild(meta);
      const links = el('div', { class: 'bl-row-meta' });
      if (b.transaction_id) links.appendChild(el('span', null, 'txn ' + b.transaction_id));
      if (b.type === 'travel') appendSheetLink(links, b, false);
      else appendFileLinks(links, b, false);
      if (b.proof_url) { links.appendChild(el('span', { class: 'sep' }, '·')); links.appendChild(el('a', { class: 'bl-link', href: b.proof_url, target: '_blank', rel: 'noopener' }, '🧾 Proof')); }
      if (links.childNodes.length) main.appendChild(links);
      head.appendChild(main);
      // Right rail: paid badge + (travel only) the per-user "Announce paid" action.
      const right = el('div', { style: { display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: '8px' } });
      right.appendChild(el('span', { class: 'bl-status bl-status-paid' }, 'paid'));
      if (b.type === 'travel') right.appendChild(announceButton(b));
      head.appendChild(right);
      row.appendChild(head);
      list.appendChild(row);
    });
    wrap.appendChild(list);
    return wrap;
  }

  // Excel export — the server builds the .xlsx (with per-type summary) from the
  // same filters; the browser downloads it via Content-Disposition (GET,
  // session-authed, no navigation).
  function exportRecordsExcel() {
    const a = el('a', { href: '/api/bills/records/export' + recordsQuery() });
    document.body.appendChild(a); a.click(); a.remove();
  }

  // Keep the "Select all" checkbox + "⬇ Excel (N)" button label in sync with the
  // ticked rows, without a full re-render (preserves scroll / expanded panels).
  function syncQueueSelectionUI() {
    const pending = (state.queue && state.queue.pending) || [];
    const selAll = document.getElementById('bl-queue-select-all');
    if (selAll) {
      const allChecked = pending.length > 0 && pending.every(b => state.queueSelected.has(b.id));
      selAll.checked = allChecked;
      selAll.indeterminate = !allChecked && pending.some(b => state.queueSelected.has(b.id));
    }
    const btn = document.getElementById('bl-queue-excel');
    if (btn) { const n = state.queueSelected.size; btn.textContent = n ? ('⬇ Excel (' + n + ')') : '⬇ Excel'; }
  }

  // Excel export of the Pay Queue — GET, session-authed (mirrors exportRecordsExcel).
  // Sends current queue filters + ticked ids; none ⇒ server exports all filtered pending.
  function exportQueueExcel() {
    const p = new URLSearchParams();
    const f = state.queueFilter;
    if (f.type) p.set('type', f.type);
    if (f.uploader) p.set('uploader', f.uploader);
    if (f.from) p.set('from', f.from);
    if (f.to) p.set('to', f.to);
    if (f.search) p.set('search', f.search);
    if (f.sort) p.set('sort', f.sort);
    const ids = Array.from(state.queueSelected);
    if (ids.length) p.set('ids', ids.join(','));
    const qs = p.toString();
    const a = el('a', { href: '/api/bills/queue/export' + (qs ? '?' + qs : '') });
    document.body.appendChild(a); a.click(); a.remove();
  }

  // ── Helpers ──────────────────────────────────────────────────────────────
  function showMsg(node, type, text) { node.className = 'bl-msg ' + type; node.style.display = 'block'; node.textContent = text; }
  function toggleAction(key) { if (state.actionOpen.has(key)) state.actionOpen.delete(key); else state.actionOpen.add(key); render(); }

  // ── Live filter search: suggestions + focus restore ─────────────────────────
  function uniqStrings(arr) {
    const seen = new Set();
    const out = [];
    arr.forEach((s) => {
      const v = (s == null ? '' : String(s)).trim();
      if (!v) return;
      const k = v.toLowerCase();
      if (seen.has(k)) return;
      seen.add(k);
      out.push(v);
    });
    return out.sort((a, b) => a.localeCompare(b));
  }

  // Native <datalist> for free-text autocomplete on a filter search box. The
  // element renders nothing itself (UA default display:none), so it's safe to
  // drop straight into the flex filter bar.
  function buildSuggestList(id, values) {
    const dl = el('datalist', { id });
    values.forEach((v) => dl.appendChild(el('option', { value: v })));
    return dl;
  }

  function queueSuggestions() {
    const vals = (state.queueUploaders || []).map((u) => u.name);
    (state.queue.pending || []).forEach((b) => { vals.push(b.submitter && b.submitter.name, b.title, b.vendor_name); });
    return uniqStrings(vals).slice(0, 50);
  }

  function recordsSuggestions() {
    const vals = (state.recordsUploaders || []).map((u) => u.name);
    (state.records || []).forEach((b) => { vals.push(b.submitter && b.submitter.name, b.title, b.vendor_name, b.transaction_id); });
    return uniqStrings(vals).slice(0, 50);
  }

  // A full re-render rebuilds the DOM, so the live-search input loses focus
  // mid-type — re-grab it by id and restore the caret after each debounced reload.
  function refocusSearch(id, caret) {
    const inp = document.getElementById(id);
    if (!inp) return;
    inp.focus();
    const pos = caret == null ? inp.value.length : Math.min(caret, inp.value.length);
    try { inp.setSelectionRange(pos, pos); } catch (e) { /* input type without selection support */ }
  }

  // ── Entry ─────────────────────────────────────────────────────────────────
  async function entry(target) {
    container = target;
    if (!container) return;
    try {
      await loadMine();
      // Admins land on the Pay Queue (their action area); load it up front for the badge.
      if (state.flags.is_admin) {
        state.tab = 'queue';
        await loadQueue();
      } else {
        const tabs = availableTabs();
        state.tab = tabs.length ? tabs[0].id : null;
        // bill/reimbursement use the loadMine() data already fetched; the travel
        // tab has its own loader, so fetch it if that's where we land.
        if (state.tab === 'travel') await loadTravelTrips();
      }
    } catch (e) {
      setFlash('error', e.message);
    }
    render();
  }

  window.Bills = { render: entry };
})();
