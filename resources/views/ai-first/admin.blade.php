@extends('layouts.app')

@section('title', 'AI First — Admin')

@section('content')
<style>
  .aif { max-width: 1200px; margin: 0 auto; padding: 24px 20px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #111; }
  .aif h1 { font-size: 28px; margin: 0 0 4px 0; }
  .aif .sub { color: #666; margin-bottom: 20px; }
  .stat-row { display: flex; gap: 12px; margin-bottom: 24px; flex-wrap: wrap; }
  .stat-card { flex: 1; min-width: 160px; background: #fff; border: 1px solid #e3e3e6; border-radius: 10px; padding: 14px 16px; }
  .stat-card .lbl { font-size: 11px; letter-spacing: 1.5px; color: #888; text-transform: uppercase; }
  .stat-card .val { font-size: 24px; font-weight: 700; margin-top: 4px; }
  .stat-card .pct { font-size: 12px; color: #666; margin-top: 2px; }

  .squads-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 16px; }
  .squad-card { background: #fff; border: 1px solid #e3e3e6; border-radius: 10px; overflow: hidden; }
  .squad-head { padding: 12px 16px; border-bottom: 1px solid #f0f0f3; display: flex; justify-content: space-between; align-items: center; }
  .squad-head .title { font-size: 15px; font-weight: 700; }
  .squad-head .pct-pill { font-size: 12px; padding: 4px 10px; border-radius: 999px; font-weight: 600; }
  .pct-on  { background: #d1fadf; color: #027a48; }
  .pct-mid { background: #fef0c7; color: #93370d; }
  .pct-off { background: #fee4e2; color: #b42318; }
  .squad-body table { width: 100%; border-collapse: collapse; font-size: 14px; }
  .squad-body td { padding: 8px 12px; border-top: 1px solid #f5f5f7; }
  .squad-body tr:first-child td { border-top: 0; }
  .role-badge { font-size: 10px; letter-spacing: 1.4px; color: #888; text-transform: uppercase; margin-right: 8px; min-width: 64px; display: inline-block; }
  .role-mentor { color: #5925dc; font-weight: 700; }
  .role-associate { color: #1570ef; font-weight: 700; }
  .check { font-size: 16px; }
  .check.on { color: #027a48; }
  .check.off { color: #b42318; opacity: 0.55; }
  .row-action { font-size: 11px; color: #6172f3; cursor: pointer; }
  .row-action:hover { text-decoration: underline; }
  .copy-input { font-size: 11px; color: #555; background: #f7f7f8; border: 1px solid #e3e3e6; padding: 2px 6px; border-radius: 4px; width: 220px; max-width: 100%; }

  details summary { cursor: pointer; font-size: 13px; color: #555; padding: 6px 0; }
  details summary::marker { color: #999; }
  .move-form { display: flex; gap: 6px; align-items: center; margin-top: 6px; }
  .move-form select { font-size: 12px; padding: 4px 6px; }
  .move-form button { font-size: 12px; padding: 5px 10px; background: #111; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
  .saved { background: #d1fadf; color: #027a48; border: 1px solid #6ce9a6; border-radius: 8px; padding: 10px 14px; font-size: 14px; margin-bottom: 16px; }
  .err { background: #fee4e2; color: #b42318; border: 1px solid #fda29b; border-radius: 8px; padding: 10px 14px; font-size: 14px; margin-bottom: 16px; }
</style>

<div class="aif">
  <h1>AI First — Admin</h1>
  <div class="sub">Track Claude activation across all 4 squads. People self-update on the <a href="{{ route('ai-first.index') }}" target="_blank">public page</a>. Use the Move action below to shuffle squads.</div>

  @if (session('saved'))
    <div class="saved">{{ session('saved') }}</div>
  @endif
  @error('move')
    <div class="err">{{ $message }}</div>
  @enderror

  <div class="stat-row">
    <div class="stat-card">
      <div class="lbl">People in AI First</div>
      <div class="val">{{ $totals['people'] }}</div>
    </div>
    <div class="stat-card">
      <div class="lbl">Claude Activated</div>
      <div class="val">{{ $totals['activated'] }}</div>
      <div class="pct">{{ $totals['people'] > 0 ? round($totals['activated'] * 100 / $totals['people']) : 0 }}% of team</div>
    </div>
    @foreach ($bySquad as $num => $s)
      <div class="stat-card">
        <div class="lbl">Squad {{ $num }} · {{ $s['mentor'] }}</div>
        <div class="val">{{ $s['done'] }} / {{ $s['total'] }}</div>
        <div class="pct">{{ $s['pct'] }}% activated</div>
      </div>
    @endforeach
  </div>

  <div class="squads-grid">
    @foreach ($participants as $squadNum => $rows)
      <div class="squad-card">
        <div class="squad-head">
          <span class="title">Squad {{ $squadNum }} · {{ $rows->firstWhere('role_in_squad','mentor')?->name ?? '—' }}</span>
          @php $pct = $bySquad[$squadNum]['pct']; @endphp
          <span class="pct-pill {{ $pct >= 80 ? 'pct-on' : ($pct >= 40 ? 'pct-mid' : 'pct-off') }}">{{ $pct }}%</span>
        </div>
        <div class="squad-body">
          <table>
            @foreach ($rows as $r)
              <tr>
                <td style="width: 28px; text-align:center;">
                  <span class="check {{ $r->isActivated() ? 'on' : 'off' }}">{{ $r->isActivated() ? '✓' : '○' }}</span>
                </td>
                <td>
                  <span class="role-badge role-{{ $r->role_in_squad }}">{{ strtoupper($r->role_in_squad) }}</span>
                  <strong>{{ $r->name }}</strong>
                  @if ($r->claude_plan)
                    <span style="font-size:11px;color:#888;margin-left:6px;">[{{ strtoupper($r->claude_plan) }}]</span>
                  @endif
                  <details>
                    <summary>Move</summary>
                    <form class="move-form" method="POST" action="{{ route('ai-first.admin.move') }}">
                      @csrf
                      <input type="hidden" name="participant_id" value="{{ $r->id }}">
                      <select name="new_squad">
                        @foreach ([1,2,3,4] as $sn)
                          <option value="{{ $sn }}" {{ $sn === $r->squad_num ? 'selected' : '' }}>Squad {{ $sn }}</option>
                        @endforeach
                      </select>
                      <select name="new_role">
                        @foreach (['mentor','associate','mentee'] as $rl)
                          <option value="{{ $rl }}" {{ $rl === $r->role_in_squad ? 'selected' : '' }}>{{ ucfirst($rl) }}</option>
                        @endforeach
                      </select>
                      <button type="submit">Move</button>
                    </form>
                  </details>
                </td>
              </tr>
            @endforeach
          </table>
        </div>
      </div>
    @endforeach
  </div>

</div>
@endsection
