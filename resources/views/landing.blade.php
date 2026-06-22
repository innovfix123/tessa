<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tessa — Innovfix's portal</title>
    <meta name="description" content="Tessa — the operating system Innovfix runs on. Tasks, meetings, reports, reviews, leave, letters.">
    <link rel="icon" type="image/svg+xml" href="{{ asset('img/tessa-logo.svg') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('img/favicon-32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('img/favicon-16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('img/apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/landing.css') }}">
</head>
<body>
    <div class="lp-noise" aria-hidden="true"></div>

    <header class="lp-nav">
        <a href="/" class="lp-brand">
            <img src="{{ asset('img/tessa-logo.svg') }}" alt="Tessa" class="lp-logo">
            <span>Tessa</span>
            <span class="lp-brand-status"><span class="lp-status-dot"></span> live</span>
        </a>
        <div class="lp-nav-links">
            <a href="#inside">Inside</a>
            <a href="#flow">Flow</a>
            <a href="#start">Start</a>
            <a href="{{ route('login') }}" class="lp-nav-cta">
                Sign in
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
            </a>
        </div>
    </header>

    <main>
        <section class="lp-hero">
            <div class="lp-hero-glow" aria-hidden="true"></div>
            <div class="lp-hero-grid" aria-hidden="true"></div>

            <div class="lp-tag-row">
                <span class="lp-tag"><span class="lp-tag-bar"></span>INNOVFIX / INTERNAL</span>
                <span class="lp-tag-sep">v.2026</span>
            </div>

            <h1 class="lp-h1">
                <span>The portal</span>
                <span>Innovfix</span>
                <span class="lp-h1-italic">runs on.</span>
            </h1>

            <p class="lp-lede">Tasks, meetings, reports, reviews, leave, letters &mdash; one address, one login, one source of truth for the work of running a company.</p>

            <div class="lp-cta-cluster">
                <a href="{{ route('login') }}" class="lp-btn lp-btn-fill">
                    <span>Sign in</span>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </a>
                <a href="#inside" class="lp-btn lp-btn-ghost">See what's inside</a>
                <span class="lp-cta-note">@innovfix.in only</span>
            </div>

            <div class="lp-marquee" aria-hidden="true">
                <div class="lp-marquee-track">
                    <span>TASKS</span><span class="lp-marquee-dot"></span>
                    <span>MEETINGS</span><span class="lp-marquee-dot"></span>
                    <span>DAILY REPORTS</span><span class="lp-marquee-dot"></span>
                    <span>REVIEWS</span><span class="lp-marquee-dot"></span>
                    <span>LEAVE</span><span class="lp-marquee-dot"></span>
                    <span>LETTERS</span><span class="lp-marquee-dot"></span>
                    <span>TIMESHEETS</span><span class="lp-marquee-dot"></span>
                    <span>REWARDS</span><span class="lp-marquee-dot"></span>
                    <span>ORG CHART</span><span class="lp-marquee-dot"></span>
                    <span>KRAs</span><span class="lp-marquee-dot"></span>
                    <span>TASKS</span><span class="lp-marquee-dot"></span>
                    <span>MEETINGS</span><span class="lp-marquee-dot"></span>
                    <span>DAILY REPORTS</span><span class="lp-marquee-dot"></span>
                    <span>REVIEWS</span><span class="lp-marquee-dot"></span>
                    <span>LEAVE</span><span class="lp-marquee-dot"></span>
                    <span>LETTERS</span><span class="lp-marquee-dot"></span>
                </div>
            </div>
        </section>

        <section id="inside" class="lp-bento-wrap">
            <header class="lp-section-head">
                <span class="lp-section-num">/ 01</span>
                <h2 class="lp-h2">Six tools. <em>One address.</em></h2>
            </header>

            <div class="lp-bento">
                <div class="lp-bento-card lp-bento-tall">
                    <div class="lp-card-head">
                        <span class="lp-card-num">01</span>
                        <span class="lp-card-kicker">TASKS</span>
                    </div>
                    <h3>Work that knows when it's overdue.</h3>
                    <p>ClickUp-style list + slide-over. Recurring tasks, daily checklists, mandatory work that demands proof.</p>
                    <div class="lp-tasklist">
                        <div class="lp-tasklist-row">
                            <span class="lp-tasklist-check lp-tasklist-check-done"></span>
                            <span class="lp-strike">Send Friday review</span>
                            <span class="lp-tag-mini">Done</span>
                        </div>
                        <div class="lp-tasklist-row">
                            <span class="lp-tasklist-check"></span>
                            <span>Sync Hima paid users</span>
                            <span class="lp-tag-mini lp-tag-mini-warn">Today</span>
                        </div>
                        <div class="lp-tasklist-row">
                            <span class="lp-tasklist-check"></span>
                            <span>Approve leave request</span>
                            <span class="lp-tag-mini lp-tag-mini-info">HR</span>
                        </div>
                        <div class="lp-tasklist-row lp-tasklist-row-dim">
                            <span class="lp-tasklist-check"></span>
                            <span>Issue appointment letter</span>
                            <span class="lp-tag-mini">Later</span>
                        </div>
                    </div>
                </div>

                <div class="lp-bento-card lp-bento-accent">
                    <div class="lp-card-head">
                        <span class="lp-card-num">02</span>
                        <span class="lp-card-kicker">REPORTS</span>
                    </div>
                    <h3>Numbers, not opinions.</h3>
                    <p>Daily reports, KRA scorecards, mission dashboards &mdash; wired to live revenue.</p>
                    <div class="lp-bars">
                        <span style="height:22%"></span>
                        <span style="height:48%"></span>
                        <span style="height:36%"></span>
                        <span style="height:72%"></span>
                        <span style="height:55%"></span>
                        <span style="height:88%" class="lp-bar-hot"></span>
                        <span style="height:62%"></span>
                    </div>
                </div>

                <div class="lp-bento-card">
                    <div class="lp-card-head">
                        <span class="lp-card-num">03</span>
                        <span class="lp-card-kicker">MEETINGS</span>
                    </div>
                    <h3>Decisions, written down.</h3>
                    <p>Per-meeting agendas, attendance from Slack huddles, decisions logged where the next owner can find them.</p>
                </div>

                <div class="lp-bento-card lp-bento-wide">
                    <div class="lp-card-head">
                        <span class="lp-card-num">04</span>
                        <span class="lp-card-kicker">HR &middot; LEAVE &middot; PROFILE</span>
                    </div>
                    <h3>Trust-based, by design.</h3>
                    <p>Documents, leave, holiday calendar, recurring reminders. No chasing approvals you don't need; the rules are encoded so people don't have to ask.</p>
                    <div class="lp-pill-row">
                        <span class="lp-pill">Profile</span>
                        <span class="lp-pill">Documents</span>
                        <span class="lp-pill">Leave</span>
                        <span class="lp-pill">Timesheets</span>
                        <span class="lp-pill">Holiday calendar</span>
                    </div>
                </div>

                <div class="lp-bento-card">
                    <div class="lp-card-head">
                        <span class="lp-card-num">05</span>
                        <span class="lp-card-kicker">LETTERS</span>
                    </div>
                    <h3>Issued. Signed. Filed.</h3>
                    <p>Appointment letters, offer letters, NDAs &mdash; without anyone emailing "the latest version".</p>
                </div>

                <div class="lp-bento-card">
                    <div class="lp-card-head">
                        <span class="lp-card-num">06</span>
                        <span class="lp-card-kicker">REWARDS &amp; REVIEWS</span>
                    </div>
                    <h3>Recognition meets accountability.</h3>
                    <p>Reward tasks, manager reviews, escalations on overdue work &mdash; same place.</p>
                </div>
            </div>
        </section>

        <section id="ai" class="lp-bento-wrap">
            <header class="lp-section-head">
                <span class="lp-section-num">/ 02 &mdash; AI inside</span>
                <h2 class="lp-h2">AI that knows your <em>company.</em></h2>
            </header>

            <div class="lp-bento">
                <div class="lp-bento-card lp-bento-tall">
                    <div class="lp-card-head">
                        <span class="lp-card-num">01</span>
                        <span class="lp-card-kicker">CLAUDE &middot; MCP</span>
                    </div>
                    <h3>Talk to Tessa from Claude.</h3>
                    <p>Connect Claude Desktop or Code to your portal via MCP. Tasks, leaves, reports &mdash; available to your AI, gated by your role.</p>
                    <div class="lp-codeblock">
                        <div class="lp-codeline lp-codeline-prompt"><span class="lp-codeprefix">&gt;</span> What's overdue on my plate?</div>
                        <div class="lp-codeline lp-codeline-resp">3 tasks, all on the Hima sync. Want me to ping the assignees?</div>
                        <div class="lp-codeline lp-codeline-prompt"><span class="lp-codeprefix">&gt;</span> File leave for tomorrow &mdash; sick</div>
                        <div class="lp-codeline lp-codeline-resp lp-codeline-ok"><span class="lp-tick">&#10003;</span> Auto-approved &middot; manager looped in</div>
                    </div>
                </div>

                <div class="lp-bento-card lp-bento-accent">
                    <div class="lp-card-head">
                        <span class="lp-card-num">02</span>
                        <span class="lp-card-kicker">TIMESHEET ASSISTANT</span>
                    </div>
                    <h3>"Worked 9&ndash;7 plus 1hr OT."</h3>
                    <p>Plain-English daily logs. The AI parses hours, projects and overtime &mdash; logged with one tap.</p>
                    <div class="lp-chat">
                        <div class="lp-chat-msg lp-chat-them">Worked 9&ndash;7 on user dashboard + 1hr OT after dinner on the migration</div>
                        <div class="lp-chat-msg lp-chat-me"><span class="lp-tick">&#10003;</span> 9h regular + 1h OT logged to "Hima migration"</div>
                    </div>
                </div>

                <div class="lp-bento-card lp-bento-wide">
                    <div class="lp-card-head">
                        <span class="lp-card-num">03</span>
                        <span class="lp-card-kicker">CONTEXT BUILDER</span>
                    </div>
                    <h3>Tessa knows the org.</h3>
                    <p>Reporting lines, who's on leave today, this sprint's bugs, last Friday's reviews &mdash; fed to the AI so answers are about <em>your</em> team, not Wikipedia.</p>
                    <div class="lp-fact-row">
                        <span class="lp-fact"><span class="lp-fact-k">Org</span><span class="lp-fact-v">44 employees</span></span>
                        <span class="lp-fact"><span class="lp-fact-k">On leave today</span><span class="lp-fact-v">3</span></span>
                        <span class="lp-fact"><span class="lp-fact-k">Sprint</span><span class="lp-fact-v">12 stories · 4 bugs</span></span>
                        <span class="lp-fact"><span class="lp-fact-k">Reviews due</span><span class="lp-fact-v">Friday</span></span>
                    </div>
                </div>
            </div>
        </section>

        <section id="hr" class="lp-bento-wrap">
            <header class="lp-section-head">
                <span class="lp-section-num">/ 03 &mdash; HR runs itself</span>
                <h2 class="lp-h2">Automation for the <em>people stuff.</em></h2>
            </header>

            <div class="lp-bento">
                <div class="lp-bento-card lp-bento-wide">
                    <div class="lp-card-head">
                        <span class="lp-card-num">01</span>
                        <span class="lp-card-kicker">SLACK NUDGES</span>
                    </div>
                    <h3>Reminders that find you where you work.</h3>
                    <p>Sign-in nudges, review reminders, overdue-task escalations, daily-report check-ins &mdash; bundled into one DM per person per run. Quiet hours respected.</p>
                    <div class="lp-slack-card">
                        <div class="lp-slack-header">
                            <span class="lp-slack-logo">T</span>
                            <span class="lp-slack-name">Tessa <span class="lp-slack-app">APP</span></span>
                            <span class="lp-slack-time">2:30 PM</span>
                        </div>
                        <div class="lp-slack-msg">Friday review window is open &mdash; you owe ratings for <strong>4 reports</strong>. <a class="lp-slack-link" href="#">Open in Tessa &rarr;</a></div>
                    </div>
                </div>

                <div class="lp-bento-card lp-bento-tall">
                    <div class="lp-card-head">
                        <span class="lp-card-num">02</span>
                        <span class="lp-card-kicker">LETTERS</span>
                    </div>
                    <h3>Appointment letters in 30 seconds.</h3>
                    <p>Pick an employee, choose a template, hit preview. CTC slabs, ESI gate, PF, gross/net &mdash; auto-calculated. Signed PDF, share link, done.</p>
                    <div class="lp-doc-stack">
                        <div class="lp-doc lp-doc-3"></div>
                        <div class="lp-doc lp-doc-2">
                            <div class="lp-doc-line"></div>
                            <div class="lp-doc-line lp-doc-line-short"></div>
                        </div>
                        <div class="lp-doc lp-doc-1">
                            <div class="lp-doc-stamp">SIGNED</div>
                            <div class="lp-doc-title"></div>
                            <div class="lp-doc-line"></div>
                            <div class="lp-doc-line lp-doc-line-short"></div>
                            <div class="lp-doc-line"></div>
                            <div class="lp-doc-line lp-doc-line-medium"></div>
                            <div class="lp-doc-line"></div>
                            <div class="lp-doc-line lp-doc-line-short"></div>
                        </div>
                    </div>
                </div>

                <div class="lp-bento-card">
                    <div class="lp-card-head">
                        <span class="lp-card-num">03</span>
                        <span class="lp-card-kicker">AUTO-APPROVE</span>
                    </div>
                    <h3>Trust-based leave.</h3>
                    <p>Sick, emergency, menstrual &mdash; auto-approved, no chase. Casual + WFH still loop in your manager.</p>
                    <div class="lp-leave-row">
                        <span class="lp-leave-tag lp-leave-ok"><span class="lp-tick">&#10003;</span> Sick</span>
                        <span class="lp-leave-tag lp-leave-ok"><span class="lp-tick">&#10003;</span> Emergency</span>
                        <span class="lp-leave-tag lp-leave-ok"><span class="lp-tick">&#10003;</span> Menstrual</span>
                        <span class="lp-leave-tag">Casual</span>
                        <span class="lp-leave-tag">WFH</span>
                    </div>
                </div>

                <div class="lp-bento-card">
                    <div class="lp-card-head">
                        <span class="lp-card-num">04</span>
                        <span class="lp-card-kicker">REVIEW CYCLE</span>
                    </div>
                    <h3>Weekly reviews on rails.</h3>
                    <p>Friday opens the window &middot; weekend nudges the active &middot; weekday nags the holdouts. No spreadsheets.</p>
                    <div class="lp-timeline">
                        <span class="lp-time-dot lp-time-active" data-d="Fri"></span>
                        <span class="lp-time-line"></span>
                        <span class="lp-time-dot lp-time-active" data-d="Sat"></span>
                        <span class="lp-time-line"></span>
                        <span class="lp-time-dot lp-time-active" data-d="Sun"></span>
                        <span class="lp-time-line"></span>
                        <span class="lp-time-dot" data-d="Mon"></span>
                        <span class="lp-time-line"></span>
                        <span class="lp-time-dot" data-d="Tue"></span>
                        <span class="lp-time-line"></span>
                        <span class="lp-time-dot" data-d="Wed"></span>
                        <span class="lp-time-line"></span>
                        <span class="lp-time-dot" data-d="Thu"></span>
                    </div>
                </div>

                <div class="lp-bento-card">
                    <div class="lp-card-head">
                        <span class="lp-card-num">05</span>
                        <span class="lp-card-kicker">RECURRING TASKS</span>
                    </div>
                    <h3>Daily rituals, scheduled.</h3>
                    <p>Recurring tasks, daily checklists, mandatory work &mdash; created hourly, escalated when overdue.</p>
                </div>

                <div class="lp-bento-card">
                    <div class="lp-card-head">
                        <span class="lp-card-num">06</span>
                        <span class="lp-card-kicker">REWARD WORKFLOW</span>
                    </div>
                    <h3>Recognition, end to end.</h3>
                    <p>JP assigns reward tasks &rarr; assignee posts updates &rarr; approval routes to Ayush &rarr; auto-pending withdrawal. No follow-ups.</p>
                </div>
            </div>
        </section>

        <section id="flow" class="lp-flow">
            <header class="lp-section-head">
                <span class="lp-section-num">/ 04</span>
                <h2 class="lp-h2">Plays nice with <em>what you already use.</em></h2>
            </header>
            <div class="lp-chips">
                <span class="lp-chip"><span class="lp-chip-dot" style="--c:#ECB22E"></span> Slack DMs &amp; huddles</span>
                <span class="lp-chip"><span class="lp-chip-dot" style="--c:#4285F4"></span> Google Sign-in</span>
                <span class="lp-chip"><span class="lp-chip-dot" style="--c:#f5f5f5"></span> GitHub PRs</span>
                <span class="lp-chip"><span class="lp-chip-dot" style="--c:#cc785c"></span> Claude (MCP)</span>
                <span class="lp-chip"><span class="lp-chip-dot" style="--c:#06b6d4"></span> Hima live revenue</span>
            </div>
        </section>

        <section id="start" class="lp-cta-final">
            <div class="lp-cta-final-bg" aria-hidden="true"></div>
            <span class="lp-section-num">/ 05 &mdash; Start</span>
            <h2 class="lp-cta-final-h">Three clicks to your day.</h2>
            <p class="lp-cta-final-sub">Sign in. Land on your home. Get the day done.</p>
            <a href="{{ route('login') }}" class="lp-btn lp-btn-fill lp-btn-xl">
                <span>Sign in to Tessa</span>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
            </a>
        </section>
    </main>

    <footer class="lp-foot">
        <div class="lp-foot-inner">
            <div class="lp-foot-brand">
                <img src="{{ asset('img/tessa-logo.svg') }}" alt="" class="lp-logo">
                <span>Tessa</span>
                <span class="lp-foot-by">&mdash; built at Innovfix</span>
            </div>
            <div class="lp-foot-links">
                <a href="{{ route('login') }}">Sign in</a>
                <a href="#inside">Inside</a>
                <span>&copy; {{ date('Y') }} Innovfix</span>
            </div>
        </div>
    </footer>
</body>
</html>
