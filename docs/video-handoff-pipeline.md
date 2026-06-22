# Video Handoff Pipeline

A three-stage pipeline in **Daily Reports** that moves actual video files between
people: content creators upload raw videos → Anaz reworks them → the Content Lead
(Krishnan) views/downloads the finished versions. It replaced an earlier
"status-tag" flow (a Sent/Received radio that moved no files).

Shipped 2026-05-22.

---

## 1. The flow

```
┌──────────────────┐   raw video    ┌──────────────┐  reworked video  ┌──────────────────┐
│ Content creators │ ─────────────▶ │  Anaz (#18)  │ ───────────────▶ │ Content Lead     │
│ (Krishnan's team)│  ai_videos_    │  the reworker│   video_handoffs │ Krishnan (#20)   │
│                  │  generated     │              │                  │ view + download  │
└──────────────────┘                └──────────────┘                  └──────────────────┘
        │                                                                      ▲
        └────────── in-app notification ──────────────────────────────────────┘
                    + Slack reminders to Anaz for pending reworks
```

1. **Content creators** upload raw videos through their existing
   `ai_videos_generated` upload field in Daily Reports.
2. Those videos appear in **Anaz's** Daily Reports — one row per creator — where he
   downloads each, reworks it, and uploads the updated version(s).
3. The reworked videos appear in **Krishnan's** Daily Reports to preview/download.
4. **In-app notifications** land on Krishnan's dashboard "Team updates" panel;
   **Slack reminders** nudge Anaz about videos still pending a rework.

### Cast (DB user IDs)

| Role | Users |
|------|-------|
| Content creators | `reporting_manager_id = 20`: Tiyasa #21, Maanasi #22 (inactive), Disha #40, Haripriya #49, Kishore #51, Fathima #52, Y Nehal #56, Sivaranjani #58 |
| Reworker | Anaz #18 |
| Content Lead | Krishnan #20 |

---

## 2. Data model

### `video_handoffs` table — migration `2026_05_24_000019`

Each row is **one reworked video file** uploaded by Anaz.

| Column | Notes |
|--------|-------|
| `id` | PK |
| `raw_upload_id` | FK → `creative_uploads.id`, `cascadeOnDelete`. **Not unique** — a raw video may have several reworked versions |
| `updated_file_path` / `_name` / `_size` / `_type` | the reworked file, stored inline on the `public` disk |
| `updated_by` | FK → `users.id` (Anaz). Signed `integer` (the `users.id` convention) |
| `report_date` | denormalised from the raw upload — lets week queries / the Slack scan skip a join |
| `timestamps` | |

**Key design decisions:**

- **A "raw video" is just a `creative_uploads` row** on `field_key = 'ai_videos_generated'`. No row is created in `video_handoffs` for the pending state.
- **Status is derived, never stored.** A raw video is *updated* (green) if it has ≥ 1 `video_handoffs` row, else *pending* (red).
- **Reworked files are stored inline** on `video_handoffs` (under `storage/app/public/video_handoffs/{date}/`) — **not** as `creative_uploads` rows. A second `creative_uploads` row would corrupt the team upload-count roll-up in `CreativeUploadController::syncManagerAggregateCount()`.
- **No file is ever copied between users.** "Forwarding" is *visibility*: Anaz's view queries the creators' `creative_uploads`; Krishnan's view queries `video_handoffs`.
- `creative_uploads` has **no soft deletes** — it hard-deletes. The FK cascade removes handoff rows automatically; the orphaned reworked *blobs* are cleaned up explicitly in `CreativeUploadController::handleDelete()`.

### Retirement of the old flow — migration `2026_05_24_000020`

- Soft-deletes Anaz #18's old `videos_delivered` KPI field (Sooraj #19 shares the
  `videos_delivered` field key but is a different team — **left untouched**).
- Nulls the `choices` JSON on the content team's `ai_videos_generated` field,
  which removes the "Sent to Anaz / Not sent to Anaz" radio (the UI is
  data-driven, so no JS change was needed).
- Deletes the stale `daily_report_choice` notifications.
- `down()` restores all three.

---

## 3. Backend components

| File | Purpose |
|------|---------|
| `database/migrations/2026_05_24_000019_create_video_handoffs_table.php` | Creates the table |
| `database/migrations/2026_05_24_000020_retire_video_handoff_choices.php` | Retires the old choice-tag flow |
| `app/Models/VideoHandoff.php` | Model — `rawUpload()` + `updater()` relations |
| `app/Models/CreativeUpload.php` | Added `handoffs()` HasMany |
| `app/Services/VideoHandoffNotifier.php` | User-ID constants, `creatorIds()`, `isCreator()`, and the two notification builders |
| `app/Http/Controllers/Api/Reports/VideoHandoffController.php` | The pipeline endpoints |
| `app/Http/Controllers/Api/Marketing/CreativeUploadController.php` | Hooked to fire notifications + clean up blobs |
| `routes/api/reports.php` | Two routes |
| `app/Console/Commands/NudgePendingVideos.php` | Slack reminder command |
| `routes/console.php` | Schedule entries |

### Endpoints

Both behind auth; access is gated **by explicit user id** inside the controller
(a two-person feature — not the role-based `ProjectRoleService`).

- **`GET /api/video-handoffs?week_key=YYYY-MM-DD`** — `index()`
  - Allowed: Anaz #18, Krishnan #20 (+ JP #1 / Ayush #4 read-only); else `403`.
  - Returns one entry per content creator who has raw videos that week, each
    carrying its videos grouped by date, with derived `status` and any
    `updatedVideos`. `canEdit` is `true` only for Anaz.
- **`POST /api/video-handoffs`** — `store()`, dispatched on `action`:
  - `action=upload` — **Anaz only.** One reworked file per request (the
    frontend loops for multi-select). Validates the raw upload belongs to a
    creator, extension ∈ `mp4,mov,avi,mkv,webm`, size ≤ 100 MB.
  - `action=delete` — **Anaz only.** Deletes one reworked video (row + blob).
    This is also how "replace" works: delete the old, upload a new one.

### Notifications — `VideoHandoffNotifier`

Reuses the existing `manager_notifications` table → Krishnan's dashboard
"Team updates" panel (`manager_id = 20`). Two distinct `source` values so the
two lines for the same creator-day never overwrite each other:

| `source` | Fired when | Message |
|----------|-----------|---------|
| `video_submitted` | a creator uploads/deletes a raw `ai_videos_generated` video | `"Disha: 3 videos submitted to Anaz"` |
| `video_reworked` | Anaz uploads/deletes a reworked video | `"Anaz updated 2/3 of Disha's videos"` |

`source_ref` is the `report_date`, so each creator-day is its own bucket. A zero
count deletes the row (no stale notifications).

### Slack reminders — `videos:nudge-pending`

- Scheduled weekdays at **12:00 / 15:00 / 17:00 IST** (`routes/console.php`).
- Scans the current week (Mon → today) for creator raw videos with no handoff.
- Sends **one bundled DM to Anaz**, one line per creator:
  `"2/4 videos still pending from Disha"`.
- Resolves Anaz via `users.slack_user_id` (falls back to name lookup).
- Respects the Slack quiet window; sends nothing when there is no pending work.

---

## 4. Frontend

Files: `public/js/portal.js`, `public/css/app.css`. Shown only to Anaz (#18) and
Krishnan (#20), keyed on the logged-in user.

### The table — one row per creator

Inside the Daily Reports table (`dr-table`), a **"VIDEO HANDOFFS"** group with
**one row per content creator** — the same shape as the "Videos Delivered" row.
Each day cell is a compact **`N videos ▼`** button (reusing `dr-up-cell-btn`, so
it is visually identical to the "AI Videos Generated" cell), or `—` when empty.

- Anaz's cells count that creator's **raw** videos per day.
- Krishnan's cells count that creator's **reworked** videos per day; creators who
  have delivered nothing that week are hidden from his view.

### The slide-over panel

Clicking a cell opens a slide-over panel (reusing `dr-upload-panel`) for that
**creator + day**, titled e.g. *"Disha — Mon, 18 May"*. The videos render as a
**tile grid** (like the AI Videos Generated upload cards), each tile tinted by
status — **red = pending, green = updated** (like the My Documents tiles). There
is no status pill; the tile colour *is* the status.

- **Anaz's tiles** (raw videos): play preview, "Download raw", the reworked
  versions listed inside, and a **"+ Updated video"** multi-file upload. Deleting
  the last reworked version flips the tile back to red.
- **Krishnan's tiles** (reworked videos only): play preview + download.

After an upload/delete the panel re-fetches and re-renders in place — it stays
open and the tile colour flips live.

### Video preview modal

`openVideoModal()` plays videos in-browser with an HTML5 `<video>` player.
`mp4 / webm / mov` play inline; other containers (`avi / mkv / wmv`) degrade to a
download prompt. Every tile and the modal also offer a direct download link.

---

## 5. Edge cases & behaviour

- **Status is per raw video.** Red until Anaz attaches ≥ 1 reworked file, then
  green. He can attach multiple and delete them individually.
- **Creator deletes a raw video that was already reworked** — the FK cascade
  removes the handoff rows; `handleDelete()` removes the orphaned reworked blobs;
  both notifications recompute.
- **Inactive creators** (e.g. Maanasi #22) still appear if they have an in-week
  video; the UI marks them "(inactive)".
- **Date scoping** — the panel and table follow the Daily Reports week
  navigator; the Slack nudge scans the current week Mon → today. A reworked
  file always inherits the raw upload's `report_date`.
- **Upload ceiling** — the real cap is the ~50 MB nginx `client_max_body_size`;
  the app's 100 MB check just produces a friendly error before a raw 413.

---

## 6. Deployment & verification

```bash
php artisan migrate                 # runs 000019 + 000020
bin/refresh-routes.sh               # routes/ + console.php changed
# ensure storage/app/public/video_handoffs/ is owned www-data:www-data
php artisan route:list | grep video-handoffs
php artisan schedule:list | grep videos:nudge
```

Frontend assets (`portal.js`, `app.css`) are static — a browser hard-refresh
picks them up.

**Smoke test:** as a creator, upload a video on `ai_videos_generated` → a
`video_submitted` notification appears on Krishnan's dashboard. As Anaz, open the
creator's day cell → upload a reworked file → the tile flips green and a
`video_reworked` notification appears. As Krishnan, the reworked video is
listed with a working download link.
