# Tessa Desktop — How We Started, Why, and What’s Done

This document summarizes the project’s origin, goals, technical approach, implemented work, and open items. It mirrors the summary shared in project chat.

---

## Motive

- **Goal:** Ship a **cross-platform desktop app** (macOS + Windows) for Tessa that feels like a normal installed product, not “just a browser tab.”
- **Product requirement:** Reuse the **same tech direction as the I-Assist product** — Electron, React 18, TypeScript, Tailwind, Vite — so the stack stays familiar and maintainable.
- **Fidelity requirement:** The UI and behavior should **match the live Tessa web portal** — same flows, same API contracts, same visuals where possible — by **tracing the real Laravel/JS/CSS** (`portal.js`, Blade, `app.css`, controllers), **not inventing** features or data shapes.

---

## How the Approach Evolved

1. **Full React rebuild (original direction)**  
   Build a new frontend in `tessa-desktop` that talks to the existing Laravel API.

2. **Electron “web wrapper” (intermediate)**  
   A simpler shell that loaded the **existing Tessa URL** inside Electron, with tray, auto-launch, window state, etc. Faster to demo, but not a full React port.

3. **Back to full React (current direction)**  
   Rebuild the portal in React inside `tessa-desktop`, still powered by the **same backend** (session cookies, CSRF, `window.__PORTAL_CONFIG`, etc.).

So the **motive stayed**: desktop packaging + parity with Tessa; the **means** settled on **React + Electron + remote API**, with strict parity to portal code.

---

## Technical Foundation (What We Put in Place)

| Area | What we did |
|------|-------------|
| **Shell** | Electron main: `BrowserWindow`, dev vs prod load (Vite URL vs `loadFile`), tray, IPC (version, platform, reload, notifications, auto-launch). |
| **Auth / cookies** | Session cookies against the remote Laravel host: strip problematic `SameSite` on `Set-Cookie`, `webSecurity: false` where needed, Vite **proxy** for `/api` and `/__portal` with `changeOrigin` + **`cookieDomainRewrite: 'localhost'`** so dev can log in and call APIs like the browser. |
| **Config** | **`fetchPortalConfig`**: load real portal HTML (via `/__portal` in dev), parse **`window.__PORTAL_CONFIG`**; fallback **`getFallbackFeatures`** from `Role` / `PermissionSeeder` / `DashboardController` if extraction fails; store on `window.__PORTAL_CONFIG` for the app. |
| **API layer** | Axios client: relative base URL in dev (proxy), absolute `TESSA_URL` in prod; broad **`client.ts`** coverage aligned with Laravel routes. |
| **UX primitives** | Tailwind + `surface` / `brand` tokens, shared UI components, `formatTessaReply` ported from **`portal.js`** (tables, lists, etc.), **`formatDate`** fixed to **local `YYYY-MM-DD`** (not UTC `toISOString`) to match IST-style usage and server queries. |

---

## Features Implemented (React), Sourced From Real Tessa Code

- **Auth:** Login, session, logout; sidebar driven by **portal features** (and fallbacks).
- **Layout:** Sidebar with dynamic nav; custom **blue “T”** icon for Tessa to match the portal.
- **Dashboard:** Sign-in board, own vs other teams, pills/counts.
- **Meetings:** Week nav, recurrence/skip dates, list + detail, tabs (Agenda, Actions, Notes, Previous Minutes), AI helpers, create/edit modal; **Action Items** aligned with portal **table + summary** layout (not arbitrary cards).
- **Tessa AI chat:** Server-backed chats/messages, markdown matching portal, quick actions, assign task, etc.; greeting nuance noted as partly **server prompt** (`TessaAIService.php` time-appropriate greeting — needs deploy to affect live API).
- **Tasks:** Grid, filters, detail modal, threads, invite/search, optimistic send, AI thread analysis.
- **Daily Reports:** Weekly table, script/upload panels (from `renderTextareaPanel` / `renderUploadPanel` patterns), person chips, KPI-linked cells, team from portal config / employees / KPI defs as needed.
- **Team KPIs & Marketing KPIs:** Cards, week nav, person switching; KPI page uses **`kpiGroupsByPerson`** from config so people **without** KPIs show empty, not someone else’s data; chips show **names only** (no extra project line under names).
- **Calendar:** Day / week / month, mini calendar, search, now line, meetings in grid.
- **Agile:** Large single page mirroring **`agile.js`**: project bar, Sprint Board (Kanban), Backlog, Epics (with **project filter**), Velocity (visual parity with portal CSS), Squads, Guide (full doc + table styling), create modals, item detail, **11 AI actions** with loading and result modals; fixes for **empty AI JSON**, epic filtering, and markdown in modals.

---

## Problems We Solved Along the Way

- **npm / Vite / electron-vite** peer conflicts → pinned compatible versions.
- **Portal config empty in dev** → `/__portal` proxy + correct HTML fetch + robust `__PORTAL_CONFIG` string parse.
- **Dates wrong / empty data** → local `formatDate` instead of UTC ISO day.
- **KPI wrong user / empty user showing others’ KPIs** → prioritize `__PORTAL_CONFIG` `kpiGroupsByPerson` and definition loading rules.
- **Chat markdown / CSS** → parser from `portal.js` + `.tessa-reply` rules **outside** Tailwind `@layer` so tables/borders match the portal.
- **Agile** → velocity UI, guide tables, epic project filter, AI modal empty/raw JSON handling.

---

## Current State

- **Done:** Core shell, API wiring, portal config strategy, and **major features** above (Meetings through Agile), with many **parity fixes** tied to real portal behavior.
- **Deferred (planned Day 6 scope):** Remaining areas such as **Escalations, Sign Off, Releases, Scripts, Tickets, Invoices, Meta/Google Ads, Employees, Profile, Leave, Templates, Org Chart, Mission, Admin**, plus **final polish** and **EXE/DMG** builds — per sprint plan (execute when that phase starts).
- **Backend:** Laravel is largely **unchanged** except the optional **AI greeting** prompt tweak in `TessaAIService.php` (needs deployment to match portal wording everywhere).

---

## Why the App Still Uses a Server URL

The app is a **rich client** for your **existing cloud product**: users, permissions, KPIs, meetings, and AI all live on the server. The desktop app **packages the UI** and **native behaviors**; it does not replace the database or API unless you later add offline-first sync (a different product decision).

---

*Last updated from project chat summary.*
