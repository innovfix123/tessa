# HR Onboarding & Hiring — Feature Roadmap & Build Status

**Program:** A 9-feature initiative improving new-joiner onboarding and the Hiring/ATS pipeline in Tessa.
**Started:** 2026-06-10. **Delivery style:** one feature at a time → smoke-test → **pause for review** → next.
**Status at last update:** ALL features done. 1, 9A, 9C, 7, 8, 3, 4, 9B **shipped & verified**; 5 & 6 **built but DORMANT**
(activate by provisioning the Google service account). 9B live in manual mode (live busy/free needs the scheduler's Google connected).

> ⚠️ **Resume note:** the shipped work is **in the working tree but NOT committed** (along with some
> unrelated pre-existing edits — `LeaveService.php`, `config/manager_ratings.php`,
> `config/leave_dashboard_cc.php`, the `ranjini` migration — which are **not part of this program**).
> A fresh session will see the F1 + 9A changes on disk. Commit them before/within the next phase if desired.

---

## Recommended build order (re-sequenced from the original spec for value + risk)

| # | Feature | Status | Effort | Notes |
|---|---------|--------|--------|-------|
| 1 | **Probation Letter + probation-end notification** | ✅ Done | S | Feature 2 was ~80% pre-built; folded in here |
| 2 | **9A — Technical-round Panel Feedback box** | ✅ Done | S | |
| 3 | **9C — Interview agenda/syllabus in email** | ✅ Done | S | Server appends agenda to the technical invite; editable + stored |
| 4 | **7 — Required documents by employment type** | ✅ Done | S–M | Required Documents section in My Profile; templates pending real PDFs |
| 5 | **8 — New-joiner announcement** | ✅ Done | S–M | New `announcements` table; company-wide dashboard card, 7-day, localStorage dismiss |
| 6 | **3 — Team integration / auto-create account** | ✅ Done | M | One-step `addToTeam` (provision+create+notify) + dashboard provisioner tick |
| 7 | **4 — Locked new-joiner portal** | ✅ Done | M | Probation-long lock, phased allow-list (clone-template deferred) |
| 8 | **5 — Google Sheets sync** | 🟡 Built (dormant) | H | Code complete; activates once the service account JSON + sheet share exist |
| 9 | **6 — Google Drive upload** | 🟡 Built (dormant) | H | Code complete; same service-account dependency as #8 |
| 10 | **9B — Calendar slot picker** | ✅ Done | M–H | Clickable 1-hr slots; live busy/free from scheduler's Calendar, manual fallback |

**Critical dependency:** Features 5, 6 (and partly 9B) need Google API credentials provisioned **before** building.
Tessa today has **only per-user OAuth** (`GoogleUserService`) — **no service account** and no `google/apiclient`
composer package. Provision a service account + share the target Sheet/Drive folder with it first.

---

## ✅ Feature 1 — Probation Letter + probation-end notification (SHIPPED)

**What it does:** Adds a third letter type "Probation Letter" (issued when probation *starts*) for **Intern (15 days)**
and **Full-time (30 days)**. One day before probation ends, HR gets an **in-app + Slack** alert with a **"Release
Letter"** button that deep-links into the Letters composer prefilled for that candidate (defaults to an Offer Letter).

**Key decisions:** start-of-probation letter only (end-of-probation reuses the existing Offer Letter); intern + full-time
variants only (no freelancer); interns get a 15-day probation window written at account creation so the alert fires for them.

**Files changed:**
- `app/Services/LetterTemplateService.php` — `probation` type + `probation.intern`/`probation.fulltime` variants + field helpers; `probation_end_date` date-formatting.
- `app/Models/IssuedLetter.php` — `TYPE_PROBATION` constant.
- `resources/views/letters/probation/intern.blade.php`, `…/fulltime.blade.php` — new templates (no salary annexure).
- `resources/views/letters/_layout.blade.php` — "Probation Letter" doc title.
- `app/Http/Controllers/Api/HR/LetterController.php` — `letter_type` validation (+probation) and new `prefill(user_id)` endpoint.
- `routes/api/hr.php` — `GET /api/letters/prefill` (no `->name()` — shared with MCP group).
- `public/js/letters.js` — type label/dropdown, `composeFor` auto-variant, new `composeForUser(userId, type, category)`.
- `public/js/portal.js` — "Release Letter" button on `source==='probation_ending'` cards + click handler (`switchView('letters')` + `LettersModule.composeForUser`).
- `app/Http/Controllers/Api/ManagerNotificationController.php` — expose `source_ref` in the payload.
- `app/Console/Commands/NotifyProbationEnding.php` — rewritten: fires the **day before**, HR-only, **in-app + Slack**, exact copy, added **`--dry-run`**.
- `routes/console.php` — schedule `weekdays()` → `daily()` at 09:00 IST.
- `app/Http/Controllers/Api/HR/EmployeeController.php` — `handleCreate` intern branch sets `probation_start_date`/`probation_end_date` (+15 days), additive.

**Deploy steps done:** `bin/refresh-routes.sh` (route added). **No DB migration** (`issued_letters.letter_type` is varchar; probation columns already existed).

**Outstanding / follow-ups:**
- Existing interns created before this change have null `probation_*` → won't alert until re-created or backfilled (optional one-off backfill).
- Live Slack/in-app fanout was **not** executed (dry-run only). It fires automatically when a probation actually ends tomorrow.

---

## ✅ Feature 9A — Technical-round Panel Feedback box (SHIPPED)

**What it does:** Adds a required **"Panel Feedback"** textarea (min 50 chars) to the technical interview. The panel
records strengths/weaknesses/overall assessment and marks **✓ Accept / ✗ Reject** (mapped to existing `passed`/`failed`).
Feedback is **stored and visible to HR** — read-only in the HR-round modal and in the candidate-list row (all stages, incl. rejected).

**Files changed:**
- `database/migrations/2026_06_10_000002_add_feedback_to_candidate_interviews.php` — new `feedback` text column. **MIGRATED.**
- `app/Models/CandidateInterview.php` — `feedback` fillable.
- `app/Http/Controllers/Api/HR/HiringController.php` — `formatInterview()` serializes feedback; `setInterviewOutcome()` requires ≥50 chars on technical (422 otherwise), persists + activity-logs; `saveInterview()` accepts feedback in partial saves.
- `public/js/hiring.js` — technical-round feedback textarea + client-side 50-char guard; buttons relabeled ✓ Accept/✗ Reject; HR-round `prev` block + `candidateRow` show feedback read-only.

**Deploy steps done:** `php artisan migrate --path=database/migrations/2026_06_10_000002_add_feedback_to_candidate_interviews.php --force`. **No route/config change** (no refresh-routes).

---

## ✅ Feature 9C — Interview agenda/syllabus in email (SHIPPED)

**What it does:** the **technical**-interview invite carries an editable **"INTERVIEW AGENDA"** (Intro 5m · Technical 30m ·
Problem Solving 15m · Q&A 5m · Next Steps 5m) + **"TOPICS TO PREPARE"**. The agenda textarea is prefilled with a sensible
default; HR edits it before drafting; on **"Draft with AI"** it's **appended deterministically** to the email body (server-side,
not via the model — so it always appears verbatim). An explicitly-cleared agenda is honoured (no block added); stored on the
interview record. HR round is unchanged.

**Key decision:** agenda is appended in the **controller** after the AI writes the warm invite (rather than prompt-engineering
gemini-flash) — guarantees the block and matches exactly what HR set. `TessaAIService` left generic/untouched.

**Files changed:**
- `database/migrations/2026_06_10_000003_add_agenda_to_candidate_interviews.php` — new `agenda` text column. **MIGRATED.**
- `app/Models/CandidateInterview.php` — `agenda` fillable.
- `app/Http/Controllers/Api/HR/HiringController.php` — `draftInterviewEmail()` validates `agenda` + appends it (technical
  round, honouring explicit-clear, with `defaultInterviewAgenda()` fallback when the field is absent); `saveInterview()`
  validates + partial-saves `agenda`; `formatInterview()` serializes it.
- `public/js/hiring.js` — `openInterviewModal()` agenda textarea (technical only, prefilled with `DEFAULT_INTERVIEW_AGENDA`
  mirror), sent in `draft()` + `save()`; draft response syncs the field.

**Deploy steps done:** `php artisan migrate --path=database/migrations/2026_06_10_000003_add_agenda_to_candidate_interviews.php --force`. **No route/config change** (no refresh-routes). Hard-refresh the browser for the JS.

---

## ✅ Feature 7 — Required documents by employment type (SHIPPED)

**What it does:** a dedicated **"Required Documents"** section in My Profile, driven by employment type —
**intern → Form 11 + NDA + ESIC**, **full-time/experienced → Form 11 + NDA**, **freelancer → none**. Each row shows a
**Download Template** link (only when the blank PDF is present under `public/downloads/`), an **Upload Scanned Copy**
button, and a Required/Uploaded badge, with a "download → print → fill & sign by hand → scan → upload (no digital fill)"
note. Required docs are de-duplicated out of the general "My Documents" grid so each appears once.

**Key decisions:** the Required section **bypasses the category visibility exclusions** (Form 11 is hidden from the
intern *grid* but is required), reusing the same `upload_doc`/`delete_doc` flow + `.emp-doc-upload-btn`/`.emp-doc-del-btn`
classes (existing handlers bind automatically). The Download link is **gated on file existence** — no broken links and
no fake PDFs; it lights up when HR drops the real templates in. **No migration** (reuses existing doc columns).

**Files changed:**
- `app/Http/Controllers/Api/HR/EmployeeController.php` — `REQUIRED_DOCS_BY_TYPE` + `REQUIRED_DOC_TEMPLATES` consts,
  `requiredDocFields()` helper; `profile()` builds `required_docs` (label/uploaded/path/template_url) + de-dupes them
  from `documents`.
- `public/js/hr-portal.js` — `renderProfile()` renders the Required Documents section above My Documents.

**Templates:** `nda-template.pdf` + `esic-declaration-template.pdf` are **in place** (`public/downloads/`, supplied
2026-06-10) — those Download links are live. **Still needed:** `public/downloads/form-11-template.pdf` (shows "Template
coming soon" until added; upload works regardless).

**Deploy steps done:** none — **no route/config/DB change**. Hard-refresh the browser for the JS.

---

## ✅ Feature 8 — New-joiner announcement (SHIPPED)

**What it does:** when a new hire's account is created (either path — Add Member or Hiring onboard), a celebratory
**"🎉 New team member joined — {name} — {designation} · {dept}"** card appears on **every** employee's dashboard for
**7 days**. Dismissible per-browser (localStorage). The member already appears in the Team list (active user) via
`handleCreate`/`onboardCandidate`.

**Key decision:** new lightweight **`announcements` table** (broadcast — NOT scoped per viewer, unlike
`ManagerNotification`/`DashboardNote`). Creation is wrapped in try/catch so it never blocks a hire. Dismissal is
client-side (no dismissals table for a transient card).

**Files changed:**
- `database/migrations/2026_06_10_000004_create_announcements_table.php` — `announcements` (signed-int FKs). **MIGRATED.**
- `app/Models/Announcement.php` — `active()` scope + `announceNewJoiner()` static helper (DRY across both paths).
- `app/Http/Controllers/Api/AnnouncementController.php` — `index()` returns active announcements to everyone.
- `routes/api.php` — `GET /api/announcements` (web+auth group only; not MCP). **Route cache rebuilt.**
- `app/Http/Controllers/Api/HR/EmployeeController.php` + `…/HiringController.php` — fire `announceNewJoiner()` after
  `User::create` (Add Member + onboard).
- `public/js/portal.js` — `renderDashboard()` fetches `/api/announcements`, renders a dismissible card atop the main
  column, persists dismissal in localStorage.

**Footgun (NOT auto-handled):** `public/shared/org.js` is **hardcoded** — a new hire still needs a **manual** org-chart
entry. (Also relevant to Feature 3.)

**Deploy steps done:** migration + `bin/refresh-routes.sh`. Hard-refresh the browser for the JS.

---

## ✅ Feature 3 — Team integration / auto-create account (SHIPPED)

**What it does:** after a passed HR round, HR clicks **"➕ Add to Team Member"** → a prefilled form (role/manager/type/
start-date/designation) whose **"Save & Create Account"** runs one transactional step: generates the `@innovfix.in` login,
opens the provisioning ticket, **auto-creates the Tessa user** (pwd `12345678`, `onboarding_required`), links
`hired_user_id`, and notifies **Fida #41** (verify the auto-created login) + **Yuvanesh #34** (create Gmail+Slack) — each
with a **"✓ Mark done"** button right on their dashboard.

**Key decisions:** new `addToTeam` endpoint walks the proven forward edges in one request
(`hr_round→accepted→provisioning→offer→onboarding`) — no state-machine changes. Account-creation extracted into a shared
`createTessaAccount()` helper (reused by the legacy `onboardCandidate`). `markProvisioning` now **infers** tessa-vs-workspace
from the viewer (so the dashboard tick needs no task param) and clears that provisioner's own nudge when ticked. The legacy
`sendToTessa`/`onboard` paths remain intact for candidates already mid-pipeline.

**Files changed:**
- `app/Http/Controllers/Api/HR/HiringController.php` — `addToTeam()` (one-step), `createTessaAccount()` helper,
  `startProvisioning($notify)` + `notifyProvisioners($tessaAutoCreated)`, `markProvisioning` task-inference + nudge-clear.
- `routes/api/hiring.php` — `POST /hiring/candidates/{candidate}/add-to-team`. **Route cache rebuilt.**
- `public/js/hiring.js` — post-HR-round button → one-step form; `openOnboardModal(candidate, reload, cfg)` parameterized
  (endpoint/title/intro/saveLabel).
- `public/js/portal.js` — provisioner **"✓ Mark done"** action on `hiring_provision` dashboard cards.

**Footgun (NOT auto-handled):** the new hire still needs a **manual** `public/shared/org.js` entry. The **offer letter** is
not auto-issued by this fast path (the `offer` stage is just transitioned) — issue it from the Letters tab when needed.

**Not yet exercised end-to-end** (no live candidate-at-hr_round fixture) — transition chain uses only valid forward edges;
verify in the UI with a real candidate.

**Deploy steps done:** `bin/refresh-routes.sh`. Hard-refresh the browser for the JS.

---

## ✅ Feature 4 — Locked new-joiner portal (SHIPPED)

**What it does (per the user's clarification):** a new joiner is **locked for the whole of their probation**, with a
**phased allow-list**:
- **Profile + Checklist** — always, while locked.
- **+ Daily Reports** — unlocks once their **profile/onboarding is complete**.
- **Full access** — only when **probation ends** (HR moves `employee_status` off probation/intern).

Blocked nav shows a phase-aware toast ("Complete your profile to unlock full access." → "Full access unlocks when your
probation ends.").

**Key decisions / why:** the lock is gated on **`onboarding_required && employee_status ∈ {probation, intern}`** — chosen
over gating on probation dates because a data check showed that would wrongly lock **4 existing** probation employees,
whereas `onboarding_required` affects **0 current** users (only future new joiners). `completeOnboarding` **no longer clears
`onboarding_required`** (so the lock persists into the Daily-Reports phase and through probation); it still marks the
candidate `hired`. The lock lifts automatically when probation ends (the gate's probation check fails) — no flag-clearing
needed, so existing employees are never affected.

**Files changed:**
- `app/Http/Controllers/DashboardController.php` — phased lock + `onboardingAllowedViews` in the config payload.
- `app/Http/Controllers/Api/HR/HiringController.php` — `completeOnboarding` keeps the lock (doesn't clear the flag), message updated.
- `public/js/portal.js` — `onboardingAllowedViews` + `guardSwitchView` honours it + phase-aware toast.

**Deferred sub-item — "Clone [Similar Role]'s Profile Template":** NOT built (the user's clarification scoped Feature 4 to
the lock tiers; the clone needs a UX decision on who triggers it). **Related footgun:** once Daily Reports unlocks, a new
joiner with no `kpi_definitions` sees an empty tab — HR still clones KPI defs per the existing "clone like <peer>" process
until a self-serve clone-template is added.

**Deploy steps done:** none — **no route/config/DB change** (controller + static JS only). Hard-refresh the browser.

---

## ⏳ Remaining features — scope + approach for each

## 🟡 Features 5 & 6 — Google Sheets sync + Drive upload (BUILT — DORMANT)

> **UPDATE 2026-06-16 — approach changed to "Connect Google" (no service account).**
> Per the user, Features 5 & 6 now WRITE via a connected HR member's OAuth token (Meghana #45 /
> Akshara #61 `hr@innovfix.in`), not a service account. `GoogleDriveService` + `GoogleSheetsService`
> now resolve a writer via the new `GoogleHrWriter` (config `services.google.hr_writer_ids`, env
> `GOOGLE_HR_WRITER_IDS`, default `45,61`) → `GoogleUserService` (new `ensureChildFolder` /
> `uploadFileToFolder` + `readSheetValues` / `updateSheetRange` / `appendSheetRow`). OAuth scopes
> broadened to full `drive` + `spreadsheets` (was `drive.readonly`) in `config/services.php`.
> Drive sync is limited to **Aadhaar (front+back) / PAN / Photo** (`EmployeeController::DRIVE_SYNC_FIELDS`).
> **Remaining user steps:** (1) enable the **Drive API + Sheets API** in GCP project **1014639508362**;
> (2) HR (Meghana/Akshara) **Disconnect + Connect Google** once to grant the new write scopes.
> Diagnose/discover with **`php artisan hr:google-probe`** (read-only). The service-account text below is
> **SUPERSEDED** (kept for reference) — `HimaCpaSheetSyncService` still uses the dormant service account,
> and its 06:20 `sync:hima-cpa-sheet` cron is now **disabled** (CPA already lands in Tessa via
> `sync:hima-paid-users`, the API→DB path).
>
> **VERIFIED LIVE 2026-06-16.** Writer auto-selects the connected HR account that has the write
> scopes (Akshara `hr@innovfix.in` reconnected; Meghana still read-only) via `GoogleHrWriter::hasWriteScopes`.
> Drive: per-employee subfolder matched (exact → unique first-name) else created; files named
> `<first> <doc>.<ext>` (photo / pan card / adhar card front|back). Sheet (keyed by "Name of the
> Employee" — there is NO email column): fills Name · DOB · Mob. No. · Date Of Joining · Residence
> Address (= **permanent_address**) · Bank A/c · Bank IFSC, only when Tessa has a value; every other
> column preserved. Diagnose with `php artisan hr:google-probe`. Syncs on upload / profile-save only
> (no bulk backfill yet). Depends on the HR writer staying connected.

**Status:** code complete and dormant-safe. Both **no-op cleanly** (return false, no error) until the Google **service
account** is provisioned. Built with **raw JWT (RS256) + REST** — no `google/apiclient` dependency — mirroring
`GoogleUserService`'s style.

**To activate (USER ACTION, one-time):**
1. In GCP, create a **service account** + **JSON key**; enable the **Sheets API** + **Drive API**.
2. **Share** the master Sheet (`1aAyyJe_…`) and the Drive folder (`1NLGmXC…`) with the service account's `client_email` (Editor).
3. Drop the key at `storage/app/google/service-account.json` (gitignored) and `chown www-data:www-data`; set `GOOGLE_HR_SHARE_EMAILS`
   in `.env`; run `bin/refresh-routes.sh`.

**Feature 5 — Sheets sync (what it does):** on profile save (personal-info / bank), pushes Name/Email/Phone/Address/DOB/
Bank/IFSC/Emergency to the master sheet — **header-aware upsert keyed by email** (overlays only matched columns, preserves
the rest, appends if absent). Runs **after the response** (non-blocking, via `terminating`). *Aadhaar/PAN are images only
(no number columns) → they go to Drive, not the sheet.*

**Feature 6 — Drive upload (what it does):** on document upload (HR or self-service), uploads the scanned file to a
**per-person Drive folder** under the master folder, named `First_Last_<doc>.pdf`, sharing the folder **read-only** with
the configured HR emails. Non-blocking + find-or-create folder.

**Files added/changed:**
- `app/Services/GoogleServiceAccount.php` — JWT auth + token cache + `isConfigured()`.
- `app/Services/GoogleSheetsService.php` — `upsertEmployeeRow()`.
- `app/Services/GoogleDriveService.php` — `uploadDocument()` + per-person folder + HR share.
- `config/services.php` — `google.service_account` (json_path + sheet/folder IDs + share emails). **Config cache rebuilt.**
- `.env.example` — documented (commented) service-account vars.
- `app/Http/Controllers/Api/HR/EmployeeController.php` — `deferSheetSync()` (after personal-info/bank saves) +
  `deferDriveUpload()` (after both doc-upload paths), both no-op when unconfigured.

**Verify (post-setup):** save a profile → row upserts in the sheet (re-save updates same row, no dupe); upload a doc →
per-person folder created once, file named correctly, HR can read it. **PII caveat:** confirm sheet/folder sharing scope is
acceptable before going live. **Follow-up:** the "Could not sync… Retry" UI warning is deferred (sync currently runs
fire-and-forget after the response); add a synchronous status surface if HR wants the retry affordance.

### ➕ In-portal "Employee Records" view (SHIPPED) — TWO tabs: ESIC Sheet + Drive, in-portal
HR/leadership get an **"Employee Records"** sidebar item with **two tabs**:
- **ESIC Sheet** — the master ESIC sheet (`1aAyyJe…`) embedded inline (iframe).
- **Employee Documents** — the documents Drive folder (`1NLGmXC…`) embedded inline via Google `embeddedfolderview` —
  per-employee subfolders + files (Aadhaar/PAN/photo) shown and clickable *inside* Tessa.
Each tab has an "Open in Google ↗" link. Tabs mirror the **Archives** pattern; iframes **lazy-load** (data-src → src) so
both Google embeds don't load up front.
- *Files:* `config/hr_records.php` (viewer roles + ids — JP/Meghana/Akshara + HR/management), `DashboardController`
  (`$features[] = 'hr_records'` gate), `portal.blade.php` (`#hr_recordsView` two-tab section; Sheet/folder URLs from
  `config('services.google.service_account.*')` so they track Features 5/6), `public/js/portal.js`
  (`renderHrRecords()` tab toggle + lazy-load, wired from `switchView`). **Config cache rebuilt; view cache cleared.**
- *Verified:* `node --check` ✓, blade compiles ✓, gating (JP/Meghana visible; Fida/Laxmi hidden) ✓, Sheet/Drive IDs resolve ✓.
- *Note:* both embeds render only because the Sheet/folder are shared "anyone with the link" — which also exposes the PII to
  anyone with the link (tighten Google sharing if unacceptable). **Auto-fill writes (5/6) still need a service account** —
  the public link only powers these read-only views.

### ⏸ Paused auto-fill refinements (await user)
- **Drive scope → Aadhaar + PAN + photo only** (user-decided) and **fire sheet + drive sync on profile completion**
  (`completeOnboarding`) — coded approach ready; NOT yet applied. Hold for: (1) the user's **ESIC sheet column list**
  (mapping paused), and (2) the **write-auth** decision (service account vs connected Google account). Until both land,
  the auto-fill stays dormant; the two-tab view above works now (read-only).

---

## ✅ Feature 9B — Calendar slot picker (SHIPPED)

**What it does:** the interview modal now has a **date picker + clickable 1-hour slots** (09:00–18:00 IST). Slots are
marked **busy/free from the scheduler's own Google Calendar**; clicking a free slot sets the time. When Google isn't
connected it **falls back to manual** (all slots free + a "connect Google for live availability" note). The exact-time
`datetime-local` field stays as the bound source of truth (draft/save read it), so the picker is a convenience layer.

**Key decisions:** uses the **caller's** calendar (the scheduler/interviewer) via `GoogleUserService::getEventsForDate`
(IST minutes → slot overlap). Any calendar error degrades to manual (never blocks scheduling).

**Files changed:**
- `app/Http/Controllers/Api/HR/HiringController.php` — `calendarSlots()` + `slotLabel()`.
- `routes/api/hiring.php` — `GET /hiring/calendar-slots`. **Route cache rebuilt.**
- `public/js/hiring.js` — `openInterviewModal` date picker + slot grid wired to `when`.

**Verify:** open a technical interview → pick a date → slots render; with the scheduler's Google connected, busy blocks
show greyed; clicking a slot fills the time. **No DB change.** Hard-refresh the browser.

---

## Conventions & footguns when resuming (read before editing)

- **Route/config cache:** after editing anything in `routes/` or `config/`, run `bin/refresh-routes.sh` (prod runs cached).
- **Migrations:** run **only yours** with `php artisan migrate --path=database/migrations/<file>.php --force` — a blanket
  `migrate` would run others' pending migrations too.
- **Slack/notify fanout:** never run a `notify:*`/`nudge:*`/`birthdays:*` command without `--dry-run` first (irreversible).
- **FKs to `users.id`:** use signed `$table->integer('user_id')` — NOT bigint/unsigned (`users.id` is signed int).
- **`public/shared/org.js` is hardcoded** — new hires/exits need a manual edit (affects Features 8 & 3).
- **`.env` edits:** chown `www-data:www-data` afterward or the portal 500s.
- **Static JS** (`public/js/*.js`) is served as-is — hard-refresh the browser to pick up changes (no build step).
- **Letters:** the UI auto-discovers variants from `/api/letters/template-config`; `issued_letters.letter_type` is varchar
  (no migration for a new type). Letter routes carry **no `->name()`** (shared with the MCP group).
- **Notifications:** in-app via `ManagerNotification` (dedup on `[manager_id, team_member_id, source, source_ref]`); Slack via
  `SlackService::sendDirectMessage()`/`getUserIdByName()`. Dual-notify pattern: `HiringController::notifyProvisioners()`.
- **Hiring add-column pattern (9A):** migration → model `$fillable` → `formatInterview()` serialize → controller validate/persist
  → `hiring.js openInterviewModal`/`candidateRow`. Mirror this for **9C**.

## Verification command cheat-sheet
```bash
# PHP syntax
php -l path/to/File.php
# JS syntax (no build step)
node --check public/js/hiring.js
# Rebuild route+config cache (after routes/ or config/ edits)
bash bin/refresh-routes.sh
# Run ONLY your migration
php artisan migrate --path=database/migrations/<your_migration>.php --force
# Safe preview of a notify command
php artisan notify:probation-ending --dry-run
# Read-only checks
php artisan route:list | grep -i <route>
php artisan tinker --execute='...'   # Schema::hasColumn(...), model getFillable(), etc.
```

## Key people / ids
JP #1, Bala #2, Nandha #3, Ayush #4 (leadership, null reporting_manager). Fida #41 (Tessa-login provisioner).
Yuvanesh #34 (Gmail/Slack provisioner). HR: Meghana #45, Akshara #61. Freelance recruiters: Yashasvi #88, Rohit #89.

---

## Employee Records — Drive embed + new-hire auto-provision (2026-06-16, later)

Built on top of the Connect-Google sync above:

- **Employee Documents tab is now a single iframe of the master Drive folder** (`embeddedfolderview?id=<drive_folder_id>#grid`), mirroring the ESIC Sheet tab — replaced the old per-employee Tessa grid. See `portal.blade.php` `#hr_recordsView` drive panel; `portal.js renderHrRecords` drops the `renderHrRecordsDocs` call (the existing `iframe[data-src]` lazy-load handles it). `hr-portal.js renderHrRecordsDocs` is now dead/uncalled.
- **Access locked to JP #1, Meghana #45, Akshara #61 only** — `config/hr_records.php` `viewer_roles => []`, `viewer_ids => [1,45,61]` (role-based access removed; both tabs are PII).
- **New-hire auto-provision** — `app/Services/HrGoogleSync.php::provisionNewHire(userId)` (deferred via `terminating`) creates the hire's Drive folder (`GoogleDriveService::ensureFolderFor`) + upserts their HR-sheet row; called from `EmployeeController::handleCreate` + `HiringController::createTessaAccount` after `announceNewJoiner`. A new hire appears in Employee Records at creation, before any upload.
- **All existing active employees backfilled** — every active employee (excl. #33) now has a Drive folder (empty until they upload; docs sync in on upload). Per-user re-run via `GoogleDriveService::ensureFolderFor`.

**OPEN DECISION — PII sharing:** the master Sheet + Drive folder are shared **"anyone with the link"** (required for the iframes to render). The in-Tessa view is locked to the 3 above, but anyone holding the embed URLs can view the PII outside Tessa. Tightening Google sharing to the 3 accounts would likely break the embed (needs per-viewer Google-auth testing) — left as a deliberate decision, NOT changed.

### Resume notes / open items (state as of 2026-06-16)
- **Everything above is LIVE on disk (FPM reloaded) but UNCOMMITTED.** The working tree is ~180 files intermingled across many features, so a clean per-feature commit needs hunk-staging — do it through the normal flow; don't blanket-commit.
- **Open decision:** PII sharing (above) — tighten vs accept. Tightening likely breaks the embed; test per-viewer before committing to it.
- **Dead code to remove later:** `public/js/hr-portal.js` `renderHrRecordsDocs` (+ its `hrDocs*` state) is no longer called (the Drive tab is an iframe now). Safe to delete.
- **Tools:** `php artisan hr:google-probe` (health + discovery, read-only); `php artisan hr:link-drive-folders [--dry-run]` (link existing folders); `GoogleDriveService::ensureFolderFor($user)` (create/link one folder — used by the new-hire hook + the all-employees backfill).
- **Key config:** `services.google.hr_writer_ids` (env `GOOGLE_HR_WRITER_IDS`, default `45,61`); `services.google.service_account.{drive_folder_id=1NLGmXC…, sheet_id=1aAyyJe…, sheet_tab=Sheet1}`; `config/hr_records.php` viewers `[1,45,61]`. OAuth scopes (`services.google.oauth.scopes`) now include full `drive` + `spreadsheets`.
- **Dependency:** the sync writes via the first `hr_writer` whose Google token has Drive+Sheets WRITE scopes — currently **Akshara #61** (reconnected); Meghana #45 is read-only. If the active writer disconnects, sync pauses until someone in `hr_writer_ids` reconnects (Disconnect → Connect Google).
- **To resume:** run `php artisan hr:google-probe` to confirm the writer + folder/sheet are reachable; then continue (e.g., the PII decision, the dead-code cleanup, or the commit).
