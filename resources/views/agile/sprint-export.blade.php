@php
    /** @var \App\Models\Sprint $sprint */
    /** @var array $columns */
    /** @var array $burndown */
    /** @var array $capacity */
    /** @var array $sprintMeta */
    /** @var string $generatedAt */
    /** @var string $generatedBy */

    $statusLabels = [
        'todo' => 'To Do',
        'in_progress' => 'In Progress',
        'code_review' => 'Code Review',
        'qa' => 'QA',
        'done' => 'Done',
        'open' => 'Open',
        'fixed' => 'Fixed',
        'verified' => 'Verified',
        'closed' => 'Closed',
        'wont_fix' => "Won't Fix",
        'planning' => 'Planning',
        'active' => 'Active',
        'review' => 'Review',
    ];

    $statusColors = [
        'todo' => '#6b7280',
        'in_progress' => '#3b82f6',
        'code_review' => '#8b5cf6',
        'qa' => '#f59e0b',
        'done' => '#22c55e',
        'open' => '#ef4444',
        'fixed' => '#8b5cf6',
        'verified' => '#f59e0b',
        'closed' => '#22c55e',
        'wont_fix' => '#6b7280',
        'planning' => '#3b82f6',
        'active' => '#22c55e',
        'review' => '#f59e0b',
    ];

    $priorityColors = [
        'critical' => '#ef4444',
        'blocker' => '#dc2626',
        'high' => '#f97316',
        'major' => '#f59e0b',
        'medium' => '#eab308',
        'low' => '#22c55e',
        'minor' => '#22c55e',
    ];

    $retro = $sprint->retrospective_notes ?? [];
    $wentWell = $retro['wentWell'] ?? [];
    $wentPoorly = $retro['wentPoorly'] ?? [];
    $actionItems = $retro['actionItems'] ?? [];

    $allStories = [];
    $allBugs = [];
    foreach ($columns as $colStatus => $col) {
        foreach (($col['stories'] ?? []) as $s) {
            $allStories[] = array_merge((array) $s, ['_columnStatus' => $colStatus]);
        }
        foreach (($col['bugs'] ?? []) as $b) {
            $allBugs[] = array_merge((array) $b, ['_columnStatus' => $colStatus]);
        }
    }

    $totalStories = count($allStories);
    $doneStories = collect($allStories)->where('status', 'done')->count();
    $totalBugs = count($allBugs);
    $closedBugs = collect($allBugs)->whereIn('status', ['closed', 'fixed', 'verified', 'wont_fix'])->count();
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Sprint Report — {{ $sprint->name }}</title>
    <style>
        @page { margin: 28pt 32pt; size: A4; }
        html, body {
            margin: 0; padding: 0;
            font-family: DejaVu Sans, sans-serif;
            font-size: 9.5pt;
            color: #1f1f1f;
            line-height: 1.45;
        }
        h1 { font-size: 18pt; margin: 0 0 4pt 0; color: #111; }
        h2 { font-size: 12pt; margin: 18pt 0 6pt 0; color: #111; border-bottom: 1pt solid #d4d4d8; padding-bottom: 3pt; }
        h3 { font-size: 10.5pt; margin: 10pt 0 4pt 0; color: #27272a; }
        p { margin: 0 0 6pt 0; }
        .muted { color: #6b7280; }
        .small { font-size: 8.5pt; }

        .header {
            border-bottom: 2pt solid #111;
            padding-bottom: 8pt;
            margin-bottom: 10pt;
        }
        .header .sub { font-size: 9pt; color: #6b7280; margin-top: 3pt; }

        .badge {
            display: inline-block;
            padding: 1pt 6pt;
            border-radius: 8pt;
            color: #fff;
            font-size: 8pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5pt;
            vertical-align: middle;
        }

        .meta-table { width: 100%; border-collapse: collapse; margin-top: 6pt; }
        .meta-table td { padding: 4pt 6pt; vertical-align: top; }
        .meta-table .lbl { color: #6b7280; width: 28%; font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.4pt; }
        .meta-table .val { color: #111; font-weight: 600; }

        .goal-box {
            background: #f4f4f5;
            border-left: 3pt solid #3b82f6;
            padding: 8pt 10pt;
            margin: 8pt 0;
            font-style: italic;
        }

        .progress-row { width: 100%; border-collapse: collapse; margin: 6pt 0 12pt 0; }
        .progress-row td {
            border: 1pt solid #e4e4e7;
            padding: 8pt;
            text-align: center;
            width: 25%;
        }
        .progress-row .num { font-size: 16pt; font-weight: 700; color: #111; display: block; }
        .progress-row .lbl { font-size: 8pt; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5pt; }

        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 12pt; font-size: 8.5pt; table-layout: fixed; }
        .items-table th {
            background: #18181b;
            color: #fff;
            padding: 5pt 6pt;
            text-align: left;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 0.3pt;
        }
        .items-table td {
            border-bottom: 1pt solid #e4e4e7;
            padding: 6pt 6pt;
            vertical-align: top;
            word-wrap: break-word;
            overflow-wrap: break-word;
            word-break: break-word;
        }
        .items-table tr:nth-child(even) td { background: #fafafa; }
        .items-table .title-cell { font-weight: 600; color: #111; word-wrap: break-word; overflow-wrap: break-word; }
        .items-table .desc-cell {
            color: #52525b;
            font-size: 8pt;
            margin-top: 3pt;
            line-height: 1.4;
            white-space: pre-wrap;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        .items-table .ac-cell {
            color: #3f3f46;
            font-size: 8pt;
            margin-top: 3pt;
            padding-top: 3pt;
            border-top: 1pt dashed #e4e4e7;
            white-space: pre-wrap;
        }
        .items-table .ac-cell strong { color: #18181b; }

        .col-section {
            margin-bottom: 14pt;
        }
        .col-section-header {
            background: #f4f4f5;
            padding: 5pt 8pt;
            border-left: 4pt solid #6b7280;
            font-weight: 700;
            font-size: 10pt;
            margin-bottom: 0;
        }
        .col-section-count { color: #6b7280; font-weight: 400; font-size: 8.5pt; margin-left: 4pt; }

        .notes-box {
            border: 1pt solid #e4e4e7;
            padding: 8pt 10pt;
            background: #fafafa;
            white-space: pre-wrap;
            margin-bottom: 8pt;
            min-height: 18pt;
        }

        .retro-grid { width: 100%; border-collapse: collapse; margin-top: 6pt; }
        .retro-grid td { width: 33.33%; vertical-align: top; padding: 0 4pt; }
        .retro-col-title { font-weight: 700; font-size: 9pt; margin-bottom: 4pt; text-transform: uppercase; letter-spacing: 0.4pt; }
        .retro-col-title.went-well { color: #16a34a; }
        .retro-col-title.went-poorly { color: #dc2626; }
        .retro-col-title.actions { color: #2563eb; }
        .retro-list { margin: 0; padding-left: 14pt; font-size: 8.5pt; }
        .retro-list li { margin-bottom: 3pt; }

        .burndown-table { width: 100%; border-collapse: collapse; font-size: 8pt; }
        .burndown-table th, .burndown-table td { border: 1pt solid #e4e4e7; padding: 3pt 5pt; text-align: center; }
        .burndown-table th { background: #f4f4f5; }

        .empty-row { text-align: center; color: #9ca3af; font-style: italic; padding: 10pt; }

        .footer {
            margin-top: 18pt;
            padding-top: 8pt;
            border-top: 1pt solid #e4e4e7;
            color: #9ca3af;
            font-size: 8pt;
            text-align: center;
        }

        .page-break { page-break-before: always; }
    </style>
</head>
<body>

<div class="header">
    <h1>{{ $sprint->name }}
        @php $st = $sprint->status; @endphp
        <span class="badge" style="background: {{ $statusColors[$st] ?? '#6b7280' }};">{{ $statusLabels[$st] ?? $st }}</span>
    </h1>
    <div class="sub">
        @if ($sprintMeta['projectName'])Project: <strong>{{ $sprintMeta['projectName'] }}</strong> &nbsp;·&nbsp;@endif
        @if ($sprintMeta['squadName'])Squad: <strong>{{ $sprintMeta['squadName'] }}</strong> &nbsp;·&nbsp;@endif
        Created by <strong>{{ $sprintMeta['createdByName'] ?: '—' }}</strong>
    </div>
</div>

@if ($sprint->goal)
    <div class="goal-box">
        <strong>Sprint Goal —</strong> {{ $sprint->goal }}
    </div>
@endif

<table class="meta-table">
    <tr>
        <td class="lbl">Start Date</td>
        <td class="val">{{ $sprint->start_date?->format('M j, Y') ?: '—' }}</td>
        <td class="lbl">End Date</td>
        <td class="val">{{ $sprint->end_date?->format('M j, Y') ?: '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">Days Remaining</td>
        <td class="val">{{ $sprintMeta['daysRemaining'] !== null ? $sprintMeta['daysRemaining'] . ' days' : '—' }}</td>
        <td class="lbl">Capacity</td>
        <td class="val">
            @if ($sprint->capacity_hours)
                {{ ($capacity['assignedHours'] ?? 0) }}h / {{ $sprint->capacity_hours }}h
            @else
                Not set
            @endif
        </td>
    </tr>
    <tr>
        <td class="lbl">Total Points</td>
        <td class="val">{{ $sprintMeta['totalPoints'] }} pts</td>
        <td class="lbl">Velocity (closed)</td>
        <td class="val">{{ $sprint->velocity !== null ? $sprint->velocity . ' pts' : '—' }}</td>
    </tr>
</table>

<h2>Progress Summary</h2>
<table class="progress-row">
    <tr>
        <td>
            <span class="num">{{ $sprintMeta['completedPoints'] }} / {{ $sprintMeta['totalPoints'] }}</span>
            <span class="lbl">Story Points Done</span>
        </td>
        <td>
            <span class="num">{{ $doneStories }} / {{ $totalStories }}</span>
            <span class="lbl">Stories Complete</span>
        </td>
        <td>
            <span class="num">{{ $closedBugs }} / {{ $totalBugs }}</span>
            <span class="lbl">Bugs Resolved</span>
        </td>
        <td>
            <span class="num">{{ $sprintMeta['totalPoints'] > 0 ? round(($sprintMeta['completedPoints'] / $sprintMeta['totalPoints']) * 100) : 0 }}%</span>
            <span class="lbl">Completion</span>
        </td>
    </tr>
</table>

{{-- ───── Stories by Status ───── --}}
<h2>Stories ({{ $totalStories }})</h2>
@if ($totalStories === 0)
    <div class="empty-row">No stories in this sprint.</div>
@else
    @foreach (['todo','in_progress','code_review','qa','done'] as $col)
        @php
            $stories = $columns[$col]['stories'] ?? [];
        @endphp
        @if (count($stories) > 0)
            <div class="col-section">
                <div class="col-section-header" style="border-left-color: {{ $statusColors[$col] ?? '#6b7280' }};">
                    {{ $statusLabels[$col] ?? $col }}
                    <span class="col-section-count">({{ count($stories) }})</span>
                </div>
                <table class="items-table">
                    <thead>
                        <tr>
                            <th style="width:8%;">#</th>
                            <th style="width:42%;">Title</th>
                            <th style="width:14%;">Assignee</th>
                            <th style="width:10%;">Points</th>
                            <th style="width:12%;">Priority</th>
                            <th style="width:14%;">MoSCoW</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($stories as $s)
                            <tr>
                                <td>{{ $s['id'] ?? '' }}</td>
                                <td>
                                    <div class="title-cell">{{ $s['title'] ?? '' }}</div>
                                    @if (!empty($s['description']))
                                        <div class="desc-cell">{{ trim(strip_tags($s['description'])) }}</div>
                                    @endif
                                    @if (!empty($s['acceptanceCriteria'] ?? $s['acceptance_criteria'] ?? null))
                                        <div class="ac-cell"><strong>Acceptance:</strong> {{ trim(strip_tags($s['acceptanceCriteria'] ?? $s['acceptance_criteria'])) }}</div>
                                    @endif
                                </td>
                                <td>{{ $s['assigneeName'] ?? '—' }}</td>
                                <td>{{ $s['storyPoints'] ?? $s['story_points'] ?? '—' }}</td>
                                <td>
                                    @if (!empty($s['priority']))
                                        <span class="badge" style="background: {{ $priorityColors[$s['priority']] ?? '#6b7280' }};">{{ $s['priority'] }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $s['moscow'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endforeach
@endif

{{-- ───── Bugs by Status ───── --}}
<h2>Bugs ({{ $totalBugs }})</h2>
@if ($totalBugs === 0)
    <div class="empty-row">No bugs in this sprint.</div>
@else
    @foreach (['todo','in_progress','code_review','qa','done'] as $col)
        @php
            $bugs = $columns[$col]['bugs'] ?? [];
        @endphp
        @if (count($bugs) > 0)
            <div class="col-section">
                <div class="col-section-header" style="border-left-color: {{ $statusColors[$col] ?? '#6b7280' }};">
                    {{ $statusLabels[$col] ?? $col }}
                    <span class="col-section-count">({{ count($bugs) }})</span>
                </div>
                <table class="items-table">
                    <thead>
                        <tr>
                            <th style="width:7%;">#</th>
                            <th style="width:38%;">Title</th>
                            <th style="width:14%;">Assignee</th>
                            <th style="width:13%;">Reporter</th>
                            <th style="width:9%;">Severity</th>
                            <th style="width:9%;">Priority</th>
                            <th style="width:10%;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bugs as $b)
                            <tr>
                                <td>{{ $b['id'] ?? '' }}</td>
                                <td>
                                    <div class="title-cell">{{ $b['title'] ?? '' }}</div>
                                    @if (!empty($b['description']))
                                        <div class="desc-cell">{{ trim(strip_tags($b['description'])) }}</div>
                                    @endif
                                    @if (!empty($b['stepsToReproduce'] ?? $b['steps_to_reproduce'] ?? null))
                                        <div class="ac-cell"><strong>Steps to reproduce:</strong> {{ trim(strip_tags($b['stepsToReproduce'] ?? $b['steps_to_reproduce'])) }}</div>
                                    @endif
                                </td>
                                <td>{{ $b['assigneeName'] ?? '—' }}</td>
                                <td>{{ $b['reporterName'] ?? '—' }}</td>
                                <td>
                                    @if (!empty($b['severity']))
                                        <span class="badge" style="background: {{ $priorityColors[$b['severity']] ?? '#6b7280' }};">{{ $b['severity'] }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    @if (!empty($b['priority']))
                                        <span class="badge" style="background: {{ $priorityColors[$b['priority']] ?? '#6b7280' }};">{{ $b['priority'] }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    <span class="badge" style="background: {{ $statusColors[$b['status']] ?? '#6b7280' }};">{{ $statusLabels[$b['status']] ?? ($b['status'] ?? '') }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endforeach
@endif

{{-- ───── Sprint Review ───── --}}
@if (in_array($sprint->status, ['review', 'closed'], true) || $sprint->review_notes || !empty($wentWell) || !empty($wentPoorly) || !empty($actionItems))
    <h2>Sprint Review</h2>
    @if (!empty(trim($sprint->review_notes ?? '')))
        <h3>Demo Notes</h3>
        <div class="notes-box">{{ $sprint->review_notes }}</div>
    @else
        <p class="muted small">No demo notes recorded.</p>
    @endif

    <h3 style="margin-top: 14pt;">Retrospective</h3>
    <table class="retro-grid">
        <tr>
            <td>
                <div class="retro-col-title went-well">Went Well</div>
                @if (count($wentWell) > 0)
                    <ul class="retro-list">
                        @foreach ($wentWell as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                @else
                    <p class="muted small">—</p>
                @endif
            </td>
            <td>
                <div class="retro-col-title went-poorly">Went Poorly</div>
                @if (count($wentPoorly) > 0)
                    <ul class="retro-list">
                        @foreach ($wentPoorly as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                @else
                    <p class="muted small">—</p>
                @endif
            </td>
            <td>
                <div class="retro-col-title actions">Action Items</div>
                @if (count($actionItems) > 0)
                    <ul class="retro-list">
                        @foreach ($actionItems as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                @else
                    <p class="muted small">—</p>
                @endif
            </td>
        </tr>
    </table>
@endif

{{-- ───── Burndown ───── --}}
@if (!empty($burndown['days']))
    <h2>Burndown</h2>
    <table class="burndown-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Ideal Remaining (pts)</th>
                <th>Actual Remaining (pts)</th>
                <th>Variance</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($burndown['days'] as $day)
                @php
                    $ideal = $day['ideal'] ?? '';
                    $actual = $day['remaining'] ?? '';
                    $variance = (is_numeric($ideal) && is_numeric($actual)) ? ($actual - $ideal) : '';
                @endphp
                <tr>
                    <td>{{ $day['date'] ?? '' }}</td>
                    <td>{{ $ideal === '' ? '—' : $ideal }}</td>
                    <td>{{ $actual === '' ? '—' : $actual }}</td>
                    <td style="color: {{ is_numeric($variance) && $variance > 0 ? '#dc2626' : '#16a34a' }}; font-weight: 600;">
                        {{ $variance === '' ? '—' : ($variance > 0 ? '+' . $variance : $variance) }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<div class="footer">
    Generated on {{ $generatedAt }} by {{ $generatedBy }} · Tessa
</div>

</body>
</html>
