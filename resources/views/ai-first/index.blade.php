<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>AI First — Claude Subscription</title>
<style>
  * { box-sizing: border-box; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    background: #f7f7f9;
    color: #111;
    margin: 0;
    padding: 24px 16px 60px 16px;
    min-height: 100vh;
  }
  .wrap { max-width: 1080px; margin: 0 auto; }
  .brand { text-align: center; font-size: 11px; letter-spacing: 4px; color: #888; text-transform: uppercase; }
  h1 { text-align: center; font-size: 30px; margin: 8px 0 4px 0; letter-spacing: -0.5px; }
  .sub { text-align: center; color: #555; margin-bottom: 8px; font-size: 14px; }
  .cta { text-align: center; color: #555; font-size: 13px; margin: 0 auto 22px auto; max-width: 560px; }

  .stats-row {
    display: flex; gap: 10px; flex-wrap: wrap;
    margin-bottom: 16px;
  }
  .stat {
    flex: 1; min-width: 160px;
    background: #fff;
    border: 1px solid #e6e6ea;
    border-radius: 10px;
    padding: 14px 16px;
  }
  .stat .lbl { font-size: 10px; letter-spacing: 1.5px; color: #888; text-transform: uppercase; }
  .stat .val { font-size: 24px; font-weight: 800; margin-top: 4px; letter-spacing: -0.5px; }
  .stat .val small { font-size: 13px; color: #888; font-weight: 500; }
  .stat .bar { background: #f0f0f3; height: 6px; border-radius: 999px; margin-top: 8px; overflow: hidden; }
  .stat .bar > div { background: #027a48; height: 100%; transition: width 0.4s ease; }

  .filters {
    display: flex; gap: 8px; flex-wrap: wrap;
    margin-bottom: 12px;
    align-items: center;
  }
  .filter-pill {
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    background: #fff;
    border: 1px solid #e6e6ea;
    color: #555;
    cursor: pointer;
    user-select: none;
    transition: all 0.12s ease;
  }
  .filter-pill:hover { border-color: #ccc; }
  .filter-pill.active { background: #111; color: #fff; border-color: #111; }
  .filter-pill .count { color: #999; font-weight: 500; margin-left: 6px; font-size: 11px; }
  .filter-pill.active .count { color: #aaa; }

  .table-wrap {
    background: #fff;
    border: 1px solid #e6e6ea;
    border-radius: 12px;
    overflow: hidden;
  }
  table.aif {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
  }
  table.aif thead {
    background: #fafafb;
    position: sticky; top: 0; z-index: 5;
  }
  table.aif th {
    text-align: left;
    padding: 12px 14px;
    font-size: 11px;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: #888;
    border-bottom: 1px solid #e6e6ea;
    font-weight: 600;
  }
  table.aif th.col-num   { width: 38px; text-align: center; }
  table.aif th.col-squad { width: 90px; }
  table.aif th.col-role  { width: 110px; }
  table.aif th.col-tool  { width: 110px; text-align: center; }
  table.aif th.col-when  { width: 130px; }

  table.aif td {
    padding: 9px 14px;
    border-top: 1px solid #f1f1f3;
    vertical-align: middle;
  }
  table.aif tbody tr:hover { background: #fafafb; }
  table.aif tbody tr.row-done { background: #f6fbf7; }
  table.aif tbody tr.row-done:hover { background: #eef8f1; }

  td.col-num   { text-align: center; color: #999; font-size: 12px; font-variant-numeric: tabular-nums; }
  td.col-name  { font-weight: 600; }
  td.col-when  { color: #777; font-size: 12px; font-variant-numeric: tabular-nums; }

  .squad-pill {
    display: inline-block;
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.5px;
  }
  .squad-1 { background: #ede9fe; color: #5925dc; }
  .squad-2 { background: #dbeafe; color: #1e40af; }
  .squad-3 { background: #d1fae5; color: #047857; }
  .squad-4 { background: #fee4e2; color: #b42318; }

  .role-badge {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: #888;
  }
  .role-badge.role-mentor    { color: #5925dc; }
  .role-badge.role-associate { color: #1570ef; }
  .role-badge.role-mentee    { color: #777; }

  .col-tool { text-align: center; }
  .check-cell { display: inline-flex; align-items: center; justify-content: center; cursor: pointer; padding: 4px; }
  .check-box {
    width: 22px; height: 22px;
    border: 2px solid #c8c8cf;
    border-radius: 6px;
    background: #fff;
    display: flex; align-items: center; justify-content: center;
    transition: all 0.12s ease;
  }
  .check-cell:hover .check-box { border-color: #888; }
  .check-cell.checked .check-box { background: #027a48; border-color: #027a48; }
  .check-box svg { width: 14px; height: 14px; color: #fff; opacity: 0; }
  .check-cell.checked .check-box svg { opacity: 1; }
  .check-cell.saving { opacity: 0.5; pointer-events: none; }

  .conductor-badge {
    display: inline-block;
    font-size: 9px;
    letter-spacing: 1.2px;
    font-weight: 700;
    padding: 4px 8px;
    border-radius: 999px;
    background: #fef0c7;
    color: #93370d;
    border: 1px solid #fde68a;
  }

  .toast {
    position: fixed;
    bottom: 24px; left: 50%; transform: translateX(-50%) translateY(20px);
    background: #111; color: #fff;
    padding: 10px 18px; border-radius: 999px;
    font-size: 13px;
    opacity: 0;
    transition: all 0.25s ease;
    pointer-events: none;
    z-index: 100;
  }
  .toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

  .footer { text-align: center; font-size: 10px; color: #aaa; margin-top: 20px; letter-spacing: 1.5px; }

  /* Tablet (between phone-cards and full-desktop-table) */
  @media (max-width: 1000px) and (min-width: 769px) {
    .wrap { max-width: 100%; }
    h1 { font-size: 26px; }
    table.aif th.col-num, table.aif td.col-num { display: none; }
  }

  /* Phone — bumped from 600 to 768 so larger phones / phablets / Samsung
     Internet (which sometimes uses a wider viewport) all get the card layout. */
  @media (max-width: 768px) {
    body { padding: 18px 12px 60px 12px; }
    h1 { font-size: 24px; }
    .sub { font-size: 13px; }
    .cta { font-size: 12px; padding: 0 4px; }

    .stats-row { gap: 8px; margin-bottom: 14px; }
    .stat { padding: 10px 12px; min-width: 130px; }
    .stat .lbl { font-size: 9px; letter-spacing: 1px; }
    .stat .val { font-size: 20px; }

    .filters {
      overflow-x: auto;
      flex-wrap: nowrap;
      padding-bottom: 6px;
      -webkit-overflow-scrolling: touch;
      scrollbar-width: none;
      margin-bottom: 14px;
    }
    .filters::-webkit-scrollbar { display: none; }
    .filter-pill { white-space: nowrap; flex-shrink: 0; padding: 7px 12px; }

    /* Convert table rows into cards */
    .table-wrap { background: transparent; border: none; border-radius: 0; overflow: visible; }
    table.aif, table.aif tbody { display: block; width: 100%; }
    table.aif thead { display: none; }
    table.aif tr {
      display: block;
      background: #fff;
      border: 1px solid #e6e6ea;
      border-radius: 10px;
      margin-bottom: 10px;
      padding: 12px 14px;
    }
    table.aif tr.row-done { background: #f6fbf7; border-color: #c7e8d2; }
    table.aif td {
      display: block;
      width: 100%;
      border: 0;
      padding: 0;
    }

    table.aif td.col-num { display: none; }

    table.aif td.col-name {
      font-size: 16px;
      font-weight: 700;
      margin-bottom: 6px;
    }

    table.aif td.col-squad, table.aif td.col-role {
      display: inline-block;
      width: auto;
      margin-right: 8px;
      margin-bottom: 0;
      padding: 0;
      vertical-align: middle;
    }

    table.aif td.col-when {
      font-size: 11px;
      color: #888;
      margin-top: 4px;
      padding: 0;
      text-align: left;
    }

    /* Each tool column becomes its own labeled row with checkbox on the right */
    table.aif td.col-tool {
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-top: 1px solid #f1f1f3;
      margin-top: 10px;
      padding-top: 10px;
      text-align: left;
      width: 100%;
    }
    table.aif td.col-tool::before {
      content: attr(data-label);
      font-size: 13px;
      color: #333;
      font-weight: 500;
    }
    table.aif td.col-tool .check-cell { padding: 0; }
  }
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">InnovFix</div>
  <h1>AI First</h1>
  <div class="sub">Claude subscription tracker</div>
  <div class="cta">Find your name and tick the box once you've <b>subscribed to a Claude plan</b> (Pro / Max / Team). The board is shared — everyone sees the live status.</div>
  <div style="text-align: center; margin-bottom: 18px;">
    <a href="{{ route('ai-first.assessment') }}" style="display: inline-block; background: #111; color: #fff; padding: 10px 22px; border-radius: 999px; font-size: 13px; font-weight: 600; text-decoration: none; letter-spacing: 0.4px;">
      View Assessment Status →
    </a>
  </div>

  <div class="stats-row">
    <div class="stat">
      <div class="lbl">People in AI First</div>
      <div class="val">{{ $stats['people'] }}</div>
    </div>
    @foreach ($columns as $col)
      <div class="stat">
        <div class="lbl">{{ $col['label'] }}</div>
        <div class="val">
          <span data-stat-col="{{ $col['key'] }}-done">{{ $stats['per_column'][$col['key']]['done'] }}</span>
          <small>/ <span data-stat-col="{{ $col['key'] }}-total">{{ $stats['per_column'][$col['key']]['total'] }}</span></small>
        </div>
        <div class="bar"><div data-stat-col="{{ $col['key'] }}-bar" style="width: {{ $stats['per_column'][$col['key']]['pct'] }}%"></div></div>
      </div>
    @endforeach
  </div>

  <div class="filters">
    <div class="filter-pill active" data-filter="all">All <span class="count">{{ $participants->count() }}</span></div>
    @foreach ($squadMentors as $num => $mentor)
      <div class="filter-pill" data-filter="squad-{{ $num }}">
        Squad {{ $num }} · {{ $mentor }} <span class="count">{{ $participants->where('squad_num', $num)->count() }}</span>
      </div>
    @endforeach
  </div>

  <div class="table-wrap">
    <table class="aif">
      <thead>
        <tr>
          <th class="col-num">#</th>
          <th class="col-name">Name</th>
          <th class="col-squad">Squad</th>
          <th class="col-role">Role</th>
          @foreach ($columns as $col)
            <th class="col-tool" title="{{ $col['hint'] }}">{{ $col['label'] }}</th>
          @endforeach
          <th class="col-when">Last Update</th>
        </tr>
      </thead>
      <tbody id="aif-tbody">
        @foreach ($participants as $p)
          @php
            $allActivated = collect($columns)->every(fn ($c) => $p->{$c['field']} !== null);
            $latestUpdate = collect($columns)
              ->map(fn ($c) => $p->{$c['field']})
              ->filter()
              ->sortDesc()
              ->first();
          @endphp
          <tr data-id="{{ $p->id }}" data-squad="squad-{{ $p->squad_num }}" class="{{ $allActivated ? 'row-done' : '' }}">
            <td class="col-num">{{ $loop->iteration }}</td>
            <td class="col-name">{{ $p->name }}</td>
            <td class="col-squad"><span class="squad-pill squad-{{ $p->squad_num }}">Sq {{ $p->squad_num }}</span></td>
            <td class="col-role"><span class="role-badge role-{{ $p->role_in_squad }}">{{ strtoupper($p->role_in_squad) }}</span></td>
            @foreach ($columns as $col)
              <td class="col-tool" data-label="{{ $col['label'] }}">
                @if (! empty($col['exempt_for_conductors']) && $p->is_exam_conductor)
                  <span class="conductor-badge" title="This person is one of the 8 exam conductors and is exempt from taking the exam">CONDUCTOR</span>
                @else
                  <span class="check-cell {{ $p->{$col['field']} !== null ? 'checked' : '' }}"
                        data-col="{{ $col['key'] }}"
                        title="{{ $col['hint'] }}">
                    <span class="check-box">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    </span>
                  </span>
                @endif
              </td>
            @endforeach
            <td class="col-when" data-when>{{ $latestUpdate ? $latestUpdate->setTimezone('Asia/Kolkata')->format('d M, h:i A') : '—' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  <div class="footer">LEARN · ADAPT · IMPLEMENT</div>
</div>

<div class="toast" id="toast"></div>

<script>
(function () {
  const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  const toast = document.getElementById('toast');
  let toastTimer;

  function showToast(msg) {
    toast.textContent = msg;
    toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('show'), 1800);
  }

  // Filter by squad pill
  document.querySelectorAll('.filter-pill').forEach((pill) => {
    pill.addEventListener('click', () => {
      document.querySelectorAll('.filter-pill').forEach((p) => p.classList.remove('active'));
      pill.classList.add('active');
      const f = pill.dataset.filter;
      document.querySelectorAll('#aif-tbody tr').forEach((row) => {
        row.style.display = (f === 'all' || row.dataset.squad === f) ? '' : 'none';
      });
    });
  });

  // Click any checkbox to toggle that column for that person
  document.querySelectorAll('.check-cell').forEach((cell) => {
    cell.addEventListener('click', async (e) => {
      e.stopPropagation();
      if (cell.classList.contains('saving')) return;
      const row = cell.closest('tr');
      const id = row.dataset.id;
      const col = cell.dataset.col;
      const newState = !cell.classList.contains('checked');
      cell.classList.add('saving');

      try {
        const res = await fetch(`/ai-first/${id}/toggle`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'Accept': 'application/json',
          },
          credentials: 'same-origin',
          body: JSON.stringify({ column: col, activated: newState }),
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();

        cell.classList.toggle('checked', data.activated);
        row.querySelector('[data-when]').textContent = data.activated_at || '—';

        // Row-level "all done" highlight: only if every checkbox in the row is checked
        const allChecked = row.querySelectorAll('.check-cell.checked').length
                       === row.querySelectorAll('.check-cell').length;
        row.classList.toggle('row-done', allChecked);

        // Update the header stat for this column
        const doneEl  = document.querySelector(`[data-stat-col="${col}-done"]`);
        const totalEl = document.querySelector(`[data-stat-col="${col}-total"]`);
        const barEl   = document.querySelector(`[data-stat-col="${col}-bar"]`);
        const colStat = data.stats.per_column[col];
        if (doneEl)  doneEl.textContent  = colStat.done;
        if (totalEl) totalEl.textContent = colStat.total;
        if (barEl)   barEl.style.width   = (colStat.total > 0 ? Math.round(colStat.done * 100 / colStat.total) : 0) + '%';

        const name = row.querySelector('.col-name').textContent.trim();
        const colLabel = cell.title || col;
        showToast(data.activated ? `${name} — ${colLabel} ✓` : `${name} — ${colLabel} cleared`);
      } catch (e) {
        showToast('Could not save — try again');
      } finally {
        cell.classList.remove('saving');
      }
    });
  });
})();
</script>
</body>
</html>
