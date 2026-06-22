# Slack Huddle Notes Auto-Sync — Deployment Guide

Last updated: April 2026

---

## What This Does

When your team has a Slack huddle with "AI Notes" enabled, Slack generates meeting notes automatically. This feature auto-fetches those notes and writes them into the correct Tessa meeting notes — matching by timing (±30 min) and attendee overlap. Zero manual work.

---

## How Matching Works

```
Slack Huddle at 2:45 PM with Sneha, Bala, Nandha
        ↓
Tessa checks: any meeting scheduled between 2:15 PM - 3:15 PM?
        ↓
Found: "Ops Weekly" at 2:30 PM with attendees [Sneha, Bala, Nandha, JP]
        ↓
Match! Overlap: 3 attendees, time diff: 15 min
        ↓
Auto-writes huddle notes to "Ops Weekly" meeting notes
```

**Matching criteria:**
- Huddle timestamp within **±30 minutes** of scheduled meeting time
- At least **1 attendee overlap** (names matched via fuzzy search: first name / full name / contains)
- If multiple meetings match → picks highest attendee overlap, then closest time
- Also checks `<@U12345>` Slack user IDs via `users.slack_user_id` if present

**Won't overwrite manual notes:**
- If someone already wrote notes manually → huddle notes are **appended** below a separator
- If already synced (same huddle) → **skipped** (no duplicates)
- Deduplication tracked via `<!-- slack-huddle-sync:FILE_ID -->` marker in content

---

## Deployment Steps

### Step 1: Deploy Code

```bash
git pull origin feature/slack-huddle-notes-auto-sync
```

No new migrations — uses existing `meeting_notes` table.

### Step 2: Clear Config Cache

```bash
php artisan config:clear
php artisan route:clear
```

### Step 3: Set Up Cron (IMPORTANT — Required for All Scheduled Jobs)

Laravel's scheduler needs a **single cron entry** on the server. If you already have this, skip this step.

Add to server crontab (`crontab -e`):

```cron
* * * * * cd /path/to/tessa && php artisan schedule:run >> /dev/null 2>&1
```

This one cron entry runs **all** scheduled Tessa jobs. Laravel handles the individual timing internally.

### Step 4: Verify

```bash
# Test manually
php artisan slack:sync-huddle-notes

# Check routes
php artisan route:list --path=huddle-notes
```

---

## All Tessa Cron Jobs (Complete Reference)

All jobs are defined in `routes/console.php`. They all run via the single `php artisan schedule:run` cron entry.

| Command | Schedule | Timezone | What It Does |
|---------|----------|----------|-------------|
| `meetings:send-reminders` | Every minute | — | Sends Slack DM 10 min before meetings |
| `queue:work` | Every minute | — | Processes background job queue |
| `nudge:sign-in` | Weekdays 10:30 AM | IST | Slack reminder to sign in |
| `nudge:sign-in` | Weekdays 11:30 AM | IST | Second reminder to sign in |
| `nudge:sign-off` | Weekdays 6:00 PM | IST | Slack reminder to sign off |
| `nudge:sign-off` | Weekdays 7:00 PM | IST | Second reminder to sign off |
| `sync:hima-conversions` | Daily 6:00 AM | IST | Sync Hima platform conversion data |
| `revenue:fetch` | Daily 6:30 AM | IST | Fetch daily revenue payout data |
| `notify:probation-ending` | Weekdays 9:00 AM | IST | Alert HR/managers about probation endings |
| **`slack:sync-huddle-notes`** | **Every 30 min** | **IST** | **Auto-sync Slack huddle AI notes to meeting notes** |

### Commands Available But Not Scheduled (run manually or via external trigger)

| Command | What It Does |
|---------|-------------|
| `tasks:escalate-overdue` | Notify assignees/managers about overdue tasks |
| `tasks:blocker-checkin` | Smart task progress check-ins at 40% and 75% |
| `nudge:incomplete-meetings` | Remind meeting owners about missing agendas/notes |
| `tasks:create-recurring` | Auto-create recurring tasks on schedule |

---

## API Endpoints

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/slack/huddle-notes` | Fetch huddle AI notes from Slack (last 24h) |
| POST | `/api/slack/huddle-notes/sync` | Sync all huddle notes to matching meetings |
| POST | `/api/slack/huddle-notes/sync-one` | Sync a specific huddle note to its meeting |

All require Slack connection (`slack.connected` middleware).

---

## UI Location

**Sidebar → Slack → Huddle Notes tab**

- "Sync All to Meeting Notes" button at top → syncs everything at once
- "Sync to Meeting Notes" button on each card → syncs individual note
- Shows matched meeting name on success (e.g., "Synced to Ops Weekly")
- Shows reason on skip (e.g., "No matching meeting", "Already synced")

---

## Files Added / Changed

| File | What |
|------|------|
| `app/Services/SlackHuddleSyncService.php` | Core sync service (fetch, match, write) |
| `app/Console/Commands/SyncSlackHuddleNotes.php` | Artisan command for scheduled runs |
| `app/Http/Controllers/Api/Slack/SlackController.php` | Refactored huddleNotes(), added sync endpoints |
| `routes/api/slack.php` | Added sync routes |
| `routes/console.php` | Added 30-min schedule |
| `public/js/portal.js` | Added sync buttons to Huddle Notes UI |

---

## Troubleshooting

**"No Slack-connected user found"**
- At least one active user must have Slack connected (Profile → Connect Slack)
- The scheduled command uses any connected user's token

**Notes not matching to any meeting**
- Check if meeting is scheduled within ±30 min of huddle time
- Check if at least 1 huddle attendee is in the Tessa meeting's attendees list
- Attendee names must be close matches (first name or full name)

**Duplicate notes appearing**
- Should not happen — deduplication uses file ID markers
- If it does: check for `<!-- slack-huddle-sync:... -->` markers in meeting note content

**Scheduler not running**
- Verify cron is set up: `crontab -l` should show `php artisan schedule:run`
- Test manually: `php artisan slack:sync-huddle-notes`
- Check Laravel log: `storage/logs/laravel.log`

**Manual notes getting overwritten**
- By design, huddle notes are always **appended** below existing content with a separator
- They never replace what someone already wrote
