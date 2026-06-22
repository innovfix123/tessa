# Dashboard Notification Center

A tabbed notification area on the working dashboard that groups actionable items into
**Tessa**, **Slack**, **Gmail**, and (conditionally) **Leaves** tabs. The Slack and Gmail
tabs surface AI-extracted/-classified items as cards with the same set of actions
(**Add to Task / Set Reminder / Ignore / Clear**).

> Scope note: this document is derived strictly from the code in this repository. File and
> line references are included so each statement can be traced to source.

---

## 1. Architecture at a glance

```
Browser (public/js/portal.js → renderDashboard)
  Reminders section (unchanged)  ── /api/notes
  Tab bar  [Tessa] [Slack] [Gmail] (+[Leaves])
    ├─ Tessa  ── many existing /api/tessa, /api/meetings, /api/daily-reports… endpoints
    ├─ Slack  ── GET /api/slack/insights      → slack_insights         (SlackInsightsController)
    ├─ Gmail  ── GET /api/gmail/insights       → gmail_insights         (GmailInsightsController)
    └─ Leaves ── /api/leave/team-pending, /api/leave/team-on-leave-today

Background (Laravel scheduler — routes/console.php)
  slack:sync-huddle-notes  (every 10 min)  → SlackHuddleSyncService → SlackInsightsService → slack_insights
  gmail:sync-important      (every 15 min)  → GmailInsightsService                          → gmail_insights

AI: both pipelines call OpenRouter. Slack uses anthropic/claude-sonnet-4-6 (temp 0.3);
    Gmail uses TessaAIService::quickAi → anthropic/claude-3.5-haiku (temp 0.0).
```

---

## 2. Dashboard UI structure & tab logic

All UI lives in `public/js/portal.js`, function **`renderDashboard()`** (starts ~line 452).

### 2.1 Data fetch

A single `Promise.all` (~line 858) fetches every section's data. Index → variable → endpoint:

| # | Variable | Endpoint |
|---|----------|----------|
| 0 | `onLeave` | `/api/leave/team-on-leave-today` |
| 1 | `pendingLeave` | `/api/leave/team-pending` |
| 2 | `myItems` | `/api/tessa/tasks/my-action-needed` |
| 3 | `pendingNotes` | `/api/meetings/pending-notes` |
| 4 | `pendingReports` | `/api/daily-reports/pending` |
| 5 | `pendingTickets` | `/api/tickets/pending` |
| 6 | `managerReview` | `/api/manager-review` |
| 7 | `dashNotes` | `/api/notes` (Reminders) |
| 8 | `extensionItems` | `/api/tessa/tasks/extension-inbox` |
| 9 | `blockerItems` | `/api/tessa/tasks/blocker-inbox` |
| 10 | `myChecklists` | `/api/tessa/checklists?filter=mine` |
| 11 | `assignedChecklists` | `/api/tessa/checklists?filter=assigned` |
| 12 | `verificationItems` | `/api/tessa/tasks/verification-inbox` |
| 13 | `mgrNotifications` | `/api/manager-notifications` |
| 14 | `meetingInsights` | `/api/slack/insights` |
| 15 | `gmailInsights` | `/api/gmail/insights` |

Each request has a `.catch()` fallback so one failing endpoint never blanks the dashboard.

### 2.2 Layout

The final assembly (~line 1529) renders, inside `.dash-wrap` → `.dash-cols` → `.dash-main-col`:

1. `morningQuoteHtml` — daily motivational banner (unchanged).
2. `notesHtml` — the **Reminders** section (`#dashNotesSection`, "+ Add Reminder"). Kept above the tabs, unchanged.
3. `dashTabsHtml` — the tab bar.
4. `dashPanelsHtml` — one `.dash-tab-panel` per tab.

The previous right-hand `dash-side-col` (which held "On Leave Today") was removed; that card now lives in the Leaves tab.

### 2.3 Tab state & gating

- Active tab is a **module-scoped** variable `dashActiveTab` (declared at the top of the IIFE, default `'tessa'`), so it persists across dashboard re-renders.
- **Leaves tab gating:** `var hasLeaveTab = !!config.isHr || pendingLeave.length > 0 || onLeave.length > 0;` If the active tab is `leaves` but `hasLeaveTab` is false, it falls back to `tessa`.
- Tabs always present: **Tessa**, **Slack**, **Gmail**. **Leaves** only when `hasLeaveTab`.
- Tab badges: Tessa = sum of its item arrays; Slack = `meetingInsights.length`; Gmail = `gmailInsights.length`; Leaves = `pendingLeave.length`. A badge renders only when its count `> 0`.
- Builders: `dashTabBtn(key,label,count)` and `dashPanel(key,html)`; the active tab/panel get the `active` class.

### 2.4 Tab switching

```js
root.querySelectorAll('.dash-tab').forEach(function (btn) {
    btn.addEventListener('click', function () {
        dashActiveTab = btn.getAttribute('data-dashtab');
        // toggle .active on .dash-tab buttons and .dash-tab-panel[data-dashpanel]
    });
});
```

Pure show/hide — every panel is already in the DOM; switching toggles CSS classes (`.dash-tab-panel { display:none } .dash-tab-panel.active { display:block }`, `public/css/app.css`).

> Implementation detail: `renderGmailInsights(gmailInsights)` is called at assembly time but
> defined later in `renderDashboard`. This relies on strict-mode block-level function-declaration
> hoisting (verified valid).

---

## 3. The tabs

### 3.1 Tessa tab

Aggregates the existing native dashboard cards into one panel (default active):
`mgrNotifHtml + extensionHtml + blockerHtml + verificationHtml + fridayReviewHtml + checklistHtml + assigneeUpdatesHtml + myTasksHtml + pendingCardsHtml`. If all are empty, a "You're all caught up" placeholder shows. The individual card builders and their handlers were not modified — they're simply grouped under this tab.

### 3.2 Slack tab — "Suggestions from Huddles"

Rendered by the existing `meetingInsightsHtml` block (~line 1330). Insights are grouped by `(meeting_id, meeting_date)` into `.dash-mi-group` boxes; each row (`.dash-mi-row`) shows the insight **title** (click → read-only detail modal) plus actions. Markup/classes/handlers are unchanged by the notification-center work.

- Data source: `GET /api/slack/insights` (`SlackInsightsController@index`).
- Card actions per row: **Add to Task** (`.dash-mi-add-task`), **Set Reminder ▾** (`.dash-mi-snooze-toggle` + `.dash-mi-snooze-menu`), **Ignore** (`.dash-mi-ignore`).
- Per-group **Clear** (`.dash-mi-group-clear`) bulk-dismisses every row in a meeting box.

### 3.3 Gmail tab — important emails

Rendered by **`renderGmailInsights(list)`** (defined in `renderDashboard`). Three states:

1. **Not connected** (`!config.googleConnected`): a CTA — *"Connect Gmail in Profile"* — whose button (`#dashGmConnect`) calls `MeetingModule.switchView('profile')`.
2. **Connected, no insights**: "No important emails right now."
3. **Connected, with insights**: a `.dash-gm-list` of `.dash-gm-card`s. Each card shows:
   - **Subject** (`.dash-gm-title`)
   - meta line: **Sender** · **Category** chip · **Priority** chip (`dash-gm-pri--{priority}`) · **Received** (relative time via `gmailTimeAgo()`)
   - **Summary** (`.dash-gm-summary`)
   - actions: **Add to Task** (`.dash-gm-add-task`), **Set Reminder ▾** (`.dash-gm-snooze-toggle`/`.dash-gm-snooze-menu`), **Ignore** (`.dash-gm-ignore`)

Data source: `GET /api/gmail/insights` (`GmailInsightsController@index`). Styling mirrors the Slack cards via separate `.dash-gm-*` classes (so Slack handlers are untouched).

### 3.4 Leaves tab (HR + managers)

Built only when `hasLeaveTab`. Content = `pendingLeaveHtml + leaveHtml`:

- **Leave Requests** (`#dashPendingLeave`) from `pendingLeave` (`/api/leave/team-pending`) — each row has **Approve** (`.dash-leave-approve`) / **Reject** (`.dash-leave-reject`) (existing handlers, unchanged).
- **On Leave Today** card from `onLeave` (`/api/leave/team-on-leave-today`). Its previous `<aside class="dash-side-col">` wrapper was stripped so it sits inside the tab panel.

Leave functionality, calculations and permissions are unchanged — only the UI location moved.

---

## 4. User role & visibility rules

Exposed to the frontend by **`DashboardController`** into `window.__PORTAL_CONFIG`:

```php
$config['isHr']            = in_array((int) $user->id, array_map('intval', (array) config('hr_leave_alerts.user_ids', [])), true);
$config['googleConnected'] = $user->hasGoogleConnection();
```

- **`isHr`** — true for the ids in `config/hr_leave_alerts.php` (`user_ids`, e.g. Meghana 45, Akshara 61). HR always gets the Leaves tab.
- **Managers** — get the Leaves tab whenever they have leave content (`pendingLeave`/`onLeave` are themselves scoped server-side to the requester's reports), so a manager with pending team leaves sees the tab even without `isHr`.
- **Gmail ownership** — every Gmail insight is scoped to its `user_id` (the inbox owner). All `GmailInsightsController` actions resolve the row via `ownedOr404($id, $request->user()->id)`. A user can only see/act on their own Gmail insights; there is no shared/audience concept for Gmail.
- **`googleConnected`** — chooses the Gmail tab's connect-CTA vs empty/list state.

---

## 5. Gmail backend

### 5.1 `GmailInsightsService` (`app/Services/GmailInsightsService.php`)

`syncForUser(User $user, bool $dryRun = false): array` returns `{created, fetched, scanned, important[], error}`. Flow:

1. **Guard**: returns `error: 'not connected'` if `! $user->hasGoogleConnection()`.
2. **Fetch**: `GoogleUserService::forUser($user)->listMessages($max, $query)` using `config('gmail_insights.query')` and `config('gmail_insights.max_per_scan')`. Collects message ids.
3. **Dedup**: drops ids already present in `gmail_insights` for this user (so stored-important messages are never re-classified).
4. **Snippets**: `getMessageSnippets($ids)` → `{id, threadId, snippet, from, subject, date, labelIds}`.
5. **Subject exclusion**: `filterExcludedSubjects()` removes any message whose subject contains a `config('gmail_insights.exclude_subject_keywords')` keyword (case-insensitive substring) — **before** classification, so excluded mail costs no AI and can't be flagged important.
6. **Classify**: `classify()` builds one batched prompt for all remaining messages and calls `app(TessaAIService::class)->quickAi($system, $userMessage, 0.0)` (temperature **0** for deterministic, non-flapping verdicts across re-runs). Response parsed by `parseJsonArray()` (tolerates code fences / stray prose).
7. **Persist**: for each `important === true`, `GmailInsight::firstOrCreate(['user_id','gmail_message_id'], $row)`; counts only `wasRecentlyCreated`. In `--dry-run`, items are collected but not written.

`received_at` is parsed from the email `Date` header via `parseDate()` (null on failure). All `\Throwable` are caught → `error` set + `Log::warning`.

**Classification prompt (verbatim, `classify()`):**

```
You are an email triage assistant for a company work portal. You receive a numbered list of emails (sender, subject, preview). For EACH email decide if it is an important, actionable work notification that belongs on the user's dashboard.

SHOW (important=true): meeting invitations, calendar events, event updates, meeting reschedules/cancellations, client emails, follow-up requests, approval requests, project updates, security alerts, billing/payment alerts, domain or SSL expiry alerts, and important operational notifications.

DO NOT SHOW (important=false): advertisements, promotions, marketing/newsletters, sales campaigns, shopping/order blasts, social media notifications, spam, and generic bulk mail.

For EACH email return one object:
{"i": <the email's number>, "important": <true|false>, "category": "<one of: Meeting, Calendar, Client, Approval, Project, Security, Billing, Alert, Operational, Other>", "priority": "<low|medium|high|urgent>", "summary": "<one short sentence, max 120 chars, describing the action or why it matters>", "confidence": <0.0-1.0>}

Rules:
- Return ONLY a JSON array. No prose, no markdown code fences.
- Include EVERY input email exactly once, by its number.
- When unsure, prefer important=false — never surface ad/newsletter/promotional content.
- priority: urgent = security/expiry/time-critical today; high = client/approval/meeting soon; medium = normal; low = FYI.
```

The user message is `"Emails:\n" + numbered "N. From: … | Subject: … | Preview: …"` (preview truncated to 220 chars). `priority` is clamped to the allowed set, `confidence` to `[0,1]`.

### 5.2 `config/gmail_insights.php`

| Key | Value (current) | Meaning |
|-----|-----------------|---------|
| `sync_user_ids` | `[1]` (JP) | Allowlist of users the scheduler syncs. `--all` overrides to every Google-connected active user. |
| `dashboard_days` | `7` | Dashboard shows insights created within N days; older remain in `?archive=1`. |
| `query` | `newer_than:2d -category:promotions -category:social -in:chats` | Gmail search to pull candidates; pre-drops promotions/social/chats and bounds the window. |
| `max_per_scan` | `20` | Max messages fetched + classified per inbox per run. |
| `exclude_subject_keywords` | `['reimbursement']` | Subjects containing any keyword (case-insensitive substring) are dropped before classification. |

### 5.3 Console command & schedule

- `app/Console/Commands/SyncGmailImportant.php` — signature `gmail:sync-important {--user=} {--all} {--dry-run}`.
  - Targets = `--user` id, else `config('gmail_insights.sync_user_ids')`, unless `--all` = every active user with a `google_access_token`.
  - `--dry-run` prints classification without persisting.
- Schedule (`routes/console.php`): `Schedule::command('gmail:sync-important')->everyFifteenMinutes()->timezone('Asia/Kolkata')->withoutOverlapping(120);`

### 5.4 `GmailInsightsController` (`app/Http/Controllers/Api/Gmail/GmailInsightsController.php`)

Personal-only; every action scoped by `user_id`.

- `index` — dashboard list: `status IN (new,seen)`, snooze null/past, within `dashboard_days`; ordered `FIELD(priority,'urgent','high','medium','low')`, then `received_at DESC`, then `created_at DESC`, limit 50. `?archive=1` returns all.
- `scan` — `syncForUser($me)` (requires a live connection; 403 otherwise).
- `update` — `{status: seen|actioned|dismissed}`.
- `snooze` — `{until}` (`date|after:now`) → sets `snooze_until` + status `seen`.
- `createTask` — `TessaTaskService::createAndNotify($user, $user->id, subject, summary+"From: sender", priority, null)`; sets status `actioned` + `task_id`; 422 if a task already exists.
- `markAllSeen` — flips this user's `new` → `seen`.

---

## 6. Slack backend & comparison

The Slack pipeline is the blueprint the Gmail one mirrors.

- `SlackHuddleSyncService` fetches huddle notes → `SlackInsightsService::extractFromMeetingNote()` → `analyzeWithAI()` (OpenRouter, model `anthropic/claude-sonnet-4-6`, temperature `0.3`) → `persist()`.
- Constants (`app/Services/SlackInsightsService.php`): `MIN_CONTENT_CHARS = 200`, `PERSONAL_TYPES = [action_item, reminder]`, `SHARED_TYPES = [decision, follow_up]`, `ALLOWED_TYPES`, `ASSIGNEE_CONFIDENCE_THRESHOLD = 0.65` (personal items below this confidence are dropped).
- **Two audiences:** `personal` (one `user_id`, the doer) and `meeting` (shared; recipients in `audience_user_ids`, per-recipient state in `slack_insight_user_state`).

### Gmail vs Slack

| Aspect | Slack insights | Gmail insights |
|--------|----------------|----------------|
| Source | Slack huddle AI notes (`SlackHuddleSyncService`) | Gmail messages (`GoogleUserService`) |
| AI model | `anthropic/claude-sonnet-4-6`, temp 0.3 | `anthropic/claude-3.5-haiku` via `quickAi`, temp 0.0 |
| Audience | personal **and** shared (meeting) | personal only |
| Per-recipient state table | `slack_insight_user_state` | none (single owner) |
| Dedup | `source_note_hash` (+ meeting/file checks) | unique `(user_id, gmail_message_id)` + skip already-stored ids |
| Item types | action_item / reminder / follow_up / decision | category + priority (no typed actions) |
| Confidence gate | 0.65 for personal items | none (deterministic temp 0; subject exclusion + prompt) |
| Sync cadence | every 10 min (+30 min per-user) | every 15 min |
| Rollout | organic (whoever's Slack view surfaces a huddle) | allowlist `sync_user_ids` (JP only) |

---

## 7. `TessaAIService::quickAi`

`app/Services/TessaAIService.php` — `quickAi(string $systemPrompt, string $userMessage, ?float $temperature = null): string`.

- POSTs to OpenRouter (`https://openrouter.ai/api/v1/chat/completions`) with model **`anthropic/claude-3.5-haiku`**; returns `choices[0].message.content` trimmed; empty string on missing key or any exception.
- The optional **`temperature`** parameter (added for the Gmail classifier) is only included in the payload when non-null. The Gmail classifier passes `0.0` so identical inputs don't flip verdicts between the 15-minute re-runs.

---

## 8. API endpoints

All endpoints below are mounted twice: under the session-auth group `Route::middleware(['web','auth'])` (paths `/api/...`) and under the MCP group `Route::middleware(['mcp.token','throttle:120,1'])->prefix('mcp')` (paths `/api/mcp/...`) — both `require` the same route files (`routes/api.php`).

### Gmail (`routes/api/google.php`)

| Method | Path | Controller | Extra middleware |
|--------|------|------------|------------------|
| GET | `/api/gmail/insights` | `GmailInsightsController@index` | — (auth only) |
| PUT | `/api/gmail/insights/{id}` | `@update` | — |
| POST | `/api/gmail/insights/{id}/snooze` | `@snooze` | — |
| POST | `/api/gmail/insights/{id}/create-task` | `@createTask` | — |
| POST | `/api/gmail/insights/mark-all-seen` | `@markAllSeen` | — |
| POST | `/api/gmail/insights/scan` | `@scan` | `google.connected` |

Reads + actions sit **outside** the `google.connected` group (so a user who later disconnects can still clear old cards); only `scan` requires a live connection.

### Slack (`routes/api/slack.php`)

| Method | Path | Controller | Extra middleware |
|--------|------|------------|------------------|
| GET | `/api/slack/insights` | `SlackInsightsController@index` | — (auth only) |
| PUT | `/api/slack/insights/{id}` | `@update` | — |
| POST | `/api/slack/insights/{id}/create-task` | `@createTask` | — |
| POST | `/api/slack/insights/{id}/snooze` | `@snooze` | — |
| POST | `/api/slack/insights/mark-all-seen` | `@markAllSeen` | — |
| POST | `/api/slack/insights/scan` | `@scan` | `slack.connected` |

`google.connected` → `EnsureGoogleConnected` (403 `{error:'Google not connected', connect_url:'/api/google/connect'}` when `! user->hasGoogleConnection()`); `slack.connected` → `EnsureSlackConnected` (mapped in `bootstrap/app.php`).

---

## 9. Database tables

### `gmail_insights`

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | bigint unsigned | NO | PK |
| `user_id` | int | NO | inbox owner; FK → users (cascade) |
| `gmail_message_id` | varchar(64) | NO | Gmail message id |
| `gmail_thread_id` | varchar(64) | YES | |
| `subject` | varchar(255) | NO | default `''` |
| `sender` | varchar(255) | YES | raw `From` header |
| `summary` | text | YES | AI one-liner |
| `snippet` | text | YES | Gmail snippet |
| `category` | varchar(50) | YES | AI category |
| `priority` | enum(low,medium,high,urgent) | NO | default `medium` |
| `received_at` | datetime | YES | parsed `Date` header |
| `confidence_score` | decimal(3,2) | YES | |
| `status` | enum(new,seen,actioned,dismissed) | NO | default `new` |
| `snooze_until` | datetime | YES | |
| `task_id` | bigint unsigned | YES | FK → tessa_tasks (nullOnDelete) |
| `scanned_date` | date | NO | |
| `created_at`,`updated_at` | timestamp | YES | |

Indexes: PK(`id`); **unique(`user_id`,`gmail_message_id`)**; (`user_id`,`status`); (`snooze_until`); (`scanned_date`); FK index on `task_id`.
Model `App\Models\GmailInsight` — casts `received_at`/`snooze_until` → datetime, `scanned_date` → `date:Y-m-d`, `confidence_score` → `decimal:2`; relations `user()`, `task()`.

### `slack_insights`

Columns: `id`, `user_id` (int, **nullable** — null for shared rows), `type` enum(action_item,reminder,follow_up,decision,important), `title`, `summary`, `source_action_item`, `source_channel`, `source_channel_name`, `source_message_ts`, `meeting_id`, `meeting_title`, `meeting_date`, `suggested_assignee_id`, `assigned_by_user_id`, `audience` enum(personal,meeting) default `personal`, `audience_user_ids` json, `source_note_hash`, `mentioned_by`, `due_date`, `priority`, `confidence_score`, `status`, `snooze_until`, `task_id`, `scanned_date`, timestamps.
Indexes include: (`user_id`,`status`); (`user_id`,`scanned_date`); `type`; `scanned_date`; `meeting_id`; `audience`; `source_note_hash`; `snooze_until`; composite **`slack_insights_dashboard_idx`** (`user_id`,`status`,`snooze_until`).
Model `App\Models\SlackInsight` relations: `user()`, `task()`, `suggestedAssignee()`, `assignedBy()`, `meeting()` (`meeting_id` → `meetings.meeting_key`), `userStates()`.

### `slack_insight_user_state` (Slack shared rows only)

Columns: `id`, `insight_id` (FK → slack_insights, cascade), `user_id` (int), `status` enum(seen,snoozed,dismissed,actioned) default `seen`, `snooze_until`, `task_id` (FK → tessa_tasks), timestamps. **unique(`insight_id`,`user_id`)**. Gmail has no equivalent (single-owner).

---

## 10. Frontend action handlers

Slack handlers live in `renderDashboard` (~lines 1930–2210, `.dash-mi-*`); Gmail handlers immediately after (`.dash-gm-*`). Each set is bound after `root.innerHTML` is set, so it binds elements in every (possibly hidden) panel.

| Action | Slack | Gmail |
|--------|-------|-------|
| **Add to Task** | `createTaskFromInsight()` → `POST /api/slack/insights/{id}/create-task` (may send `assigned_to`); button → "Task Created" → row fades (`removeMeetingInsightItem`) | `.dash-gm-add-task` → `POST /api/gmail/insights/{id}/create-task` (`{}`) → fades (`removeGmailInsightItem`) |
| **Set Reminder** | `.dash-mi-snooze-toggle` opens `.dash-mi-snooze-menu`; presets `1h/4h/tomorrow/custom` → `POST …/snooze {until: ISO}` → row removed | `.dash-gm-snooze-toggle` / `.dash-gm-snooze-menu`; identical presets → `POST /api/gmail/insights/{id}/snooze` |
| **Ignore** | `.dash-mi-ignore` → `dismissInsightReq()` `PUT …/{id} {status:'dismissed'}` → row removed | `.dash-gm-ignore` → `PUT /api/gmail/insights/{id} {status:'dismissed'}` |
| **Clear** | `.dash-mi-group-clear` → confirm → batch `dismissInsightReq` for every row in the meeting box (`Promise.all`) | n/a — Gmail cards aren't grouped; per-card **Ignore** is the dismiss path |
| **Detail** | `.dash-mi-row-title` → `openMeetingInsightDetails()` read-only modal | n/a — fields shown inline on the card |

Removal helpers fade the element (200 ms), update the tab/group/section counts, and swap in an empty-state when the last item is removed. "Ignore"/"Clear"/snooze hide from the dashboard but keep the record (still visible under the archive/history view, `?archive=1`).

---

## 11. Permissions & security model

- **Authentication** — every endpoint is behind `['web','auth']` (session) via `routes/api.php`; the same routes are additionally exposed under `/api/mcp/*` behind `['mcp.token','throttle:120,1']`.
- **Gmail ownership** — `gmail_insights.user_id` is the inbox owner; `GmailInsightsController::ownedOr404()` 404s any cross-user access. Gmail OAuth tokens live encrypted on `users` (`google_access_token` cast `encrypted`); `GoogleUserService::forUser()` uses only that user's token and auto-refreshes. The classifier only ever runs for allow-listed, connected users.
- **Slack visibility** — `index()` returns personal rows where `user_id = me` (and `meeting_id NOT NULL`) plus shared rows whose `audience_user_ids` contains me and with no active dismiss/snooze in `slack_insight_user_state`; `insightVisibleTo()` enforces the same on writes. Insight read/action routes are intentionally open to any authenticated user (a meeting attendee may receive a shared insight without personally connecting Slack).
- **HR leave gating** — `isHr` from `config/hr_leave_alerts.php`; the leave data endpoints themselves scope rows to the requester (reports for managers, company-wide for HR). The notification center only relocates these widgets into a tab — it does not change leave permissions or calculations.

---

## 12. Known limitations & phase-1 scope

- **Gmail rollout is allow-listed to JP only** (`config/gmail_insights.php` → `sync_user_ids = [1]`). Other Google-connected users see a **connected-but-empty** Gmail tab until their id is added and the route/config cache is rebuilt (`bin/refresh-routes.sh`).
- **`exclude_subject_keywords` is global** (currently `['reimbursement']`). With the JP-only allowlist it effectively applies to JP; if the rollout widens and some users *do* want those subjects, this would need to become per-user.
- **Gmail classification has no confidence gate** (unlike Slack's 0.65). It relies on `temperature 0` determinism, the Gmail pre-filter query, the subject exclusion list, and the prompt's "prefer important=false" rule.
- **Gmail is personal-only** — no shared/"audience" model and no per-recipient state table.
- **Slack delivery is organic** — insights appear from whichever Slack-connected user's view surfaces a huddle; there is no per-user Slack allowlist equivalent to the Gmail one.
- **Cost containment** for Gmail: `newer_than:2d`, `max_per_scan ≤ 20`, one Haiku call per inbox per run, and dedup of already-stored messages; non-important messages are re-classified on later runs (cheap) but never stored.
- The separate **Google view** (`renderGoogle` — Gmail/Calendar/Drive browser) and all Slack rendering/handlers are unchanged by this feature.
