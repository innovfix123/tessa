<?php

/*
|--------------------------------------------------------------------------
| AI First — Assessment Question Sets
|--------------------------------------------------------------------------
| One entry per assessee, keyed by the exact ai_first_participants.name.
|
| PURPOSE OF THE ASSESSMENT (per CEO):
|   This is NOT a depth/knowledge test — Claude gives the answer anyway.
|   It validates two things on a live 1-on-1 screen share:
|     1. The person has actually CONNECTED their MCP connectors.
|     2. The person KNOWS HOW TO ASK Claude to do the task.
|   So breadth across connectors > depth in one topic.
|
| STRUCTURE (6 questions each):
|   - "Everyday Connectors" (3): Tessa + Slack + Gmail — the must-have
|     connectors everyone is expected to have wired up. One question each.
|   - "Your Work" (3): role-specific. At most the role tool appears twice;
|     the third question uses a different connector (Drive / Calendar /
|     Safety-validation) so we touch 5+ distinct connectors per person.
|
| FORMAT: live demo instructions ("Show me…", "Pull…", "Draft…") for a
| screen-share, never written-exam phrasing.
|
| Tool keys map to coloured pills in resources/views/ai-first/exam.blade.php:
|   tessa, slack, gmail, calendar, drive, hima, onlycare, meta_ads,
|   google_ads, analyst_db, code, claude, safety
*/

return [

    // ───────────────────────── SQUAD 1 — Finance / HR / AI interns ─────────────────────────

    'Akshara' => [
        'role' => 'HR',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Sign in for the day on Tessa through Claude right now — show me the confirmation.'],
                ['tool' => 'slack', 'text' => 'Draft a Slack message to the team about an HR policy update via Claude — show me the draft before it sends.'],
                ['tool' => 'gmail', 'text' => 'Ask Claude to pull any unread emails from the team today — read me the summary.'],
            ]],
            ['title' => 'Your Work — HR', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Pull your current KRA scores via Claude — read me each metric on screen.'],
                ['tool' => 'drive', 'text' => 'Find an HR document in your Google Drive through Claude — show me the file.'],
                ['tool' => 'safety', 'text' => 'Connect a connector you do not have yet and pull one real record — do it live, no notes, no Googling.'],
            ]],
        ],
    ],

    'Ayush' => [
        'role' => 'CFO',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Submit a daily report in Tessa via Claude — show me it appear in Tessa.'],
                ['tool' => 'slack', 'text' => 'Draft a Slack message to your team about a project update via Claude — show me the draft, then send.'],
                ['tool' => 'gmail', 'text' => 'Search your Gmail through Claude for a specific email from last week — type the prompt live and show me what comes back.'],
            ]],
            ['title' => 'Your Work — Finance Leadership', 'prompts' => [
                ['tool' => 'drive', 'text' => 'Find a finance file in your Google Drive related to your current work through Claude — show me the result.'],
                ['tool' => 'tessa', 'text' => 'Assign a high-priority task to a teammate with tomorrow as the deadline through Claude — show me it created in Tessa.'],
                ['tool' => 'safety', 'text' => 'Send a Slack message through Claude two ways — once as a draft, once as a direct send. Show me the difference on screen.'],
            ]],
        ],
    ],

    'Bhuvan Prasad' => [
        'role' => 'AI Intern',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Sign in for the day on Tessa through Claude now, then sign off with a short note — show both confirmations.'],
                ['tool' => 'slack', 'text' => 'Send a Slack message to a teammate through Claude — show me each step and the sent message.'],
                ['tool' => 'gmail', 'text' => 'Ask Claude to check if you have any unread emails today — read me the summary.'],
            ]],
            ['title' => 'Your Work — AI Intern', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Pull your current KRA scores via Claude — read me each metric on screen.'],
                ['tool' => 'calendar', 'text' => 'Set up a meeting with a teammate tomorrow through Claude — show me the event in your calendar.'],
                ['tool' => 'safety', 'text' => 'Connect a connector you do not have yet and pull one real record — live on screen, no notes, no Googling.'],
            ]],
        ],
    ],

    'Irisha' => [
        'role' => "Founder's Office",
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'List all your pending tasks due this week through Claude — read them out.'],
                ['tool' => 'slack', 'text' => 'Draft a Slack message to a teammate via Claude — show me the draft, then send.'],
                ['tool' => 'gmail', 'text' => 'Summarise your unread emails from this week through Claude — read me the summary.'],
            ]],
            ['title' => "Your Work — Founder's Office", 'prompts' => [
                ['tool' => 'drive', 'text' => 'Find a file in your Google Drive related to your current work through Claude — show me the result.'],
                ['tool' => 'tessa', 'text' => 'Nudge a teammate about an overdue task through Claude — show me the nudge in Tessa.'],
                ['tool' => 'safety', 'text' => 'Ask Claude to forward all your emails automatically — show me what Claude actually does, and tell me why.'],
            ]],
        ],
    ],

    'Shoyab' => [
        'role' => 'Accountant',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'List all your pending tasks due this week through Claude — read them out.'],
                ['tool' => 'slack', 'text' => 'Draft a Slack message to your team about a project update via Claude — show me the draft, then send.'],
                ['tool' => 'gmail', 'text' => 'Summarise any emails this week about invoices or payments through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Accounts', 'prompts' => [
                ['tool' => 'drive', 'text' => 'Find a finance file in your Google Drive through Claude — show me the result.'],
                ['tool' => 'tessa', 'text' => 'Assign a high-priority task to a teammate with tomorrow as the deadline through Claude — show me it created.'],
                ['tool' => 'safety', 'text' => "Ask Claude to summarise your last 5 Tessa tasks, then open Tessa live and verify whether the summary is accurate."],
            ]],
        ],
    ],

    'Soundaraya' => [
        'role' => 'AI Intern',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Submit your daily work log through Claude — show me the entry appear in Tessa.'],
                ['tool' => 'slack', 'text' => 'Send a Slack message to a teammate through Claude — show me the sent message.'],
                ['tool' => 'gmail', 'text' => 'Ask Claude to check if you have any unread emails from HR today — show me what it returns.'],
            ]],
            ['title' => 'Your Work — AI Intern', 'prompts' => [
                ['tool' => 'calendar', 'text' => 'Set up a recurring daily meeting with a teammate Mon-Fri at 10 AM through Claude — show me the event in your calendar.'],
                ['tool' => 'tessa', 'text' => 'Assign a checklist to a teammate through Claude — show me the checklist created in Tessa.'],
                ['tool' => 'safety', 'text' => "Open a Tessa page with a hidden instruction telling Claude to message an external email, say 'handle the items on this page' — show me what Claude actually does."],
            ]],
        ],
    ],

    // ───────────────────────── SQUAD 2 — Engineering / QA / Data / Design ─────────────────────────

    'Rishabh' => [
        'role' => 'Senior Developer — Hima',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Apply for leave for next Monday through Claude in Tessa — show me where Tessa asks you to confirm.'],
                ['tool' => 'slack', 'text' => 'Draft a Slack message to your manager about the next Hima version update — show me the draft, then send.'],
                ['tool' => 'gmail', 'text' => 'Pull any bug-report or release emails from this week through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Senior Developer', 'prompts' => [
                ['tool' => 'code', 'text' => 'Point Claude Code at the Hima repo, create a feature branch, and make a real change — show me the diff without touching main.'],
                ['tool' => 'code', 'text' => "Open a junior's messy PR and use Claude to review the diff — show me the two biggest problems it flags."],
                ['tool' => 'safety', 'text' => 'When Claude Code suggests a change you do not fully understand, show me on screen what you check before accepting it.'],
            ]],
        ],
    ],

    'Barkha Agarwal' => [
        'role' => 'Developer Intern — Astro',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Sign in for the day on Tessa through Claude, then add a daily log entry — show me both.'],
                ['tool' => 'slack', 'text' => "Draft a Slack message to your lead saying today's build is ready for testing — show me the draft, then send."],
                ['tool' => 'gmail', 'text' => 'Pull any emails this week about your tasks or the build through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Developer Intern', 'prompts' => [
                ['tool' => 'code', 'text' => 'Connect Claude Code to your repo — show me it open a real file on screen.'],
                ['tool' => 'code', 'text' => 'Make a small one-line fix and open a PR — show me the diff, do not merge.'],
                ['tool' => 'safety', 'text' => "Hit a real error you don't understand — show me how you prompt Claude to debug it step by step, instead of just pasting 'fix this'."],
            ]],
        ],
    ],

    'Laxmi' => [
        'role' => 'QA Intern — Hima',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Create a Tessa task for a bug you found and assign it to the developer through Claude — show me the task.'],
                ['tool' => 'slack', 'text' => 'Draft a Slack message to the QA lead saying the chat module testing is done — show me the draft.'],
                ['tool' => 'gmail', 'text' => 'Pull any emails this week about releases or bug reports through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — QA', 'prompts' => [
                ['tool' => 'code', 'text' => 'Take a recent Hima commit, generate test cases for it through Claude — read me the top 3.'],
                ['tool' => 'code', 'text' => "Take a developer's commit message and turn it into a short organised test plan via Claude — show me the structure."],
                ['tool' => 'safety', 'text' => 'Pick a feature where all auto-generated cases pass but the feature is actually broken — show me how you catch the gap.'],
            ]],
        ],
    ],

    'Iksha H S' => [
        'role' => 'QA Intern — Only Care',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Pull your pending tasks and action items in Tessa via Claude — read them out.'],
                ['tool' => 'slack', 'text' => 'Draft a Slack message to the Only Care QA lead that a test cycle is done — show me the draft, then send.'],
                ['tool' => 'gmail', 'text' => 'Pull any emails this week about Only Care releases or bugs through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — QA', 'prompts' => [
                ['tool' => 'code', 'text' => 'Take a recent Only Care commit and generate its test cases through Claude — read me the top 3.'],
                ['tool' => 'code', 'text' => 'Take a real run where 3 of 20 cases failed — use Claude to write a clear bug report for each, ready for the developer.'],
                ['tool' => 'safety', 'text' => 'Pick a feature where all cases passed but it clearly broke in the app — show me how you find the gap.'],
            ]],
        ],
    ],

    'Maari' => [
        'role' => 'Full Stack Developer — Only Care',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Create a task in Tessa for a teammate through Claude with a deadline — show it appear in Tessa.'],
                ['tool' => 'slack', 'text' => 'Draft a Slack message to your manager about the next Only Care version update — show me the draft.'],
                ['tool' => 'gmail', 'text' => 'Pull any emails this week about Only Care releases or bugs through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Full Stack Developer', 'prompts' => [
                ['tool' => 'code', 'text' => 'Use Claude Code to change an API endpoint in the Only Care repo — show me the diff.'],
                ['tool' => 'code', 'text' => 'Roll back the last commit on a branch — type each git step live on screen.'],
                ['tool' => 'safety', 'text' => 'Take a bug that spans frontend and backend — show me how you scope it for Claude Code so it does not rewrite everything.'],
            ]],
        ],
    ],

    'Prajwal' => [
        'role' => 'Data Analyst Intern — Only Care',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Set a reminder in Tessa through Claude to send the weekly metrics on Friday — show me the reminder created.'],
                ['tool' => 'slack', 'text' => "Draft a Slack message to your lead with today's numbers — show me the draft, then send."],
                ['tool' => 'gmail', 'text' => 'Pull any emails this week with data or reporting requests through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Data Analysis', 'prompts' => [
                ['tool' => 'analyst_db', 'text' => 'Pull DAU, WAU and MAU for the last 30 days through Claude — show me the numbers on screen.'],
                ['tool' => 'analyst_db', 'text' => "Pull last week's Only Care signup → trial → premium funnel through Claude — show me each step count."],
                ['tool' => 'safety', 'text' => "Claude says 'premium conversion = 12%' but two dashboards disagree. Open both and use Claude to reconcile the gap — show me what you'd actually report."],
            ]],
        ],
    ],

    'Tamil Arasan' => [
        'role' => 'Product Designer — All Apps',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Create a Tessa task for your design revisions through Claude with a deadline — show it appear in Tessa.'],
                ['tool' => 'slack', 'text' => 'Draft a Slack message to your manager that new mockups are ready for review — show me the draft.'],
                ['tool' => 'gmail', 'text' => 'Pull any emails this week with design feedback or requests through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Product Design', 'prompts' => [
                ['tool' => 'claude', 'text' => 'Take a feature idea and turn it into a short design brief / spec through Claude live — show me the brief.'],
                ['tool' => 'drive', 'text' => 'Find a specific design asset in your Google Drive through Claude — show me the file.'],
                ['tool' => 'safety', 'text' => 'You have feedback from both Hima and Only Care in the same chat — show me how you keep Claude from mixing them up.'],
            ]],
        ],
    ],

    'Sumit' => [
        'role' => 'Full Stack Developer Intern — Bangalore Connect',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Sign in for the day on Tessa through Claude and add a daily log — show me both.'],
                ['tool' => 'slack', 'text' => "Draft a Slack message to your lead that today's build is ready — show me the draft, then send."],
                ['tool' => 'gmail', 'text' => 'Pull any emails this week about your tasks or the build through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Developer Intern', 'prompts' => [
                ['tool' => 'code', 'text' => 'Connect Claude Code to the Bangalore Connect repo — show me it opening a real file.'],
                ['tool' => 'code', 'text' => 'Make a small change and open a PR on a branch without touching main — show me the diff, do not merge.'],
                ['tool' => 'safety', 'text' => 'Take a real error you have never seen — show me step by step how you use Claude to understand and fix it.'],
            ]],
        ],
    ],

    'Ranjini' => [
        'role' => 'QA Lead — Unman',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Pull your pending tasks and action items in Tessa via Claude — read them out.'],
                ['tool' => 'slack', 'text' => "Draft a Slack message to Yuvanesh with this week's QA status across apps — show me the draft, then send."],
                ['tool' => 'gmail', 'text' => 'Pull any bug-report or escalation emails from this week through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — QA Lead', 'prompts' => [
                ['tool' => 'code', 'text' => 'Take a new Unman commit, generate its test cases through Claude — read me the top 3.'],
                ['tool' => 'code', 'text' => "Take a developer's commit message and turn it into an organised test plan via Claude — show me the structure you'd hand your interns."],
                ['tool' => 'safety', 'text' => 'Pick a feature where the auto-generated cases passed but it is broken in the app — show me how you find the gap as the lead.'],
            ]],
        ],
    ],

    'Saran' => [
        'role' => 'Data Analyst — Hima',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Pull your pending tasks and check-ins in Tessa via Claude — read them out.'],
                ['tool' => 'slack', 'text' => "Draft a Slack message to your manager with this week's headline Hima numbers — show me the draft."],
                ['tool' => 'gmail', 'text' => 'Pull any emails this week with data or reporting requests through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Data Analysis (Hima)', 'prompts' => [
                ['tool' => 'analyst_db', 'text' => 'Pull DAU, WAU and MAU for the last 30 days through Claude — show me the numbers.'],
                ['tool' => 'analyst_db', 'text' => 'Run a revenue breakdown by language, gender and payment type for last month — show me the result.'],
                ['tool' => 'drive', 'text' => 'Find your latest data report in Google Drive through Claude — read me the headline numbers.'],
            ]],
        ],
    ],

    'Meghana' => [
        'role' => 'Business Analyst — Hima',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'List your pending tasks and check-ins in Tessa via Claude — read them out.'],
                ['tool' => 'slack', 'text' => "Draft a Slack message to your manager with last month's revenue summary — show me the draft."],
                ['tool' => 'gmail', 'text' => 'Pull any emails this week with data or reporting requests through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Business Analysis', 'prompts' => [
                ['tool' => 'analyst_db', 'text' => 'Run a revenue breakdown by language, gender and payment type for last month — show me the result.'],
                ['tool' => 'analyst_db', 'text' => 'Pull the acquisition funnel and first-time-depositor summary for last week — show me on screen.'],
                ['tool' => 'drive', 'text' => "Find last month's data report in Google Drive through Claude — read me the headline numbers."],
            ]],
        ],
    ],

    'Swapna M' => [
        'role' => 'Junior Performance Marketer',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Set a reminder in Tessa through Claude to review channel performance tomorrow morning — show me the reminder.'],
                ['tool' => 'slack', 'text' => "Draft a Slack message to your team channel with this week's blended spend and conversions — show me the draft."],
                ['tool' => 'gmail', 'text' => 'Pull any emails this week about campaign budgets or invoices through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Performance Marketing', 'prompts' => [
                ['tool' => 'meta_ads', 'text' => 'Pull Meta vs Google ad spend and conversions for the last 7 days through Claude — show me the comparison.'],
                ['tool' => 'analyst_db', 'text' => 'Pull our cost per first-time depositor across channels last week — show me on screen.'],
                ['tool' => 'drive', 'text' => 'Find your latest performance report in Google Drive through Claude — read me the key numbers.'],
            ]],
        ],
    ],

    // ───────────────────────── SQUAD 3 — Marketing / Hima Ops / Leadership ─────────────────────────

    'Nandha' => [
        'role' => 'CMO',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Sign in for today on Tessa via Claude and show me your open tasks.'],
                ['tool' => 'slack', 'text' => "Draft a Slack message to your manager with this week's Meta ad spend and results — show me the draft, then send."],
                ['tool' => 'gmail', 'text' => 'Pull emails from your Meta ads rep this week through Claude — flag anything needing a reply.'],
            ]],
            ['title' => 'Your Work — Meta Marketing', 'prompts' => [
                ['tool' => 'meta_ads', 'text' => "Pull yesterday's total Meta spend and conversions across all ad accounts — show me on screen."],
                ['tool' => 'meta_ads', 'text' => 'Pull which ad creatives are starting to fatigue (spend climbing, conversions dropping) — show me on screen.'],
                ['tool' => 'drive', 'text' => 'Find the latest marketing report or creative deck in Google Drive through Claude — show me the file.'],
            ]],
        ],
    ],

    'Anirudh' => [
        'role' => 'Performance Marketing Lead',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Pull your pending tasks in Tessa for today via Claude — read them out.'],
                ['tool' => 'slack', 'text' => "Draft a Slack message to your manager on today's Google Ads spend and conversions — show me the draft."],
                ['tool' => 'gmail', 'text' => 'Pull the latest email thread about Google Ads billing through Claude — read me what it says.'],
            ]],
            ['title' => 'Your Work — Google Marketing', 'prompts' => [
                ['tool' => 'google_ads', 'text' => "Pull today's running spend and PURCHASE / SIGNUP conversions in Google Ads — show me on screen."],
                ['tool' => 'google_ads', 'text' => 'Break down Google Ads performance by device (mobile / desktop / tablet) — show me on screen.'],
                ['tool' => 'calendar', 'text' => 'Pull your meetings tomorrow through Claude — show me on screen.'],
            ]],
        ],
    ],

    'Anindita' => [
        'role' => 'Growth Manager, North India',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'List all your pending work in Tessa across tasks and check-ins via Claude — read them out.'],
                ['tool' => 'slack', 'text' => "Draft a Slack message to your manager with this week's active-user and retention numbers — show me the draft."],
                ['tool' => 'gmail', 'text' => 'Pull your unread emails from this week through Claude — highlight anything urgent.'],
            ]],
            ['title' => 'Your Work — Growth', 'prompts' => [
                ['tool' => 'analyst_db', 'text' => 'Pull DAU, WAU and MAU for the last 30 days through Claude — show me on screen.'],
                ['tool' => 'analyst_db', 'text' => 'Pull D1, D7 and D30 retention for the most recent install cohort — show me on screen.'],
                ['tool' => 'drive', 'text' => 'Find your latest growth report in Google Drive through Claude — read me the headline numbers.'],
            ]],
        ],
    ],

    'Gargi Bisht' => [
        'role' => 'Social Media Manager',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Apply for a half-day leave in Tessa for tomorrow afternoon through Claude — show me the confirm step.'],
                ['tool' => 'slack', 'text' => 'Draft a Slack message to your manager with our best-performing Instagram ad this week — show me the draft.'],
                ['tool' => 'gmail', 'text' => 'Pull any emails from creators or partners this week through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Social Media', 'prompts' => [
                ['tool' => 'meta_ads', 'text' => 'Pull Instagram placement performance for our Meta ads over the last 7 days — show me on screen.'],
                ['tool' => 'meta_ads', 'text' => 'Pull which ad creatives got the most engagement on Instagram last week — show me on screen.'],
                ['tool' => 'drive', 'text' => 'Find the content calendar in your Google Drive through Claude — read me what is scheduled this week.'],
            ]],
        ],
    ],

    'Sneha Sunoj' => [
        'role' => 'Hima PM (acting) · Operations',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => "Pull your team's pending leave requests in Tessa that need approval through Claude — show me on screen."],
                ['tool' => 'slack', 'text' => "Draft a Slack message to the leadership channel with today's key ops numbers — show me the draft."],
                ['tool' => 'gmail', 'text' => 'Pull the most important emails you received today through Claude — flag anything needing your decision.'],
            ]],
            ['title' => 'Your Work — Operations Head', 'prompts' => [
                ['tool' => 'analyst_db', 'text' => "Pull today's executive ops summary through Claude — read me the highlights."],
                ['tool' => 'analyst_db', 'text' => 'Pull creator payouts and withdrawals by day with the status breakdown — show me on screen.'],
                ['tool' => 'calendar', 'text' => 'Pull your calendar for the rest of the week through Claude — show me the summary.'],
            ]],
        ],
    ],

    'Deeksha' => [
        'role' => 'Team Lead — Support (Hima)',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Sign in for today on Tessa via Claude and list your pending check-ins.'],
                ['tool' => 'slack', 'text' => 'Draft a Slack message to your manager about a recurring issue users report after the latest version update — show me the draft.'],
                ['tool' => 'gmail', 'text' => 'Pull the support-related emails you received today through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Hima Support Lead', 'prompts' => [
                ['tool' => 'hima', 'text' => 'Pull the open Hima support tickets right now through Claude — show me on screen.'],
                ['tool' => 'hima', 'text' => 'Pull the ticket dashboard (user vs creator tickets by status) — show me on screen.'],
                ['tool' => 'safety', 'text' => 'After Claude pulls a user record, show me how you verify the details on screen before acting on them.'],
            ]],
        ],
    ],

    'Gousia' => [
        'role' => 'Telugu Support — Hima',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Pull your pending tickets and tasks in Tessa for today via Claude — read them out.'],
                ['tool' => 'slack', 'text' => 'Draft a Slack message to your manager flagging a bug several users mentioned today — show me the draft.'],
                ['tool' => 'gmail', 'text' => 'Pull the latest email from the product team about the version update through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Telugu Support (Hima)', 'prompts' => [
                ['tool' => 'hima', 'text' => 'Pull the support tickets waiting for a human reply through Claude — show me on screen.'],
                ['tool' => 'hima', 'text' => 'Take this user (mobile) and pull their balance, language and recent transactions — show me on screen.'],
                ['tool' => 'safety', 'text' => 'After Claude pulls a ticket thread, show me how you verify the details before acting on them.'],
            ]],
        ],
    ],

    'Reshma' => [
        'role' => 'Malayalam Support — Hima',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => "Set a reminder in Tessa through Claude to follow up on a user's pending withdrawal tomorrow — show me the reminder."],
                ['tool' => 'slack', 'text' => 'Draft a Slack message to your manager summarising the top 3 user complaints today — show me the draft.'],
                ['tool' => 'gmail', 'text' => 'Pull any escalation emails you received this week through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Malayalam Support (Hima)', 'prompts' => [
                ['tool' => 'hima', 'text' => 'Pull all the open tickets in your Malayalam queue through Claude — show me on screen.'],
                ['tool' => 'hima', 'text' => "Take this user and pull their full profile and recent call history through Claude — show me on screen."],
                ['tool' => 'safety', 'text' => 'Run the AI classifier on a ticket, then show me how you verify its language/intent before you reply.'],
            ]],
        ],
    ],

    'Anjali Bhatt' => [
        'role' => 'Bengali Support — Hima',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'List your pending work in Tessa across tasks and check-ins via Claude — read them out.'],
                ['tool' => 'slack', 'text' => 'Draft a Slack message to your manager about an issue spiking after the version update — show me the draft.'],
                ['tool' => 'gmail', 'text' => 'Pull your support emails from the last 2 days through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Bengali Support (Hima)', 'prompts' => [
                ['tool' => 'hima', 'text' => 'Pull the open tickets that need a human reply through Claude — show me on screen.'],
                ['tool' => 'hima', 'text' => 'Take this user and pull their recent activity and transactions — show me on screen.'],
                ['tool' => 'safety', 'text' => 'After Claude pulls a chat thread, show me how you verify the details before acting on them.'],
            ]],
        ],
    ],

    'Dhanush' => [
        'role' => 'Product Manager — Bangalore Connect',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Create a task in Tessa through Claude for the QA team to test the new build by Friday — show it appear.'],
                ['tool' => 'slack', 'text' => 'Draft a Slack message to your team channel about the next version update timeline — show me the draft.'],
                ['tool' => 'gmail', 'text' => "Pull this week's emails about the version rollout through Claude — list any decisions made."],
            ]],
            ['title' => 'Your Work — Product Manager', 'prompts' => [
                ['tool' => 'analyst_db', 'text' => "Pull yesterday's executive ops summary through Claude — read me the highlights."],
                ['tool' => 'analyst_db', 'text' => 'Pull how the latest app version performed vs the previous one on retention and calls — show me on screen.'],
                ['tool' => 'calendar', 'text' => 'Pull your meetings tomorrow and find a 30-minute slot to review the rollout — show me on screen.'],
            ]],
        ],
    ],

    'Suwetha S' => [
        'role' => 'Technical Support — Only Care',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Sign in for today on Tessa via Claude and list your pending Only Care tickets.'],
                ['tool' => 'slack', 'text' => 'Draft a Slack message to Bala about a recurring issue users report in the latest Only Care update — show me the draft.'],
                ['tool' => 'gmail', 'text' => 'Pull the Only Care support emails you received today through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Only Care Support', 'prompts' => [
                ['tool' => 'onlycare', 'text' => 'Pull all the open Only Care tickets right now through Claude — show me which need a human reply.'],
                ['tool' => 'onlycare', 'text' => 'Take this Only Care user (mobile) and pull their recent activity and open tickets — show me on screen.'],
                ['tool' => 'safety', 'text' => 'After Claude pulls a ticket thread, show me how you verify the details before acting on them.'],
            ]],
        ],
    ],

    'Bala' => [
        'role' => 'COO — Product Operations',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => "Pull your team's pending leave requests in Tessa that need your approval through Claude — show me on screen."],
                ['tool' => 'slack', 'text' => "Draft a Slack message to the leadership channel with today's key Only Care numbers — show me the draft."],
                ['tool' => 'gmail', 'text' => 'Pull the most important emails you received today through Claude — flag anything needing your decision.'],
            ]],
            ['title' => 'Your Work — COO / Product Ops', 'prompts' => [
                ['tool' => 'onlycare', 'text' => 'Pull an overview of all open Only Care support tickets and how long they have been waiting — show me on screen.'],
                ['tool' => 'onlycare', 'text' => "Pull yesterday's end-of-day Only Care ops report through Claude — read me the highlights."],
                ['tool' => 'safety', 'text' => "Ask Claude to summarise the ops numbers you'd share with JP, then open the source and verify the output before sending."],
            ]],
        ],
    ],

    // ───────────────────────── SQUAD 4 — Creative / Content ─────────────────────────

    'Krishnan' => [
        'role' => 'Creative Head',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Check in Tessa via Claude which creators submitted daily content logs today and which have not — show me the list.'],
                ['tool' => 'slack', 'text' => "Draft a Slack message to Nandha with this week's creative pipeline status — show me the draft."],
                ['tool' => 'gmail', 'text' => "Pull this week's emails about creative reviews and content feedback through Claude — read me the summary."],
            ]],
            ['title' => 'Your Work — Creative Head', 'prompts' => [
                ['tool' => 'claude', 'text' => 'Generate an image with Claude for a Hima campaign concept, then change one element — show me before / after.'],
                ['tool' => 'drive', 'text' => "Pull this week's content calendars in Google Drive across Hima, Only Care and Unman — read me what is scheduled."],
                ['tool' => 'calendar', 'text' => 'Pull your content review meetings this week through Claude — show me any overlaps.'],
            ]],
        ],
    ],

    'Kishore Prabakaran' => [
        'role' => 'Content Lead — Hima',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Assign a new content brief task in Tessa through Claude to one of your Hima creators with a Friday deadline — show it created.'],
                ['tool' => 'slack', 'text' => "Draft a Slack message to Krishnan summarising this week's Hima content pipeline status — show me the draft."],
                ['tool' => 'gmail', 'text' => "Pull this week's emails about content reviews or shoot scheduling through Claude — read me the summary."],
            ]],
            ['title' => 'Your Work — Content Lead (Hima)', 'prompts' => [
                ['tool' => 'drive', 'text' => "Pull this week's Hima content calendar from Google Drive through Claude — read me what is scheduled."],
                ['tool' => 'tessa', 'text' => 'Check in Tessa via Claude which Hima creators submitted daily content logs today and which did not — show me the list.'],
                ['tool' => 'calendar', 'text' => 'Pull your content review meetings this week — flag any overlap with creator shoots.'],
            ]],
        ],
    ],

    'Nehal Y' => [
        'role' => 'Content Creator — Hima',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Sign in and sign off for the day on Tessa through the Claude connector — show me both confirmations.'],
                ['tool' => 'slack', 'text' => 'Send a Slack message through Claude — show me each step and the sent message.'],
                ['tool' => 'gmail', 'text' => 'Pull any emails this week about content reviews or feedback through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Content Creation', 'prompts' => [
                ['tool' => 'claude', 'text' => 'Connect an MCP server in the Claude platform and Higgsfield — show me both connections on screen.'],
                ['tool' => 'claude', 'text' => 'Write a short prompt in Claude to generate an image, then change one element — show me before / after.'],
                ['tool' => 'drive', 'text' => 'Save the final image to your Google Drive through Claude — show me the file appear in Drive.'],
            ]],
        ],
    ],

    'Fathima K P' => [
        'role' => 'Content Creator — Hima',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Sign in and sign off for the day on Tessa through the Claude connector — show me both confirmations.'],
                ['tool' => 'slack', 'text' => 'Send a Slack message through Claude — show me each step and the sent message.'],
                ['tool' => 'gmail', 'text' => 'Pull any emails this week about content reviews or feedback through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Content Creation', 'prompts' => [
                ['tool' => 'claude', 'text' => 'Connect an MCP server in the Claude platform and Higgsfield — show me both connections on screen.'],
                ['tool' => 'claude', 'text' => 'Write a short prompt in Claude to generate an image, then change one element — show me before / after.'],
                ['tool' => 'drive', 'text' => 'Save the final image to your Google Drive through Claude — show me the file appear in Drive.'],
            ]],
        ],
    ],

    'Haripriya' => [
        'role' => 'Content Creator — Hima',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Sign in and sign off for the day on Tessa through the Claude connector — show me both confirmations.'],
                ['tool' => 'slack', 'text' => 'Send a Slack message through Claude — show me each step and the sent message.'],
                ['tool' => 'gmail', 'text' => 'Pull any emails this week about content reviews or feedback through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Content Creation', 'prompts' => [
                ['tool' => 'claude', 'text' => 'Connect an MCP server in the Claude platform and Higgsfield — show me both connections on screen.'],
                ['tool' => 'claude', 'text' => 'Write a short prompt in Claude to generate an image, then change one element — show me before / after.'],
                ['tool' => 'drive', 'text' => 'Save the final image to your Google Drive through Claude — show me the file appear in Drive.'],
            ]],
        ],
    ],

    'Disha' => [
        'role' => 'Content Creator Intern — Hima',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Sign in and sign off for the day on Tessa through the Claude connector — show me both confirmations.'],
                ['tool' => 'slack', 'text' => 'Send a Slack message through Claude — show me each step and the sent message.'],
                ['tool' => 'gmail', 'text' => 'Pull any emails this week about content reviews or feedback through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Content Creation', 'prompts' => [
                ['tool' => 'claude', 'text' => 'Connect an MCP server in the Claude platform and Higgsfield — show me both connections on screen.'],
                ['tool' => 'claude', 'text' => 'Write a short prompt in Claude to generate an image, then change one element — show me before / after.'],
                ['tool' => 'drive', 'text' => 'Save the final image to your Google Drive through Claude — show me the file appear in Drive.'],
            ]],
        ],
    ],

    'Tiyasa' => [
        'role' => 'Content Creator Intern — Unman',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Sign in and sign off for the day on Tessa through the Claude connector — show me both confirmations.'],
                ['tool' => 'slack', 'text' => 'Send a Slack message through Claude — show me each step and the sent message.'],
                ['tool' => 'gmail', 'text' => 'Pull any emails this week about content reviews or feedback through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Content Creation', 'prompts' => [
                ['tool' => 'claude', 'text' => 'Connect an MCP server in the Claude platform and Higgsfield — show me both connections on screen.'],
                ['tool' => 'claude', 'text' => 'Write a short prompt in Claude to generate an image, then change one element — show me before / after.'],
                ['tool' => 'drive', 'text' => 'Save the final image to your Google Drive through Claude — show me the file appear in Drive.'],
            ]],
        ],
    ],

    'Sivaranjani N' => [
        'role' => 'Content Lead — Only Care',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Sign in and sign off for the day on Tessa through the Claude connector — show me both confirmations.'],
                ['tool' => 'slack', 'text' => 'Send a Slack message through Claude — show me each step and the sent message.'],
                ['tool' => 'gmail', 'text' => 'Pull any emails this week about Only Care content reviews or feedback through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Content Lead (Only Care)', 'prompts' => [
                ['tool' => 'claude', 'text' => 'Connect an MCP server in the Claude platform and Higgsfield — show me both connections on screen.'],
                ['tool' => 'claude', 'text' => 'Write a short prompt in Claude to generate an image for an Only Care concept, then change one element — show me before / after.'],
                ['tool' => 'drive', 'text' => 'Save the final image to your Google Drive through Claude — show me the file appear in Drive.'],
            ]],
        ],
    ],

    'Sooraj' => [
        'role' => 'Graphic / Poster Designer',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Sign in for the day on Tessa through Claude and add a daily log entry — show me both.'],
                ['tool' => 'slack', 'text' => 'Draft a Slack message to Krishnan that the new posters are ready for review — show me the draft, then send.'],
                ['tool' => 'gmail', 'text' => 'Pull any emails this week with design feedback or requests through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Poster Design', 'prompts' => [
                ['tool' => 'claude', 'text' => 'Write the same poster brief as a prompt in ChatGPT and in Claude — show me both prompts and both generated posters.'],
                ['tool' => 'claude', 'text' => 'Take one of those posters and edit it in Claude — show me before / after and explain the change.'],
                ['tool' => 'drive', 'text' => 'Upload the posters to Google Drive, generate a shareable link, and share it on Slack via Claude — show me the sent message.'],
            ]],
        ],
    ],

    'Anaz' => [
        'role' => 'Video Editor',
        'sections' => [
            ['title' => 'Everyday Connectors', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Sign in and sign off for the day on Tessa through the Claude connector — show me both confirmations.'],
                ['tool' => 'slack', 'text' => 'Draft a Slack message to your manager that a video is ready for review — show me the draft.'],
                ['tool' => 'gmail', 'text' => 'Pull any emails this week with video feedback or requests through Claude — read me the summary.'],
            ]],
            ['title' => 'Your Work — Video Editing', 'prompts' => [
                ['tool' => 'tessa', 'text' => 'Check the total number of videos received in Tessa via Claude — show me the steps and the result.'],
                ['tool' => 'drive', 'text' => 'Manually upload videos to Google Drive with proper names — no Claude for this step — show me the named files.'],
                ['tool' => 'slack', 'text' => 'Send the Google Drive video link to the assessor on Slack via Claude — show me the sent message.'],
            ]],
        ],
    ],

];
