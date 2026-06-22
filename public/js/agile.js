/**
 * Agile / Sprint Management — Frontend Module
 * Renders inside #agileRoot when the "agile" tab is active.
 */
(function () {
  'use strict';

  const CFG = () => window.__PORTAL_CONFIG || {};
  const agile = () => CFG().agile || {};
  const people = () => CFG().MODAL_PEOPLE || [];
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

  /* ── API helpers ─────────────────────────────────────── */
  async function api(url, opts = {}) {
    const res = await fetch('/api' + url, {
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json', ...opts.headers },
      credentials: 'same-origin',
      ...opts,
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
    const json = await res.json();
    if (!res.ok) throw new Error(json.error || 'Request failed');
    return json;
  }
  const GET = (u) => api(u);
  const POST = (u, b) => api(u, { method: 'POST', body: b });
  const PUT = (u, b) => api(u, { method: 'PUT', body: b });
  const PATCH = (u, b) => api(u, { method: 'PATCH', body: b });
  const DELETE = (u) => api(u, { method: 'DELETE' });

  /* ── State ───────────────────────────────────────────── */
  let state = {
    tab: 'board',       // board | backlog | epics | velocity | squads
    projects: [],
    selectedProjectId: null,
    squads: [],
    sprints: [],
    labels: [],
    selectedSquadId: null,
    selectedSprintId: null,
    boardData: null,
    epics: [],
    stories: [],
    bugs: [],
    allProjects: [],
  };

  /* ── Utility ─────────────────────────────────────────── */
  function h(tag, attrs, ...kids) {
    const el = document.createElement(tag);
    if (attrs) Object.entries(attrs).forEach(([k, v]) => {
      if (k === 'className') el.className = v;
      else if (k.startsWith('on')) el.addEventListener(k.slice(2).toLowerCase(), v);
      else if (k === 'innerHTML') el.innerHTML = v;
      else el.setAttribute(k, v);
    });
    kids.flat().forEach(c => { if (c != null) el.append(typeof c === 'string' ? c : c); });
    return el;
  }

  // Combined color map covers both story-priority (low/medium/high/critical) and
  // bug-priority (blocker/critical/major/minor) plus shared severity (low/medium/high).
  const PRIORITY_COLORS = {
    blocker: '#dc2626', critical: '#ef4444', high: '#f97316',
    major: '#f59e0b', medium: '#eab308',
    minor: '#22c55e', low: '#22c55e',
  };
  // Rank used for the "effective level" of a bug (max of its severity + priority).
  // Buckets: critical/blocker → top, high/major/medium-priority → middle, etc.
  const LEVEL_RANK = {
    low: 1, minor: 1,
    medium: 2, major: 2,
    high: 3, critical: 3,
    blocker: 4,
  };
  // For bugs, the effective level is the higher of priority and severity. Stories
  // don't have severity so this returns priority.
  function effectiveLevel(item) {
    const p = item.priority;
    const s = item.severity;
    if (s && (LEVEL_RANK[s] || 0) > (LEVEL_RANK[p] || 0)) return s;
    return p;
  }
  // Maps an effective level to the summary-card bucket. Keeps the existing 4-card
  // UI (Critical / High / Medium / Low) working with the new bug vocabulary.
  function summaryBucket(level) {
    if (level === 'blocker' || level === 'critical') return 'critical';
    if (level === 'high' || level === 'major') return 'high';
    if (level === 'medium') return 'medium';
    if (level === 'low' || level === 'minor') return 'low';
    return null;
  }
  const STATUS_LABELS = {
    backlog: 'Backlog', todo: 'To Do', in_progress: 'In Progress',
    code_review: 'Code Review', qa: 'QA', done: 'Done',
    open: 'Open', fixed: 'Fixed', verified: 'Verified', closed: 'Closed', wont_fix: "Won't Fix",
    planning: 'Planning', active: 'Active', review: 'Review',
    in_progress: 'In Progress', cancelled: 'Cancelled',
  };

  function badge(text, color) {
    return h('span', { className: 'badge ag-badge', style: `background:${color || '#374151'};color:#fff` }, text);
  }

  // Map of duplicateGroupId -> array of sibling bugs in that group. Built from
  // state.bugs every time it's queried, so it stays in sync after loadBugs().
  // Only bugs with a non-null group id are indexed; singletons are absent.
  function bugDuplicateGroups() {
    const map = new Map();
    (state.bugs || []).forEach(b => {
      if (!b.duplicateGroupId) return;
      const list = map.get(b.duplicateGroupId) || [];
      list.push(b);
      map.set(b.duplicateGroupId, list);
    });
    return map;
  }
  function bugDuplicateSiblings(bug) {
    if (!bug || !bug.duplicateGroupId) return [];
    const group = bugDuplicateGroups().get(bug.duplicateGroupId) || [];
    return group.filter(b => b.id !== bug.id);
  }

  // Compact date helper: "May 4, 2:46 PM" or "Apr 12" if older than 30 days.
  function formatDateShort(iso) {
    if (!iso) return '';
    try {
      const d = new Date(iso);
      const now = new Date();
      const diffDays = (now - d) / (1000 * 60 * 60 * 24);
      const sameYear = d.getFullYear() === now.getFullYear();
      const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
      const datePart = months[d.getMonth()] + ' ' + d.getDate() + (sameYear ? '' : ', ' + d.getFullYear());
      if (diffDays > 30) return datePart;
      let h12 = d.getHours();
      const ampm = h12 >= 12 ? 'PM' : 'AM';
      h12 = h12 % 12 || 12;
      const m = String(d.getMinutes()).padStart(2, '0');
      return datePart + ', ' + h12 + ':' + m + ' ' + ampm;
    } catch (e) { return iso; }
  }

  function priorityDot(p) {
    return h('span', { className: 'ag-priority-dot', style: `background:${PRIORITY_COLORS[p] || '#6b7280'}`, title: p });
  }

  function labelChips(labels) {
    return labels.map(l => h('span', { className: 'badge ag-label-chip', style: `background:${l.color}22;color:${l.color};border:1px solid ${l.color}44` }, l.name));
  }

  /* ── Root render ─────────────────────────────────────── */
  function render() {
    const root = document.getElementById('agileRoot');
    if (!root) return;
    root.innerHTML = '';
    root.append(renderProjectBar(), renderSubNav(), renderContent());
  }

  function renderProjectBar() {
    const bar = h('div', { className: 'ag-project-bar' });
    const projects = agile().projects || [];
    if (!projects.length) return bar;

    bar.append(h('span', { className: 'ag-project-label' }, 'Project:'));
    // If only one project, auto-select it and skip "All" button
    if (projects.length === 1) {
      if (!state.selectedProjectId) { state.selectedProjectId = projects[0].id; }
      bar.append(h('button', { className: 'ag-project-chip active' }, projects[0].name));
      return bar;
    }
    const allBtn = h('button', {
      className: 'ag-project-chip' + (!state.selectedProjectId ? ' active' : ''),
      onClick: () => { state.selectedProjectId = null; reloadForProject(); },
    }, 'All');
    bar.append(allBtn);

    projects.forEach(p => {
      const btn = h('button', {
        className: 'ag-project-chip' + (state.selectedProjectId === p.id ? ' active' : ''),
        onClick: () => { state.selectedProjectId = p.id; reloadForProject(); },
      }, p.name);
      bar.append(btn);
    });

    return bar;
  }

  async function reloadForProject() {
    await Promise.all([loadSprints(), loadEpics(), loadStories(), loadBugs()]);
    // Reset sprint selection if current sprint doesn't match project
    if (state.selectedSprintId) {
      const sprint = state.sprints.find(s => s.id === state.selectedSprintId);
      if (!sprint) { state.selectedSprintId = null; state.boardData = null; }
    }
    // Auto-select first active sprint for project
    if (!state.selectedSprintId) {
      const active = state.sprints.find(s => s.status === 'active');
      if (active) state.selectedSprintId = active.id;
      else if (state.sprints.length) state.selectedSprintId = state.sprints[0].id;
    }
    render();
    if (state.selectedSprintId) loadBoard();
  }

  function renderSubNav() {
    const tabs = [{ key: 'board', label: 'Sprint Board' }, { key: 'backlog', label: 'Backlog' }];
    if (agile().canManageProjects) tabs.push({ key: 'projects', label: 'Projects' });

    const nav = h('div', { className: 'ag-subnav' });
    tabs.forEach(t => {
      const btn = h('button', {
        className: 'ag-subnav-btn' + (state.tab === t.key ? ' active' : ''),
        onClick: () => { state.tab = t.key; render(); loadTabData(); },
      }, t.label);
      nav.append(btn);
    });
    return nav;
  }

  function renderContent() {
    const wrap = h('div', { className: 'ag-content' });
    switch (state.tab) {
      case 'board': wrap.append(renderBoard()); break;
      case 'backlog': wrap.append(renderBacklog()); break;
      case 'epics': wrap.append(renderEpics()); break;
      case 'velocity': wrap.append(renderVelocity()); break;
      case 'squads': wrap.append(renderSquads()); break;
      case 'projects': wrap.append(renderProjects()); break;
      case 'guide': wrap.append(renderGuide()); break;
    }
    return wrap;
  }

  /* ── Board ───────────────────────────────────────────── */
  function renderBoard() {
    const wrap = h('div', { className: 'ag-board-wrap' });

    // Sprint selector
    const toolbar = h('div', { className: 'ag-toolbar' });
    const sprintSelect = h('select', { className: 'input ag-select', onChange: (e) => { state.selectedSprintId = +e.target.value; loadBoard(); } });
    sprintSelect.append(h('option', { value: '' }, '-- Select Sprint --'));
    state.sprints.forEach(s => {
      const opt = h('option', { value: s.id }, `${s.name} (${STATUS_LABELS[s.status] || s.status})`);
      if (s.id === state.selectedSprintId) opt.selected = true;
      sprintSelect.append(opt);
    });
    toolbar.append(h('label', {}, 'Sprint: '), sprintSelect);

    if (agile().canManageSprints) {
      toolbar.append(h('button', { className: 'btn btn-primary', onClick: showCreateSprintModal }, '+ New Sprint'));
    }
    wrap.append(toolbar);

    // Sprint info
    const sprint = state.sprints.find(s => s.id === state.selectedSprintId);
    if (sprint) {
      const info = h('div', { className: 'ag-sprint-info' });
      info.append(
        h('div', { className: 'ag-sprint-meta' },
          h('strong', {}, sprint.name),
          badge(STATUS_LABELS[sprint.status] || sprint.status, sprint.status === 'active' ? '#22c55e' : sprint.status === 'closed' ? '#6b7280' : '#3b82f6'),
          h('span', {}, `${sprint.completedPoints || 0}/${sprint.totalPoints || 0} pts`),
          sprint.daysRemaining != null ? h('span', {}, `${sprint.daysRemaining} days left`) : null
        )
      );
      if (sprint.goal) info.append(h('div', { className: 'ag-sprint-goal' }, sprint.goal));

      // Sprint actions — only the sprint creator can edit/manage
      const actions = h('div', { className: 'ag-sprint-actions' });
      const isSprintCreator = sprint.createdBy === agile().userId;
      if (isSprintCreator) {
        actions.append(h('button', { className: 'btn', onClick: () => showEditSprintModal(sprint) }, 'Edit Sprint'));
        if (sprint.status === 'planning') actions.append(h('button', { className: 'btn btn-success', onClick: () => sprintAction('activate') }, 'Start Sprint'));
        if (sprint.status === 'active') actions.append(h('button', { className: 'btn btn-warning', onClick: () => sprintAction('review') }, 'Move to Review'));
        if (sprint.status === 'active' || sprint.status === 'review') actions.append(h('button', { className: 'btn btn-danger', onClick: () => sprintAction('close') }, 'Close Sprint'));
        if (sprint.status === 'closed' || sprint.status === 'review') actions.append(h('button', { className: 'btn btn-success', onClick: () => sprintAction('reopen') }, 'Re-open Sprint'));
      } else if (agile().canCloseAnySprint && (sprint.status === 'active' || sprint.status === 'review')) {
        actions.append(h('button', { className: 'btn btn-danger', onClick: () => sprintAction('close') }, 'Close Sprint'));
      }
      // Export button — available to anyone who can view the sprint, regardless of role.
      // Routes through window.location so the auth session cookie is sent and dompdf can stream the file back.
      actions.append(h('button', {
        className: 'btn btn-outline-primary',
        title: 'Download a PDF snapshot of this sprint (stories, bugs, review notes, retrospective)',
        onClick: () => exportSprintPdf(sprint),
      }, '⬇ Export PDF'));
      info.append(actions);
      wrap.append(info);

      // Capacity bar
      const cap = state.boardCapacity;
      if (cap) {
        const capacity = cap.capacityHours;
        const used = cap.assignedHours || 0;
        const pct = capacity ? Math.min(100, Math.round((used / capacity) * 100)) : 0;
        const bar = h('div', { className: 'ag-capacity-wrap' });
        const label = capacity
          ? `${used}h / ${capacity}h capacity · ${cap.assignedPoints || 0} points · ${cap.storyCount || 0} stories`
          : `Capacity not set · ${used}h estimated · ${cap.assignedPoints || 0} points · ${cap.storyCount || 0} stories`;
        bar.append(h('div', { className: 'ag-capacity-label' }, label));
        if (capacity) {
          const fill = h('div', { className: 'ag-capacity-fill' + (cap.overCapacity ? ' ag-capacity-over' : '') });
          fill.style.width = pct + '%';
          bar.append(h('div', { className: 'ag-capacity-track' }, fill));
        }
        wrap.append(bar);
      }
    }

    // Board columns
    if (!state.boardData) {
      wrap.append(h('div', { className: 'ag-empty' }, sprint ? 'Loading board...' : 'Select a sprint to view the board.'));
      return wrap;
    }

    // Sprint bug-level summary — so critical bugs in active sprint aren't hidden behind column counts.
    const sprintBugs = Object.values(state.boardData).flatMap(c => c.bugs || []);
    const sprintLevel = { critical: 0, high: 0, medium: 0, low: 0 };
    sprintBugs.forEach(b => { const bucket = summaryBucket(b.priority); if (bucket && sprintLevel[bucket] !== undefined) sprintLevel[bucket]++; });
    if (sprintBugs.length) {
      wrap.append(h('div', { className: 'ag-bl-summary' },
        h('div', { className: 'ag-bl-card ag-bl-card-crit' }, h('span', { className: 'ag-bl-card-num' }, String(sprintLevel.critical)), h('span', { className: 'ag-bl-card-lbl' }, 'Critical')),
        h('div', { className: 'ag-bl-card ag-bl-card-high' }, h('span', { className: 'ag-bl-card-num' }, String(sprintLevel.high)), h('span', { className: 'ag-bl-card-lbl' }, 'High')),
        h('div', { className: 'ag-bl-card ag-bl-card-med' }, h('span', { className: 'ag-bl-card-num' }, String(sprintLevel.medium)), h('span', { className: 'ag-bl-card-lbl' }, 'Medium')),
        h('div', { className: 'ag-bl-card ag-bl-card-low' }, h('span', { className: 'ag-bl-card-num' }, String(sprintLevel.low)), h('span', { className: 'ag-bl-card-lbl' }, 'Low'))
      ));
    }

    const board = h('div', { className: 'ag-board' });
    const columns = ['todo', 'in_progress', 'code_review', 'qa', 'done'];
    const sprintSquad = sprint ? (state.squads || []).find(sq => sq.id === sprint.squadId) : null;
    const wipLimit = state.boardWip?.limit || null;
    const wipBreachesByCol = {};
    (state.boardWip?.breaches || []).forEach(b => {
      if (!wipBreachesByCol[b.status]) wipBreachesByCol[b.status] = new Set();
      wipBreachesByCol[b.status].add(b.userId);
    });
    const showDodFor = new Set(['code_review', 'qa', 'done']);

    columns.forEach(col => {
      const colData = state.boardData[col] || { stories: [], bugs: [] };
      const items = [...(colData.stories || []), ...(colData.bugs || [])];

      const colEl = h('div', { className: 'ag-board-col' });
      const header = h('div', { className: 'ag-col-header' });
      header.append(
        h('span', {}, STATUS_LABELS[col] || col),
        h('span', { className: 'ag-col-count' }, String(items.length))
      );
      if (wipLimit) {
        const breaching = !!wipBreachesByCol[col];
        header.append(h('span', { className: 'ag-wip-pill' + (breaching ? ' ag-wip-breach' : '') }, `WIP/user: ${wipLimit}`));
      }
      if (sprintSquad && sprintSquad.definitionOfDone && showDodFor.has(col)) {
        header.append(h('span', {
          className: 'ag-dod-link',
          title: 'View Definition of Done',
          onClick: (e) => { e.stopPropagation(); showDodModal(sprintSquad); },
        }, 'DoD'));
      }
      colEl.append(header);

      const body = h('div', { className: 'ag-col-body', 'data-status': col });
      items.forEach(item => {
        const breachUsers = wipBreachesByCol[col];
        const wipFlagged = breachUsers && item.assigneeId && breachUsers.has(item.assigneeId);
        body.append(renderCard(item, { wipFlagged }));
      });
      colEl.append(body);
      board.append(colEl);
    });
    wrap.append(board);

    // Add story button
    if (agile().canCrudStories && sprint) {
      wrap.append(h('div', { className: 'ag-add-bar' },
        h('button', { className: 'btn btn-primary', onClick: () => showCreateFeatureModal(sprint.id) }, '+ Add Feature'),
        h('button', { className: 'btn', onClick: () => showCreateBugModal(sprint.id) }, '+ Report Bug'),
        h('button', { className: 'btn', onClick: () => showCreateImprovementModal(sprint.id) }, '+ Improvement')
      ));
    }

    // Sprint review + retrospective sections (review/closed only)
    if (sprint && (sprint.status === 'review' || sprint.status === 'closed')) {
      wrap.append(renderSprintReviewSection(sprint));
    }

    return wrap;
  }

  function renderSprintReviewSection(sprint) {
    const wrap = h('div', { className: 'ag-sprint-review-wrap' });
    const canEdit = sprint.createdBy === agile().userId || agile().canManageSprints;

    // Review notes
    const reviewSection = h('div', { className: 'ag-detail-section' });
    reviewSection.append(h('strong', {}, 'Sprint Review (demo notes)'));
    const reviewArea = h('textarea', {
      className: 'input ag-input',
      rows: '4',
      placeholder: canEdit ? 'What did the team demo? Highlights, decisions, pending items.' : '',
      'data-grammar-fix': '',
    });
    reviewArea.value = sprint.reviewNotes || '';
    if (!canEdit) reviewArea.readOnly = true;
    reviewArea.addEventListener('blur', async () => {
      if (!canEdit) return;
      if ((reviewArea.value || '') === (sprint.reviewNotes || '')) return;
      try {
        await PUT(`/sprints/${sprint.id}`, { review_notes: reviewArea.value });
        sprint.reviewNotes = reviewArea.value;
      } catch (e) { alert(e.message); }
    });
    reviewSection.append(reviewArea);
    wrap.append(reviewSection);

    // Retrospective
    const retro = sprint.retrospectiveNotes || { wentWell: [], wentPoorly: [], actionItems: [] };
    const buckets = [
      { key: 'wentWell', label: 'Went Well', placeholder: 'What worked? Add one item per line.' },
      { key: 'wentPoorly', label: 'Went Poorly', placeholder: "What didn't work? Add one item per line." },
      { key: 'actionItems', label: 'Action Items', placeholder: 'What will we change next sprint?' },
    ];
    const retroSection = h('div', { className: 'ag-detail-section' });
    retroSection.append(h('strong', {}, 'Retrospective'));
    const retroGrid = h('div', { className: 'ag-retro-grid' });
    const areas = {};
    buckets.forEach(b => {
      const col = h('div', { className: 'ag-retro-col' });
      col.append(h('div', { className: 'ag-retro-col-label' }, b.label));
      const ta = h('textarea', { className: 'input ag-input', rows: '5', placeholder: canEdit ? b.placeholder : '', 'data-grammar-fix': '' });
      ta.value = (retro[b.key] || []).join('\n');
      if (!canEdit) ta.readOnly = true;
      areas[b.key] = ta;
      col.append(ta);
      retroGrid.append(col);
    });
    retroSection.append(retroGrid);

    if (canEdit) {
      const saveBtn = h('button', { className: 'btn btn-primary ag-retro-save', onClick: async () => {
        const payload = {};
        buckets.forEach(b => {
          payload[b.key] = (areas[b.key].value || '').split('\n').map(s => s.trim()).filter(Boolean);
        });
        try {
          await PUT(`/sprints/${sprint.id}`, { retrospective_notes: payload });
          sprint.retrospectiveNotes = payload;
          saveBtn.textContent = 'Saved ✓';
          setTimeout(() => { saveBtn.textContent = 'Save retrospective'; }, 1500);
        } catch (e) { alert(e.message); }
      }}, 'Save retrospective');
      retroSection.append(saveBtn);
    }
    wrap.append(retroSection);

    return wrap;
  }

  function renderCard(item, opts) {
    const isStory = item._type ? item._type === "story" : item.storyPoints !== undefined;
    const wipClass = opts && opts.wipFlagged ? ' ag-card-wip-breach' : '';
    const card = h('div', { className: 'card ag-card' + (isStory ? '' : ' ag-card-bug') + wipClass, onClick: () => showItemDetail(item, isStory), title: opts && opts.wipFlagged ? 'WIP limit exceeded for this assignee' : '' });
    const top = h('div', { className: 'ag-card-top' });
    top.append(priorityDot(effectiveLevel(item)));
    top.append(h('span', { className: 'ag-card-type' }, isStory ? (item.title && item.title.startsWith('[IMP]') ? 'Improvement' : 'Feature') : 'Bug'));
    if (isStory && item.storyPoints) top.append(h('span', { className: 'ag-card-points' }, `${item.storyPoints}pt`));
    if (!isStory && item.severity) top.append(badge(item.severity, PRIORITY_COLORS[item.severity]));
    card.append(top);
    card.append(h('div', { className: 'ag-card-title' }, item.title));
    const bottom = h('div', { className: 'ag-card-bottom' });
    if (item.assigneeName) bottom.append(h('span', { className: 'ag-card-assignee' }, item.assigneeName));
    if (item.labels && item.labels.length) bottom.append(h('span', { className: 'ag-card-labels' }, ...labelChips(item.labels)));
    card.append(bottom);

    // Status change buttons
    if (agile().canCrudStories || agile().canCrudBugs) {
      const moveBar = h('div', { className: 'ag-card-move' });
      const statuses = isStory ? ['todo', 'in_progress', 'code_review', 'qa', 'done'] : ['open', 'in_progress', 'fixed', 'verified', 'closed'];
      const currentStatus = item.status;
      const currentIdx = statuses.indexOf(currentStatus);
      if (currentIdx > 0) {
        moveBar.append(h('button', { className: 'btn btn-sm ag-move-btn', onClick: (e) => { e.stopPropagation(); moveItem(item, statuses[currentIdx - 1], isStory); } }, '\u2190'));
      }
      if (currentIdx < statuses.length - 1) {
        moveBar.append(h('button', { className: 'btn btn-sm ag-move-btn', onClick: (e) => { e.stopPropagation(); moveItem(item, statuses[currentIdx + 1], isStory); } }, '\u2192'));
      }
      card.append(moveBar);
    }

    return card;
  }

  async function moveItem(item, newStatus, isStory) {
    try {
      const endpoint = isStory ? `/stories/${item.id}/move` : `/bugs/${item.id}/move`;
      await PATCH(endpoint, { status: newStatus });
      await loadBoard();
    } catch (e) { alert(e.message); }
  }

  async function sprintAction(action) {
    if (!state.selectedSprintId) return;
    if (!confirm(`Are you sure you want to ${action} this sprint?`)) return;
    try {
      await POST(`/sprints/${state.selectedSprintId}/${action}`, {});
      await loadSprints();
      await loadBoard();
      render();
    } catch (e) { alert(e.message); }
  }

  // Triggers a same-origin GET so the session cookie is sent and dompdf streams
  // back the file with Content-Disposition: attachment — browser handles the save.
  function exportSprintPdf(sprint) {
    if (!sprint || !sprint.id) return;
    window.location.href = `/api/sprints/${sprint.id}/export`;
  }

  /* ── Backlog ─────────────────────────────────────────── */
  function renderBacklog() {
    const wrap = h('div', { className: 'ag-backlog-wrap' });
    // Total bug count is surfaced in the header itself so triage leads see the
    // overall load at a glance — separate from the filtered counter below.
    const totalBugsAll = (state.bugs || []).length;
    // A bug leaves the active Backlog once it's resolved (closed / won't-fix —
    // matching the server's resolved_at states). It still lives in its sprint
    // board and the DB; this just keeps the triage backlog to un-resolved work.
    const isBacklogBug = b => !b.sprintId && b.status !== 'closed' && b.status !== 'wont_fix';
    const backlogBugsCount = (state.bugs || []).filter(isBacklogBug).length;
    wrap.append(h('h2', { className: 'ag-section-title' },
      'Backlog',
      h('span', { className: 'ag-bl-bug-tally', title: `${totalBugsAll} bug${totalBugsAll === 1 ? '' : 's'} total across all sprints + backlog` },
        ` · ${backlogBugsCount} bug${backlogBugsCount === 1 ? '' : 's'} in backlog · ${totalBugsAll} total`
      )
    ));

    if (agile().canCrudStories) {
      wrap.append(h('div', { className: 'ag-add-bar' },
        h('button', { className: 'btn btn-primary', onClick: () => showCreateFeatureModal(null) }, '+ Add Feature'),
        h('button', { className: 'btn', onClick: () => showCreateBugModal(null) }, '+ Report Bug'),
        h('button', { className: 'btn', onClick: () => showCreateImprovementModal(null) }, '+ Improvement')
      ));
    }

    const backlogStories = state.stories.filter(s => !s.sprintId);
    const backlogBugs = state.bugs.filter(isBacklogBug);
    const allBacklog = [
      ...backlogStories.map(s => Object.assign({}, s, { _isStory: true })),
      ...backlogBugs.map(b => Object.assign({}, b, { _isStory: false }))
    ];

    // Summary cards — count backlog bugs by *priority* (not effective max-of-priority-and-severity).
    // Clicking a card filters the backlog by that priority bucket.
    const bugsByLevel = { critical: 0, high: 0, medium: 0, low: 0 };
    backlogBugs.forEach(b => { const bucket = summaryBucket(b.priority); if (bucket && bugsByLevel[bucket] !== undefined) bugsByLevel[bucket]++; });

    function summaryCard(extraClass, num, label, filterValue, filterKey) {
      const card = h('div', {
        className: 'ag-bl-card' + (extraClass ? ' ' + extraClass : '') + ' ag-bl-card-clickable',
        title: filterValue ? `Filter to ${label}` : '',
        onClick: () => {
          if (filterKey === 'priority') {
            // Toggle: clicking the same card clears the filter
            prioritySelect.value = (prioritySelect.value === filterValue) ? '' : filterValue;
            typeSelect.value = '';
          } else if (filterKey === 'type') {
            typeSelect.value = (typeSelect.value === filterValue) ? '' : filterValue;
            prioritySelect.value = '';
          }
          applyFilters();
        },
      });
      card.append(h('span', { className: 'ag-bl-card-num' }, String(num)), h('span', { className: 'ag-bl-card-lbl' }, label));
      return card;
    }

    const summaryCards = h('div', { className: 'ag-bl-summary' },
      summaryCard('', backlogStories.length, 'Features', 'feature', 'type'),
      summaryCard('', backlogBugs.length, 'Bugs', 'bug', 'type'),
      summaryCard('ag-bl-card-crit', bugsByLevel.critical, 'Critical', 'critical', 'priority'),
      summaryCard('ag-bl-card-high', bugsByLevel.high, 'High', 'high', 'priority'),
      summaryCard('ag-bl-card-med', bugsByLevel.medium, 'Medium', 'medium', 'priority'),
      summaryCard('ag-bl-card-low', bugsByLevel.low, 'Low', 'low', 'priority'),
    );
    wrap.append(summaryCards);

    // Filter bar
    let filterSearch = '';
    let filterPriority = '';
    let filterType = '';
    let filterAssignee = '';
    let filterDate = '';
    let filterDupOnly = false;
    let groupByMoscow = !!state.backlogGroupByMoscow;

    // Count of items that AI clustering flagged as part of a duplicate group.
    // Stories never have duplicateGroupId so this is effectively a bug count.
    const dupCount = allBacklog.filter(it => it.duplicateGroupId).length;

    const searchInput = h('input', { type: 'text', className: 'input ag-bl-search', placeholder: 'Search by ID, title, or keyword...' });
    const prioritySelect = h('select', { className: 'input ag-select ag-bl-filter' });
    prioritySelect.append(
      h('option', { value: '' }, 'All Levels'),
      h('option', { value: 'critical' }, 'Critical / Blocker'),
      h('option', { value: 'high' }, 'High / Major'),
      h('option', { value: 'medium' }, 'Medium'),
      h('option', { value: 'low' }, 'Low / Minor')
    );
    const typeSelect = h('select', { className: 'input ag-select ag-bl-filter' });
    typeSelect.append(
      h('option', { value: '' }, 'All Types'),
      h('option', { value: 'bug' }, 'Bugs'),
      h('option', { value: 'feature' }, 'Features'),
      h('option', { value: 'improvement' }, 'Improvements')
    );
    // Developer/Assignee filter — derive options from people who actually have
    // items in the backlog so the dropdown stays short. Yuvanesh asked for this
    // to triage bugs by developer; the same dropdown works for stories too.
    const assigneeMap = new Map();
    let hasUnassigned = false;
    allBacklog.forEach(it => {
      if (it.assigneeId && it.assigneeName) assigneeMap.set(it.assigneeId, it.assigneeName);
      else hasUnassigned = true;
    });
    const assigneeOpts = Array.from(assigneeMap.entries())
      .sort((a, b) => String(a[1]).localeCompare(String(b[1])));
    const assigneeSelect = h('select', { className: 'input ag-select ag-bl-filter', title: 'Filter by developer / assignee' });
    assigneeSelect.append(h('option', { value: '' }, 'All Developers'));
    if (hasUnassigned) assigneeSelect.append(h('option', { value: 'unassigned' }, 'Unassigned'));
    assigneeOpts.forEach(([id, name]) => assigneeSelect.append(h('option', { value: String(id) }, name)));
    const dateInput = h('input', { type: 'date', className: 'input ag-select ag-bl-filter ag-bl-date-input', title: 'Filter by date created' });
    dateInput.addEventListener('change', applyFilters);
    const groupToggle = h('label', { className: 'ag-bl-toggle' });
    const groupCheck = h('input', { type: 'checkbox' });
    groupCheck.checked = groupByMoscow;
    groupCheck.addEventListener('change', () => { groupByMoscow = groupCheck.checked; state.backlogGroupByMoscow = groupByMoscow; applyFilters(); });
    groupToggle.append(groupCheck, h('span', {}, ' Group by MoSCoW'));

    // "Duplicates only" — checkbox so users can stack it with the other
    // filters (e.g. "Critical bugs assigned to Rishabh that are duplicates")
    // instead of being mutually exclusive. Disabled when there are no
    // duplicates in the current backlog so the affordance doesn't mislead.
    const dupToggle = h('label', { className: 'ag-bl-toggle' + (dupCount === 0 ? ' ag-bl-toggle-disabled' : ''), title: dupCount === 0 ? 'No duplicates flagged in current backlog' : 'Show only AI-flagged duplicate bugs' });
    const dupCheck = h('input', { type: 'checkbox' });
    if (dupCount === 0) dupCheck.disabled = true;
    dupCheck.addEventListener('change', () => { filterDupOnly = dupCheck.checked; applyFilters(); });
    dupToggle.append(dupCheck, h('span', {}, ` Duplicates only (${dupCount})`));

    const resetBtn = h('button', { className: 'btn ag-bl-reset', onClick: () => {
      searchInput.value = ''; prioritySelect.value = ''; typeSelect.value = '';
      assigneeSelect.value = '';
      dateInput.value = '';
      dupCheck.checked = false;
      filterSearch = ''; filterPriority = ''; filterType = '';
      filterAssignee = '';
      filterDate = '';
      filterDupOnly = false;
      applyFilters();
    }}, 'Reset');
    const resultCounter = h('span', { className: 'ag-bl-counter' }, '');
    function paintCounter(filtered) {
      const totalItems = allBacklog.length;
      const filteredItems = filtered ? filtered.length : totalItems;
      const filteredBugs = (filtered || allBacklog).filter(it => !it._isStory).length;
      resultCounter.innerHTML = '';
      resultCounter.append(
        document.createTextNode(`${filteredItems} of ${totalItems} items · `),
        h('strong', {}, `${filteredBugs} bug${filteredBugs === 1 ? '' : 's'}`)
      );
    }
    paintCounter(null);

    // "Detect Duplicates" — kicks off a project-scoped AI re-clustering run.
    // Gated to roles that can already assign work (matches the backend
    // permission) so the cost of the AI call is bounded to triage staff.
    let detectBtn = null;
    if (agile().canAssignItems) {
      detectBtn = h('button', {
        className: 'btn ag-bl-detect-dup',
        title: 'Run AI duplicate detection across active bugs',
        onClick: async () => {
          if (!confirm('Run AI duplicate detection? This re-clusters every active bug and may take a minute.')) return;
          const originalText = detectBtn.textContent;
          detectBtn.disabled = true;
          detectBtn.textContent = 'Detecting…';
          try {
            const res = await POST('/bugs/detect-duplicates', state.selectedProjectId ? { project_id: state.selectedProjectId } : {});
            await loadBugs();
            render();
            alert('AI detection done.\nGroups: ' + (res.groups || 0) + '\nBugs flagged: ' + (res.duplicates || 0));
          } catch (err) {
            alert((err && err.message) || 'Detection failed');
          } finally {
            if (detectBtn && detectBtn.isConnected) {
              detectBtn.disabled = false;
              detectBtn.textContent = originalText;
            }
          }
        },
      }, 'Detect Duplicates (AI)');
    }

    const filterBar = h('div', { className: 'ag-bl-filters' },
      searchInput, prioritySelect, typeSelect, assigneeSelect,
      h('span', { className: 'ag-bl-date-label' }, 'Filter by Date'), dateInput,
      groupToggle, dupToggle, resetBtn, detectBtn, resultCounter
    );
    wrap.append(filterBar);

    const table = h('div', { className: 'ag-list' });

    function applyFilters() {
      filterSearch = searchInput.value.toLowerCase().trim();
      filterPriority = prioritySelect.value;
      filterType = typeSelect.value;
      filterAssignee = assigneeSelect.value;
      filterDate = dateInput.value;

      const filtered = allBacklog.filter(item => {
        if (filterSearch) {
          const idStr = String(item.id);
          const title = (item.title || '').toLowerCase();
          const assignee = (item.assigneeName || '').toLowerCase();
          const epic = (item.epicTitle || '').toLowerCase();
          const reporter = (item.reporterName || '').toLowerCase();
          if (!idStr.includes(filterSearch) && !title.includes(filterSearch) && !assignee.includes(filterSearch) && !epic.includes(filterSearch) && !reporter.includes(filterSearch)) return false;
        }
        if (filterPriority) {
          const itemBucket = summaryBucket(item.priority);
          if (itemBucket !== filterPriority) return false;
        }
        if (filterType === 'bug' && item._isStory) return false;
        if (filterType === 'feature' && (!item._isStory || (item.title && item.title.startsWith('[IMP]')))) return false;
        if (filterType === 'improvement' && (!item._isStory || !(item.title && item.title.startsWith('[IMP]')))) return false;
        if (filterAssignee === 'unassigned') {
          if (item.assigneeId) return false;
        } else if (filterAssignee) {
          if (String(item.assigneeId || '') !== filterAssignee) return false;
        }
        if (filterDate) {
          if (!item.createdAt) return false;
          const d = new Date(item.createdAt);
          const local = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
          if (local !== filterDate) return false;
        }
        if (filterDupOnly && !item.duplicateGroupId) return false;
        return true;
      });

      paintCounter(filtered);
      table.innerHTML = '';

      if (!filtered.length) {
        table.append(h('div', { className: 'ag-empty', style: 'padding:20px' }, 'No items match your filters.'));
        return;
      }

      if (groupByMoscow) {
        const groupOrder = ['must', 'should', 'could', 'wont', 'unset'];
        const groupLabels = { must: 'Must Have', should: 'Should Have', could: 'Could Have', wont: "Won't Have", unset: 'No MoSCoW set' };
        const buckets = { must: [], should: [], could: [], wont: [], unset: [] };
        filtered.forEach(item => {
          const key = (item._isStory && item.moscow) ? item.moscow : 'unset';
          (buckets[key] || buckets.unset).push(item);
        });
        groupOrder.forEach(key => {
          const list = buckets[key];
          if (!list.length) return;
          table.append(h('div', { className: 'ag-bl-group ag-bl-group-' + key }, `${groupLabels[key]} (${list.length})`));
          list.forEach(item => {
            if (item._isStory) renderStoryRow(item, table); else renderBugRow(item, table);
          });
        });
      } else {
        filtered.forEach(item => {
          if (item._isStory) renderStoryRow(item, table); else renderBugRow(item, table);
        });
      }
    }

    searchInput.addEventListener('input', applyFilters);
    prioritySelect.addEventListener('change', applyFilters);
    typeSelect.addEventListener('change', applyFilters);
    assigneeSelect.addEventListener('change', applyFilters);

    function renderStoryRow(s, container) {
      const isBlocked = Array.isArray(s.dependencies) && s.dependencies.some(d => d.status !== 'done');
      const row = h('div', { className: 'ag-list-row' + (isBlocked ? ' ag-list-row-blocked' : ''), onClick: () => showItemDetail(s, true) });
      const moscowChip = s.moscow ? h('span', { className: 'ag-moscow-chip ag-moscow-' + s.moscow }, s.moscow.toUpperCase()) : null;
      row.append(
        priorityDot(s.priority),
        h('span', { className: 'ag-card-type' }, s.title && s.title.startsWith('[IMP]') ? 'Improvement' : 'Feature'),
        h('span', { className: 'ag-bl-id' }, '#' + s.id),
        h('span', { className: 'ag-list-title' }, s.title),
        moscowChip,
        s.storyPoints ? h('span', { className: 'ag-card-points' }, `${s.storyPoints}pt`) : null,
        s.assigneeName ? h('span', { className: 'ag-list-assignee' }, s.assigneeName) : null,
        s.epicTitle ? h('span', { className: 'ag-list-epic' }, s.epicTitle) : null,
        isBlocked ? h('span', { className: 'ag-blocked-pill', title: 'Blocked by ' + s.dependencies.filter(d => d.status !== 'done').map(d => '#' + d.id).join(', ') }, 'Blocked') : null,
        ...labelChips(s.labels || [])
      );

      if (agile().canManageSprints && state.sprints.length) {
        const moveSelect = h('select', { className: 'input input-sm ag-select ag-select-sm', onClick: e => e.stopPropagation(), onChange: async (e) => {
          if (!e.target.value) return;
          try {
            await POST('/stories/bulk-move', { story_ids: [s.id], sprint_id: +e.target.value });
            await loadStories();
            render();
          } catch (err) { alert(err.message); }
        }});
        moveSelect.append(h('option', { value: '' }, 'Move to sprint...'));
        state.sprints.filter(sp => sp.status !== 'closed').forEach(sp => {
          moveSelect.append(h('option', { value: sp.id }, sp.name));
        });
        row.append(moveSelect);
      }
      container.append(row);
    }

    function renderBugRow(b, container) {
      const row = h('div', { className: 'ag-list-row ag-card-bug', onClick: () => showItemDetail(b, false) });
      const lv = effectiveLevel(b);
      const siblingCount = b.duplicateGroupId ? bugDuplicateSiblings(b).length : 0;
      row.append(
        priorityDot(lv),
        h('span', { className: 'ag-card-type' }, 'Bug'),
        h('span', { className: 'ag-bl-id' }, '#' + b.id),
        h('span', { className: 'ag-list-title' }, b.title),
        lv ? badge(lv, PRIORITY_COLORS[lv]) : null,
        b.awaitingQa ? h('span', { className: 'ag-qa-pending-pill', title: 'Bug marked Fixed — awaiting QA verification' }, 'Awaiting QA') : null,
        siblingCount > 0 ? h('span', {
          className: 'ag-dup-pill',
          title: 'AI-flagged duplicate — ' + (siblingCount + 1) + ' bugs in this group. Open the bug for the full list.',
        }, 'Duplicate (' + (siblingCount + 1) + ')') : null,
        b.assigneeName ? h('span', { className: 'ag-list-assignee' }, b.assigneeName) : null,
        b.reporterName ? h('span', { className: 'ag-list-epic' }, 'by ' + b.reporterName) : null,
        b.createdAt ? h('span', { className: 'ag-list-time', title: b.createdAt }, formatDateShort(b.createdAt)) : null,
        ...labelChips(b.labels || [])
      );

      if (agile().canManageSprints && state.sprints.length) {
        const moveSelect = h('select', { className: 'input input-sm ag-select ag-select-sm', onClick: e => e.stopPropagation(), onChange: async (e) => {
          if (!e.target.value) return;
          try {
            await PUT(`/bugs/${b.id}`, { sprint_id: +e.target.value });
            await loadBugs();
            render();
          } catch (err) { alert(err.message); }
        }});
        moveSelect.append(h('option', { value: '' }, 'Move to sprint...'));
        state.sprints.filter(sp => sp.status !== 'closed').forEach(sp => {
          moveSelect.append(h('option', { value: sp.id }, sp.name));
        });
        row.append(moveSelect);
      }
      container.append(row);
    }

    // Initial render uses the same filter/group logic so MoSCoW grouping
     // (if previously toggled) is honoured on tab re-entry.
    applyFilters();

    wrap.append(table);
    return wrap;
  }

  /* ── Epics ───────────────────────────────────────────── */
  function renderEpics() {
    const wrap = h('div', { className: 'ag-epics-wrap' });
    const header = h('div', { className: 'ag-section-header' });
    header.append(h('h2', { className: 'ag-section-title' }, 'Epics'));
    if (agile().canManageEpics) {
      header.append(h('button', { className: 'btn btn-primary', onClick: showCreateEpicModal }, '+ New Epic'));
    }
    wrap.append(header);

    if (!state.epics.length) {
      wrap.append(h('div', { className: 'ag-empty' }, 'No epics yet.'));
      return wrap;
    }

    state.epics.forEach(e => {
      const card = h('div', { className: 'card ag-epic-card' });
      card.append(
        h('div', { className: 'ag-epic-header' },
          priorityDot(e.priority),
          h('strong', {}, e.title),
          badge(STATUS_LABELS[e.status] || e.status, e.status === 'done' ? '#22c55e' : '#3b82f6'),
          e.squadName ? h('span', { className: 'ag-epic-squad' }, e.squadName) : null
        ),
        h('div', { className: 'ag-epic-progress' },
          h('div', { className: 'ag-progress-bar' },
            h('div', { className: 'ag-progress-fill', style: `width:${e.progress}%` })
          ),
          h('span', {}, `${e.progress}%`)
        )
      );
      if (e.description) card.append(h('div', { className: 'ag-epic-desc' }, e.description));
      if (e.targetDate) card.append(h('div', { className: 'ag-epic-date' }, `Target: ${e.targetDate}`));
      if (e.ownerName) card.append(h('div', { className: 'ag-epic-owner' }, `Owner: ${e.ownerName}`));
      if (e.labels && e.labels.length) card.append(h('div', { className: 'ag-epic-labels' }, ...labelChips(e.labels)));
      wrap.append(card);
    });
    return wrap;
  }

  /* ── Velocity ────────────────────────────────────────── */
  function renderVelocity() {
    const wrap = h('div', { className: 'ag-velocity-wrap' });
    wrap.append(h('h2', { className: 'ag-section-title' }, 'Velocity Dashboard'));
    wrap.append(h('div', { className: 'ag-empty', id: 'velocityContent' }, 'Loading...'));
    loadVelocity().then(() => {
      const el = document.getElementById('velocityContent');
      if (el) el.replaceWith(renderVelocityContent());
    });
    return wrap;
  }

  function renderVelocityContent() {
    const wrap = h('div', { className: 'ag-velocity-content' });
    if (!state.velocityData) {
      return h('div', { className: 'ag-empty' }, 'No velocity data yet. Complete a sprint to see velocity.');
    }

    // If array (all squads)
    const datasets = Array.isArray(state.velocityData) ? state.velocityData : [state.velocityData];
    datasets.forEach(ds => {
      const section = h('div', { className: 'ag-velocity-squad' });
      if (ds.squadName) section.append(h('h3', {}, ds.squadName));
      const data = ds.velocity || ds;
      if (data.averageVelocity != null) section.append(h('div', { className: 'ag-velocity-avg' }, `Average Velocity: ${data.averageVelocity} pts/sprint`));

      if (data.sprints && data.sprints.length) {
        const chart = h('div', { className: 'ag-velocity-chart' });
        const maxV = Math.max(...data.sprints.map(s => s.velocity || 0), 1);
        data.sprints.forEach(s => {
          const pct = ((s.velocity || 0) / maxV) * 100;
          const bar = h('div', { className: 'ag-velocity-bar-wrap' },
            h('div', { className: 'ag-velocity-bar', style: `height:${pct}%` }),
            h('div', { className: 'ag-velocity-bar-label' }, String(s.velocity || 0)),
            h('div', { className: 'ag-velocity-bar-name' }, s.name)
          );
          chart.append(bar);
        });
        section.append(chart);
      } else {
        section.append(h('div', { className: 'ag-empty' }, 'No completed sprints yet.'));
      }
      wrap.append(section);
    });
    return wrap;
  }

  /* ── Squads ──────────────────────────────────────────── */
  function renderSquads() {
    const wrap = h('div', { className: 'ag-squads-wrap' });
    const header = h('div', { className: 'ag-section-header' });
    header.append(h('h2', { className: 'ag-section-title' }, 'Squads'));
    header.append(h('button', { className: 'btn btn-primary', onClick: showCreateSquadModal }, '+ New Squad'));
    wrap.append(header);

    if (!state.squads.length) {
      wrap.append(h('div', { className: 'ag-empty' }, 'No squads yet. Create one to get started.'));
      return wrap;
    }

    state.squads.forEach(sq => {
      const card = h('div', { className: 'card ag-squad-card' });
      const headerRow = h('div', { className: 'ag-squad-header' },
        h('strong', {}, sq.name),
        sq.leadName ? h('span', { className: 'ag-squad-lead' }, `Lead: ${sq.leadName}`) : null
      );
      if (agile().canManageSprints) {
        headerRow.append(h('button', { className: 'btn btn-sm', onClick: () => showSquadAgileSettingsModal(sq), style: 'margin-left:auto' }, 'Agile Settings'));
      }
      card.append(headerRow);
      if (sq.description) card.append(h('p', { className: 'ag-squad-desc' }, sq.description));

      // Agile settings summary chips
      const chips = h('div', { className: 'ag-squad-chips' });
      if (sq.wipLimitPerUser) chips.append(h('span', { className: 'ag-squad-chip' }, `WIP/user: ${sq.wipLimitPerUser}`));
      if (sq.definitionOfReady) chips.append(h('span', { className: 'ag-squad-chip' }, 'DoR set'));
      if (sq.definitionOfDone) chips.append(h('span', { className: 'ag-squad-chip' }, 'DoD set'));
      if (chips.children.length) card.append(chips);

      // Members
      const memberList = h('div', { className: 'ag-squad-members' });
      (sq.members || []).forEach(m => {
        const member = h('div', { className: 'ag-squad-member' },
          h('span', {}, m.name),
          h('span', { className: 'ag-member-role' }, m.roleInSquad),
          h('button', { className: 'btn-icon ag-btn-danger-text', onClick: async () => {
            if (!confirm(`Remove ${m.name} from ${sq.name}?`)) return;
            try { await DELETE(`/squads/${sq.id}/members/${m.id}`); await loadSquads(); render(); } catch (e) { alert(e.message); }
          }}, '\u00d7')
        );
        memberList.append(member);
      });
      card.append(memberList);

      // Add member
      const addRow = h('div', { className: 'ag-squad-add-member' });
      const userSelect = h('select', { className: 'input input-sm ag-select ag-select-sm' });
      userSelect.append(h('option', { value: '' }, 'Add member...'));
      people().forEach(p => {
        if (!(sq.members || []).find(m => m.id === p.id)) {
          userSelect.append(h('option', { value: p.id }, p.name));
        }
      });
      const addBtn = h('button', { className: 'btn btn-sm', onClick: async () => {
        if (!userSelect.value) return;
        try { await POST(`/squads/${sq.id}/members`, { user_id: +userSelect.value }); await loadSquads(); render(); } catch (e) { alert(e.message); }
      }}, 'Add');
      addRow.append(userSelect, addBtn);
      card.append(addRow);

      wrap.append(card);
    });
    return wrap;
  }

  /* ── Modals ──────────────────────────────────────────── */
  function showModal(title, fields, onSubmit) {
    const overlay = h('div', { className: 'modal-overlay ag-modal-overlay', onClick: (e) => { if (e.target === overlay) overlay.remove(); } });
    const modal = h('div', { className: 'modal ag-modal' });
    modal.append(h('h3', { className: 'ag-modal-title' }, title));

    const form = h('form', { className: 'ag-modal-form', onSubmit: async (e) => {
      e.preventDefault();
      const hasFile = fields.some(f => f.type === 'file');
      const data = hasFile ? new FormData() : {};
      fields.forEach(f => {
        if (f.type === 'section' || f.type === 'info') return;
        const el = form.querySelector(`[name="${f.name}"]`);
        if (!el) return;
        if (f.type === 'file') {
          if (el.files[0]) data.append(f.name, el.files[0]);
        } else if (hasFile) {
          data.append(f.name, el.value || '');
        } else if (f.type === 'number') {
          data[f.name] = el.value ? +el.value : null;
        } else if (f.type === 'select-multiple') {
          data[f.name] = Array.from(el.selectedOptions).map(o => +o.value);
        } else {
          data[f.name] = el.value || null;
        }
      });
      try {
        await onSubmit(data);
        overlay.remove();
      } catch (err) { alert(err.message); }
    }});

    fields.forEach(f => {
      // Section header — non-field, used to group fields visually
      if (f.type === 'section') {
        form.append(h('div', { className: 'ag-form-section' }, f.label));
        return;
      }
      // Info panel — non-field, renders rich help text
      if (f.type === 'info') {
        const panel = h('div', { className: 'ag-form-info' });
        if (f.label) panel.append(h('strong', {}, f.label));
        if (Array.isArray(f.lines)) {
          f.lines.forEach(line => panel.append(h('div', { className: 'ag-form-info-line' }, line)));
        } else if (f.text) {
          panel.append(h('div', {}, f.text));
        }
        form.append(panel);
        return;
      }

      const group = h('div', { className: 'ag-form-group' });
      group.append(h('label', {}, f.label));
      if (f.type === 'textarea') {
        const ta = h('textarea', { name: f.name, className: 'input ag-input', rows: String(f.rows || 3), placeholder: f.placeholder || '', 'data-grammar-fix': '' });
        if (f.value !== undefined && f.value !== null) ta.value = f.value;
        group.append(ta);
      } else if (f.type === 'select') {
        const sel = h('select', { name: f.name, className: 'input ag-select' });
        (f.options || []).forEach(o => {
          const opt = h('option', { value: o.value }, o.label);
          if (f.value !== undefined && f.value !== null && String(o.value) === String(f.value)) opt.selected = true;
          sel.append(opt);
        });
        group.append(sel);
      } else if (f.type === 'select-multiple') {
        const sel = h('select', { name: f.name, className: 'input ag-select ag-select-multi', multiple: 'multiple', size: String(Math.min(8, Math.max(3, (f.options || []).length))) });
        const preset = Array.isArray(f.value) ? f.value.map(v => String(v)) : [];
        (f.options || []).forEach(o => {
          const opt = h('option', { value: o.value }, o.label);
          if (preset.includes(String(o.value))) opt.selected = true;
          sel.append(opt);
        });
        group.append(sel);
        if (f.hint) group.append(h('div', { className: 'ag-form-hint' }, f.hint));
      } else if (f.type === 'file') {
        const inp = h('input', { type: 'file', name: f.name, className: 'input ag-input', accept: f.accept || 'image/*' });
        group.append(inp);
      } else if (f.type === 'number') {
        const inp = h('input', { type: 'number', name: f.name, className: 'input ag-input', placeholder: f.placeholder || '', ...(f.min != null ? { min: String(f.min) } : {}), ...(f.max != null ? { max: String(f.max) } : {}), ...(f.required ? { required: '' } : {}) });
        if (f.value !== undefined && f.value !== null) inp.value = f.value;
        group.append(inp);
      } else {
        const inp = h('input', { type: f.type || 'text', name: f.name, className: 'input ag-input', placeholder: f.placeholder || '', ...(f.required ? { required: '' } : {}) });
        if (f.value !== undefined && f.value !== null) inp.value = f.value;
        group.append(inp);
      }
      if (f.hint && f.type !== 'select-multiple') group.append(h('div', { className: 'ag-form-hint' }, f.hint));
      form.append(group);
    });

    form.append(h('div', { className: 'ag-modal-actions' },
      h('button', { type: 'button', className: 'btn', onClick: () => overlay.remove() }, 'Cancel'),
      h('button', { type: 'submit', className: 'btn btn-primary' }, 'Create')
    ));

    modal.append(form);
    overlay.append(modal);
    document.body.append(overlay);
    const first = form.querySelector('input,textarea,select');
    if (first) first.focus();
  }

  function showCreateSprintModal() {
    const projOpts = (agile().projects || []).map(p => ({ value: p.id, label: p.name }));
    showModal('Create Sprint', [
      { name: 'name', label: 'Sprint Name', placeholder: 'e.g. Sprint 1', required: true },
      { name: 'goal', label: 'Sprint Goal', type: 'textarea', placeholder: 'What do you want to achieve?' },
      { name: 'project_id', label: 'Project', type: 'select', options: [{ value: '', label: '-- Select --' }, ...projOpts] },
      { name: 'start_date', label: 'Start Date', type: 'date', required: true },
      { name: 'end_date', label: 'End Date', type: 'date', required: true },
    ], async (data) => {
      await POST('/sprints', data);
      await loadSprints();
      render();
    });
  }

  function showEditSprintModal(sprint) {
    const projOpts = (agile().projects || []).map(p => ({ value: p.id, label: p.name }));
    showModal('Edit Sprint', [
      { name: 'name', label: 'Sprint Name', required: true, value: sprint.name },
      { name: 'goal', label: 'Sprint Goal', type: 'textarea', value: sprint.goal || '' },
      { name: 'project_id', label: 'Project', type: 'select', options: [{ value: '', label: '-- Select --' }, ...projOpts], value: sprint.projectId || '' },
      { name: 'start_date', label: 'Start Date', type: 'date', required: true, value: sprint.startDate || '' },
      { name: 'end_date', label: 'End Date', type: 'date', required: true, value: sprint.endDate || '' },
    ], async (data) => {
      await PUT('/sprints/' + sprint.id, data);
      await loadSprints();
      if (state.selectedSprintId === sprint.id) await loadBoard();
      render();
    });
  }

  // INVEST checklist content — guidance only, not persisted
  const INVEST_LINES = [
    'Independent — minimal coupling to other stories',
    'Negotiable — details can evolve through conversation',
    'Valuable — clear value to a user or the business',
    'Estimable — team can estimate effort',
    'Small — fits comfortably inside one sprint',
    'Testable — clear, verifiable acceptance criteria',
  ];
  const PRIORITY_OPTS = [{ value: 'low', label: 'Low' }, { value: 'medium', label: 'Medium' }, { value: 'high', label: 'High' }, { value: 'critical', label: 'Critical' }];
  const MOSCOW_OPTS = [{ value: '', label: '— Not set —' }, { value: 'must', label: 'Must Have' }, { value: 'should', label: 'Should Have' }, { value: 'could', label: 'Could Have' }, { value: 'wont', label: "Won't Have (this release)" }];
  const BV_OPTS = [{ value: '', label: '— Not set —' }, { value: 'low', label: 'Low' }, { value: 'medium', label: 'Medium' }, { value: 'high', label: 'High' }];

  function buildStoryFields(opts = {}) {
    const projOpts = (agile().projects || []).map(p => ({ value: p.id, label: p.name }));
    const peopleOpts = people().map(p => ({ value: p.id, label: p.name }));
    const epicOpts = (state.epics || []).map(e => ({ value: e.id, label: e.title }));
    const sprintOpts = (state.sprints || []).filter(sp => sp.status !== 'closed').map(sp => ({ value: sp.id, label: sp.name }));
    const depOpts = (state.stories || [])
      .filter(s => !opts.excludeStoryId || s.id !== opts.excludeStoryId)
      .map(s => ({ value: s.id, label: `#${s.id} ${s.title}` }));

    return [
      { type: 'info', label: 'INVEST checklist (guidance)', lines: INVEST_LINES },

      { type: 'section', label: 'Story' },
      { name: 'title', label: 'Title', placeholder: opts.titlePlaceholder || 'As a [user] I want [feature] so that [value]', required: true, value: opts.title },
      { name: 'description', label: 'Description', type: 'textarea', placeholder: 'User-facing context. Why this matters.', value: opts.description },
      { name: 'project_id', label: 'Project', type: 'select', options: [{ value: '', label: 'None' }, ...projOpts], value: opts.projectId },
      { name: 'epic_id', label: 'Epic', type: 'select', options: [{ value: '', label: 'None' }, ...epicOpts], value: opts.epicId },
      { name: 'sprint_id', label: 'Sprint', type: 'select', options: [{ value: '', label: 'Backlog' }, ...sprintOpts], value: opts.sprintId },
      { name: 'assignee_id', label: 'Assignee', type: 'select', options: [{ value: '', label: 'Unassigned' }, ...peopleOpts], value: opts.assigneeId },

      { type: 'section', label: 'Refine' },
      { name: 'acceptance_criteria', label: 'Acceptance Criteria', type: 'textarea', rows: 4, placeholder: 'Given … When … Then …\nGiven … When … Then …', value: opts.acceptanceCriteria },
      { name: 'technical_notes', label: 'Technical Notes', type: 'textarea', rows: 3, placeholder: 'API endpoints, DB tables, gotchas. Optional.', value: opts.technicalNotes },

      { type: 'section', label: 'Estimate & Prioritize' },
      { name: 'story_points', label: 'Story Points (1–21)', type: 'number', min: 1, max: 21, value: opts.storyPoints },
      { name: 'priority', label: 'Priority (severity)', type: 'select', options: PRIORITY_OPTS, value: opts.priority },
      { name: 'moscow', label: 'MoSCoW', type: 'select', options: MOSCOW_OPTS, value: opts.moscow },
      { name: 'business_value', label: 'Business Value', type: 'select', options: BV_OPTS, value: opts.businessValue },

      { type: 'section', label: 'Link' },
      { name: 'dependency_ids', label: 'Blocked by (dependencies)', type: 'select-multiple', options: depOpts, value: opts.dependencyIds, hint: 'Hold Ctrl/Cmd to select multiple. These must finish first.' },
    ];
  }

  function showCreateFeatureModal(sprintId) {
    showModal('Add Feature', buildStoryFields({ sprintId }), async (data) => {
      if (sprintId) data.sprint_id = sprintId;
      // Ensure dependency_ids is always an array (empty if none selected)
      if (!Array.isArray(data.dependency_ids)) data.dependency_ids = [];
      await POST('/stories', data);
      await Promise.all([loadStories(), loadBoard()]);
      render();
    });
  }

  function showCreateImprovementModal(sprintId) {
    showModal('Add Improvement', buildStoryFields({ sprintId, titlePlaceholder: 'What needs to be improved?' }), async (data) => {
      data.title = '[IMP] ' + (data.title || '');
      if (sprintId) data.sprint_id = sprintId;
      if (!Array.isArray(data.dependency_ids)) data.dependency_ids = [];
      await POST('/stories', data);
      await Promise.all([loadStories(), loadBoard()]);
      render();
    });
  }

  // Bug-specific dropdown vocabularies
  const BUG_SEVERITY_OPTS = [
    { value: 'high', label: 'High' },
    { value: 'medium', label: 'Medium' },
    { value: 'low', label: 'Low' },
  ];
  const BUG_PRIORITY_OPTS = [
    { value: 'blocker', label: 'Blocker' },
    { value: 'critical', label: 'Critical' },
    { value: 'major', label: 'Major' },
    { value: 'minor', label: 'Minor' },
  ];

  // Reusable multi-file attachment zone for bug create/edit modals.
  // Returns { element, getNewFiles(), getRemoveIds(), attachPasteHandler(modal) }.
  function buildAttachmentZone(existingAttachments) {
    const wrap = h('div', { className: 'ag-attach-wrap' });
    const newFiles = [];      // File[]
    const removeIds = new Set();   // attachment IDs slated for delete

    const fileInput = h('input', { type: 'file', accept: '*/*', multiple: 'multiple', style: 'display:none' });

    const dropZone = h('div', { className: 'ag-bug-shot-zone', tabIndex: '0' });
    const hint = h('div', { className: 'ag-bug-shot-hint' }, 'Click, drop, or paste files (multiple allowed, any type, up to 50 MB each)');
    dropZone.append(hint, fileInput);
    wrap.append(dropZone);

    // Existing attachments list (only present in edit modal)
    const existingList = h('div', { className: 'ag-attach-list' });
    function renderExistingList() {
      existingList.innerHTML = '';
      const visible = (existingAttachments || []).filter(a => a.id == null || !removeIds.has(a.id));
      if (!visible.length && (existingAttachments || []).length === 0) {
        existingList.style.display = 'none';
      } else {
        existingList.style.display = '';
      }
      visible.forEach(a => {
        const row = h('div', { className: 'ag-attach-row' });
        const link = h('a', { href: a.url, target: '_blank', rel: 'noopener', className: 'ag-attach-name' },
          (a.isImage ? '🖼 ' : '📎 ') + (a.name || 'file')
        );
        row.append(link);
        if (a.legacy) {
          row.append(h('span', { className: 'ag-attach-tag' }, 'Existing'));
        }
        if (a.id != null) {
          const rm = h('button', { type: 'button', className: 'btn btn-sm btn-danger', onClick: (e) => {
            e.preventDefault();
            removeIds.add(a.id);
            renderExistingList();
          }}, 'Remove');
          row.append(rm);
        } else if (a.legacy) {
          // Legacy single screenshot: removal goes through screenshot_clear flag (kept on edit modal)
          const rm = h('button', { type: 'button', className: 'btn btn-sm btn-danger', onClick: (e) => {
            e.preventDefault();
            wrap.dataset.legacyClear = '1';
            row.style.opacity = '0.4';
            rm.disabled = true;
            rm.textContent = 'Will remove';
          }}, 'Remove');
          row.append(rm);
        }
        existingList.append(row);
      });
    }
    wrap.append(existingList);
    renderExistingList();

    // New uploads list
    const queuedList = h('div', { className: 'ag-attach-list ag-attach-queued' });
    function renderQueuedList() {
      queuedList.innerHTML = '';
      if (!newFiles.length) { queuedList.style.display = 'none'; return; }
      queuedList.style.display = '';
      queuedList.append(h('div', { className: 'ag-attach-list-label' }, 'New files queued:'));
      newFiles.forEach((f, idx) => {
        const row = h('div', { className: 'ag-attach-row' });
        const sizeMb = (f.size / (1024 * 1024)).toFixed(2);
        const isImg = f.type && f.type.startsWith('image/');
        row.append(
          h('span', { className: 'ag-attach-name' }, (isImg ? '🖼 ' : '📎 ') + f.name + ' (' + sizeMb + ' MB)'),
          h('button', { type: 'button', className: 'btn btn-sm', onClick: (e) => { e.preventDefault(); newFiles.splice(idx, 1); renderQueuedList(); } }, '×')
        );
        queuedList.append(row);
      });
    }
    wrap.append(queuedList);
    renderQueuedList();

    function addFiles(fileList) {
      Array.from(fileList).forEach(f => {
        if (f && f.size > 0) newFiles.push(f);
      });
      renderQueuedList();
    }

    dropZone.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => { addFiles(fileInput.files); fileInput.value = ''; });
    dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.classList.add('ag-bug-shot-zone-dragover'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('ag-bug-shot-zone-dragover'));
    dropZone.addEventListener('drop', (e) => {
      e.preventDefault();
      dropZone.classList.remove('ag-bug-shot-zone-dragover');
      if (e.dataTransfer && e.dataTransfer.files) addFiles(e.dataTransfer.files);
    });

    return {
      element: wrap,
      attachPasteHandler(modal) {
        const onPaste = (e) => {
          const items = (e.clipboardData || window.clipboardData)?.items || [];
          const collected = [];
          for (const it of items) {
            if (it.kind === 'file') {
              const f = it.getAsFile();
              if (f) {
                const isImage = f.type && f.type.startsWith('image/');
                collected.push(isImage
                  ? new File([f], 'pasted-' + Date.now() + '.' + (f.type.split('/')[1] || 'png'), { type: f.type })
                  : f);
              }
            }
          }
          if (collected.length) {
            e.preventDefault();
            collected.forEach(f => newFiles.push(f));
            renderQueuedList();
          }
        };
        modal.addEventListener('paste', onPaste);
        return () => modal.removeEventListener('paste', onPaste);
      },
      getNewFiles: () => newFiles.slice(),
      getRemoveIds: () => Array.from(removeIds),
      shouldClearLegacy: () => wrap.dataset.legacyClear === '1',
    };
  }

  function showCreateBugModal(sprintId) {
    const peopleOpts = people().map(p => ({ value: p.id, label: p.name }));
    const projOpts = (agile().projects || []).map(p => ({ value: p.id, label: p.name }));

    const overlay = h('div', { className: 'modal-overlay ag-modal-overlay', onClick: (e) => { if (e.target === overlay) overlay.remove(); } });
    const modal = h('div', { className: 'modal ag-modal' });
    modal.append(h('h3', { className: 'ag-modal-title' }, 'Report Bug'));

    const grp = (label, child) => h('div', { className: 'ag-form-group' }, h('label', {}, label), child);
    const sel = (opts, val) => {
      const s = h('select', { className: 'input ag-select' });
      opts.forEach(o => {
        const opt = h('option', { value: o.value }, o.label);
        if (val != null && String(o.value) === String(val)) opt.selected = true;
        s.append(opt);
      });
      return s;
    };

    const titleEl = h('input', { type: 'text', className: 'input ag-input', required: '' });
    const descEl = h('textarea', { className: 'input ag-input', rows: '3', 'data-grammar-fix': '' });
    const stepsEl = h('textarea', { className: 'input ag-input', rows: '3', 'data-grammar-fix': '' });
    const projectEl = sel([{ value: '', label: 'None' }, ...projOpts], '');
    const assigneeEl = sel([{ value: '', label: 'Unassigned' }, ...peopleOpts], '');
    const severityEl = sel(BUG_SEVERITY_OPTS, 'medium');
    const priorityEl = sel(BUG_PRIORITY_OPTS, 'major');
    const environmentEl = sel([{ value: '', label: 'N/A' }, { value: 'dev', label: 'Dev' }, { value: 'staging', label: 'Staging' }, { value: 'production', label: 'Production' }], '');

    // Duplicate-detection panel — populated as the reporter types.
    const dupPanel = h('div', { className: 'ag-dup-panel', style: 'display:none' });
    let dupTimer = null;
    let dupReqId = 0;
    async function checkDuplicates() {
      const title = titleEl.value.trim();
      if (title.length < 4) {
        dupPanel.style.display = 'none';
        dupPanel.innerHTML = '';
        return;
      }
      const projectId = projectEl.value || (state.selectedProjectId ? String(state.selectedProjectId) : '');
      const myReq = ++dupReqId;
      try {
        const json = await POST('/bugs/check-duplicates', {
          title,
          project_id: projectId ? +projectId : null,
        });
        if (myReq !== dupReqId) return; // a newer keystroke superseded us
        renderDupPanel(json.duplicates || []);
      } catch (_) {
        if (myReq !== dupReqId) return;
        dupPanel.style.display = 'none';
        dupPanel.innerHTML = '';
      }
    }
    function renderDupPanel(dups) {
      dupPanel.innerHTML = '';
      if (!dups.length) { dupPanel.style.display = 'none'; return; }
      dupPanel.style.display = 'block';
      dupPanel.append(h('div', { className: 'ag-dup-panel-head' },
        h('strong', {}, `Possible duplicate${dups.length > 1 ? 's' : ''} found`),
        h('span', { className: 'ag-dup-panel-sub' }, ' — open these before filing a new one to avoid redundant reports.')
      ));
      dups.forEach(d => {
        const row = h('div', { className: 'ag-dup-row', title: `Similarity: ${d.similarity}% · click to view`, onClick: () => {
          const existing = (state.bugs || []).find(b => b.id === d.id);
          if (existing) showItemDetail(existing, false);
        }});
        row.append(
          h('span', { className: 'ag-dup-id' }, '#' + d.id),
          h('span', { className: 'ag-dup-title' }, d.title),
          h('span', { className: 'ag-dup-meta' }, (STATUS_LABELS[d.status] || d.status) + (d.assigneeName ? ' · ' + d.assigneeName : '')),
          h('span', { className: 'ag-dup-score' }, d.similarity + '%')
        );
        dupPanel.append(row);
      });
    }
    titleEl.addEventListener('input', () => {
      clearTimeout(dupTimer);
      dupTimer = setTimeout(checkDuplicates, 350);
    });
    projectEl.addEventListener('change', checkDuplicates);

    const attachZone = buildAttachmentZone([]);
    const detachPaste = attachZone.attachPasteHandler(modal);

    const form = h('form', { className: 'ag-modal-form', onSubmit: async (e) => {
      e.preventDefault();
      const fd = new FormData();
      fd.append('title', titleEl.value.trim());
      fd.append('description', descEl.value || '');
      fd.append('steps_to_reproduce', stepsEl.value || '');
      const projectId = projectEl.value || (state.selectedProjectId ? String(state.selectedProjectId) : '');
      if (projectId) fd.append('project_id', projectId);
      if (assigneeEl.value) fd.append('assignee_id', assigneeEl.value);
      fd.append('severity', severityEl.value);
      fd.append('priority', priorityEl.value);
      if (environmentEl.value) fd.append('environment', environmentEl.value);
      if (sprintId) fd.append('sprint_id', sprintId);
      attachZone.getNewFiles().forEach(f => fd.append('attachments[]', f));

      const submitBtn = form.querySelector('button[type="submit"]');
      submitBtn.disabled = true;
      submitBtn.textContent = 'Saving...';
      try {
        const res = await fetch('/api/bugs', {
          method: 'POST',
          headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
          credentials: 'same-origin',
          body: fd,
        });
        const json = await res.json();
        if (!res.ok || !json.ok) {
          const firstErr = json.errors ? Object.values(json.errors)[0][0] : (json.error || 'Save failed');
          throw new Error(firstErr);
        }
        detachPaste();
        overlay.remove();
        await Promise.all([loadBugs(), loadBoard()]);
        render();
      } catch (err) {
        alert(err.message);
        submitBtn.disabled = false;
        submitBtn.textContent = 'Create';
      }
    }});

    form.append(
      grp('Title', titleEl),
      dupPanel,
      grp('Description', descEl),
      grp('Steps to Reproduce', stepsEl),
      grp('Project', projectEl),
      grp('Assignee', assigneeEl),
      grp('Severity', severityEl),
      grp('Priority', priorityEl),
      grp('Environment', environmentEl),
      grp('Attachments (any file, up to 50 MB each, max 10 files)', attachZone.element),
      h('div', { className: 'ag-modal-actions' },
        h('button', { type: 'button', className: 'btn', onClick: () => { detachPaste(); overlay.remove(); } }, 'Cancel'),
        h('button', { type: 'submit', className: 'btn btn-primary' }, 'Create')
      )
    );

    modal.append(form);
    overlay.append(modal);
    document.body.append(overlay);
    titleEl.focus();
  }

  function showCreateEpicModal() {
    const squadOpts = state.squads.map(s => ({ value: s.id, label: s.name }));
    const peopleOpts = people().map(p => ({ value: p.id, label: p.name }));
    const projOpts = (agile().projects || []).map(p => ({ value: p.id, label: p.name }));
    showModal('Create Epic', [
      { name: 'title', label: 'Title', required: true },
      { name: 'description', label: 'Description', type: 'textarea' },
      { name: 'project_id', label: 'Project', type: 'select', options: [{ value: '', label: 'None' }, ...projOpts] },
      { name: 'squad_id', label: 'Squad', type: 'select', options: [{ value: '', label: 'None' }, ...squadOpts] },
      { name: 'owner_id', label: 'Owner', type: 'select', options: [{ value: '', label: 'None' }, ...peopleOpts] },
      { name: 'priority', label: 'Priority', type: 'select', options: [{ value: 'low', label: 'Low' }, { value: 'medium', label: 'Medium' }, { value: 'high', label: 'High' }, { value: 'critical', label: 'Critical' }] },
      { name: 'target_date', label: 'Target Date', type: 'date' },
    ], async (data) => {
      await POST('/epics', data);
      await loadEpics();
      render();
    });
  }

  function showCreateSquadModal() {
    const peopleOpts = people().map(p => ({ value: p.id, label: p.name }));
    showModal('Create Squad', [
      { name: 'name', label: 'Squad Name', required: true },
      { name: 'description', label: 'Description', type: 'textarea' },
      { name: 'lead_user_id', label: 'Squad Lead', type: 'select', options: [{ value: '', label: 'None' }, ...peopleOpts] },
    ], async (data) => {
      await POST('/squads', data);
      await loadSquads();
      render();
    });
  }

  // Suggested defaults users can edit
  const DEFAULT_DOR = [
    'Story title and description are clear',
    'Acceptance criteria written (Given/When/Then)',
    'Story points estimated',
    'Dependencies identified and unblocked',
    'Designs / specs attached if needed',
  ].join('\n');
  const DEFAULT_DOD = [
    'Code written and peer reviewed',
    'Unit tests passing (≥80% coverage where applicable)',
    'Integration tests passing',
    'Docs updated',
    'Deployed to staging',
    'QA approved',
  ].join('\n');

  function showSquadAgileSettingsModal(sq) {
    showModal(`Agile Settings — ${sq.name}`, [
      { type: 'info', label: 'Per-squad Agile rules', lines: ['These apply to every sprint and story in this squad.', 'Leave any field blank to disable.'] },
      { type: 'section', label: 'Definition of Ready' },
      { name: 'definition_of_ready', label: 'Story is ready to start when…', type: 'textarea', rows: 6, placeholder: DEFAULT_DOR, value: sq.definitionOfReady || '' },
      { type: 'section', label: 'Definition of Done' },
      { name: 'definition_of_done', label: 'Story is done when…', type: 'textarea', rows: 6, placeholder: DEFAULT_DOD, value: sq.definitionOfDone || '' },
      { type: 'section', label: 'WIP Limit' },
      { name: 'wip_limit_per_user', label: 'Max items per person per board column (0 = unlimited)', type: 'number', min: 0, max: 50, value: sq.wipLimitPerUser ?? '' },
    ], async (data) => {
      await PUT(`/squads/${sq.id}`, data);
      await loadSquads();
      render();
    });
  }

  function showDodModal(squad) {
    const overlay = h('div', { className: 'modal-overlay ag-modal-overlay', onClick: (e) => { if (e.target === overlay) overlay.remove(); } });
    const modal = h('div', { className: 'modal ag-modal' });
    modal.append(h('h3', { className: 'ag-modal-title' }, `Definition of Done — ${squad.name}`));
    const body = h('div', { className: 'ag-detail-body' });
    if (squad.definitionOfReady) {
      body.append(h('div', { className: 'ag-detail-section' }, h('strong', {}, 'Definition of Ready'), h('pre', { className: 'ag-pre-soft' }, squad.definitionOfReady)));
    }
    body.append(h('div', { className: 'ag-detail-section' }, h('strong', {}, 'Definition of Done'), h('pre', { className: 'ag-pre-soft' }, squad.definitionOfDone || 'Not set')));
    body.append(h('div', { style: 'display:flex;justify-content:flex-end;margin-top:12px' },
      h('button', { className: 'btn', onClick: () => overlay.remove() }, 'Close')
    ));
    modal.append(body);
    overlay.append(modal);
    document.body.append(overlay);
  }

  function showItemDetail(item, isStory) {
    const overlay = h('div', { className: 'modal-overlay ag-modal-overlay', onClick: (e) => { if (e.target === overlay) overlay.remove(); } });
    const modal = h('div', { className: 'modal ag-modal ag-modal-detail' });
    modal.append(
      h('div', { className: 'ag-detail-header' },
        h('span', { className: 'ag-card-type' }, isStory ? (item.title && item.title.startsWith('[IMP]') ? 'Improvement' : 'Feature') : 'Bug'),
        priorityDot(!isStory && item.severity ? item.severity : item.priority),
        h('h3', {}, item.title),
        h('button', { className: 'ag-modal-close', onClick: () => overlay.remove() }, '\u00d7')
      )
    );

    const body = h('div', { className: 'ag-detail-body' });

    // Duplicate warning banner — only for bugs that AI clustering flagged.
    // Clickable rows so the reviewer can jump to a sibling and compare.
    if (!isStory && item.duplicateGroupId) {
      const siblings = bugDuplicateSiblings(item);
      if (siblings.length) {
        const banner = h('div', { className: 'ag-dup-banner' });
        banner.append(h('div', { className: 'ag-dup-banner-head' },
          h('strong', {}, '⚠ AI-flagged possible duplicate'),
          h('span', { className: 'ag-dup-banner-sub' }, ' — review these before working on this bug:')
        ));
        siblings.forEach(s => {
          const row = h('div', {
            className: 'ag-dup-banner-row',
            title: 'Open #' + s.id,
            onClick: (e) => {
              e.stopPropagation();
              overlay.remove();
              showItemDetail(s, false);
            },
          });
          row.append(
            h('span', { className: 'ag-dup-id' }, '#' + s.id),
            h('span', { className: 'ag-dup-title' }, s.title),
            h('span', { className: 'ag-dup-meta' }, (STATUS_LABELS[s.status] || s.status) + (s.assigneeName ? ' · ' + s.assigneeName : (s.reporterName ? ' · by ' + s.reporterName : '')))
          );
          banner.append(row);
        });
        body.append(banner);
      }
    }

    body.append(h('div', { className: 'ag-detail-row' }, h('strong', {}, 'Status: '), h('span', {}, STATUS_LABELS[item.status] || item.status)));
    if (item.assigneeName) body.append(h('div', { className: 'ag-detail-row' }, h('strong', {}, 'Assignee: '), h('span', {}, item.assigneeName)));
    if (item.reporterName) body.append(h('div', { className: 'ag-detail-row' }, h('strong', {}, 'Reporter: '), h('span', {}, item.reporterName)));
    if (isStory && item.storyPoints) body.append(h('div', { className: 'ag-detail-row' }, h('strong', {}, 'Points: '), h('span', {}, String(item.storyPoints))));
    if (item.epicTitle) body.append(h('div', { className: 'ag-detail-row' }, h('strong', {}, 'Epic: '), h('span', {}, item.epicTitle)));
    if (item.sprintName) body.append(h('div', { className: 'ag-detail-row' }, h('strong', {}, 'Sprint: '), h('span', {}, item.sprintName)));
    if (item.description) body.append(h('div', { className: 'ag-detail-section' }, h('strong', {}, 'Description'), h('p', {}, item.description)));
    if (isStory && item.acceptanceCriteria) body.append(h('div', { className: 'ag-detail-section' }, h('strong', {}, 'Acceptance Criteria'), h('p', {}, item.acceptanceCriteria)));
    if (isStory && item.technicalNotes) body.append(h('div', { className: 'ag-detail-section' }, h('strong', {}, 'Technical Notes'), h('p', {}, item.technicalNotes)));
    if (isStory && item.moscow) {
      const MOSCOW_LBL = { must: 'Must Have', should: 'Should Have', could: 'Could Have', wont: "Won't Have" };
      body.append(h('div', { className: 'ag-detail-row' }, h('strong', {}, 'MoSCoW: '), h('span', { className: 'ag-moscow-badge ag-moscow-' + item.moscow }, MOSCOW_LBL[item.moscow] || item.moscow)));
    }
    if (isStory && item.businessValue) body.append(h('div', { className: 'ag-detail-row' }, h('strong', {}, 'Business Value: '), h('span', {}, item.businessValue)));
    if (isStory && Array.isArray(item.dependencies) && item.dependencies.length) {
      const sec = h('div', { className: 'ag-detail-section' }, h('strong', {}, 'Blocked by'));
      item.dependencies.forEach(d => {
        const blocked = d.status !== 'done';
        sec.append(h('div', { className: 'ag-dep-row' + (blocked ? ' ag-dep-blocked' : '') }, '#' + d.id + ' — ' + d.title + ' (' + (STATUS_LABELS[d.status] || d.status) + ')'));
      });
      body.append(sec);
    }
    if (isStory && Array.isArray(item.dependents) && item.dependents.length) {
      const sec = h('div', { className: 'ag-detail-section' }, h('strong', {}, 'Blocks'));
      item.dependents.forEach(d => {
        sec.append(h('div', { className: 'ag-dep-row' }, '#' + d.id + ' — ' + d.title + ' (' + (STATUS_LABELS[d.status] || d.status) + ')'));
      });
      body.append(sec);
    }
    if (!isStory && item.stepsToReproduce) body.append(h('div', { className: 'ag-detail-section' }, h('strong', {}, 'Steps to Reproduce'), h('p', {}, item.stepsToReproduce)));
    if (!isStory && item.severity) body.append(h('div', { className: 'ag-detail-row' }, h('strong', {}, 'Severity: '), badge(item.severity, PRIORITY_COLORS[item.severity])));
    if (!isStory && item.priority) body.append(h('div', { className: 'ag-detail-row' }, h('strong', {}, 'Priority: '), badge(item.priority, PRIORITY_COLORS[item.priority])));
    if (!isStory && item.environment) body.append(h('div', { className: 'ag-detail-row' }, h('strong', {}, 'Environment: '), h('span', {}, item.environment)));
    if (!isStory && item.awaitingQa) body.append(h('div', { className: 'ag-detail-row' }, h('strong', {}, 'Status: '), h('span', { className: 'ag-qa-pending-pill' }, 'Awaiting QA verification')));
    if (!isStory) {
      // Render the unified attachments list (legacy screenshot + new bug_attachments rows)
      const list = Array.isArray(item.attachments) && item.attachments.length
        ? item.attachments
        : (item.attachmentUrl || item.screenshotUrl ? [{
            id: null,
            name: item.attachmentName || (item.attachmentUrl || item.screenshotUrl).split('/').pop(),
            url: item.attachmentUrl || item.screenshotUrl,
            isImage: item.attachmentIsImage !== false,
            legacy: true,
          }] : []);
      if (list.length) {
        const sec = h('div', { className: 'ag-detail-section' }, h('strong', {}, list.length === 1 ? 'Attachment' : `Attachments (${list.length})`));
        list.forEach(a => {
          // Server flags attachments whose underlying file was lost (older
          // dir-perms bug). Render as a non-clickable placeholder so the user
          // sees the original filename and knows to re-upload.
          if (a.needsReupload) {
            sec.append(h('div', { className: 'ag-attachment-link', style: 'opacity:.7;text-decoration:line-through' },
              '📎 ' + (a.name || 'file'),
              h('span', { style: 'margin-left:8px;padding:1px 6px;border-radius:8px;background:#fef3c7;color:#92400e;font-size:11px;text-decoration:none' }, 'Needs re-upload')
            ));
            return;
          }
          if (a.isImage) {
            const img = h('img', { src: a.url, className: 'ag-bug-screenshot', alt: a.name || 'Bug attachment', loading: 'lazy', title: a.name || '' });
            img.addEventListener('click', () => window.open(a.url, '_blank'));
            sec.append(img);
          } else {
            sec.append(h('a', { href: a.url, target: '_blank', rel: 'noopener', className: 'ag-attachment-link' }, '📎 ' + (a.name || 'file')));
          }
        });
        body.append(sec);
      }
    }
    if (item.labels && item.labels.length) body.append(h('div', { className: 'ag-detail-row' }, h('strong', {}, 'Labels: '), ...labelChips(item.labels)));
    if (item.createdAt) body.append(h('div', { className: 'ag-detail-row' }, h('strong', {}, 'Created: '), h('span', {}, formatDateShort(item.createdAt))));
    if (item.updatedAt && item.updatedAt !== item.createdAt) body.append(h('div', { className: 'ag-detail-row' }, h('strong', {}, 'Updated: '), h('span', {}, formatDateShort(item.updatedAt))));

    // Branch name with copy
    const slug = (item.title || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').substring(0, 40);
    const prefix = isStory ? 'feature' : 'bugfix';
    const branchId = isStory ? ('STORY-' + item.id) : ('BUG-' + item.id);
    const branchName = prefix + '/' + branchId + '-' + slug;
    const branchRow = h('div', {
      style: 'display:flex;align-items:center;gap:8px;margin-top:12px;padding:10px 12px;background:#0f0f1a;border-radius:8px;cursor:pointer',
      title: 'Click to copy branch name',
      onClick: (e) => {
        e.stopPropagation();
        navigator.clipboard.writeText(branchName).then(() => {
          branchText.textContent = 'Copied!';
          branchText.style.color = '#34d399';
          copyBtn.textContent = '✓';
          copyBtn.style.color = '#34d399';
          setTimeout(() => { branchText.textContent = branchName; branchText.style.color = '#a5b4fc'; copyBtn.textContent = 'Copy'; copyBtn.style.color = '#6b7280'; }, 1500);
        });
      }
    });
    branchRow.append(h('strong', { style: 'font-size:12px;color:#6b7280;white-space:nowrap' }, 'Branch:'));
    const branchText = h('code', { style: 'font-size:12px;color:#a5b4fc;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap' }, branchName);
    branchRow.append(branchText);
    const copyBtn = h('span', { style: 'font-size:11px;color:#6b7280;padding:3px 8px;border:1px solid #3f3f46;border-radius:5px;white-space:nowrap' }, 'Copy');
    branchRow.append(copyBtn);
    body.append(branchRow);

    // Edit/Delete actions (for users with crud permissions)
    if ((isStory && agile().canCrudStories) || (!isStory && agile().canCrudBugs)) {
      const itemActions = h('div', { style: 'display:flex;gap:8px;margin-top:16px;justify-content:flex-end' });
      itemActions.append(h('button', { className: 'btn', onClick: () => { overlay.remove(); if (isStory) showEditItemModal(item, true); else showEditBugModal(item); } }, 'Edit'));
      itemActions.append(h('button', { className: 'btn btn-danger', onClick: async () => {
        if (!confirm('Delete this ' + (isStory ? 'feature' : 'bug') + '? This cannot be undone.')) return;
        try {
          const endpoint = isStory ? ('/stories/' + item.id) : ('/bugs/' + item.id);
          await DELETE(endpoint);
          overlay.remove();
          await Promise.all([loadStories(), loadBugs(), loadBoard()]);
          render();
        } catch (e) { alert(e.message); }
      }}, 'Delete'));
      body.append(itemActions);
    }

    modal.append(body);
    overlay.append(modal);
    document.body.append(overlay);
  }

  function showEditItemModal(item, isStory) {
    if (isStory) {
      // Story edit — use the same INVEST-aware fields as Add
      const fields = buildStoryFields({
        excludeStoryId: item.id,
        title: item.title || '',
        description: item.description || '',
        projectId: item.projectId || item.project_id || '',
        epicId: item.epicId || item.epic_id || '',
        sprintId: item.sprintId || item.sprint_id || '',
        assigneeId: item.assigneeId || item.assignee_id || '',
        acceptanceCriteria: item.acceptanceCriteria || item.acceptance_criteria || '',
        technicalNotes: item.technicalNotes || item.technical_notes || '',
        storyPoints: item.storyPoints || item.story_points || '',
        priority: item.priority || 'medium',
        moscow: item.moscow || '',
        businessValue: item.businessValue || item.business_value || '',
        dependencyIds: Array.isArray(item.dependencies) ? item.dependencies.map(d => d.id) : [],
      });
      showModal('Edit Story', fields, async (data) => {
        if (!Array.isArray(data.dependency_ids)) data.dependency_ids = [];
        await PUT('/stories/' + item.id, data);
        await Promise.all([loadStories(), loadBoard()]);
        render();
      });
      return;
    }

    // Bug edit — defer to the full bug-edit modal (with attach support)
    showEditBugModal(item);
  }

  /* ── Edit Bug — custom modal with paste/drop attachment support ── */
  function showEditBugModal(bug) {
    const projOpts = (agile().projects || []).map(p => ({ value: p.id, label: p.name }));
    const peopleOpts = people().map(p => ({ value: p.id, label: p.name }));

    const overlay = h('div', { className: 'modal-overlay ag-modal-overlay', onClick: (e) => { if (e.target === overlay) overlay.remove(); } });
    const modal = h('div', { className: 'modal ag-modal' });
    modal.append(h('h3', { className: 'ag-modal-title' }, 'Edit Bug'));

    function group(label, child) {
      return h('div', { className: 'ag-form-group' }, h('label', {}, label), child);
    }
    function textInput(value, attrs) {
      const el = h('input', { type: 'text', className: 'input ag-input', ...(attrs || {}) });
      if (value != null) el.value = value;
      return el;
    }
    function textarea(value) {
      const ta = h('textarea', { className: 'input ag-input', rows: '3', 'data-grammar-fix': '' });
      if (value != null) ta.value = value;
      return ta;
    }
    function select(opts, value) {
      const sel = h('select', { className: 'input ag-select' });
      opts.forEach(o => {
        const opt = h('option', { value: o.value }, o.label);
        if (value != null && String(o.value) === String(value)) opt.selected = true;
        sel.append(opt);
      });
      return sel;
    }

    const titleEl = textInput(bug.title || '', { required: '' });
    const descEl = textarea(bug.description || '');
    const projectEl = select([{ value: '', label: 'None' }, ...projOpts], bug.projectId || '');
    const assigneeEl = select([{ value: '', label: 'Unassigned' }, ...peopleOpts], bug.assigneeId || '');
    const priorityEl = select(BUG_PRIORITY_OPTS, bug.priority || 'major');
    const severityEl = select(BUG_SEVERITY_OPTS, bug.severity || 'medium');
    const stepsEl = textarea(bug.stepsToReproduce || '');

    // Multi-attachment zone — uses the unified `attachments` list (legacy
    // screenshot is presented as a virtual entry with id=null and legacy=true).
    const existingList = Array.isArray(bug.attachments) && bug.attachments.length
      ? bug.attachments
      : (bug.screenshotUrl ? [{
          id: null,
          name: (bug.attachmentName || bug.screenshotUrl.split('/').pop()),
          url: bug.screenshotUrl,
          isImage: bug.attachmentIsImage !== false,
          legacy: true,
        }] : []);
    const attachZone = buildAttachmentZone(existingList);
    const detachPaste = attachZone.attachPasteHandler(modal);

    const form = h('form', { className: 'ag-modal-form', onSubmit: async (e) => {
      e.preventDefault();
      const fd = new FormData();
      fd.append('_method', 'PUT');
      fd.append('title', titleEl.value.trim());
      fd.append('description', descEl.value || '');
      fd.append('steps_to_reproduce', stepsEl.value || '');
      if (projectEl.value) fd.append('project_id', projectEl.value);
      if (assigneeEl.value) fd.append('assignee_id', assigneeEl.value);
      fd.append('priority', priorityEl.value);
      fd.append('severity', severityEl.value);
      attachZone.getNewFiles().forEach(f => fd.append('attachments[]', f));
      attachZone.getRemoveIds().forEach(id => fd.append('attachment_remove_ids[]', String(id)));
      if (attachZone.shouldClearLegacy()) fd.append('screenshot_clear', '1');

      const submitBtn = form.querySelector('button[type="submit"]');
      submitBtn.disabled = true;
      submitBtn.textContent = 'Saving...';
      try {
        const res = await fetch('/api/bugs/' + bug.id, {
          method: 'POST',
          headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
          credentials: 'same-origin',
          body: fd,
        });
        const json = await res.json();
        if (!res.ok || !json.ok) {
          const firstErr = json.errors ? Object.values(json.errors)[0][0] : (json.error || 'Save failed');
          throw new Error(firstErr);
        }
        detachPaste();
        overlay.remove();
        await Promise.all([loadStories(), loadBugs(), loadBoard()]);
        render();
      } catch (err) {
        alert(err.message);
        submitBtn.disabled = false;
        submitBtn.textContent = 'Save';
      }
    }});

    form.append(
      group('Title', titleEl),
      group('Description', descEl),
      group('Project', projectEl),
      group('Assignee', assigneeEl),
      group('Priority', priorityEl),
      group('Severity', severityEl),
      group('Steps to Reproduce', stepsEl),
      group('Attachments (any file, up to 50 MB each)', attachZone.element),
      h('div', { className: 'ag-modal-actions' },
        h('button', { type: 'button', className: 'btn', onClick: () => { detachPaste(); overlay.remove(); } }, 'Cancel'),
        h('button', { type: 'submit', className: 'btn btn-primary' }, 'Save')
      )
    );

    modal.append(form);
    overlay.append(modal);
    document.body.append(overlay);
    titleEl.focus();
  }

  /* ── Projects ─────────────────────────────────────────── */
  function renderProjects() {
    const wrap = h('div', { className: 'ag-squads-wrap' });
    const header = h('div', { className: 'ag-section-header' });
    header.append(h('h2', { className: 'ag-section-title' }, 'Projects'));
    header.append(h('button', { className: 'btn btn-primary', onClick: showCreateProjectModal }, '+ New Project'));
    wrap.append(header);

    if (!state.allProjects.length) {
      wrap.append(h('div', { className: 'ag-empty' }, 'No projects yet.'));
      return wrap;
    }

    state.allProjects.forEach(p => {
      const card = h('div', { className: 'card ag-squad-card' });
      const cardHeader = h('div', { className: 'ag-squad-header' });
      cardHeader.append(h('strong', {}, p.name));
      if (p.createdAt) cardHeader.append(h('span', { className: 'ag-member-role' }, `Created: ${new Date(p.createdAt).toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata' })}`));
      card.append(cardHeader);

      const actions = h('div', { className: 'ag-sprint-actions', style: 'margin-top:8px' });
      actions.append(h('button', { className: 'btn btn-sm', onClick: () => showEditProjectModal(p) }, 'Edit'));
      actions.append(h('button', { className: 'btn btn-sm btn-danger', onClick: async () => {
        if (!confirm(`Delete project "${p.name}"? This will NOT delete associated sprints/stories/epics, but they will lose their project association.`)) return;
        try { await DELETE(`/projects/${p.id}`); await loadAllProjects(); render(); } catch (e) { alert(e.message); }
      }}, 'Delete'));
      card.append(actions);
      wrap.append(card);
    });
    return wrap;
  }

  function showCreateProjectModal() {
    showModal('Create Project', [
      { name: 'name', label: 'Project Name', placeholder: 'e.g. Hima, Sudar', required: true },
    ], async (data) => {
      await POST('/projects', data);
      await loadAllProjects();
      render();
    });
  }

  function showEditProjectModal(project) {
    const overlay = h('div', { className: 'modal-overlay ag-modal-overlay', onClick: (e) => { if (e.target === overlay) overlay.remove(); } });
    const modal = h('div', { className: 'modal ag-modal' });
    modal.append(h('h3', { className: 'ag-modal-title' }, 'Edit Project'));

    const nameInput = h('input', { type: 'text', className: 'input ag-input', value: project.name, required: '' });

    const form = h('form', { className: 'ag-modal-form', onSubmit: async (e) => {
      e.preventDefault();
      try {
        await PUT(`/projects/${project.id}`, { name: nameInput.value });
        overlay.remove();
        await loadAllProjects();
        // Also refresh project bar
        const agileConfig = agile();
        if (agileConfig.projects) {
          const updated = state.allProjects.map(p => ({ id: p.id, name: p.name }));
          agileConfig.projects = updated;
        }
        render();
      } catch (err) { alert(err.message); }
    }});

    form.append(
      h('div', { className: 'ag-form-group' }, h('label', {}, 'Project Name'), nameInput),
      h('div', { className: 'ag-modal-actions' },
        h('button', { type: 'button', className: 'btn', onClick: () => overlay.remove() }, 'Cancel'),
        h('button', { type: 'submit', className: 'btn btn-primary' }, 'Save')
      )
    );

    modal.append(form);
    overlay.append(modal);
    document.body.append(overlay);
    nameInput.focus();
  }

  async function loadAllProjects() {
    try { const r = await GET('/projects'); state.allProjects = r.projects || []; } catch (e) { console.error('loadAllProjects', e); }
  }

  /* ── Guide ────────────────────────────────────────────── */
  function renderGuide() {
    const wrap = h('div', { className: 'ag-guide' });
    wrap.innerHTML = GUIDE_HTML;
    return wrap;
  }

  const GUIDE_HTML = `
<h2>Agile Guide for the Team</h2>
<p class="ag-guide-intro">New to Agile? This guide explains everything in simple terms. Read it once — it'll make the board, backlog, and sprints make sense.</p>

<div class="ag-guide-section">
<h3>What is Agile & Why Are We Doing This?</h3>
<p><strong>The Problem:</strong> We have multiple tech members all working on different things. Without a system — nobody knows what others are working on, big features get stuck with no visibility, bugs get lost, and leadership can't see progress without asking everyone.</p>
<p><strong>The Solution — Agile (Scrum):</strong></p>
<ol>
<li>Break work into small pieces (2 weeks at a time)</li>
<li>Everyone can see what everyone else is doing (transparency)</li>
<li>You review and adjust every 2 weeks (no waiting months to find problems)</li>
</ol>
<p>Think of it like cooking — instead of preparing a 10-course meal all at once, you cook one dish at a time, taste it, adjust seasoning, then move to the next.</p>
</div>

<div class="ag-guide-section">
<h3>The Work Hierarchy: Epic > Story > Task > Bug</h3>

<h4>Epic</h4>
<p>A BIG feature or project that takes weeks or months. Too large for one sprint.</p>
<p><em>Examples: "Build Invoice Module", "Revamp Meeting System", "Add AI Chat"</em></p>
<p>Without Epics, big work items sit as one giant task with no way to track progress. With Epics, you can see "Invoice Module is 60% done — 6 of 10 stories completed."</p>

<h4>Story</h4>
<p>One specific thing a user can do after you build it. Written as: <strong>"As a [role], I can [do something]"</strong></p>
<p><em>Examples: "As a CFO, I can approve invoices", "As an Accountant, I can upload an invoice"</em></p>
<p>Each Story should be completable within one sprint (2 weeks). If it can't, break it smaller.</p>
<p>A Story has: title, description, <strong>acceptance criteria</strong> (how do we know it's DONE?), story points, priority, and an assignee.</p>

<h4>Task</h4>
<p>The actual technical work needed to complete a Story. One Story usually has multiple Tasks.</p>
<p><em>Example for "CFO can approve invoices": 1) Create DB table, 2) Build API endpoint, 3) Add UI buttons, 4) Send Slack notification</em></p>

<h4>Bug</h4>
<p>Something that's broken in existing functionality. Bugs have extra fields: <strong>severity</strong> (how bad), <strong>steps to reproduce</strong>, and <strong>environment</strong> (dev/staging/production).</p>
<p><strong>Severity vs Priority:</strong> Severity = how broken (critical = app crashes). Priority = how soon to fix (a low-severity bug on login page may be high priority because everyone sees it).</p>
</div>

<div class="ag-guide-section">
<h3>Story Points</h3>
<p>Story Points are NOT hours. They are a rough estimate of complexity relative to other work.</p>
<table class="ag-guide-table">
<tr><td><strong>1 pt</strong></td><td>Trivial — fix a typo, change a color</td></tr>
<tr><td><strong>2 pts</strong></td><td>Small — add a column, simple change</td></tr>
<tr><td><strong>3 pts</strong></td><td>Medium — build a simple API endpoint</td></tr>
<tr><td><strong>5 pts</strong></td><td>Large — form + API + DB changes</td></tr>
<tr><td><strong>8 pts</strong></td><td>Very large — needs research, many parts</td></tr>
<tr><td><strong>13 pts</strong></td><td>Too big — break it into smaller stories</td></tr>
</table>
<p>After a few sprints, we'll know our <strong>velocity</strong> — how many points we complete per sprint. This helps us plan realistically.</p>
</div>

<div class="ag-guide-section">
<h3>Sprints: The Heartbeat</h3>
<p>A Sprint is a fixed 2-week period where the team commits to completing a set of Stories/Bugs.</p>

<div class="ag-guide-flow">
<span class="ag-guide-step">Planning</span>
<span class="ag-guide-arrow">&rarr;</span>
<span class="ag-guide-step active">Active</span>
<span class="ag-guide-arrow">&rarr;</span>
<span class="ag-guide-step">Review</span>
<span class="ag-guide-arrow">&rarr;</span>
<span class="ag-guide-step">Closed</span>
</div>

<p><strong>Planning (Day 1):</strong> Team picks stories from the backlog they can realistically finish. Sprint gets a one-sentence Goal.</p>
<p><strong>Active (Day 1-14):</strong> Everyone works. Daily standups (15 min). Stories move across the board: Todo → In Progress → Code Review → QA → Done.</p>
<p><strong>Review (Day 14):</strong> Team demos what they built. Incomplete work goes back to backlog.</p>
<p><strong>Closed:</strong> Velocity calculated. Retro: What went well? What didn't? What to improve?</p>
</div>

<div class="ag-guide-section">
<h3>The Sprint Board</h3>
<p>A visual board with columns. Each Story/Bug is a card that moves left to right.</p>
<table class="ag-guide-table">
<tr><td><strong>Todo</strong></td><td>Planned for this sprint, not started yet</td><td>Sprint Planning</td></tr>
<tr><td><strong>In Progress</strong></td><td>Someone is actively coding</td><td>Developer moves own card</td></tr>
<tr><td><strong>Code Review</strong></td><td>Code written, waiting for review</td><td>Developer after pushing code</td></tr>
<tr><td><strong>QA</strong></td><td>Reviewed, waiting for testing</td><td>After review approved</td></tr>
<tr><td><strong>Done</strong></td><td>Tested, approved, merged</td><td>QA after testing passes</td></tr>
</table>
<p><strong>Rules:</strong> Only move YOUR cards. If stuck, raise it in standup. A card shouldn't stay in one column for more than 2-3 days.</p>
</div>

<div class="ag-guide-section">
<h3>Squads</h3>
<p>A Squad is a small team (3-6 people) within the larger tech team. Each Squad owns a specific area.</p>
<p><strong>Why?</strong> Too many people in one team = standups take forever, work gets confusing. Squads have their own sprints, their own board, their own velocity.</p>
</div>

<div class="ag-guide-section">
<h3>Velocity & Burndown</h3>
<p><strong>Velocity</strong> = how many Story Points completed per sprint. It tells you how much to plan next time. If velocity is 26, don't plan 40 points.</p>
<p><strong>Important:</strong> Velocity is NOT a performance metric. Don't use it to judge people. It's a planning tool.</p>
<p><strong>Burndown Chart</strong> = shows remaining work day by day. Line should go down steadily. Flat line = team is stuck. Line going up = work was added mid-sprint (bad).</p>
</div>

<div class="ag-guide-section">
<h3>Daily Standup</h3>
<p>Each person answers 3 questions:</p>
<ol>
<li>What did I do yesterday?</li>
<li>What will I do today?</li>
<li>Am I blocked by anything?</li>
</ol>
<p><strong>15 minutes MAX.</strong> Not a discussion meeting. Take discussions offline.</p>
</div>

<div class="ag-guide-section">
<h3>Labels</h3>
<p>Color-coded tags on Stories/Bugs: <em>frontend, backend, database, ai, urgent, tech-debt</em>. Helps filter the board — "show me only backend work" or "all urgent items".</p>
</div>

<div class="ag-guide-section">
<h3>Who Can Do What</h3>
<table class="ag-guide-table">
<tr><td><strong>Action</strong></td><td><strong>Tech Lead</strong></td><td><strong>Developers</strong></td><td><strong>QA</strong></td><td><strong>CEO/COO</strong></td></tr>
<tr><td>Create/manage Sprints</td><td>Yes</td><td>No</td><td>No</td><td>No</td></tr>
<tr><td>Create/manage Epics</td><td>Yes</td><td>No</td><td>No</td><td>No</td></tr>
<tr><td>Create Stories & Bugs</td><td>Yes</td><td>Yes</td><td>Yes</td><td>No</td></tr>
<tr><td>Move own items on board</td><td>Yes</td><td>Yes</td><td>Yes</td><td>No</td></tr>
<tr><td>Assign items to people</td><td>Yes</td><td>No</td><td>Yes</td><td>No</td></tr>
<tr><td>View dashboard & velocity</td><td>Yes</td><td>No</td><td>No</td><td>Yes</td></tr>
</table>
</div>

<div class="ag-guide-section">
<h3>Writing Stories with INVEST</h3>
<p>A good story is small enough to estimate, valuable enough to ship, and clear enough to test. Use INVEST as a sanity check before adding any story.</p>
<ul>
<li><strong>Independent</strong> — minimal coupling to other stories. If you need three other things first, your story is too big.</li>
<li><strong>Negotiable</strong> — details can evolve through conversation. The story is a placeholder for a discussion, not a contract.</li>
<li><strong>Valuable</strong> — clear value to a user or the business. If you can't say who benefits, redraft it.</li>
<li><strong>Estimable</strong> — the team can estimate effort. Vague stories ("improve performance") aren't estimable.</li>
<li><strong>Small</strong> — fits inside one sprint comfortably. If story points feel like 13+, split it.</li>
<li><strong>Testable</strong> — has Acceptance Criteria written as Given/When/Then.</li>
</ul>
<p>The "Add Feature" modal shows this checklist as a reminder.</p>
</div>

<div class="ag-guide-section">
<h3>MoSCoW Prioritization</h3>
<p>MoSCoW is for <em>release-level</em> priority — different from the per-item severity (low/medium/high/critical), which speaks to urgency.</p>
<ul>
<li><strong>Must Have</strong> — release fails without this. Defaults to the smallest possible set.</li>
<li><strong>Should Have</strong> — important but not release-blocking. Ship it if there's time.</li>
<li><strong>Could Have</strong> — nice-to-have. First to drop if scope tightens.</li>
<li><strong>Won't Have (this release)</strong> — explicitly out of scope this release. Re-evaluate next planning.</li>
</ul>
<p>Toggle "Group by MoSCoW" in the Backlog to see the buckets. Pair with the per-squad <strong>Definition of Ready</strong> and <strong>Definition of Done</strong> set in the Squads tab.</p>
</div>

<div class="ag-guide-section">
<h3>Sprint Capacity & WIP Limits</h3>
<p>The capacity bar on the Sprint Board sums hour estimates across all subtasks. If a sprint goes over its capacity, the bar turns red — that's a warning, not a hard block.</p>
<p>WIP limits are set per squad in <strong>Squads → Agile Settings</strong>. They cap how many items one person can have in any single board column at once. Cards exceeding the limit get a red top border so the team can rebalance.</p>
</div>
`;

  /* ── Data loading ────────────────────────────────────── */
  async function loadSquads() {
    try { const r = await GET('/squads'); state.squads = r.squads || []; } catch (e) { console.error('loadSquads', e); }
  }

  async function loadSprints() {
    try {
      const q = state.selectedProjectId ? `?project_id=${state.selectedProjectId}` : '';
      const r = await GET('/sprints' + q);
      state.sprints = r.sprints || [];
    } catch (e) { console.error('loadSprints', e); }
  }

  async function loadLabels() {
    try { const r = await GET('/labels'); state.labels = r.labels || []; } catch (e) { console.error('loadLabels', e); }
  }

  async function loadEpics() {
    try {
      const q = state.selectedProjectId ? `?project_id=${state.selectedProjectId}` : '';
      const r = await GET('/epics' + q);
      state.epics = r.epics || [];
    } catch (e) { console.error('loadEpics', e); }
  }

  async function loadStories() {
    try {
      const q = state.selectedProjectId ? `?project_id=${state.selectedProjectId}` : '';
      const r = await GET('/stories' + q);
      state.stories = r.stories || [];
    } catch (e) { console.error('loadStories', e); }
  }

  async function loadBugs() {
    try {
      const q = state.selectedProjectId ? `?project_id=${state.selectedProjectId}` : '';
      const r = await GET('/bugs' + q);
      state.bugs = r.bugs || [];
    } catch (e) { console.error('loadBugs', e); }
  }

  async function loadBoard() {
    if (!state.selectedSprintId) { state.boardData = null; state.boardCapacity = null; state.boardWip = null; return; }
    try {
      const sid = state.selectedSprintId;
      const [boardRes, capRes, wipRes] = await Promise.all([
        GET(`/sprints/${sid}/board`),
        GET(`/sprints/${sid}/capacity`).catch(() => null),
        GET(`/sprints/${sid}/wip-status`).catch(() => null),
      ]);
      state.boardData = boardRes.columns || null;
      state.boardCapacity = capRes?.capacity || null;
      state.boardWip = wipRes?.wip || null;
      render();
    } catch (e) { console.error('loadBoard', e); state.boardData = null; state.boardCapacity = null; state.boardWip = null; }
  }

  async function loadVelocity() {
    try {
      const r = await GET('/agile/velocity');
      state.velocityData = r.data || null;
    } catch (e) { console.error('loadVelocity', e); }
  }

  async function loadTabData() {
    switch (state.tab) {
      case 'board': await loadBoard(); break;
      case 'backlog': await Promise.all([loadStories(), loadBugs()]); render(); break;
      case 'epics': await loadEpics(); render(); break;
      case 'velocity': break; // loaded in renderVelocity
      case 'squads': await loadSquads(); render(); break;
      case 'projects': await loadAllProjects(); render(); break;
    }
  }

  /* ── Init ────────────────────────────────────────────── */
  async function init() {
    if (!agile() || !document.getElementById('agileRoot')) return;

    // Auto-select project if user only has one (before loading data so API filters correctly)
    const projects = agile().projects || [];
    if (projects.length === 1 && !state.selectedProjectId) {
      state.selectedProjectId = projects[0].id;
    }

    // Load core data
    await Promise.all([loadSquads(), loadSprints(), loadLabels(), loadEpics(), loadStories(), loadBugs()]);

    // Auto-select first active sprint
    const active = state.sprints.find(s => s.status === 'active');
    if (active) state.selectedSprintId = active.id;
    else if (state.sprints.length) state.selectedSprintId = state.sprints[0].id;

    render();
    if (state.selectedSprintId) loadBoard();
  }

  // Hook into portal tab switching
  const origObserver = new MutationObserver(() => {
    const root = document.getElementById('agileRoot');
    const view = document.getElementById('agileView');
    if (root && view && !view.classList.contains('hidden') && !root.dataset.loaded) {
      root.dataset.loaded = '1';
      init();
    }
  });

  if (document.getElementById('agileView')) {
    origObserver.observe(document.getElementById('agileView'), { attributes: true, attributeFilter: ['class'] });
  }

  // Also init if agile is the first visible tab
  document.addEventListener('DOMContentLoaded', () => {
    setTimeout(() => {
      const view = document.getElementById('agileView');
      if (view && !view.classList.contains('hidden')) init();
    }, 200);
  });
})();
