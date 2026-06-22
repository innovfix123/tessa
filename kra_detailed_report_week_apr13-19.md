# KRA Detailed Report — Week of Apr 13–19, 2026

## How the KRA Score is Calculated

### Four Buckets

Every employee's weekly KRA is a weighted average of four buckets, each scored 0–5:

| Bucket | What it measures |
|--------|-----------------|
| **Discipline** | Daily consistency — sign-in, sign-off, KPI reports, meeting notes, task check-ins |
| **Deliverables** | Output & timeliness — tasks, tickets, action items, stories, releases, or KPI report completion as fallback |
| **Quality** | Negative signals — blocked action items, unresolved escalations |
| **Manager Review** | Manager's weekly rating (1–5) |

### Role-Based Weights

| Role | Discipline | Deliverables | Quality | Manager Review |
|------|-----------|-------------|---------|---------------|
| Default (most roles) | 22% | 42% | 21% | 15% |
| Tech Lead | 22% | 42% | 21% | 15% |
| Full Stack Developer | 17% | 47% | 21% | 15% |
| Gen AI Developer | 17% | 47% | 21% | 15% |
| QA Analyst | 22% | 38% | 25% | 15% |
| CMO | 22% | 38% | 25% | 15% |

### Fairness Rules Applied

| Rule | How it works |
|------|-------------|
| **Null buckets → 3.0 baseline** | If a bucket has no data, it gets 3.0 (neutral) instead of redistributing weight. Prevents "less tracked work = higher score" |
| **Quality = null when no signals** | No negative signals means no data, not a free 5.0. Gets 3.0 baseline |
| **Sign-off at 0.5x weight** | Missing sign-off still hurts discipline but doesn't halve it (sign-in and other subs use 1.0 weight) |
| **KPI as deliverables fallback** | For roles with no tasks/tickets/stories, daily KPI report completion becomes the deliverables score |
| **No double-penalty** | Sprint carry-over removed from quality — incomplete stories are already penalized in deliverables |

### Discipline Sub-Score Calculation

| Sub-score | Formula | Weight | Applies when |
|-----------|---------|--------|-------------|
| Sign-in | (days signed in / business days) × 5 | 1.0 | Always |
| Sign-off | (days signed off / business days) × 5 | **0.5** | Always |
| KPI Reports | (days KPI filled / business days) × 5 | 1.0 | User has KPI definitions |
| Meeting Notes | (notes written / expected meetings) × 5 | 1.0 | User owns meetings |
| Task Check-ins | (check-in days / business days) × 5 | 1.0 | User has active tasks |

**Final Discipline** = weighted average of applicable sub-scores

### Composite Formula

```
For each bucket:
  If bucket has data → use actual score
  If bucket is null  → use 3.0 baseline

Composite = (discipline × D_weight) + (deliverables × Del_weight) 
          + (quality × Q_weight) + (manager_review × MR_weight)
```

---

## Employee-by-Employee Breakdown

### #1 — Fida (Gen AI Developer) — 4.5/5

**Weights:** D=17% | Del=47% | Q=21% | MR=15%

**Discipline: 4.7**
| Sub-score | Raw | Score | Weight |
|-----------|-----|-------|--------|
| Sign-in | 5/5 days | 5.0 | 1.0 |
| Sign-off | 4/5 days (missed Wed) | 4.0 | 0.5 |
| KPI | No KPI definitions | — | — |
| Meetings | Doesn't own meetings | — | — |
| Check-ins | No active tasks | — | — |

Calculation: (5×1.0 + 4×0.5) / 1.5 = **4.7**

**Deliverables: 5.0**
- 1 ticket resolved: "Cannot connect with Slack" — created Apr 13, resolved Apr 16 (within 3-day SLA + 24h grace) → **5.0**

**Quality: null → 3.0 baseline**
- No blocked action items, no escalations, no sprint carry-overs

**Manager Review: 5.0**
- JP rated 5/5 — "well executed keep it up"

| Bucket | Score | Weight | Contribution |
|--------|-------|--------|-------------|
| Discipline | 4.7 | 0.17 | 0.80 |
| Deliverables | 5.0 | 0.47 | 2.35 |
| Quality | 3.0 | 0.21 | 0.63 |
| Manager Review | 5.0 | 0.15 | 0.75 |
| **Composite** | | | **4.5** |

---

### #2 — Deeksha (Technical Support) — 4.4/5

**Weights:** D=22% | Del=42% | Q=21% | MR=15%

**Discipline: 5.0** (Perfect)
| Sub-score | Raw | Score | Weight |
|-----------|-----|-------|--------|
| Sign-in | 5/5 | 5.0 | 1.0 |
| Sign-off | 5/5 | 5.0 | 0.5 |
| KPI | 5/5 days filled | 5.0 | 1.0 |

Calculation: (5×1 + 5×0.5 + 5×1) / 2.5 = **5.0**

**Deliverables: 5.0** (KPI fallback — no tasks/tickets assigned, KPI 5/5 used as deliverable)

**Quality: null → 3.0 baseline**

**Manager Review: 4.0** — Sneha Sunoj rated 4/5

| Bucket | Score | Weight | Contribution |
|--------|-------|--------|-------------|
| Discipline | 5.0 | 0.22 | 1.10 |
| Deliverables | 5.0 | 0.42 | 2.10 |
| Quality | 3.0 | 0.21 | 0.63 |
| Manager Review | 4.0 | 0.15 | 0.60 |
| **Composite** | | | **4.4** |

---

### #3 — Reshma (Technical Support) — 4.4/5

**Discipline: 5.0** — Sign-in 5/5, Sign-off 5/5, KPI 5/5. Perfect across all.

**Deliverables: 5.0** — KPI fallback 5/5

**Quality: null → 3.0** | **Manager Review: 4.0** (Sneha Sunoj 4/5)

Composite: (5×0.22 + 5×0.42 + 3×0.21 + 4×0.15) = **4.4**

---

### #4 — Gousia (Technical Support) — 4.3/5

**Discipline: 4.4**
| Sub-score | Raw | Score | Weight |
|-----------|-----|-------|--------|
| Sign-in | 5/5 | 5.0 | 1.0 |
| Sign-off | 2/5 | 2.0 | 0.5 |
| KPI | 5/5 | 5.0 | 1.0 |

Calculation: (5 + 1 + 5) / 2.5 = **4.4**

**Deliverables: 5.0** — KPI fallback 5/5

**Quality: null → 3.0** | **Manager Review: 4.0** (Sneha Sunoj 4/5)

Composite: (4.4×0.22 + 5×0.42 + 3×0.21 + 4×0.15) = **4.3**

---

### #5 — Sooraj (Graphic Designer) — 4.3/5

**Discipline: 5.0** — Sign-in 5/5, Sign-off 5/5, KPI 5/5. Perfect.

**Deliverables: 5.0** — KPI fallback 5/5

**Quality: null → 3.0** | **Manager Review: null → 3.0** (Nandha rated 4/5 but stored under this week's key, not last week)

Composite: (5×0.22 + 5×0.42 + 3×0.21 + 3×0.15) = **4.3**

---

### #6 — Tiyasa (Content Creator) — 4.2/5

**Discipline: 4.8**
| Sub-score | Raw | Score | Weight |
|-----------|-----|-------|--------|
| Sign-in | 5/5 | 5.0 | 1.0 |
| Sign-off | 4/5 | 4.0 | 0.5 |
| KPI | 5/5 | 5.0 | 1.0 |

Calculation: (5 + 2 + 5) / 2.5 = **4.8**

**Deliverables: 5.0** — KPI fallback 5/5

**Quality: null → 3.0** | **Manager Review: 3.0** (Krishnan 3/5)

Composite: (4.8×0.22 + 5×0.42 + 3×0.21 + 3×0.15) = **4.2**

---

### #7 — Anaz (Video Editor) — 4.1/5

**Discipline: 4.0**
| Sub-score | Raw | Score | Weight |
|-----------|-----|-------|--------|
| Sign-in | 5/5 | 5.0 | 1.0 |
| Sign-off | 0/5 | 0.0 | 0.5 |
| KPI | 5/5 | 5.0 | 1.0 |

Calculation: (5 + 0 + 5) / 2.5 = **4.0**

**Deliverables: 5.0** — KPI fallback 5/5

**Quality: null → 3.0** | **Manager Review: null → 3.0** (Nandha's review under this week's key)

Composite: (4×0.22 + 5×0.42 + 3×0.21 + 3×0.15) = **4.1**

---

### #8 — Dhanush (Product Manager) — 4.1/5

**Discipline: 4.0** — Sign-in 5/5 (5.0, w1.0), Sign-off 0/5 (0.0, w0.5), KPI 5/5 (5.0, w1.0) → (5+0+5)/2.5 = 4.0

**Deliverables: 5.0** — KPI fallback 5/5

**Quality: null → 3.0** | **Manager Review: null → 3.0** (Bala's review under this week's key)

Composite: **4.1**

---

### #9 — Shoyab (Accountant) — 4.1/5

**Discipline: 4.3**
| Sub-score | Raw | Score | Weight |
|-----------|-----|-------|--------|
| Sign-in | 5/5 | 5.0 | 1.0 |
| Sign-off | 0/5 | 0.0 | 0.5 |
| KPI | 5/5 | 5.0 | 1.0 |
| Meeting Notes | 5/5 (all Finance-Team Standup notes written) | 5.0 | 1.0 |

Calculation: (5 + 0 + 5 + 5) / 3.5 = **4.3**

**Deliverables: 5.0** — KPI fallback 5/5

**Quality: null → 3.0** | **Manager Review: null → 3.0** (Ayush hasn't reviewed)

Composite: **4.1**

---

### #10 — Swapna M (Marketing) — 4.1/5

**Discipline: 4.2** — Sign-in 5/5 (5.0, w1.0), Sign-off 1/5 (1.0, w0.5), KPI 5/5 (5.0, w1.0) → (5+0.5+5)/2.5 = 4.2

**Deliverables: 5.0** — KPI fallback 5/5

**Quality: null → 3.0** | **Manager Review: null → 3.0** (Nandha's review under this week's key)

Composite: **4.1**

---

### #11 — Tamil Arasan (Product Manager) — 4.1/5

**Discipline: 4.0** — Sign-in 5/5, Sign-off 0/5, KPI 5/5 → same as Dhanush

**Deliverables: 5.0** — KPI fallback 5/5

**Quality: null → 3.0** | **Manager Review: null → 3.0** (Bala's review under this week's key)

Composite: **4.1**

---

### #12 — Yuvanesh (Tech Lead) — 4.1/5

**Weights:** D=22% | Del=42% | Q=21% | MR=15%

**Discipline: 2.9**
| Sub-score | Raw | Score | Weight |
|-----------|-----|-------|--------|
| Sign-in | 5/5 | 5.0 | 1.0 |
| Sign-off | 3/5 | 3.0 | 0.5 |
| Meeting Notes | 3/20 (only Mon/Tue Hima Tech + Tue Data Analytics) | 0.8 | 1.0 |

Calculation: (5 + 1.5 + 0.8) / 2.5 = **2.9**

Meeting notes dragged discipline down significantly — 20 expected meetings across Hima Tech, Only Care Tech, Data Analytics, and Bangalore Connect standups. Only 3 had notes.

**Deliverables: 5.0**
- Tasks: 3/3 on-time → 5.0 (Delete branches, Tracking link, Karuna email — all completed on/before deadline)
- Tickets: 8/8 resolved within SLA → 5.0 (Daily report reassign, Slack access, CAC calculation, AI tickets, AI poster, Hindi reports, Payment refund, Onboarding)
- Average: (5.0 + 5.0) / 2 = **5.0**

**Quality: null → 3.0** | **Manager Review: 5.0** (JP 5/5 — "good work")

| Bucket | Score | Weight | Contribution |
|--------|-------|--------|-------------|
| Discipline | 2.9 | 0.22 | 0.64 |
| Deliverables | 5.0 | 0.42 | 2.10 |
| Quality | 3.0 | 0.21 | 0.63 |
| Manager Review | 5.0 | 0.15 | 0.75 |
| **Composite** | | | **4.1** |

---

### #13 — Anirudh (Marketing) — 3.8/5

**4 business days** (1 day approved leave)

**Discipline: 3.0** — Sign-in 2/4 (2.5, w1.0), Sign-off 0/4 (0.0, w0.5), KPI 4/4 (5.0, w1.0) → (2.5+0+5)/2.5 = 3.0

**Deliverables: 5.0** — KPI fallback 4/4 = 5.0

**Quality: null → 3.0** | **Manager Review: null → 3.0** (Nandha's review under this week's key)

Composite: **3.8**

---

### #14 — Laxmi (QA Analyst) — 3.8/5

**Weights:** D=22% | Del=38% | Q=25% | MR=15%

**Discipline: 3.0**
| Sub-score | Raw | Score | Weight |
|-----------|-----|-------|--------|
| Sign-in | 5/5 | 5.0 | 1.0 |
| Sign-off | 1/5 | 1.0 | 0.5 |
| KPI | 5/5 | 5.0 | 1.0 |
| Check-ins | 0/5 (has active tasks, 0 check-in days) | 0.0 | 1.0 |

Calculation: (5 + 0.5 + 5 + 0) / 3.5 = **3.0**

**Deliverables: 5.0** — KPI fallback 5/5

**Quality: null → 3.0** | **Manager Review: null → 3.0** (Raksha's review under this week's key)

Composite: (3×0.22 + 5×0.38 + 3×0.25 + 3×0.15) = **3.8**

---

### #15 — Ranjini (QA Analyst) — 3.8/5

**Weights:** D=22% | Del=38% | Q=25% | MR=15%

**Discipline: 4.0** — Sign-in 5/5 (5.0), Sign-off 2/5 (2.0, w0.5), KPI 4/5 (4.0) → (5+1+4)/2.5 = 4.0

**Deliverables: 4.0** — KPI fallback 4/5 = 4.0

**Quality: null → 3.0** | **Manager Review: 4.5** (avg of Sneha Sunoj 4/5 + Sneha Prathap 5/5)

Composite: (4×0.22 + 4×0.38 + 3×0.25 + 4.5×0.15) = **3.8**

---

### #16 — Bala (COO) — 3.7/5

**Discipline: 2.3**
| Sub-score | Raw | Score | Weight |
|-----------|-----|-------|--------|
| Sign-in | 3/5 | 3.0 | 1.0 |
| Sign-off | 1/5 | 1.0 | 0.5 |
| Meeting Notes | 2/4 (Sudar + Thedal notes, missed Only Care Mon & Tue) | 2.5 | 1.0 |
| Check-ins | 2/5 | 2.0 | 1.0 |

Calculation: (3 + 0.5 + 2.5 + 2) / 3.5 = **2.3**

**Deliverables: 5.0** — Tasks: 1/1 on-time ("Get New Vendor for UPI instant Payout" completed Apr 16, deadline Apr 17)

**Quality: null → 3.0** | **Manager Review: null → 3.0** (C-suite exempt from receiving reviews)

Composite: **3.7**

---

### #17 — Krishnan (Content Lead) — 3.7/5

**Discipline: 2.2**
| Sub-score | Raw | Score | Weight |
|-----------|-----|-------|--------|
| Sign-in | 5/5 | 5.0 | 1.0 |
| Sign-off | 0/5 | 0.0 | 0.5 |
| KPI | 5/5 | 5.0 | 1.0 |
| Meeting Notes | 0/10 (Content Planning Intern + Fulltime — none written all week) | 0.0 | 1.0 |
| Check-ins | 0/5 (has active tasks, 0 check-in days) | 0.0 | 1.0 |

Calculation: (5 + 0 + 5 + 0 + 0) / 4.5 = **2.2**

**Deliverables: 5.0** — KPI fallback 5/5

**Quality: null → 3.0** | **Manager Review: null → 3.0** (Nandha's review under this week's key)

Composite: **3.7**

---

### #18 — Saran (Data Analyst) — 3.7/5

**Discipline: 4.0** — Sign-in 5/5 (5.0, w1.0), Sign-off 2/5 (2.0, w0.5) → (5+1)/1.5 = 4.0

**Deliverables: 3.8**
- Tasks: ~3/4 on-time → 3.8
  - "Check call connections" — completed Apr 15, deadline Apr 15 ✓
  - "Give Data of Fake IDs to Sneha" — completed Apr 17, deadline Apr 17 ✓
  - "Creator Details" — completed Apr 16, deadline Apr 16 ✓
  - "Check Revenue Increase" — completed Apr 17, deadline Apr 17 but late by timestamp margin ✗

**Quality: null → 3.0** | **Manager Review: 4.0** (Yuvanesh 4/5)

Composite: (4×0.22 + 3.8×0.42 + 3×0.21 + 4×0.15) = **3.7**

---

### #19 — JP (CEO) — 3.6/5

**Discipline: 1.7**
| Sub-score | Raw | Score | Weight |
|-----------|-----|-------|--------|
| Sign-in | 3/5 | 3.0 | 1.0 |
| Sign-off | 1/5 | 1.0 | 0.5 |
| Meeting Notes | 4/11 (11 expected: AI Standup 5, Ayush Daily 5, Hima KPI 1 — only Mon+Fri written) | 1.8 | 1.0 |
| Check-ins | 1/5 | 1.0 | 1.0 |

Calculation: (3 + 0.5 + 1.8 + 1) / 3.5 = **1.7** (lowest discipline sub-score drag: meeting notes)

**Deliverables: 5.0**
- Tasks: 4/4 on-time (all completed Apr 17, no deadlines set = auto on-time)
  - "Tasks, agenda, minutes of meetings"
  - "Nandha's tickets"
  - "Pending status"
  - "Leave section for managers"

**Quality: null → 3.0** | **Manager Review: null → 3.0** (CEO, no reporting manager)

Composite: **3.6**

---

### #20 — Maari (Full Stack Developer) — 3.6/5

**Weights:** D=17% | Del=47% | Q=21% | MR=15%

**Discipline: 5.0** — Sign-in 5/5, Sign-off 5/5. Perfect.

**Deliverables: null → 3.0 baseline** — No tasks, tickets, stories, or KPI definitions. Nothing tracked.

**Quality: null → 3.0** | **Manager Review: 5.0** (Yuvanesh 5/5)

Composite: (5×0.17 + 3×0.47 + 3×0.21 + 5×0.15) = **3.6**

Note: Despite perfect discipline and 5/5 manager review, null deliverables (→ 3.0 baseline) keeps composite at 3.6 instead of the old inflated 5.0.

---

### #21 — Barkha Agarwal (Full Stack Developer) — 3.5/5

**Weights:** D=17% | Del=47% | Q=21% | MR=15%

**Discipline: 4.3** — Sign-in 5/5 (5.0, w1.0), Sign-off 3/5 (3.0, w0.5) → (5+1.5)/1.5 = 4.3

**Deliverables: null → 3.0 baseline** — No tracked output

**Quality: null → 3.0** | **Manager Review: 5.0** (Rishabh 5/5)

Composite: (4.3×0.17 + 3×0.47 + 3×0.21 + 5×0.15) = **3.5**

---

### #22 — Perumal (Full Stack Developer) — 3.5/5

**Discipline: 5.0** — Sign-in 5/5, Sign-off 5/5. Perfect.

**Deliverables: null → 3.0 baseline** — No tracked output

**Quality: null → 3.0** | **Manager Review: 4.0** (Yuvanesh 4/5)

Composite: (5×0.17 + 3×0.47 + 3×0.21 + 4×0.15) = **3.5**

---

### #23 — Anjali Bhatt (Technical Support) — 3.2/5

**Discipline: 3.0** — Sign-in 3/5 (3.0, w1.0), Sign-off 3/5 (3.0, w0.5) → (3+1.5)/1.5 = 3.0

**Deliverables: null → 3.0 baseline** — No KPI definitions, no tasks/tickets

**Quality: null → 3.0** | **Manager Review: 4.0** (Sneha Sunoj 4/5)

Composite: **3.2**

---

### #24 — Rishabh (Full Stack Developer) — 3.2/5

**Weights:** D=17% | Del=47% | Q=21% | MR=15%

**Discipline: 4.4**
| Sub-score | Raw | Score | Weight |
|-----------|-----|-------|--------|
| Sign-in | 5/5 | 5.0 | 1.0 |
| Sign-off | 2/5 | 2.0 | 0.5 |
| Meeting Notes | 4/4 (all Astro App Standup notes written — Tue to Fri) | 5.0 | 1.0 |

Calculation: (5 + 1 + 5) / 2.5 = **4.4**

**Deliverables: null → 3.0 baseline** — No sprint stories/tasks this week

**Quality: null → 3.0** | **Manager Review: 3.0** (Yuvanesh 3/5)

Composite: (4.4×0.17 + 3×0.47 + 3×0.21 + 3×0.15) = **3.2**

---

### #25 — Irisha (Founders Office) — 3.1/5

**Discipline: 3.3** — Sign-in 5/5 (5.0, w1.0), Sign-off 0/5 (0.0, w0.5) → (5+0)/1.5 = 3.3

**Deliverables: null → 3.0** — No KPI definitions, no tasks | **Quality: null → 3.0** | **MR: null → 3.0** (Ayush hasn't reviewed)

Composite: **3.1**

---

### #26 — Disha (Content Creator) — 3.0/5

**Discipline: 3.2** — Sign-in 4/5 (4.0, w1.0), Sign-off 2/5 (2.0, w0.5), KPI 3/5 (3.0, w1.0) → (4+1+3)/2.5 = 3.2

**Deliverables: 3.0** — KPI fallback 3/5 = 3.0

**Quality: null → 3.0** | **Manager Review: 3.0** (Krishnan 3/5)

Composite: **3.0**

---

### #27 — Meghana (Business Analyst) — 3.0/5

**Discipline: 2.4**
| Sub-score | Raw | Score | Weight |
|-----------|-----|-------|--------|
| Sign-in | 5/5 | 5.0 | 1.0 |
| Sign-off | 2/5 | 2.0 | 0.5 |
| Check-ins | 0/5 (has active tasks, 0 check-in days) | 0.0 | 1.0 |

Calculation: (5 + 1 + 0) / 2.5 = **2.4**

Task check-ins with zero activity is the drag here.

**Deliverables: null → 3.0 baseline** — No KPI, no tasks/tickets

**Quality: null → 3.0** | **Manager Review: 4.0** (Sneha Sunoj 4/5)

Composite: (2.4×0.22 + 3×0.42 + 3×0.21 + 4×0.15) = **3.0**

---

### #28 — Nandha (CMO) — 3.0/5

**Weights:** D=22% | Del=38% | Q=25% | MR=15%

**Discipline: 2.9**
| Sub-score | Raw | Score | Weight |
|-----------|-----|-------|--------|
| Sign-in | 4/5 | 4.0 | 1.0 |
| Sign-off | 0/5 | 0.0 | 0.5 |
| Meeting Notes | 12/16 (missed Wed entirely + Mon Nandha-Sneha) | 3.8 | 1.0 |

Calculation: (4 + 0 + 3.8) / 2.5 = **2.9** (meeting notes drag: 4 of 16 missed)

**Deliverables: null → 3.0** — No tasks/tickets | **Quality: null → 3.0** | **MR: null → 3.0** (C-suite exempt)

Composite: **3.0**

---

### #29 — Karuna Behal (Accountant) — 2.5/5

**Discipline: 0.0** — Sign-in 0/5, Sign-off 0/5. No logins recorded.

**Deliverables: null → 3.0** — No KPI definitions, no tasks | **Quality: null → 3.0**

**Manager Review: 4.0** (Shoyab 4/5)

Composite: (0×0.22 + 3×0.42 + 3×0.21 + 4×0.15) = **2.5**

---

### #30 — Nisha (Technical Support) — 2.5/5

**Discipline: 0.0** — Sign-in 0/5, Sign-off 0/5. No logins.

**Deliverables: null → 3.0** | **Quality: null → 3.0** | **MR: 4.0** (Sneha Sunoj 4/5)

Composite: **2.5** (same pattern as Karuna — saved slightly by manager review)

---

### #31 — Ayush (CFO) — 2.4/5

**Discipline: 0.3**
| Sub-score | Raw | Score | Weight |
|-----------|-----|-------|--------|
| Sign-in | 0/5 | 0.0 | 1.0 |
| Sign-off | 0/5 | 0.0 | 0.5 |
| Meeting Notes | 2/10 (only Fri Finance Stand-up + Founders Office Meetup) | 1.0 | 1.0 |
| Check-ins | 0/5 (has active tasks, 0 check-in days) | 0.0 | 1.0 |

Calculation: (0 + 0 + 1 + 0) / 3.5 = **0.3**

**Deliverables: null → 3.0** | **Quality: null → 3.0** | **MR: null → 3.0** (C-suite exempt)

Composite: **2.4**

---

### #32 — Admin (admin) — 2.3/5

**Discipline: 0.0** — Sign-in 0/5, Sign-off 0/5. System account.

**All other buckets: null → 3.0 baseline**

Composite: (0×0.22 + 3×0.42 + 3×0.21 + 3×0.15) = **2.3**

---

### #33 — Iksha H S (QA Analyst) — 2.3/5

**Weights:** D=22% | Del=38% | Q=25% | MR=15%

**Discipline: 0.0** — Sign-in 0/5, Sign-off 0/5. No logins recorded.

**All other buckets: null → 3.0 baseline** (No KPI, no tasks, no manager review from Raksha under this week's key)

Composite: (0×0.22 + 3×0.38 + 3×0.25 + 3×0.15) = **2.3**

---

### #34 — Maanasi (Content Creator) — 2.3/5

**Discipline: 1.6** — Sign-in 2/5 (2.0, w1.0), Sign-off 0/5 (0.0, w0.5), KPI 2/5 (2.0, w1.0) → (2+0+2)/2.5 = 1.6

**Deliverables: 2.0** — KPI fallback 2/5 = 2.0

**Quality: null → 3.0** | **Manager Review: 3.0** (Krishnan 3/5)

Composite: (1.6×0.22 + 2×0.42 + 3×0.21 + 3×0.15) = **2.3**

---

### #35 — Suwetha S (Technical Support) — 2.3/5

**Discipline: 0.0** — Sign-in 0/5, Sign-off 0/5. No logins.

**All other buckets: null → 3.0** (No KPI, no tasks, Bala's review under this week's key)

Composite: **2.3**

---

### #36 — Sneha Sunoj (Ops) — 2.1/5

**Discipline: 3.3**
| Sub-score | Raw | Score | Weight |
|-----------|-----|-------|--------|
| Sign-in | 5/5 | 5.0 | 1.0 |
| Sign-off | 0/5 | 0.0 | 0.5 |
| KPI | 5/5 | 5.0 | 1.0 |
| Meeting Notes | 4/6 (missed Wed Team Standup + Mon Sneha-Meghana) | 3.3 | 1.0 |
| Check-ins | 2/5 | 2.0 | 1.0 |

Calculation: (5 + 0 + 5 + 3.3 + 2) / 4.5 = **3.3**

**Deliverables: 0.0** — Tasks: 0/4 on-time. All 4 tasks missed Apr 17 deadline:
- "Badge System Plan" — still in_progress
- "Implement Warning System" — still in_progress
- "Remove Fake IDs" — still in_progress
- "Get Agencies for Creators" — on_hold

**Quality: null → 3.0** | **Manager Review: 5.0** (JP 5/5 — "yeah good work")

| Bucket | Score | Weight | Contribution |
|--------|-------|--------|-------------|
| Discipline | 3.3 | 0.22 | 0.73 |
| Deliverables | **0.0** | 0.42 | **0.00** |
| Quality | 3.0 | 0.21 | 0.63 |
| Manager Review | 5.0 | 0.15 | 0.75 |
| **Composite** | | | **2.1** |

5/5 manager review but 0/4 tasks delivered — deliverables score anchored her down.

---

### #37 — Anindita (Growth Manager) — 1.7/5

**Discipline: 2.9** — Sign-in 4/5 (4.0, w1.0), Sign-off 0/5 (0.0, w0.5), KPI 5/5 (5.0, w1.0), Check-ins 1/5 (1.0, w1.0) → (4+0+5+1)/3.5 = 2.9

**Deliverables: 0.0** — Tasks: 0/1 on-time. "Implement Discount Offer for new users" — still pending, missed Apr 17 deadline.

**Quality: null → 3.0** | **Manager Review: null → 3.0** (Nandha's review under this week's key)

Composite: (2.9×0.22 + 0×0.42 + 3×0.21 + 3×0.15) = **1.7**

---

### #38 — Sneha Prathap (Gen AI Developer) — 1.6/5

**Weights:** D=17% | Del=47% | Q=21% | MR=15%

**Discipline: 3.3** — Sign-in 5/5 (5.0, w1.0), Sign-off 0/5 (0.0, w0.5) → (5+0)/1.5 = 3.3

**Deliverables: 0.0**
- Stories: 1 story "Fix Emoji Repetition (😭 Overuse)" in ended sprint — still in_progress → 0/1 = 0.0

**Quality: null → 3.0 baseline** — Sprint carry-over no longer penalizes here (removed double-penalty)

**Manager Review: 3.0** (JP 3/5 — "need more proper plan")

| Bucket | Score | Weight | Contribution |
|--------|-------|--------|-------------|
| Discipline | 3.3 | 0.17 | 0.56 |
| Deliverables | **0.0** | 0.47 | **0.00** |
| Quality | 3.0 | 0.21 | 0.63 |
| Manager Review | 3.0 | 0.15 | 0.45 |
| **Composite** | | | **1.6** |

Up from 1.0 after removing double-penalty. The single unfinished story still tanks deliverables (47% weight at zero), but quality now gets 3.0 baseline instead of the old 0.0 carry-over penalty.

---

### #39 — Haripriya (Content Creator) — 1.3/5

**Discipline: 1.2** — Sign-in 3/5 (3.0, w1.0), Sign-off 0/5 (0.0, w0.5), KPI 0/5 (0.0, w1.0) → (3+0+0)/2.5 = 1.2

**Deliverables: 0.0** — KPI fallback 0/5 = 0.0 (has KPI definitions but filled none)

**Quality: null → 3.0** | **Manager Review: 3.0** (Krishnan 3/5)

Composite: (1.2×0.22 + 0×0.42 + 3×0.21 + 3×0.15) = **1.3**

---

### #40 — Raksha (QA Analyst) — 1.3/5

**Weights:** D=22% | Del=38% | Q=25% | MR=15%

**Discipline: 1.1**
| Sub-score | Raw | Score | Weight |
|-----------|-----|-------|--------|
| Sign-in | 4/5 | 4.0 | 1.0 |
| Sign-off | 0/5 | 0.0 | 0.5 |
| KPI | 0/5 | 0.0 | 1.0 |
| Meeting Notes | 0/4 (QA Daily Standup — 0 notes written) | 0.0 | 1.0 |

Calculation: (4 + 0 + 0 + 0) / 3.5 = **1.1**

**Deliverables: 0.0** — KPI fallback 0/5 = 0.0 (has KPI definitions but filled none)

**Quality: null → 3.0** | **Manager Review: 2.0** (Yuvanesh 2/5 — "NEED MORE DEPTH KNOWLEDGE OF THE APP AND FEATURES")

Composite: (1.1×0.22 + 0×0.38 + 3×0.25 + 2×0.15) = **1.3**

---

### #41 — Kishore Prabakaran (Content Creator) — 1.2/5

**Discipline: 0.0** — Sign-in 0/5 (0.0), Sign-off 0/5 (0.0, w0.5), KPI 0/5 (0.0) → all zeros

**Deliverables: 0.0** — KPI fallback 0/5 = 0.0

**Quality: null → 3.0** | **Manager Review: 4.0** (Krishnan 4/5)

Composite: (0×0.22 + 0×0.42 + 3×0.21 + 4×0.15) = **1.2**

Note: 4/5 manager review but zero discipline and zero KPI delivery = composite still only 1.2

---

### #42 — Fathima K P (Content Creator) — 1.1/5

**Discipline: 0.0** — Sign-in 0/5, Sign-off 0/5, KPI 0/5. Zero across everything.

**Deliverables: 0.0** — KPI fallback 0/5 = 0.0

**Quality: null → 3.0** | **Manager Review: 3.0** (Krishnan 3/5)

Composite: (0×0.22 + 0×0.42 + 3×0.21 + 3×0.15) = **1.1**

---

## Summary Rankings

| Rank | Employee | Role | Composite | Discipline | Deliverables | Quality | Manager Review |
|------|----------|------|-----------|-----------|-------------|---------|---------------|
| 1 | Fida | Gen AI Developer | **4.5** | 4.7 | 5.0 | 3.0* | 5.0 |
| 2 | Deeksha | Technical Support | **4.4** | 5.0 | 5.0 | 3.0* | 4.0 |
| 3 | Reshma | Technical Support | **4.4** | 5.0 | 5.0 | 3.0* | 4.0 |
| 4 | Gousia | Technical Support | **4.3** | 4.4 | 5.0 | 3.0* | 4.0 |
| 5 | Sooraj | Graphic Designer | **4.3** | 5.0 | 5.0 | 3.0* | 3.0* |
| 6 | Tiyasa | Content Creator | **4.2** | 4.8 | 5.0 | 3.0* | 3.0 |
| 7 | Anaz | Video Editor | **4.1** | 4.0 | 5.0 | 3.0* | 3.0* |
| 8 | Dhanush | Product Manager | **4.1** | 4.0 | 5.0 | 3.0* | 3.0* |
| 9 | Shoyab | Accountant | **4.1** | 4.3 | 5.0 | 3.0* | 3.0* |
| 10 | Swapna M | Marketing | **4.1** | 4.2 | 5.0 | 3.0* | 3.0* |
| 11 | Tamil Arasan | Product Manager | **4.1** | 4.0 | 5.0 | 3.0* | 3.0* |
| 12 | Yuvanesh | Tech Lead | **4.1** | 2.9 | 5.0 | 3.0* | 5.0 |
| 13 | Anirudh | Marketing | **3.8** | 3.0 | 5.0 | 3.0* | 3.0* |
| 14 | Laxmi | QA Analyst | **3.8** | 3.0 | 5.0 | 3.0* | 3.0* |
| 15 | Ranjini | QA Analyst | **3.8** | 4.0 | 4.0 | 3.0* | 4.5 |
| 16 | Bala | COO | **3.7** | 2.3 | 5.0 | 3.0* | 3.0* |
| 17 | Krishnan | Content Lead | **3.7** | 2.2 | 5.0 | 3.0* | 3.0* |
| 18 | Saran | Data Analyst | **3.7** | 4.0 | 3.8 | 3.0* | 4.0 |
| 19 | JP | CEO | **3.6** | 1.7 | 5.0 | 3.0* | 3.0* |
| 20 | Maari | Full Stack Dev | **3.6** | 5.0 | 3.0* | 3.0* | 5.0 |
| 21 | Barkha Agarwal | Full Stack Dev | **3.5** | 4.3 | 3.0* | 3.0* | 5.0 |
| 22 | Perumal | Full Stack Dev | **3.5** | 5.0 | 3.0* | 3.0* | 4.0 |
| 23 | Anjali Bhatt | Technical Support | **3.2** | 3.0 | 3.0* | 3.0* | 4.0 |
| 24 | Rishabh | Full Stack Dev | **3.2** | 4.4 | 3.0* | 3.0* | 3.0 |
| 25 | Irisha | Founders Office | **3.1** | 3.3 | 3.0* | 3.0* | 3.0* |
| 26 | Disha | Content Creator | **3.0** | 3.2 | 3.0 | 3.0* | 3.0 |
| 27 | Meghana | Business Analyst | **3.0** | 2.4 | 3.0* | 3.0* | 4.0 |
| 28 | Nandha | CMO | **3.0** | 2.9 | 3.0* | 3.0* | 3.0* |
| 29 | Karuna Behal | Accountant | **2.5** | 0.0 | 3.0* | 3.0* | 4.0 |
| 30 | Nisha | Technical Support | **2.5** | 0.0 | 3.0* | 3.0* | 4.0 |
| 31 | Ayush | CFO | **2.4** | 0.3 | 3.0* | 3.0* | 3.0* |
| 32 | Admin | Admin | **2.3** | 0.0 | 3.0* | 3.0* | 3.0* |
| 33 | Iksha H S | QA Analyst | **2.3** | 0.0 | 3.0* | 3.0* | 3.0* |
| 34 | Maanasi | Content Creator | **2.3** | 1.6 | 2.0 | 3.0* | 3.0 |
| 35 | Suwetha S | Technical Support | **2.3** | 0.0 | 3.0* | 3.0* | 3.0* |
| 36 | Sneha Sunoj | Ops | **2.1** | 3.3 | 0.0 | 3.0* | 5.0 |
| 37 | Anindita | Growth Manager | **1.7** | 2.9 | 0.0 | 3.0* | 3.0* |
| 38 | Sneha Prathap | Gen AI Dev | **1.6** | 3.3 | 0.0 | 3.0* | 3.0 |
| 39 | Haripriya | Content Creator | **1.3** | 1.2 | 0.0 | 3.0* | 3.0 |
| 40 | Raksha | QA Analyst | **1.3** | 1.1 | 0.0 | 3.0* | 2.0 |
| 41 | Kishore Prabakaran | Content Creator | **1.2** | 0.0 | 0.0 | 3.0* | 4.0 |
| 42 | Fathima K P | Content Creator | **1.1** | 0.0 | 0.0 | 3.0* | 3.0 |

*\* = 3.0 neutral baseline (no data for this bucket)*

---

## Key Observations

### What differentiates top performers
- **Rank 1–4** all have real deliverables scores (5.0) from either resolved tickets (Fida, Yuvanesh) or perfect KPI completion (Deeksha, Reshma, Gousia), combined with manager reviews of 4–5.
- **Discipline alone isn't enough** — Maari and Perumal have perfect discipline (5.0) but rank #20 and #22 because no deliverables are tracked for them.

### Biggest score drags
- **Zero deliverables with tracked work** is the harshest penalty. Sneha Sunoj (0/4 tasks), Anindita (0/1 task), Sneha Prathap (0/1 story), Haripriya (0/5 KPI), Raksha (0/5 KPI), Kishore (0/5 KPI), Fathima (0/5 KPI) all score 0.0 in the heaviest bucket.
- **Meeting notes** significantly hurt JP (4/11), Yuvanesh (3/20), Krishnan (0/10), and Ayush (2/10) in discipline.
- **Zero logins** (Karuna, Nisha, Iksha, Suwetha, Kishore, Fathima) guarantee 0.0 discipline.

### Manager Review gaps
- Nandha, Bala, and Raksha submitted reviews on Apr 20 — stored under this week's key (Apr 24), so they don't count for last week's KRA window (Apr 13–19). Their team members get 3.0 baseline instead.
- Ayush still hasn't reviewed Irisha and Shoyab.
- Sneha Sunoj gave all 7 reports a flat 4/5 — doesn't differentiate performance.

### Full Stack Developers need tracked deliverables
- Maari (3.6), Barkha (3.5), Perumal (3.5), Rishabh (3.2) all have null deliverables despite being sprint-board roles.
- No stories in ended sprints, no tasks, no tickets assigned → deliverables defaults to 3.0 baseline.
- These developers would rank much higher if their work was tracked in the sprint/task system.
