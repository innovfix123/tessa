/**
 * Freelance-recruiter open portal (public, passwordless) — dashboard SPA.
 *
 * Boots from the /r/{token} shell (resources/views/hiring/recruiter-portal.blade.php),
 * driven entirely by the token-scoped JSON endpoints on PublicRecruiterPortalController:
 *   GET  /r/{token}/jobs               → dashboard (assigned JDs + counts)
 *   GET  /r/{token}/jobs/{id}          → JD detail + this recruiter's candidates
 *   POST /r/{token}/jobs/{id}/candidates (one résumé per request; we loop)
 *
 * Two hash-routed views: #/  (dashboard)  and  #/jd/{id}  (JD detail). No login,
 * no CSRF token (the route is exempt — the URL token is the credential), so a tab
 * left open for hours never hits a "page expired" wall.
 */
(function () {
  'use strict';

  var TOKEN = (window.RP && window.RP.token) || '';
  var NAME = (window.RP && window.RP.name) || 'there';
  var BASE = '/r/' + TOKEN;
  var app = document.getElementById('rp-app');

  // Canonical 6-step pipeline (matches PublicRecruiterPortalController::STAGE_MAP `step`).
  var STEP_LABELS = ['Submitted', 'Panel Review', 'Technical', 'HR', 'Offered', 'Hired'];

  // ── DOM helper ──────────────────────────────────────────────────────────────
  function el(tag, attrs) {
    var e = document.createElement(tag);
    if (attrs) {
      for (var k in attrs) {
        if (k === 'style') Object.assign(e.style, attrs[k]);
        else if (k === 'html') e.innerHTML = attrs[k];
        else if (k.indexOf('on') === 0 && typeof attrs[k] === 'function') e.addEventListener(k.slice(2), attrs[k]);
        else if (attrs[k] !== undefined && attrs[k] !== null && attrs[k] !== false) e.setAttribute(k, attrs[k]);
      }
    }
    for (var i = 2; i < arguments.length; i++) {
      var kids = arguments[i];
      (Array.isArray(kids) ? kids : [kids]).forEach(function (kid) {
        if (kid == null || kid === false) return;
        e.appendChild(typeof kid === 'string' || typeof kid === 'number' ? document.createTextNode(String(kid)) : kid);
      });
    }
    return e;
  }

  function clear(node) { while (node.firstChild) node.removeChild(node.firstChild); }

  // ── Fetch helpers ─────────────────────────────────────────────────────────────
  async function api(path) {
    var res = await fetch(BASE + path, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    var json = await res.json().catch(function () { return {}; });
    if (!res.ok) throw new Error(json.error || json.message || 'Could not load. Please refresh.');
    return json;
  }

  // Multipart upload — branch on res.status BEFORE res.json() so an nginx/php-fpm
  // 413/504 (which returns HTML, not JSON) doesn't read as a generic failure.
  async function apiForm(path, formData) {
    var res = await fetch(BASE + path, {
      method: 'POST',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: formData,
    });
    if (res.status === 413) throw new Error('File too large (max 10 MB).');
    if (res.status === 504 || res.status === 502) throw new Error('Server timed out — try again.');
    var json = await res.json().catch(function () { return {}; });
    if (!res.ok) {
      var firstErr = json.errors && Object.values(json.errors)[0] && Object.values(json.errors)[0][0];
      throw new Error(json.error || firstErr || json.message || 'Upload failed.');
    }
    return json;
  }

  // Run async tasks with a concurrency cap (2) — avoids PHP-FPM pool exhaustion
  // when a recruiter submits several résumés at once (UPLOAD_ERR_PARTIAL guard).
  async function runWithConcurrency(items, limit, worker) {
    var idx = 0;
    async function next() {
      var i = idx++;
      if (i >= items.length) return;
      await worker(items[i], i);
      return next();
    }
    var runners = [];
    for (var n = 0; n < Math.min(limit, items.length); n++) runners.push(next());
    await Promise.all(runners);
  }

  var _toastTimer = null;
  function toast(msg, isErr) {
    var t = document.getElementById('rpToast');
    if (!t) { t = el('div', { id: 'rpToast', class: 'rp-toast' }); document.body.appendChild(t); }
    t.textContent = msg;
    t.className = 'rp-toast show' + (isErr ? ' err' : '');
    if (_toastTimer) clearTimeout(_toastTimer);
    _toastTimer = setTimeout(function () { t.className = 'rp-toast'; }, 3600);
  }

  function loading(label) {
    clear(app);
    app.appendChild(el('div', { class: 'rp-loading' }, el('div', { class: 'rp-spinner' }), label || 'Loading…'));
  }

  function errorState(msg) {
    clear(app);
    app.appendChild(el('div', { class: 'rp-empty' }, msg || 'Something went wrong.'));
    app.appendChild(el('div', { style: { textAlign: 'center', marginTop: '14px' } },
      el('button', { class: 'rp-btn rp-btn-ghost', onclick: route }, 'Retry')));
  }

  function header() {
    return el('div', {},
      el('div', { class: 'rp-brand' }, 'InnovFix Recruitment'),
      el('h1', { class: 'rp-h1' }, 'Welcome, ' + NAME),
      el('p', { class: 'rp-sub' }, 'Submit candidates for the roles assigned to you and track their progress.'));
  }

  // ── Dashboard (#/) ───────────────────────────────────────────────────────────
  async function renderDashboard() {
    loading('Loading your dashboard…');
    var data;
    try { data = await api('/jobs'); }
    catch (e) { return errorState(e.message); }

    clear(app);
    app.appendChild(header());

    app.appendChild(el('div', { class: 'rp-stats' },
      el('div', { class: 'rp-stat' }, el('b', {}, String(data.stats.submitted)), el('span', {}, 'Profiles submitted')),
      el('div', { class: 'rp-stat' }, el('b', {}, String(data.stats.selected)), el('span', {}, 'Selected'))));

    if (!data.jobs.length) {
      app.appendChild(el('div', { class: 'rp-empty' }, 'No roles are assigned to you yet. Your HR contact will assign job descriptions here.'));
    } else {
      var grid = el('div', { class: 'rp-grid' });
      data.jobs.forEach(function (jd) { grid.appendChild(jobCard(jd)); });
      app.appendChild(grid);
    }
    app.appendChild(el('div', { class: 'rp-foot' }, 'InnovFix · Powered by Tessa'));
  }

  function jobCard(jd) {
    var open = function () { go('#/jd/' + jd.id); };
    var card = el('div', { class: 'rp-card' });
    card.appendChild(el('h2', {}, jd.title));
    if (jd.experience_level) card.appendChild(el('div', { class: 'rp-meta' }, el('b', {}, 'Experience: '), jd.experience_level));
    if (jd.salary_range) card.appendChild(el('div', { class: 'rp-meta' }, el('b', {}, 'Budget: '), jd.salary_range));
    if (jd.required_skills) card.appendChild(el('div', { class: 'rp-skills' }, jd.required_skills));
    card.appendChild(el('div', { class: 'rp-count' }, el('b', {}, String(jd.submitted_count)), jd.submitted_count === 1 ? ' candidate submitted' : ' candidates submitted'));
    card.appendChild(el('div', { class: 'rp-card-actions' },
      el('button', { class: 'rp-btn rp-btn-ghost rp-btn-sm', onclick: open }, 'View details'),
      el('button', { class: 'rp-btn rp-btn-sm', onclick: open }, 'Upload candidates')));
    return card;
  }

  // ── JD detail (#/jd/{id}) ──────────────────────────────────────────────────────
  async function renderJd(id) {
    loading('Loading role…');
    var data;
    try { data = await api('/jobs/' + id); }
    catch (e) { return errorState(e.message); }

    var jd = data.jd;
    clear(app);
    app.appendChild(el('button', { class: 'rp-back', onclick: function () { go('#/'); } }, '← All roles'));
    app.appendChild(el('h1', { class: 'rp-h1' }, jd.title));
    if (jd.experience_level) app.appendChild(el('div', { class: 'rp-meta' }, el('b', {}, 'Experience: '), jd.experience_level));
    if (jd.salary_range) app.appendChild(el('div', { class: 'rp-meta' }, el('b', {}, 'Budget: '), jd.salary_range));
    if (jd.required_skills) app.appendChild(el('div', { class: 'rp-meta' }, el('b', {}, 'Skills: '), jd.required_skills));
    if (jd.jd_file_url) app.appendChild(el('div', { class: 'rp-meta', style: { marginTop: '6px' } }, el('a', { href: jd.jd_file_url, target: '_blank', rel: 'noopener' }, '📄 Open job description (PDF)')));
    if (jd.description) app.appendChild(el('div', { class: 'rp-desc' }, jd.description));

    // Upload section
    var upSection = el('div', { class: 'rp-section' }, el('h3', {}, 'Submit candidates'));
    upSection.appendChild(buildStager(jd, function () { refreshHistory(jd.id); }));
    app.appendChild(upSection);

    // History section (its own node so a successful upload can refresh just this)
    var histSection = el('div', { class: 'rp-section', id: 'rpHist' }, el('h3', {}, 'Your submissions'));
    histSection.appendChild(historyList(data.candidates));
    app.appendChild(histSection);
  }

  async function refreshHistory(id) {
    try {
      var data = await api('/jobs/' + id);
      var sec = document.getElementById('rpHist');
      if (!sec) return;
      clear(sec);
      sec.appendChild(el('h3', {}, 'Your submissions'));
      sec.appendChild(historyList(data.candidates));
    } catch (e) { /* non-fatal; the toast already told them it submitted */ }
  }

  function historyList(candidates) {
    if (!candidates.length) return el('div', { class: 'rp-empty' }, 'No submissions yet — add your first candidate above.');
    var wrap = el('div', {});
    candidates.forEach(function (c) {
      var main = el('div', { class: 'rp-hist-main' },
        el('div', { class: 'rp-hist-name' }, c.name || c.resume_name || 'Candidate'),
        el('div', { class: 'rp-hist-sub' }, 'Submitted ' + (c.date || '—')));
      if (c.resume_url) {
        main.appendChild(el('div', { style: { marginTop: '3px' } },
          el('a', { class: 'rp-resume-link', href: c.resume_url, target: '_blank', rel: 'noopener' }, '📎 ' + (c.resume_name || 'résumé'))));
      }
      wrap.appendChild(el('div', { class: 'rp-hist-row' }, main, progress(c)));
    });
    return wrap;
  }

  // Compact progress: 6-dot stepper + current-stage badge; rejected/withdrawn → red + reason.
  function progress(c) {
    if (!c.step) {
      var term = el('div', { class: 'rp-term' }, el('span', { class: 'rp-badge ' + c.stage_class }, c.stage_label));
      if (c.rejected_reason) term.appendChild(el('span', { class: 'rp-reason' }, '“' + c.rejected_reason + '”'));
      return term;
    }
    var steps = el('div', { class: 'rp-steps' });
    STEP_LABELS.forEach(function (lbl, i) {
      var n = i + 1;
      var cls = 'rp-step ' + (n < c.step ? 'done' : (n === c.step ? 'current ' + c.stage_class : 'todo'));
      steps.appendChild(el('div', { class: cls, title: lbl }, el('span', { class: 'rp-dot' })));
    });
    return el('div', { class: 'rp-progress' }, steps, el('span', { class: 'rp-badge ' + c.stage_class }, c.stage_label));
  }

  // ── Multi-candidate stager ─────────────────────────────────────────────────────
  function buildStager(jd, onSubmitted) {
    var rows = [];
    var listEl = el('div', {});

    function renumber() {
      rows.forEach(function (r, i) {
        r.numEl.textContent = 'Candidate ' + (i + 1);
        r.removeEl.style.display = rows.length > 1 && !r.done ? '' : 'none';
      });
    }

    function addRow() {
      var fileInput = el('input', { class: 'rp-input', type: 'file', accept: '.pdf,.doc,.docx,application/pdf' });
      var nameInput = el('input', { class: 'rp-input', type: 'text', maxlength: '150', placeholder: 'Optional' });
      var emailInput = el('input', { class: 'rp-input', type: 'email', maxlength: '150', placeholder: 'Optional' });
      var phoneInput = el('input', { class: 'rp-input', type: 'text', maxlength: '40', placeholder: 'Optional' });
      var statusEl = el('div', { class: 'rp-cand-status' });
      var numEl = el('span', { class: 'rp-cand-num' });
      var removeEl = el('button', { class: 'rp-remove', title: 'Remove', onclick: function () { removeRow(r); } }, '×');

      var node = el('div', { class: 'rp-cand' },
        el('div', { class: 'rp-cand-head' }, numEl, removeEl),
        el('label', { class: 'rp-label' }, 'Résumé (PDF or Word, max 10 MB) *'),
        fileInput,
        el('div', { class: 'rp-row' },
          el('div', {}, el('label', { class: 'rp-label' }, 'Candidate name'), nameInput),
          el('div', {}, el('label', { class: 'rp-label' }, 'Email'), emailInput),
          el('div', {}, el('label', { class: 'rp-label' }, 'Phone'), phoneInput)),
        statusEl);

      var r = {
        node: node, numEl: numEl, removeEl: removeEl, statusEl: statusEl,
        fileInput: fileInput, nameInput: nameInput, emailInput: emailInput, phoneInput: phoneInput,
        done: false,
        setStatus: function (msg, kind) { statusEl.textContent = msg; statusEl.className = 'rp-cand-status' + (kind ? ' ' + kind : ''); },
        disable: function () { [fileInput, nameInput, emailInput, phoneInput].forEach(function (i) { i.disabled = true; }); removeEl.style.display = 'none'; },
      };
      rows.push(r);
      listEl.appendChild(node);
      renumber();
    }

    function removeRow(r) {
      var i = rows.indexOf(r);
      if (i === -1) return;
      rows.splice(i, 1);
      r.node.remove();
      if (!rows.length) addRow();
      renumber();
    }

    var addBtn = el('button', { class: 'rp-btn rp-btn-ghost rp-btn-sm', onclick: addRow }, '+ Add another candidate');
    var submitBtn = el('button', { class: 'rp-btn', onclick: submitAll }, 'Submit candidates');

    function setBusy(b) {
      addBtn.disabled = b; submitBtn.disabled = b;
      submitBtn.textContent = b ? 'Submitting…' : 'Submit candidates';
    }

    async function submitAll() {
      var pending = rows.filter(function (r) { return !r.done && r.fileInput.files[0]; });
      if (!pending.length) { toast('Attach at least one résumé to submit.', true); return; }

      setBusy(true);
      await runWithConcurrency(pending, 2, async function (r) {
        r.setStatus('Uploading…', 'busy');
        var fd = new FormData();
        fd.append('resume', r.fileInput.files[0]);
        if (r.nameInput.value.trim()) fd.append('name', r.nameInput.value.trim());
        if (r.emailInput.value.trim()) fd.append('email', r.emailInput.value.trim());
        if (r.phoneInput.value.trim()) fd.append('phone', r.phoneInput.value.trim());
        try {
          var res = await apiForm('/jobs/' + jd.id + '/candidates', fd);
          r.done = true;
          r.setStatus('✓ Submitted' + (res.duplicate_warning ? ' · ' + res.duplicate_warning : ''), 'ok');
          r.disable();
        } catch (e) {
          r.setStatus('✗ ' + e.message, 'err');
        }
      });
      setBusy(false);

      var ok = pending.filter(function (r) { return r.done; }).length;
      var fail = pending.length - ok;
      if (ok) toast(fail ? (ok + ' submitted, ' + fail + ' failed — fix and retry') : (ok + (ok > 1 ? ' candidates' : ' candidate') + ' submitted'));
      else toast('Upload failed. Please try again.', true);

      if (ok) {
        // Drop the submitted rows (now visible in history); keep failures to retry.
        rows.filter(function (r) { return r.done; }).forEach(function (r) { r.node.remove(); });
        rows = rows.filter(function (r) { return !r.done; });
        if (!rows.length) addRow();
        renumber();
        if (onSubmitted) onSubmitted();
      }
    }

    addRow();
    return el('div', { class: 'rp-stager' }, listEl, el('div', { class: 'rp-stager-actions' }, addBtn, submitBtn));
  }

  // ── Router ──────────────────────────────────────────────────────────────────
  function go(hash) { if (location.hash === hash) route(); else location.hash = hash; }

  function route() {
    var m = (location.hash || '').match(/^#\/jd\/(\d+)/);
    if (m) renderJd(parseInt(m[1], 10));
    else renderDashboard();
    if (window.scrollTo) window.scrollTo(0, 0);
  }

  if (!TOKEN || !app) return;
  window.addEventListener('hashchange', route);
  route();
})();
