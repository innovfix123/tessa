<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>AI First — Extra Question Papers</title>
<style>
  @page { margin: 36pt 36pt 28pt 36pt; }
  * { box-sizing: border-box; }
  body {
    font-family: 'Helvetica', 'Arial', sans-serif;
    color: #111;
    font-size: 10.5pt;
    line-height: 1.5;
    margin: 0;
  }

  .cover {
    text-align: center;
    padding: 48pt 0 24pt 0;
    border-bottom: 2pt solid #111;
    margin-bottom: 24pt;
  }
  .cover .brand { font-size: 9pt; letter-spacing: 4pt; color: #777; text-transform: uppercase; }
  .cover h1 { margin: 8pt 0 4pt 0; font-size: 26pt; letter-spacing: -0.5pt; }
  .cover .sub { font-size: 11pt; color: #555; margin-top: 6pt; }
  .cover .intro { font-size: 10pt; color: #444; margin-top: 14pt; max-width: 440pt; margin-left: auto; margin-right: auto; line-height: 1.6; }

  .person {
    page-break-before: always;
    padding-top: 12pt;
  }
  .person:first-of-type { page-break-before: auto; }
  .person-name { font-size: 22pt; font-weight: 700; margin-bottom: 2pt; letter-spacing: -0.3pt; }
  .person-role { font-size: 12pt; color: #555; margin-bottom: 18pt; }

  .section {
    margin-bottom: 18pt;
  }
  .section-title {
    font-size: 9pt;
    letter-spacing: 2.5pt;
    color: #888;
    text-transform: uppercase;
    margin-bottom: 8pt;
    font-weight: 700;
    border-bottom: 1pt solid #e6e6ea;
    padding-bottom: 4pt;
  }

  .prompt {
    margin: 8pt 0;
    display: table;
    width: 100%;
  }
  .prompt .num {
    display: table-cell;
    width: 22pt;
    font-weight: 700;
    color: #888;
    vertical-align: top;
    padding-top: 1pt;
  }
  .prompt .tool {
    display: inline-block;
    font-size: 8pt;
    font-weight: 700;
    letter-spacing: 0.4pt;
    padding: 2pt 8pt;
    border-radius: 999pt;
    margin-right: 8pt;
    text-transform: uppercase;
    vertical-align: middle;
  }
  .prompt .body {
    display: table-cell;
    vertical-align: top;
  }
  .prompt .text {
    display: inline;
    color: #111;
  }

  .tool-gmail    { background: #fef2f2; color: #b42318; }
  .tool-slack    { background: #f3e8ff; color: #6927bf; }
  .tool-tessa    { background: #dbeafe; color: #1e40af; }
  .tool-calendar { background: #fef0c7; color: #93370d; }
  .tool-drive    { background: #d1fae5; color: #047857; }
  .tool-hima     { background: #fce7f3; color: #be185d; }
  .tool-onlycare { background: #cffafe; color: #0e7490; }

  .footer-note {
    margin-top: 18pt;
    padding-top: 10pt;
    border-top: 1pt solid #e6e6ea;
    font-size: 9pt;
    color: #888;
    font-style: italic;
  }
</style>
</head>
<body>

<div class="cover">
  <div class="brand">InnovFix · AI First</div>
  <h1>Question Papers — Extras</h1>
  <div class="sub">Nisha · Suwetha S · Kishore Prabakaran</div>
  <div class="intro">
    These supplement the squad-level question packs. Each page is for one teammate.
    Open Claude, make sure your connectors are on, and type any prompt in plain English —
    Claude pulls the data or does the task for you using the right tool.
  </div>
</div>

@php
$papers = [
  [
    'name'    => 'Nisha',
    'role'    => 'Tamil Support — Hima',
    'sections' => [
      [
        'title'   => 'Everyday tools · Gmail, Slack, Tessa & Calendar',
        'prompts' => [
          ['tool' => 'gmail',    'text' => 'Summarise any escalation emails about Tamil-language users I received this week.'],
          ['tool' => 'slack',    'text' => 'Draft a Slack message to Deeksha (Team Lead — Support) about the top 3 Tamil user complaints today.'],
          ['tool' => 'tessa',    'text' => 'Set a reminder in Tessa to follow up on a Tamil user\'s pending withdrawal tomorrow.'],
          ['tool' => 'tessa',    'text' => 'Show me my pending tickets and tasks in Tessa for today.'],
        ],
      ],
      [
        'title'   => 'Your work · Tamil Support (Hima)',
        'prompts' => [
          ['tool' => 'hima', 'text' => 'List the support tickets in the Hima Tamil queue waiting for a human reply.'],
          ['tool' => 'hima', 'text' => 'Look up this Tamil user (mobile number) and show their balance, language, recent transactions, and any open complaints.'],
          ['tool' => 'hima', 'text' => 'Run the AI classifier on this ticket to confirm its language and intent before I reply.'],
          ['tool' => 'hima', 'text' => 'Show today\'s ticket volume for the Tamil queue with the status breakdown.'],
          ['tool' => 'hima', 'text' => 'Pull the chat thread for this ticket so I can investigate before responding.'],
        ],
      ],
    ],
  ],
  [
    'name'    => 'Suwetha S',
    'role'    => 'Technical Support — Only Care',
    'sections' => [
      [
        'title'   => 'Everyday tools · Gmail, Slack, Tessa & Calendar',
        'prompts' => [
          ['tool' => 'gmail',    'text' => 'Summarise the Only Care support emails I received today.'],
          ['tool' => 'slack',    'text' => 'Send my manager (Bala) a Slack message about a recurring issue users are reporting in the latest Only Care update.'],
          ['tool' => 'tessa',    'text' => 'Apply for leave in Tessa next Tuesday for a personal reason.'],
          ['tool' => 'tessa',    'text' => 'Sign me in for today and list my pending Only Care tickets in Tessa.'],
        ],
      ],
      [
        'title'   => 'Your work · Only Care Technical Support',
        'prompts' => [
          ['tool' => 'onlycare', 'text' => 'Show me all the open Only Care tickets right now and which need a human reply.'],
          ['tool' => 'onlycare', 'text' => 'Look up this Only Care user (mobile number) and show their recent activity and open tickets.'],
          ['tool' => 'onlycare', 'text' => 'Pull the chat thread for this Only Care ticket so I can investigate before responding.'],
          ['tool' => 'onlycare', 'text' => 'Which Only Care ticket categories are generating the most volume right now?'],
          ['tool' => 'onlycare', 'text' => 'Show me today\'s end-of-day Only Care ops report with ticket breakdown by status.'],
        ],
      ],
    ],
  ],
  [
    'name'    => 'Kishore Prabakaran',
    'role'    => 'Content Lead — Hima',
    'sections' => [
      [
        'title'   => 'Everyday tools · Gmail, Slack, Tessa & Calendar',
        'prompts' => [
          ['tool' => 'gmail',    'text' => 'Summarise this week\'s emails about content reviews, creator feedback, or shoot scheduling.'],
          ['tool' => 'slack',    'text' => 'Draft a Slack message to Krishnan (Creative Head) summarising this week\'s Hima content pipeline status.'],
          ['tool' => 'calendar', 'text' => 'Show me my upcoming content review meetings this week and flag any overlap with creator shoots.'],
          ['tool' => 'tessa',    'text' => 'List my pending tasks and action items in Tessa for today.'],
        ],
      ],
      [
        'title'   => 'Your work · Content Lead (Hima)',
        'prompts' => [
          ['tool' => 'tessa', 'text' => 'Assign a new content brief task in Tessa to one of my Hima creators (Nehal / Fathima / Haripriya / Disha) with a Friday deadline.'],
          ['tool' => 'drive', 'text' => 'Find this week\'s Hima content calendar in Google Drive and read out what\'s scheduled.'],
          ['tool' => 'tessa', 'text' => 'Check which Hima creators have submitted their daily content logs in Tessa today and which haven\'t.'],
          ['tool' => 'slack', 'text' => 'Pull the design feedback scattered across the Hima creative Slack channel this week and summarise it into a clean to-do list.'],
          ['tool' => 'tessa', 'text' => 'Nudge an overdue creator about a pending content piece using Claude — show me the message before it sends.'],
        ],
      ],
    ],
  ],
];

$toolLabels = [
  'gmail'    => 'Gmail',
  'slack'    => 'Slack',
  'tessa'    => 'Tessa',
  'calendar' => 'Calendar',
  'drive'    => 'Drive',
  'hima'     => 'Hima',
  'onlycare' => 'Only Care',
];
@endphp

@foreach ($papers as $p)
<div class="person">
  <div class="person-name">{{ $p['name'] }}</div>
  <div class="person-role">{{ $p['role'] }}</div>

  @php $promptNum = 1; @endphp
  @foreach ($p['sections'] as $sec)
    <div class="section">
      <div class="section-title">{{ $sec['title'] }}</div>
      @foreach ($sec['prompts'] as $prompt)
        <div class="prompt">
          <div class="num">{{ $promptNum }}</div>
          <div class="body">
            <span class="tool tool-{{ $prompt['tool'] }}">{{ $toolLabels[$prompt['tool']] }}</span>
            <span class="text">{{ $prompt['text'] }}</span>
          </div>
        </div>
        @php $promptNum++; @endphp
      @endforeach
    </div>
  @endforeach

  <div class="footer-note">
    Just type the prompt to Claude — it uses the connected tool for you. Tweak the dates, names and numbers to fit what you need.
  </div>
</div>
@endforeach

</body>
</html>
