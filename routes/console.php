<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('meetings:send-reminders')->everyMinute();
Schedule::command('queue:work --stop-when-empty --tries=2')->everyMinute()->withoutOverlapping();

Schedule::command('nudge:sign-in')->weekdays()->at('10:30')->timezone('Asia/Kolkata');
Schedule::command('nudge:sign-in')->weekdays()->at('11:30')->timezone('Asia/Kolkata');

Schedule::command('nudge:sign-off')->weekdays()->at('18:00')->timezone('Asia/Kolkata');
Schedule::command('nudge:sign-off')->weekdays()->at('19:00')->timezone('Asia/Kolkata');

// sync:hima-conversions is disabled — analytics.himaapp.in/api/conversion has been
// 403-blocked since 2026-04-28. avg_paying_amount is now computed inside
// sync:hima-paid-users from paid-registered-by-language; conversion_pct fields are
// switched to manual entry until the analytics subdomain is restored.
// Schedule::command('sync:hima-conversions')->dailyAt('06:00')->timezone('Asia/Kolkata');
Schedule::command('sync:hima-paid-users')->dailyAt('06:15')->timezone('Asia/Kolkata');
// Auto-fill Anirudh's CPA Master Sheet (external Google Sheet) from the Hima admin
// "user report" (users-report endpoint). Targets yesterday's completed row. Writes
// via OAuth (CpaSheetWriter -> GoogleUserService) — dormant-safe: no-ops until a
// writer in writer_user_ids has connected Google with write scopes (Anirudh #11,
// JP #1 fallback). Enabled 2026-06-21 once the Hima endpoint was delivered.
Schedule::command('sync:hima-cpa-sheet')->dailyAt('06:20')->timezone('Asia/Kolkata');
Schedule::command('revenue:fetch')->dailyAt('06:30')->timezone('Asia/Kolkata');
Schedule::command('revenue:sync-mission')->everyFiveMinutes()->withoutOverlapping()->timezone('Asia/Kolkata');
Schedule::command('onlycare:snapshot-revenue')->weeklyOn(1, '06:30')->timezone('Asia/Kolkata');

// Daily (not just weekdays): the command fires the day BEFORE probation ends,
// so a weekend-adjacent end date would be missed by a weekday-only schedule.
Schedule::command('notify:probation-ending')->daily()->at('09:00')->timezone('Asia/Kolkata');

// Friday Work-Quality Review reminders: primary nudge at 16:00 IST, straggler
// nudge at 17:30. The command itself skips managers who've already submitted.
Schedule::command('notify:friday-review')->fridays()->at('16:00')->timezone('Asia/Kolkata');
Schedule::command('notify:friday-review')->fridays()->at('17:30')->timezone('Asia/Kolkata');

// Persistent follow-up only inside the rating window (Fri/Sat/Sun) for managers who haven't submitted reviews.
Schedule::command('notify:review-followup')->fridays()->saturdays()->sundays()->at('10:00')->timezone('Asia/Kolkata');
Schedule::command('notify:review-followup')->fridays()->saturdays()->sundays()->at('14:00')->timezone('Asia/Kolkata');
Schedule::command('notify:review-followup')->fridays()->saturdays()->sundays()->at('18:00')->timezone('Asia/Kolkata');

// Outside the live window (Mon–Thu): nag managers who still owe ratings for past weeks.
// The form stays open on the portal until they submit.
Schedule::command('notify:overdue-review')->weekdays()->at('10:30')->timezone('Asia/Kolkata');
Schedule::command('notify:overdue-review')->weekdays()->at('15:30')->timezone('Asia/Kolkata');

// Weekly Timesheet reminders: nudge every employee who still owes their weekly
// work record (regular + overtime hours). It's mandatory and blocks sign-off
// across the Fri–Sun window, so the nudge spans it: Fri 16:30 + 18:30, then a
// Sat and Sun 11:00 follow-up. The command skips holidays, leave-takers, the
// excluded list (JP), and anyone who's already submitted for the week.
Schedule::command('notify:weekly-timesheet')->fridays()->at('16:30')->timezone('Asia/Kolkata');
Schedule::command('notify:weekly-timesheet')->fridays()->at('18:30')->timezone('Asia/Kolkata');
Schedule::command('notify:weekly-timesheet')->saturdays()->at('11:00')->timezone('Asia/Kolkata');
Schedule::command('notify:weekly-timesheet')->sundays()->at('11:00')->timezone('Asia/Kolkata');

// KPI Report — Fri–Mon nudge to managers with unfilled KPI weekly notes for the
// current week. The notes are fillable Fri→Mon; the command skips holidays,
// leave-takers, and managers whose whole team is already filled. Monday is the
// final day of the window, so it gets a morning nudge as a last call.
Schedule::command('notify:kpi-report')->fridays()->at('16:30')->timezone('Asia/Kolkata');
Schedule::command('notify:kpi-report')->saturdays()->at('11:00')->timezone('Asia/Kolkata');
Schedule::command('notify:kpi-report')->sundays()->at('11:00')->timezone('Asia/Kolkata');
Schedule::command('notify:kpi-report')->mondays()->at('10:00')->timezone('Asia/Kolkata');

// KPI Report — month-end AI summary of the previous month's weekly notes (was
// each KPI target met, and to what %). Runs on the 1st so all four Fridays are in.
Schedule::command('kpi:generate-monthly-summaries')->monthlyOn(1, '07:00')->timezone('Asia/Kolkata');

// Full sync disabled — team updates meeting notes manually.
// Note-extraction insights run alongside attendance sync (independent of MeetingNote writes).
// Schedule::command('slack:sync-huddle-notes')->everyThirtyMinutes()->timezone('Asia/Kolkata');
// Every 10 min so a finished huddle's AI notes surface on dashboards within
// the user's expected latency (target: visible ~10 min after huddle ends).
Schedule::command('slack:sync-huddle-notes --attendance-only --with-insights')->everyTenMinutes()->timezone('Asia/Kolkata');

// Per-user DM / private-group huddle sync. Each connected user's own Slack
// visibility is exercised, so 1:1 DM huddles, private groups, and private
// conversations surface even when the shared-cron user isn't in them.
// Dedup is idempotent (Slack file-id based), so the same huddle showing up
// in multiple per-user views is harmless.
Schedule::command('slack:sync-huddle-notes --per-user --attendance-only --with-insights --since=today')
    ->everyThirtyMinutes()
    ->weekdays()
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping(120);

// Gmail: fetch + AI-classify recent important emails into dashboard insights.
// Gated to the allowlist in config/gmail_insights.php (JP first). The Gmail
// query pre-filters promotions/social and bounds to newer_than:2d, and only
// new messages are classified — so AI cost stays low.
Schedule::command('gmail:sync-important')
    ->everyFifteenMinutes()
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping(120);

// Hiring: auto-detect candidates' emailed offer acceptances. Per offer-stage
// candidate, searches the HR offer-senders' Gmail (config/hiring_access.php
// offer_sender_ids) for their reply and classifies it with gemini-2.5-flash;
// a confident accept flags the candidate for "Add to Team". No-ops for inboxes
// that haven't connected Google. Idempotent (skips already-accepted), so the
// re-run cost is just the few offer-stage candidates.
Schedule::command('hiring:detect-offer-acceptances')
    ->everyFifteenMinutes()
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping(20);

Schedule::command('notes:send-reminders')->everyMinute()->timezone('Asia/Kolkata');

// Personal Calendar: morning DM of each Calendar user's all-day notes due today.
// 09:05 IST so it clears the 10pm-9am Slack quiet window (earlier = suppressed).
Schedule::command('notify:calendar-notes')->dailyAt('09:05')->timezone('Asia/Kolkata');

// Logs: scan opted-in users' own Slack messages into their Logs timeline.
// Forward-only (per-user cursor) and capped per run, so AI cost tracks the
// volume of your own messages, not the polling frequency.
Schedule::command('logs:scan-slack')
    ->everyFifteenMinutes()
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping(20);

// Recurring tasks: create due tasks every hour, nudge pending ones every 2 hours
Schedule::command('tasks:create-recurring')->hourly()->timezone('Asia/Kolkata');
Schedule::command('tasks:nudge-pending')->weekdays()->at('10:00')->timezone('Asia/Kolkata');
Schedule::command('tasks:nudge-pending')->weekdays()->at('12:00')->timezone('Asia/Kolkata');
Schedule::command('tasks:nudge-pending')->weekdays()->at('14:00')->timezone('Asia/Kolkata');
Schedule::command('tasks:nudge-pending')->weekdays()->at('16:00')->timezone('Asia/Kolkata');
Schedule::command('tasks:nudge-pending')->weekdays()->at('18:00')->timezone('Asia/Kolkata');
Schedule::command('tasks:escalate-overdue')->weekdays()->at('09:00')->timezone('Asia/Kolkata');
Schedule::command('tasks:escalate-overdue')->weekdays()->at('17:00')->timezone('Asia/Kolkata');

// Video handoff pipeline: nudge Anaz about creator videos still pending a rework.
Schedule::command('videos:nudge-pending')->weekdays()->at('12:00')->timezone('Asia/Kolkata');
Schedule::command('videos:nudge-pending')->weekdays()->at('15:00')->timezone('Asia/Kolkata');
Schedule::command('videos:nudge-pending')->weekdays()->at('17:00')->timezone('Asia/Kolkata');

// Birthday eve reminder — Slack DM the team about tomorrow's birthdays at
// 17:30 IST. Friday runs also cover Sat/Sun/Mon so the team gets advance
// notice over the weekend (cron is weekday-only). The command no-ops when
// nobody has a birthday in the lookahead window.
Schedule::command('birthdays:remind-eve')->weekdays()->at('17:30')->timezone('Asia/Kolkata');

// Compensation day notifier — DMs both employee and manager on the Sat/Sun
// the user is working a Compensate swap. Quiet on days with no swaps.
Schedule::command('notify:compensation-day')->saturdays()->sundays()->at('09:00')->timezone('Asia/Kolkata');

