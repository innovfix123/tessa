<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI First — Assessment</title>
<style>
  * { box-sizing: border-box; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    background: #f7f7f9;
    color: #111;
    margin: 0;
    padding: 28px 16px 60px 16px;
    min-height: 100vh;
  }
  .wrap { max-width: 780px; margin: 0 auto; }
  .top-link { display: block; text-align: center; margin-bottom: 8px; font-size: 12px; color: #6172f3; text-decoration: none; }
  .top-link:hover { text-decoration: underline; }
  .brand { text-align: center; font-size: 11px; letter-spacing: 4px; color: #888; text-transform: uppercase; }
  h1 { text-align: center; font-size: 30px; margin: 8px 0 6px 0; letter-spacing: -0.5px; }
  .sub { text-align: center; color: #555; margin-bottom: 22px; font-size: 14px; }

  .notice {
    background: #111;
    color: #fff;
    border-radius: 14px;
    padding: 16px 22px;
    margin-bottom: 18px;
    text-align: center;
    font-size: 14px;
    font-weight: 500;
  }
  .notice b { font-weight: 700; }
  .notice .time {
    display: block;
    font-size: 22px;
    font-weight: 800;
    letter-spacing: -0.5px;
    margin-top: 4px;
  }
  .notice .lunch {
    display: block;
    font-size: 11px;
    font-weight: 500;
    letter-spacing: 1.4px;
    text-transform: uppercase;
    color: #aaa;
    margin-top: 8px;
  }

  .progress-card {
    background: #fff;
    border: 1px solid #e6e6ea;
    border-radius: 14px;
    padding: 18px 22px;
    margin-bottom: 16px;
    text-align: center;
  }
  .progress-card .num { font-size: 34px; font-weight: 800; letter-spacing: -1px; }
  .progress-card .num small { font-size: 14px; color: #888; font-weight: 500; }
  .progress-card .num .cleared { color: #027a48; }
  .progress-card .lbl { font-size: 11px; letter-spacing: 2px; color: #888; text-transform: uppercase; margin-top: 2px; }
  .progress-bar { background: #f0f0f3; height: 8px; border-radius: 999px; margin-top: 12px; overflow: hidden; }
  .progress-bar > div { background: #027a48; height: 100%; transition: width 0.4s ease; }

  .filter-row {
    display: flex; gap: 6px; flex-wrap: wrap;
    margin-bottom: 8px; align-items: center;
  }
  .filter-row.assessor-row { margin-bottom: 18px; }
  .filter-row .lead {
    font-size: 10px; letter-spacing: 1.4px; color: #888;
    text-transform: uppercase; font-weight: 700;
    margin-right: 4px;
  }
  .filter-pill {
    padding: 7px 13px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    background: #fff;
    border: 1px solid #e6e6ea;
    color: #444;
    cursor: pointer;
    user-select: none;
    white-space: nowrap;
    transition: all 0.12s ease;
  }
  .filter-pill:hover { border-color: #ccc; }
  .filter-pill.active { background: #111; color: #fff; border-color: #111; }
  .filter-pill .count { color: #999; font-weight: 500; margin-left: 4px; font-size: 11px; }
  .filter-pill.active .count { color: #aaa; }

  .person-card {
    background: #fff;
    border: 1px solid #e6e6ea;
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 8px;
    transition: all 0.15s ease;
    display: flex; align-items: center; gap: 12px;
    flex-wrap: wrap;
  }
  .person-card.passed {
    background: #f6fbf7;
    border-color: #c7e8d2;
  }
  .person-card.hide { display: none !important; }

  .time-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 11px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.2px;
    background: #111;
    color: #fff;
    white-space: nowrap;
  }
  .time-pill .clock {
    width: 6px; height: 6px;
    background: #4ade80;
    border-radius: 999px;
    display: inline-block;
    font-size: 0;
  }

  .search-row {
    position: relative;
    margin-bottom: 8px;
  }
  .search-row input[type="search"] {
    width: 100%;
    padding: 11px 14px 11px 36px;
    border: 1px solid #e6e6ea;
    border-radius: 10px;
    background: #fff;
    font-size: 14px;
    color: #111;
    -webkit-appearance: none;
    appearance: none;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
  }
  .search-row input[type="search"]:focus {
    outline: none;
    border-color: #111;
    box-shadow: 0 0 0 3px rgba(17, 17, 17, 0.08);
  }
  .search-row input[type="search"]::-webkit-search-cancel-button {
    -webkit-appearance: none;
    appearance: none;
    height: 14px; width: 14px;
    background: #e6e6ea; border-radius: 999px;
    cursor: pointer;
  }
  .search-row .search-icon {
    position: absolute;
    top: 50%;
    left: 12px;
    transform: translateY(-50%);
    font-size: 18px;
    color: #999;
    pointer-events: none;
  }
  .person-name { font-size: 16px; font-weight: 700; flex: 1; min-width: 140px; }
  .status-pill {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.4px;
  }
  .status-cleared { background: #d1fadf; color: #027a48; }
  .status-pending { background: #fef0c7; color: #93370d; }

  .assessor-pill {
    display: inline-block;
    padding: 4px 11px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
    background: #f4f3ff;
    color: #5925dc;
    border: 1px solid #e9e5ff;
  }
  .assessor-pill.unassigned {
    background: #f7f7f8;
    color: #888;
    border-color: #e6e6ea;
    font-style: italic;
  }
  .assessor-pill .lbl {
    font-size: 9px; color: #8a7bd6; letter-spacing: 1px; text-transform: uppercase;
    font-weight: 700; margin-right: 4px;
  }
  .assessor-pill.unassigned .lbl { color: #aaa; }
  .assessor-pill .assessor-role { font-weight: 500; color: #7c6fc7; }

  .person-role {
    flex-basis: 100%;
    font-size: 12px;
    color: #888;
    margin-top: -4px;
  }

  .meta-row {
    flex-basis: 100%;
    font-size: 12px; color: #777;
    margin-top: 4px;
  }
  .meta-row .marked-by { color: #027a48; font-weight: 600; }
  .notes-block {
    flex-basis: 100%;
    margin-top: 8px;
    padding: 10px 14px;
    background: #f7f7f8;
    border-left: 3px solid #c7e8d2;
    border-radius: 4px;
    font-size: 13px;
    color: #333;
    white-space: pre-wrap;
    line-height: 1.5;
  }
  .notes-block .lbl {
    font-size: 10px; letter-spacing: 1px; color: #888; text-transform: uppercase; font-weight: 600;
    display: block; margin-bottom: 2px;
  }

  /* ── Questions accordion ── */
  details.q-detail {
    flex-basis: 100%;
    margin-top: 10px;
    border-top: 1px solid #f1f1f3;
    padding-top: 10px;
  }
  .person-card.passed details.q-detail { border-top-color: #dff0e4; }
  details.q-detail summary {
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    color: #6172f3;
    user-select: none;
    list-style: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }
  details.q-detail summary::-webkit-details-marker { display: none; }
  details.q-detail summary::before {
    content: '▸';
    font-size: 11px;
    transition: transform 0.15s ease;
  }
  details.q-detail[open] summary::before { transform: rotate(90deg); }
  details.q-detail summary:hover { text-decoration: underline; }

  .q-body { margin-top: 10px; }
  .q-section-title {
    font-size: 10px;
    letter-spacing: 1.8px;
    color: #888;
    text-transform: uppercase;
    font-weight: 700;
    margin: 12px 0 6px 0;
    padding-bottom: 4px;
    border-bottom: 1px solid #f1f1f3;
  }
  .q-section-title:first-child { margin-top: 0; }
  .q-row {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 6px 0;
    font-size: 13px;
    line-height: 1.5;
    color: #222;
  }
  .q-num {
    color: #aaa;
    font-size: 11px;
    font-weight: 700;
    min-width: 16px;
    padding-top: 2px;
    text-align: right;
    flex-shrink: 0;
  }
  .q-tool {
    display: inline-block;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.6px;
    text-transform: uppercase;
    padding: 2px 8px;
    border-radius: 999px;
    white-space: nowrap;
    flex-shrink: 0;
    margin-top: 2px;
  }
  .q-text { flex: 1; }

  .t-tessa      { background: #dbeafe; color: #1e40af; }
  .t-slack      { background: #f3e8ff; color: #6927bf; }
  .t-gmail      { background: #fef2f2; color: #b42318; }
  .t-calendar   { background: #fef0c7; color: #93370d; }
  .t-drive      { background: #d1fae5; color: #047857; }
  .t-hima       { background: #fce7f3; color: #be185d; }
  .t-onlycare   { background: #cffafe; color: #0e7490; }
  .t-meta_ads   { background: #e0e7ff; color: #3730a3; }
  .t-google_ads { background: #ffedd5; color: #c2410c; }
  .t-analyst_db { background: #ccfbf1; color: #0f766e; }
  .t-code       { background: #e2e8f0; color: #334155; }
  .t-claude     { background: #ede9fe; color: #5925dc; }
  .t-safety     { background: #fef9c3; color: #854d0e; }

  .footer { text-align: center; font-size: 10px; color: #aaa; margin-top: 24px; letter-spacing: 1.5px; }

  /* ── Tablet ── */
  @media (max-width: 880px) {
    .wrap { max-width: 100%; }
  }

  /* ── Phone ── */
  @media (max-width: 720px) {
    body { padding: 16px 12px 48px 12px; }
    .top-link { font-size: 11px; }
    .brand { font-size: 10px; letter-spacing: 3px; }
    h1 { font-size: 22px; margin: 6px 0 4px 0; }
    .sub { font-size: 12px; margin-bottom: 14px; }

    .notice { padding: 12px 16px; font-size: 12px; margin-bottom: 12px; }
    .notice .time { font-size: 18px; }

    .progress-card { padding: 14px 16px; margin-bottom: 12px; }
    .progress-card .num { font-size: 26px; }
    .progress-card .num small { font-size: 12px; }
    .progress-card .lbl { font-size: 10px; letter-spacing: 1.4px; }

    /* Filter pills become a single horizontal-scroll row each */
    .filter-row {
      flex-wrap: nowrap;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      scrollbar-width: none;
      padding-bottom: 4px;
      margin-bottom: 6px;
    }
    .filter-row::-webkit-scrollbar { display: none; }
    .filter-row.assessor-row { margin-bottom: 12px; }
    .filter-pill {
      flex-shrink: 0;
      padding: 6px 12px;
      font-size: 12px;
    }
    .filter-row .lead {
      flex-shrink: 0;
      font-size: 10px;
      padding-right: 4px;
    }

    /* Card surface — denser padding, bigger touch targets */
    .person-card { padding: 12px 14px; }
    .person-name {
      font-size: 16px;
      flex: 1 1 100%;
      min-width: 0;
      margin-bottom: 2px;
    }
    .assessor-pill {
      font-size: 10px;
      padding: 3px 9px;
    }
    /* Keep the "Assessor" label visible on mobile too so it is always clear
       who is assessing — the pill wraps onto its own line if needed. */
    .assessor-pill { flex-basis: 100%; }
    .status-pill { font-size: 10px; padding: 3px 8px; }
    .person-role { font-size: 11px; margin-top: 2px; }
    .time-pill { font-size: 10px; padding: 3px 9px; }
    .time-pill .clock { width: 5px; height: 5px; }
    .notice .lunch { font-size: 10px; letter-spacing: 1.2px; margin-top: 6px; }
    .search-row input[type="search"] { padding: 10px 12px 10px 34px; font-size: 14px; }
    .search-row .search-icon { font-size: 16px; left: 10px; }

    /* Questions accordion — bigger tap target, denser text */
    details.q-detail { margin-top: 8px; padding-top: 10px; }
    details.q-detail summary {
      font-size: 13px;
      padding: 4px 0;
    }

    .q-section-title {
      font-size: 9px;
      letter-spacing: 1.4px;
      margin: 10px 0 4px 0;
    }

    /* Stack the tool pill above the text on narrow screens — gives the
       text the full row width so 8-9 word prompts read in 2 lines max. */
    .q-row {
      flex-wrap: wrap;
      gap: 4px 6px;
      padding: 8px 0;
      font-size: 13.5px;
      line-height: 1.45;
    }
    .q-num {
      font-size: 10px;
      min-width: 14px;
      padding-top: 4px;
    }
    .q-tool {
      font-size: 9px;
      padding: 2px 7px;
      margin-top: 3px;
    }
    .q-text {
      flex-basis: calc(100% - 24px);
      margin-left: 22px;
      margin-top: -2px;
    }

    .meta-row { font-size: 11px; }
    .notes-block { font-size: 12px; padding: 8px 12px; }
  }

  /* ── Very small phones ── */
  @media (max-width: 380px) {
    body { padding: 14px 10px 48px 10px; }
    .person-card { padding: 11px 12px; }
    .progress-card .num { font-size: 22px; }
    .notice .time { font-size: 16px; }
    h1 { font-size: 20px; }
  }
</style>
</head>
<body>
<div class="wrap">

  <a class="top-link" href="{{ route('ai-first.index') }}">← Back to AI First dashboard</a>
  <div class="brand">InnovFix</div>
  <h1>AI First — Assessment</h1>
  <div class="sub">1-on-1 assessment from the Q-bank · view only</div>

  <div class="notice">
    Assessment begins
    <span class="time">11:00 AM IST</span>
    <span class="lunch">Lunch break · 1:30 – 2:30 PM</span>
  </div>

  <div class="progress-card">
    <div class="num"><span class="cleared" id="pass-count">{{ $passed }}</span> <small>/ {{ $total }} cleared</small></div>
    <div class="lbl">{{ $passed === $total && $total > 0 ? 'All done!' : ($total - $passed) . ' still to be assessed' }}</div>
    <div class="progress-bar"><div style="width: {{ $total > 0 ? round($passed * 100 / $total) : 0 }}%"></div></div>
  </div>

  <div class="search-row">
    <input type="search" id="aif-search" placeholder="Search by name…" autocomplete="off" />
    <span class="search-icon" aria-hidden="true">⌕</span>
  </div>

  <div class="filter-row">
    <span class="filter-pill active" data-filter="all">All <span class="count">{{ $mentees->count() }}</span></span>
    <span class="filter-pill" data-filter="passed">Cleared <span class="count">{{ $mentees->whereNotNull('exam_passed_at')->count() }}</span></span>
    <span class="filter-pill" data-filter="not-passed">Pending <span class="count">{{ $mentees->whereNull('exam_passed_at')->count() }}</span></span>
  </div>
  <div class="filter-row assessor-row">
    <span class="lead">Assessor</span>
    @foreach ($conductors as $conductor)
      @php $cCount = $perConductor[$conductor] ?? 0; @endphp
      @if ($cCount > 0)
        <span class="filter-pill" data-filter="conductor-{{ $conductor }}">{{ $conductor }} <span class="count">{{ $cCount }}</span></span>
      @endif
    @endforeach
    @if ($unassignedCount > 0)
      <span class="filter-pill" data-filter="conductor-__unassigned__">Unassigned <span class="count">{{ $unassignedCount }}</span></span>
    @endif
  </div>

  @php
    $toolLabels = [
      'tessa'      => 'Tessa',
      'slack'      => 'Slack',
      'gmail'      => 'Gmail',
      'calendar'   => 'Calendar',
      'drive'      => 'Drive',
      'hima'       => 'Hima',
      'onlycare'   => 'Only Care',
      'meta_ads'   => 'Meta Ads',
      'google_ads' => 'Google Ads',
      'analyst_db' => 'Analyst DB',
      'code'       => 'Claude Code',
      'claude'     => 'Claude',
      'safety'     => 'Safety',
    ];
  @endphp

  <div id="mentee-list">
    @foreach ($mentees as $m)
      <div class="person-card {{ $m->isExamPassed() ? 'passed' : '' }}"
           data-conductor="conductor-{{ $m->assigned_conductor ?? '__unassigned__' }}"
           data-passed="{{ $m->isExamPassed() ? '1' : '0' }}">
        <span class="person-name">{{ $m->name }}</span>
        @if ($m->assigned_conductor)
          <span class="assessor-pill" title="Will be assessed by {{ $m->assigned_conductor }}"><span class="lbl">Assessor</span>{{ $m->assigned_conductor }}</span>
        @else
          <span class="assessor-pill unassigned"><span class="lbl">Assessor</span>unassigned</span>
        @endif
        @if (isset($slots[$m->id]))
          <span class="time-pill" title="Scheduled slot — {{ $slots[$m->id]['start'] }} to {{ $slots[$m->id]['end'] }}">
            <span class="clock" aria-hidden="true">●</span>
            {{ $slots[$m->id]['start'] }} – {{ $slots[$m->id]['end'] }}
          </span>
        @endif
        @if ($m->isExamPassed())
          <span class="status-pill status-cleared">✓ CLEARED</span>
        @else
          <span class="status-pill status-pending">Pending</span>
        @endif
        @php $qs = $questionSets[$m->name] ?? null; @endphp
        @if ($qs && ! empty($qs['role']))
          <div class="person-role">{{ $qs['role'] }}</div>
        @endif
        @if ($m->isExamPassed())
          <div class="meta-row">
            @if ($m->exam_marked_by)
              Cleared by <span class="marked-by">{{ $m->exam_marked_by }}</span> on
            @else
              Cleared on
            @endif
            <span>{{ $m->exam_passed_at->setTimezone('Asia/Kolkata')->format('d M Y, h:i A') }}</span>
          </div>
          @if ($m->exam_notes)
            <div class="notes-block">
              <span class="lbl">Notes</span>{{ $m->exam_notes }}
            </div>
          @endif
        @endif
        @if ($qs)
          @php $qTotal = collect($qs['sections'])->sum(fn ($s) => count($s['prompts'])); @endphp
          <details class="q-detail">
            <summary>View Questions ({{ $qTotal }})</summary>
            <div class="q-body">
              @php $qNum = 1; @endphp
              @foreach ($qs['sections'] as $section)
                @foreach ($section['prompts'] as $prompt)
                  <div class="q-row">
                    <span class="q-num">{{ $qNum }}</span>
                    <span class="q-tool t-{{ $prompt['tool'] }}">{{ $toolLabels[$prompt['tool']] ?? ucfirst($prompt['tool']) }}</span>
                    <span class="q-text">{{ $prompt['text'] }}</span>
                  </div>
                  @php $qNum++; @endphp
                @endforeach
              @endforeach
            </div>
          </details>
        @endif
      </div>
    @endforeach
  </div>

  <div class="footer">LEARN · ADAPT · IMPLEMENT</div>
</div>

<script>
(function () {
  document.querySelectorAll('.filter-pill').forEach((pill) => {
    pill.addEventListener('click', () => {
      // Status filters (all/passed/not-passed) belong to first row only;
      // assessor filters belong to second row. We allow both rows to have
      // an active pill simultaneously — combine them with AND.
      const isAssessor = pill.dataset.filter.startsWith('conductor-');
      const siblings = isAssessor
        ? document.querySelectorAll('.filter-row.assessor-row .filter-pill')
        : document.querySelectorAll('.filter-row:not(.assessor-row) .filter-pill');
      siblings.forEach((p) => p.classList.remove('active'));
      pill.classList.add('active');
      applyFilters();
    });
  });

  // Combines status pill + assessor pill + free-text search box with AND.
  const searchEl = document.getElementById('aif-search');
  function applyFilters() {
    const statusActive   = document.querySelector('.filter-row:not(.assessor-row) .filter-pill.active');
    const assessorActive = document.querySelector('.filter-row.assessor-row .filter-pill.active');
    const sFilter = statusActive   ? statusActive.dataset.filter   : 'all';
    const aFilter = assessorActive ? assessorActive.dataset.filter : null;
    const query   = (searchEl?.value || '').trim().toLowerCase();

    document.querySelectorAll('.person-card').forEach((card) => {
      let show = true;
      if (sFilter === 'passed')          show = show && card.dataset.passed === '1';
      else if (sFilter === 'not-passed') show = show && card.dataset.passed === '0';
      if (aFilter)                       show = show && card.dataset.conductor === aFilter;
      if (query) {
        const name = card.querySelector('.person-name')?.textContent.toLowerCase() ?? '';
        show = show && name.includes(query);
      }
      card.style.display = show ? '' : 'none';
    });
  }

  if (searchEl) {
    searchEl.addEventListener('input', applyFilters);
  }
})();
</script>
</body>
</html>
