# Agile Sprint Management — Full Implementation Plan for Tessa

> **Who is this for?** The Tessa tech team (11 members). This document explains every concept in simple terms because the team is new to Agile.

---

## What is Agile & Why Are We Doing This?

**The Problem:**
We have 11 tech members (Tech Lead, Full Stack Devs, Gen AI Devs, QA, Data Analyst) all working on different things. Without a system:
- Nobody knows what others are working on
- Big features get stuck with no visibility
- Bugs get lost or forgotten
- Leadership can't see progress without asking everyone individually
- Work gets duplicated or dropped

**The Solution — Agile (Scrum):**
Agile is a way of working where instead of planning 3 months ahead and hoping it works, you:
1. Break work into small pieces (2 weeks at a time)
2. Everyone can see what everyone else is doing (transparency)
3. You review and adjust every 2 weeks (no waiting months to find problems)

Think of it like cooking — instead of preparing a 10-course meal all at once, you cook one dish at a time, taste it, adjust seasoning, then move to the next.

---

## The Work Hierarchy: Epic > Story > Task > Bug

### What is an Epic?

**Simple explanation:** An Epic is a BIG feature or project that takes weeks or even months to finish. It's too large to complete in one sprint (2 weeks).

**Example:**
- "Build Invoice Management Module" — this is an Epic
- "Revamp the Meeting System" — this is an Epic
- "Add AI Chat to Tessa" — this is an Epic

**Why we need it:** Without Epics, big work items sit as one giant task with no way to track progress. With Epics, you can see "Invoice Module is 60% done — 6 of 10 stories completed."

**Who creates Epics?** Tech Lead or leadership. Developers usually don't create Epics — they work on Stories within them.

---

### What is a Story?

**Simple explanation:** A Story is one specific thing a user can do after you build it. It's written from the user's perspective: "As a [role], I can [do something]."

**Example (inside the "Invoice Module" Epic):**
- "As a CFO, I can approve or reject submitted invoices"
- "As an Accountant, I can upload an invoice with attachments"
- "As a CEO, I can see a dashboard of all pending invoices"

**Why we need it:** Stories break Epics into small, deliverable pieces. Each Story should be completable within one sprint (2 weeks). If it can't, it's too big — break it into smaller Stories.

**Who creates Stories?** Tech Lead, QA, or any developer. Usually discussed in a Sprint Planning meeting.

**Story has:**
- Title & description
- Acceptance criteria — "How do we know this is DONE?" (e.g., "CFO clicks approve, invoice status changes, notification sent to accountant")
- Story points — A rough size estimate (explained below)
- Priority — low, medium, high, critical
- Assignee — Who is working on this

---

### What is a Task?

**Simple explanation:** A Task is the actual technical work needed to complete a Story. One Story usually has multiple Tasks.

**Example (for the Story "CFO can approve invoices"):**
- Task 1: Create `invoice_approvals` database table
- Task 2: Build the approval API endpoint
- Task 3: Add approve/reject buttons to the frontend
- Task 4: Send Slack notification on approval

**Why we need it:** Developers think in technical steps, but Stories are written in user language. Tasks bridge that gap. They also help if multiple people work on the same Story — each person takes different Tasks.

**Who creates Tasks?** The developer assigned to the Story, or the Tech Lead during sprint planning.

---

### What is a Bug?

**Simple explanation:** A Bug is something that's broken or not working correctly in existing functionality.

**Example:**
- "Login page shows error 500 when password has special characters"
- "Daily report CSV export has wrong date format"
- "Meeting notes don't save when clicking save button"

**Why we track Bugs separately?** Bugs have extra fields that Stories don't need:
- **Severity** — How bad is it? (critical = app is down, low = minor UI issue)
- **Steps to reproduce** — Exact steps to see the bug
- **Environment** — Where does it happen? (dev, staging, production)

**Who creates Bugs?** Anyone — QA finds them during testing, developers find them while coding, or users report them.

---

### What are Story Points?

**Simple explanation:** Story Points are NOT hours. They are a rough estimate of how complex and effortful a piece of work is, relative to other work.

**Common scale (Fibonacci):** 1, 2, 3, 5, 8, 13

| Points | Meaning | Example |
|--------|---------|---------|
| 1 | Trivial — you can do it in your sleep | Fix a typo, change a button color |
| 2 | Small — straightforward, no surprises | Add a new column to a table |
| 3 | Medium — you know how to do it but it takes some effort | Build a simple CRUD API endpoint |
| 5 | Large — multiple parts, some complexity | Build a form with validation + API + database changes |
| 8 | Very large — might need research, many moving parts | Build the entire sprint board with drag-and-drop |
| 13 | Too big — should probably be broken down | If a Story is 13 points, split it into smaller Stories |

**Why we need them:** After a few sprints, we'll know our team's "velocity" — how many points we complete per sprint. If our velocity is 30, and next sprint's backlog has 45 points, we know we're overcommitting.

---

## Sprints: The Heartbeat of Agile

### What is a Sprint?

**Simple explanation:** A Sprint is a fixed time period (we'll use **2 weeks**) where the team commits to completing a set of Stories/Bugs.

**Sprint Lifecycle:**

```
Planning  -->  Active  -->  Review  -->  Closed
(Day 1)       (Day 1-14)   (Day 14)     (After review)
```

**1. Planning (Day 1 — ~1 hour meeting)**
- Team looks at the backlog (all unfinished Stories/Bugs)
- Team picks what they can realistically finish in 2 weeks
- Stories get assigned to people
- Sprint gets a Goal (one sentence describing what we want to achieve)

**Example Sprint Goal:** "Complete invoice submission and approval flow"

**2. Active (Day 1-14 — the actual work)**
- Everyone works on their assigned Stories
- Daily standup meetings (15 min max) to share progress and blockers
- Stories move across the board: Todo → In Progress → Code Review → QA → Done

**3. Review (Day 14 — ~30 min meeting)**
- Team demos what they built
- Stakeholders give feedback
- Incomplete work goes back to the backlog

**4. Closed**
- Sprint velocity is calculated (total points of completed Stories)
- Retro discussion: What went well? What didn't? What to improve?

**Why fixed 2-week cycles?** Without a deadline, work expands forever. Two weeks is short enough that nothing stays stuck for too long, but long enough to actually finish meaningful work.

---

### What is a Backlog?

**Simple explanation:** The Backlog is a prioritized list of ALL work that needs to be done but hasn't been assigned to a sprint yet. Think of it as a "to-do list for the whole project."

**Who manages it?** Tech Lead keeps it organized and prioritized. Anyone can add items to it.

**Why we need it:** Without a backlog, work requests come from everywhere (Slack, meetings, email) and get lost. The backlog is the single source of truth for "what needs to be built."

---

## The Sprint Board: Visualizing Work

### What is a Sprint Board?

**Simple explanation:** A visual board (like sticky notes on a wall) with columns. Each Story/Bug is a card that moves from left to right as work progresses.

### Board Columns (and what each means):

```
| Backlog | Todo | In Progress | Code Review | QA | Done |
```

| Column | What it means | Who moves cards here |
|--------|--------------|---------------------|
| **Backlog** | Not yet planned for any sprint. "We need to do this someday." | Anyone can add items |
| **Todo** | Planned for this sprint but nobody has started working on it yet | Moved here during Sprint Planning |
| **In Progress** | Someone is actively coding this right now | Developer moves their own card |
| **Code Review** | Code is written, waiting for another developer to review it | Developer moves it after pushing code |
| **QA** | Code is reviewed, waiting for QA to test it | Moves here after code review is approved |
| **Done** | Tested, approved, and merged. Ready for release. | QA moves it after testing passes |

**Why we need it:** Without the board, the only way to know what's happening is to ask each person. The board gives instant visibility to everyone — Tech Lead, CEO, COO can see progress at a glance.

**Rules:**
- Only move YOUR cards (unless you're Tech Lead or QA)
- If you're stuck, don't leave the card in "In Progress" silently — raise it in standup
- A card should not stay in one column for more than 2-3 days. If it does, something is wrong.

---

## Squads: Organizing the Team

### What is a Squad?

**Simple explanation:** A Squad is a small team (3-6 people) within the larger tech team. Each Squad owns a specific area of work.

**Our Suggested Squads:**

| Squad | Members | Owns |
|-------|---------|------|
| **Product Squad** | Yuvanesh (Lead), Rishabh, Perumal, Maari, Barkha + QA | Tessa core features, platform work |
| **AI Squad** | Fida, Sneha, Sanika, Saran | AI chat, script generation, ML/data features |

**Why Squads?**
- 11 people in one team is too many — standups take forever, everyone waits for everyone
- Squads can have their own sprints, their own board, their own velocity
- Each squad has a clear focus area, reducing confusion about "who works on what"
- QA (Laxmi, Raksha) can float between squads as needed

**Who manages Squads?** Tech Lead creates and manages squad composition.

---

## Velocity & Burndown: Tracking Progress

### What is Velocity?

**Simple explanation:** Velocity = how many Story Points your team completes per sprint.

**Example:**
- Sprint 1: Completed 24 points
- Sprint 2: Completed 28 points
- Sprint 3: Completed 26 points
- Average velocity: ~26 points per sprint

**Why we need it:** Velocity tells you how much work to plan for the next sprint. If your velocity is 26, don't plan 40 points — you'll fail. Plan 25-28.

**Important:** Velocity is NOT a performance metric. Don't use it to judge people. It's a planning tool. If you pressure people to increase velocity, they'll just inflate story points.

---

### What is a Burndown Chart?

**Simple explanation:** A chart that shows how much work is left in the current sprint, day by day.

```
Points |  30 *
Left   |  25   *
       |  20     *  *
       |  15          *
       |  10            *  *
       |   5                  *
       |   0                    *
       +---------------------------
         D1  D3  D5  D7  D9  D11 D14
```

- The line should go down steadily toward zero
- If it's flat for days → team is stuck on something
- If it goes UP → new work was added mid-sprint (bad practice)

**Why we need it:** It gives early warning. If we're on Day 7 and the line hasn't moved much, we know we'll miss the sprint goal and can adjust.

---

## Labels: Categorizing Work

### What are Labels?

**Simple explanation:** Color-coded tags you attach to Stories/Bugs to filter and categorize them.

**Example Labels:**
- `frontend` (blue) — UI/Vue.js work
- `backend` (green) — PHP/Laravel work
- `database` (orange) — Migration/schema changes
- `ai` (purple) — AI/ML related
- `urgent` (red) — Needs immediate attention
- `tech-debt` (gray) — Cleanup/refactoring, not a new feature

**Why we need them:** When looking at the board, you can quickly filter "show me only backend work" or "show me all urgent items." Helps with sprint planning too — you don't want a sprint that's 100% backend with no QA work.

---

## Daily Standup (Already exists in Tessa!)

Tessa already has standup meetings. In Agile, the standup format is simple — each person answers 3 questions:

1. **What did I do yesterday?**
2. **What will I do today?**
3. **Am I blocked by anything?**

**Rules:**
- 15 minutes MAX. Not a discussion meeting.
- If something needs discussion, take it offline after standup
- Stand up (literally) — it keeps it short

---

## Sprint Review & Retro (Tied to Tessa Meetings)

When a sprint ends, the system auto-creates a Meeting in Tessa for the sprint review. This reuses the existing meeting system, so discussion points, action items, and notes are all captured.

**Review** = Demo what was built (show stakeholders)
**Retro** = Team-only discussion:
- What went well? (keep doing)
- What didn't go well? (stop doing)
- What to try next sprint? (start doing)

---

## Permissions: Who Can Do What

| Action | Tech Lead | Developers (FSD/GAD/DA) | QA | CEO/COO |
|--------|-----------|------------------------|-----|---------|
| Create/manage Sprints | Yes | No | No | No |
| Create/manage Epics | Yes | No | No | No |
| Create/manage Squads | Yes | No | No | No |
| Create Stories & Bugs | Yes | Yes | Yes | No |
| Assign items to people | Yes | No | Yes | No |
| Update own items (status, move on board) | Yes | Yes | Yes | No |
| View dashboard & velocity | Yes | No | No | Yes |

**Why these restrictions?**
- **Developers** should focus on building, not managing sprints or squads
- **QA** needs to assign bugs and move items to "Done" after testing
- **CEO/COO** need visibility (dashboards) but shouldn't be moving cards around
- **Tech Lead** manages everything — sprint planning, assignments, squads

---

## Database Schema (10 tables)

### 1. `squads` — Team groupings

| Column | Type | Why |
|--------|------|-----|
| id | bigint PK | Unique identifier |
| name | string(100) | e.g., "Product Squad", "AI Squad" |
| slug | string(50), unique | URL-friendly name, e.g., "product-squad" |
| description | text, nullable | What this squad is responsible for |
| lead_user_id | FK → users, nullable | Squad leader (usually the most senior dev) |
| is_active | boolean, default true | So we can disable squads without deleting |
| timestamps | | created_at, updated_at |

### 2. `squad_members` — Who belongs to which squad

| Column | Type | Why |
|--------|------|-----|
| id | bigint PK | |
| squad_id | FK → squads | Which squad |
| user_id | FK → users | Which person |
| role_in_squad | string(32), default 'member' | 'lead' or 'member' |
| joined_at | timestamp | When they joined the squad |
| unique(squad_id, user_id) | | A person can only be in a squad once |

### 3. `sprints` — The 2-week iterations

| Column | Type | Why |
|--------|------|-----|
| id | bigint PK | |
| name | string(100) | e.g., "Sprint 1", "Sprint 2" |
| goal | text, nullable | One-sentence goal: "Complete invoice approval flow" |
| squad_id | FK → squads | Each sprint belongs to one squad |
| status | string(16) | planning → active → review → closed |
| start_date | date | When the sprint begins |
| end_date | date | When the sprint ends (usually start + 14 days) |
| velocity | smallint, nullable | Calculated after sprint closes — total completed points |
| created_by | FK → users | Who created this sprint |
| meeting_id | FK → meetings, nullable | Links to auto-created sprint review meeting |
| timestamps | | |

**Rule:** Only ONE sprint per squad can be "active" at a time.

### 4. `labels` — Tags for categorization

| Column | Type | Why |
|--------|------|-----|
| id | bigint PK | |
| name | string(50) | e.g., "frontend", "backend", "urgent" |
| color | string(7) | Hex color code, e.g., "#FF0000" for red |
| unique(name) | | No duplicate labels |

### 5. `epics` — Big features/initiatives

| Column | Type | Why |
|--------|------|-----|
| id | bigint PK | |
| title | string(255) | e.g., "Invoice Management Module" |
| description | text, nullable | Full description of what this Epic covers |
| squad_id | FK → squads, nullable | Which squad owns this |
| status | string(16) | open → in_progress → done → cancelled |
| priority | string(16) | low, medium, high, critical |
| owner_id | FK → users, nullable | Who is responsible for this Epic overall |
| target_date | date, nullable | When we aim to finish the whole Epic |
| created_by | FK → users | |
| timestamps | | |

### 6. `stories` — User stories (core work items)

| Column | Type | Why |
|--------|------|-----|
| id | bigint PK | |
| title | string(255) | e.g., "CFO can approve invoices" |
| description | text, nullable | Detailed description |
| acceptance_criteria | text, nullable | "How do we know this is DONE?" — critical for QA |
| epic_id | FK → epics, nullable | Which Epic this belongs to (can be standalone) |
| sprint_id | FK → sprints, nullable | Which sprint (null = in backlog, not yet planned) |
| assignee_id | FK → users, nullable | Who is working on this |
| reporter_id | FK → users | Who created/requested this |
| status | string(16) | backlog → todo → in_progress → code_review → qa → done |
| priority | string(16) | low, medium, high, critical |
| story_points | tinyint, nullable | Effort estimate (1, 2, 3, 5, 8, 13) |
| sort_order | int, default 0 | For drag-and-drop ordering on the board |
| created_by | FK → users | |
| timestamps | | |

### 7. `tasks` — Technical sub-tasks within Stories

| Column | Type | Why |
|--------|------|-----|
| id | bigint PK | |
| title | string(255) | e.g., "Build approval API endpoint" |
| description | text, nullable | |
| story_id | FK → stories, cascade delete | Every task belongs to a story |
| assignee_id | FK → users, nullable | |
| status | string(16) | todo → in_progress → done |
| estimated_hours | decimal(5,2), nullable | Optional time estimate |
| actual_hours | decimal(5,2), nullable | Optional time tracking |
| created_by | FK → users | |
| timestamps | | |

### 8. `bugs` — Defects

| Column | Type | Why |
|--------|------|-----|
| id | bigint PK | |
| title | string(255) | e.g., "Login fails with special characters" |
| description | text, nullable | |
| steps_to_reproduce | text, nullable | Exact steps to see the bug — critical for devs to fix it |
| epic_id | FK → epics, nullable | Optionally linked to an Epic |
| story_id | FK → stories, nullable | Optionally linked to a Story |
| sprint_id | FK → sprints, nullable | Which sprint (null = backlog) |
| assignee_id | FK → users, nullable | |
| reporter_id | FK → users | Who found the bug |
| status | string(16) | open → in_progress → fixed → verified → closed / wont_fix |
| severity | string(16) | How bad: low, medium, high, critical |
| priority | string(16) | How urgent to fix: low, medium, high, critical |
| environment | string(32), nullable | Where it happens: dev, staging, production |
| sort_order | int, default 0 | Board ordering |
| created_by | FK → users | |
| resolved_at | timestamp, nullable | When it was fixed |
| timestamps | | |

**Severity vs Priority — what's the difference?**
- **Severity** = How broken is it? (critical = app crashes, low = cosmetic issue)
- **Priority** = How soon do we fix it? (a low-severity bug on the login page may be high priority because everyone sees it)

### 9. `agile_labelables` — Connects labels to stories/bugs/epics

| Column | Type | Why |
|--------|------|-----|
| id | bigint PK | |
| label_id | FK → labels | |
| labelable_id | bigint | The ID of the story, bug, or epic |
| labelable_type | string(50) | "Story", "Bug", or "Epic" |

This is a polymorphic pivot — one table serves labels for all three types instead of needing three separate tables.

### 10. Permissions migration

Seeds the permissions table with agile-related permissions for each role (see permissions table above).

---

## API Endpoints (34 routes)

### Squad Management
| Method | URL | What it does | Who can use |
|--------|-----|-------------|-------------|
| GET | `/api/squads` | List all squads with members | All tech roles |
| POST | `/api/squads` | Create a new squad | Tech Lead |
| PUT | `/api/squads/{id}` | Update squad name/description | Tech Lead |
| POST | `/api/squads/{id}/members` | Add a member to squad | Tech Lead |
| DELETE | `/api/squads/{id}/members/{userId}` | Remove member from squad | Tech Lead |

### Sprint Management
| Method | URL | What it does | Who can use |
|--------|-----|-------------|-------------|
| GET | `/api/sprints` | List sprints (filter by squad, status) | All tech roles |
| POST | `/api/sprints` | Create a new sprint | Tech Lead |
| PUT | `/api/sprints/{id}` | Update sprint details | Tech Lead |
| POST | `/api/sprints/{id}/activate` | Start the sprint | Tech Lead |
| POST | `/api/sprints/{id}/review` | Move sprint to review phase | Tech Lead |
| POST | `/api/sprints/{id}/close` | Close sprint, calculate velocity | Tech Lead |
| GET | `/api/sprints/{id}/board` | Get board data (all cards grouped by column) | All tech roles |
| GET | `/api/sprints/{id}/burndown` | Get burndown chart data | Tech Lead, CEO, COO |

### Epic Management
| Method | URL | What it does | Who can use |
|--------|-----|-------------|-------------|
| GET | `/api/epics` | List all epics with progress % | All tech roles |
| POST | `/api/epics` | Create an epic | Tech Lead |
| GET | `/api/epics/{id}` | Epic detail with all its stories | All tech roles |
| PUT | `/api/epics/{id}` | Update epic | Tech Lead |
| DELETE | `/api/epics/{id}` | Delete epic | Tech Lead |

### Story Management
| Method | URL | What it does | Who can use |
|--------|-----|-------------|-------------|
| GET | `/api/stories` | List stories (filter by sprint, epic, assignee) | All tech roles |
| POST | `/api/stories` | Create a story | Tech Lead, Devs, QA |
| GET | `/api/stories/{id}` | Story detail with tasks | All tech roles |
| PUT | `/api/stories/{id}` | Update story | Owner or Tech Lead |
| DELETE | `/api/stories/{id}` | Delete story | Tech Lead |
| PATCH | `/api/stories/{id}/move` | Drag-and-drop on board (change status + order) | Owner or Tech Lead or QA |
| POST | `/api/stories/bulk-move` | Move multiple stories into a sprint (sprint planning) | Tech Lead |

### Bug Management
| Method | URL | What it does | Who can use |
|--------|-----|-------------|-------------|
| GET | `/api/bugs` | List bugs (filter by sprint, severity, assignee) | All tech roles |
| POST | `/api/bugs` | Report a bug | Tech Lead, Devs, QA |
| PUT | `/api/bugs/{id}` | Update bug | Owner or Tech Lead |
| PATCH | `/api/bugs/{id}/move` | Drag-and-drop on board | Owner or Tech Lead or QA |

### Dashboard & Velocity
| Method | URL | What it does | Who can use |
|--------|-----|-------------|-------------|
| GET | `/api/agile/dashboard` | Overview: active sprints, team capacity, summary | Tech Lead, CEO, COO |
| GET | `/api/agile/velocity` | Velocity chart across last N sprints | Tech Lead, CEO, COO |

### Labels
| Method | URL | What it does | Who can use |
|--------|-----|-------------|-------------|
| GET | `/api/labels` | List all labels | All tech roles |
| POST | `/api/labels` | Create a label | Tech Lead |
| DELETE | `/api/labels/{id}` | Delete a label | Tech Lead |

---

## Frontend Views

### Tab 1: Sprint Board (Default View)
The main view everyone uses daily. Shows the Kanban board with 6 columns.
- Developers drag their cards from column to column
- Cards show: title, assignee avatar, story points, priority color, labels
- Filter by: assignee, label, priority

### Tab 2: Backlog
A prioritized list of all Stories/Bugs not assigned to any sprint.
- Used during Sprint Planning to drag items into the next sprint
- Shows story points so team can gauge total effort
- Sortable by priority

### Tab 3: Epics
A list of all Epics with progress bars (e.g., "Invoice Module — 6/10 stories done — 60%").
- Click an Epic to see all its Stories
- Shows target date and whether we're on track

### Tab 4: Velocity (Tech Lead + Leadership only)
Charts showing:
- Velocity per sprint (bar chart)
- Burndown for current sprint (line chart)
- Completed vs planned points trend

### Tab 5: Squads (Tech Lead only)
- Create/edit squads
- Add/remove members
- View squad stats

---

## Service Layer: `AgileService.php`

A service class that handles business logic so controllers stay thin. Key rules it enforces:

| Rule | Why |
|------|-----|
| Only 1 active sprint per squad | You can't run two sprints at once — it defeats the purpose of focus |
| Velocity calculated on sprint close | Auto-counts total points of "Done" stories |
| Sprint review creates a Meeting | Reuses Tessa's existing meeting system for retro notes |
| Board moves validate status transitions | Can't skip from "Todo" directly to "Done" — must go through the process |
| Backlog items have no sprint_id | null sprint_id = backlog. When planned, sprint_id gets set. |

---

## Implementation Phases

### Phase 1 — Foundation (Day 1)
**What:** Write all 10 database migrations + 8 Eloquent models
**Why first:** Everything else depends on the database schema existing

### Phase 2 — Business Logic (Day 1-2)
**What:** Create `AgileService.php` + add permission helpers to `ProjectRoleService.php`
**Why:** Controllers need the service layer and permission checks to work

### Phase 3 — API Controllers (Day 2-3)
**What:** Build all 6 controllers with 34 endpoints
**Why:** The API is what the frontend talks to. Build simplest first (Squads, Labels), then complex (Sprints, Stories, Bugs, Dashboard)

### Phase 4 — Wiring (Day 3)
**What:** Register routes in `api.php`, update `DashboardController` to include agile config
**Why:** Connects everything together — frontend can now discover and call the APIs

### Phase 5 — Frontend (Day 4-5)
**What:** Build the Sprint Board, Backlog, Epics, Velocity views in `public/js/agile.js`
**Why:** This is what the team actually sees and interacts with

### Phase 6 — Polish (Day 5)
**What:** Slack notifications for sprint events, sprint review meeting auto-creation, testing
**Why:** Nice-to-haves that make the system feel complete and integrated with existing Tessa features

---

## Key Design Decisions & Why

| Decision | Why |
|----------|-----|
| **Separate `stories` and `bugs` tables** | Bugs have unique fields (severity, steps_to_reproduce, environment) that don't belong on stories. Keeping them separate keeps things clean. |
| **One active sprint per squad** | Scrum requires focus. Running two sprints simultaneously means the team is split and neither sprint gets done well. |
| **Sprint review auto-creates a Meeting** | Reuses Tessa's existing meeting infrastructure. No need to build a separate retro system — notes, action items, and discussion points are already supported. |
| **Model named `AgileTask` (not `Task`)** | Avoids naming conflicts with Laravel's internal concepts. The database table is still called `tasks`. |
| **Labels are polymorphic** | One `labels` system works for Stories, Bugs, and Epics. Instead of 3 separate tagging systems, we have one. |
| **Board status lives on the story/bug** | Simpler than a separate pivot table. A card has exactly one status at a time — that's just a column on the row. |
| **QA has assign permission** | QA needs to assign bugs to developers and move cards to "Done" after testing. Without this, QA becomes a bottleneck waiting for Tech Lead to move things. |
| **CEO/COO get dashboard only** | Leadership needs visibility, not control. They shouldn't move cards or change sprints — that's the Tech Lead's job. |
