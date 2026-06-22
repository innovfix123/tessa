# Bills & Reimbursements — direct employee → Ayush/Shoyab payment flow

> **Status: Live 2026-06-02.** Backend + frontend built, migrated, cache refreshed, and the allow-lists seeded from the group poll: **9** bill submitters, **21** reimbursement submitters, **14** travel-allowance claimants (flat ₹3,000/month cap), plus admins Ayush #4 + Shoyab #32. Travel was opened to the whole reimbursement-claimant list (not just interns). The third type — **Travel Allowance** — is covered in its own section below.

## Context

Today there is no clean, self-service way for staff to get a company bill paid or a personal expense reimbursed. People who hold agency/subscription invoices (e.g. Figma, an ad agency) or who need reimbursement for PG/room rent and travel have to chase someone manually. The intent is a **direct, no-middleman flow**: the person uploads the invoice/receipt with a short description from their own Tessa portal and clicks **Request payment** / **Request reimbursement**; the request lands directly in the admins' Bills queue; an admin verifies and pays, attaching **proof** (transaction/UTR id and/or a payment screenshot). Once paid, the record forms a settled ledger (Records) for company finance reconciliation.

This is deliberately built as a **new, self-contained "Bills" section**, separate from the existing finance `InvoiceSubmission`/reconciliation tool (`app/Http/Controllers/Api/Finance/InvoiceSubmissionController.php`), which is role-gated to finance leadership and has no employee-initiated "pay me with proof" path. The new feature reuses that codebase's proven patterns rather than extending that table.

### Decisions locked in
- **Eligibility:** two separate config allowlists — **Bills submitters** (small: agency/subscription owners) and **Reimbursement claimants** (possibly wider). Seeded *after* the group confirmation message below.
- **Admins (Ayush #4 + Shoyab #32) share one identical Bills section:** the **Pay Queue** (mark paid / reject) **and** the **Records** ledger for accounts. Both can pay; a payer **cannot pay their own** request (the other admin settles it). Shoyab is a full admin here, not read-only.
- **Admins are employees too:** Ayush and Shoyab also get the **submit** tabs (My Bills / My Reimbursements) to raise their own requests.
- **Records (accounts) = paid only.** The Records ledger lists settled (paid) requests for reconciliation.
- **Slack:** **both directions** — DM the admins on each new request; DM the employee when paid (with UTR/proof) or rejected (with reason). Reuses the existing per-user DM setup.

## Design overview

One table `bills` with a `type` enum (`bill` | `reimbursement`) — same lifecycle, two labels. Two personas, gated per-user:
- **Submitters** see "My Bills" and/or "My Reimbursements" tabs (whichever list they're on): submit form + their own request history with live status. Can cancel a still-pending request.
- **Admins** (Ayush #4 + Shoyab #32, `admin_user_ids`) see the **same** section: the submit tabs (they're employees too) **plus** a **Pay Queue** (all pending, both types — Mark Paid with UTR + proof screenshot + note, or Reject with reason) **plus** a **Records** tab (all paid requests, for accounts, with type/uploader/date/sort filters + Excel export). A payer **cannot mark their own request paid** — the other admin settles it.

State machine: `pending → paid` (with `reviewed_by`, `reviewed_at`, `transaction_id`, `proof_path`, `payment_note`) **or** `pending → rejected` (with `rejection_reason`). Mirrors `RewardWithdrawal` (pay-with-proof) + `LeaveRequest` (reviewer/status) patterns already in the codebase.

## Travel allowance (separate tab + ₹3,000/month cap)

Interns get a daily travel reimbursement capped at **₹3,000 per calendar month**. This is a **third `type` value `travel`** (added to the enum via `2026_06_02_000002_add_travel_type_to_bills.php`) with its own **Travel Allowance** tab — deliberately separate from general Reimbursements.

- **Eligibility:** `config/bills_access.php` → `travel_allowance_user_ids` (interns; seeded explicitly). **NOT** auto-granted to admins — it's an intern benefit. `canSubmitTravel()` checks only this list.
- **Cap:** `travel_monthly_cap` (default `3000`). **Pending + paid** travel claims for the current **IST** calendar month count against it (`BillController::travelUsedThisMonth()`, bucketed by `created_at` within the IST month window). Rejected/cancelled (deleted) claims are excluded, so they free the room back up.
- **Hard block at submit:** `store()` computes `remaining = cap − used` and 422s **before storing the file** if `amount > remaining`. The frontend also disables the submit button and the amount field's `max` when the cap is reached (server is the source of truth).
- **Daily flow:** the intern uploads a payment screenshot each day; the **Travel Allowance** tab shows a progress bar (`₹X of ₹3,000 used · ₹Y left`, month label) above their claim history.
- **Admin side is unified:** travel claims flow into the **same** Pay Queue + Records as bills/reimbursements, tagged with a teal **Travel** badge (the Records filters + Excel export include it). Admins pay everything in one place.
- `index` returns `can_submit_travel` + a `travel` block `{cap, used, remaining, month_label}` driving the tab.

## Admin notifications (non-empty Pay Queue)

When `COUNT(bills WHERE status='pending') > 0`, the admins (Ayush/Shoyab) get three signals:
1. **Sidebar red dot** on "Bills" — `DashboardController` sets `$config['pendingBills']` (admin-only count); the blade nav loop renders the shared `.side-nav-badge` (8px #ef4444 dot) for `feature==='bills'`, mirroring the `leave` badge. Page-load fresh.
2. **Dashboard card** — `renderDashboard()` fetches `GET /api/bills/pending-summary` (200 with `count:0` for non-admins, so safe for everyone) and, if non-empty, prepends an "Awaiting your payment" card to the **Tessa** action panel (reuses `.mgr-notif-*` styling) listing each pending request with an **Open Pay Queue** button → `MeetingModule.switchView('bills')`. Its count rolls into the Tessa-tab badge.
3. **Slack DM** — already fired per-submission by `BillService::notifyAdminsOfNewRequest` (both admins, minus the submitter).

## Data model

Migration `database/migrations/2026_06_02_000001_create_bills_table.php`. **FK to `users.id` is signed `integer`, never bigInteger** (users.id is a signed int):

```php
Schema::create('bills', function (Blueprint $table) {
    $table->id();
    $table->integer('user_id');                      // submitter (signed int)
    $table->enum('type', ['bill', 'reimbursement']);
    $table->string('title');                          // e.g. "Figma subscription", "PG rent — June"
    $table->text('description')->nullable();
    $table->string('category')->nullable();           // optional: rent/travel/food/subscription/agency/other
    $table->decimal('amount', 12, 2);
    $table->string('currency', 8)->default('INR');
    $table->string('vendor_name')->nullable();        // bills: agency/vendor
    $table->string('file_path', 500);                 // uploaded invoice/receipt
    $table->string('file_name');
    $table->integer('file_size')->nullable();
    $table->enum('status', ['pending', 'paid', 'rejected'])->default('pending');
    $table->integer('reviewed_by')->nullable();       // admin (paid or rejected)
    $table->timestamp('reviewed_at')->nullable();
    $table->string('transaction_id', 80)->nullable(); // UTR/UPI proof
    $table->string('proof_path', 500)->nullable();    // payment screenshot
    $table->string('proof_name')->nullable();
    $table->text('payment_note')->nullable();
    $table->text('rejection_reason')->nullable();
    $table->timestamps();

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
    $table->index(['user_id', 'status']);
    $table->index(['status', 'type']);
});
```

Model `app/Models/Bill.php`: `$fillable` for all columns, casts (`amount` → `decimal:2`, `reviewed_at` → `datetime`, `file_size` → `integer`), relations `submitter()` (`belongsTo User, user_id`) and `reviewer()` (`belongsTo User, reviewed_by`), scopes `pending()/paid()/rejected()`.

## Backend

**`config/bills_access.php`** (config is cached in prod — run `bin/refresh-routes.sh` after edits):
```php
return [
    'bill_submitter_ids'          => [],       // seed after group confirms (agency/subscription owners)
    'reimbursement_submitter_ids' => [],       // seed after group confirms (rent/travel claimants)
    // Travel allowance (interns) — separate tab + type, NOT auto-granted to admins.
    'travel_allowance_user_ids'   => [],       // intern ids — fill in
    'travel_monthly_cap'          => 3000,     // hard ₹/person/calendar-month (IST)
    // Full Bills admins — share one section: Pay Queue + Records, can mark paid/reject.
    // Ayush (CFO) + Shoyab (Accountant). They can also submit their own requests.
    'admin_user_ids'              => [4, 32],
    'approval_heads_up'           => [4, 32],  // both admins get the new-request DM
];
```

**`app/Http/Controllers/Api/Finance/BillController.php`** — gating helpers read `config('bills_access.*')`: `isAdmin` (in `admin_user_ids`), `canSubmitBill` (admins **or** on `bill_submitter_ids`), `canSubmitReimbursement` (admins **or** on `reimbursement_submitter_ids`), `ensureAdmin` (abort 403). Methods:
- `index` — submitter's own rows + capability flags `{can_submit_bill, can_submit_reimbursement, is_admin, user_id}` to drive which tabs the frontend shows.
- `store` — validate `type in:bill,reimbursement`, gate against the matching capability, `file required|file|mimes:pdf,jpg,jpeg,png,webp|max:10240`, `amount required|numeric`. Store file via `$file->store('bills/'.date('Y-m'), 'public')`; delegate create + admin DM to `BillService::submit`.
- `destroy` — submitter cancels own **pending** only; delete row + file.
- `queue` — `ensureAdmin`; pending (filtered via `pendingQuery()`) + recent paid (30d) + the distinct pending `uploaders` + `total_pending` (unfiltered, for the tab badge). `pendingQuery()` mirrors the Records filters (type, uploader, search, sort) but date/sort apply to the **submitted** date (`created_at`); default order is oldest-first (FIFO).
- `markPaid` — `ensureAdmin`; **reject if `$bill->user_id === $actor->id`** (no self-payment); `transaction_id nullable|max:80`, `proof_file nullable|file|...|max:5120`, `note nullable`; require **at least one** of transaction_id / proof_file. Store proof under `bill-proofs/'.date('Y-m')`; delegate to `BillService::markPaid`.
- `reject` — `ensureAdmin`; `rejection_reason required`; delegate to `BillService::reject`.
- `records` / `recordsExport` — `ensureAdmin`; **paid only**. A shared `recordsQuery()` applies filters: **type, uploader, paid-date range (from/to), free-text search, and sort (newest/oldest)** on the paid date. `records` returns the rows + the distinct `uploaders` list (for the dropdown); `recordsExport` streams a server-built **`.xlsx`** (PhpSpreadsheet) with a per-type **summary** (count + total) above the detail rows — same filters via query string.

**`app/Services/BillService.php`** — clones the shape of `app/Services/RewardService.php` (injects `SlackService`, reuses the resolve-by-name DM helper). `submit`/`markPaid`/`reject` perform the DB transition + Slack notify:
- `notifyAdminsOfNewRequest` → DM `config('bills_access.approval_heads_up')` (Ayush + Shoyab; skips the submitter).
- `notifySubmitterOfPayment` → DM submitter (amount, payer, txn).
- `notifySubmitterOfRejection` → DM submitter with reason.
- Portal deep-link `rtrim(config('app.url'),'/').'/#view=bills'`.

**Routes** — `routes/api/bills.php`, required in `routes/api.php` inside the `['web','auth']` group:
```php
Route::get('/bills',              [BillController::class, 'index']);
Route::post('/bills',             [BillController::class, 'store']);
Route::get('/bills/queue',        [BillController::class, 'queue']);     // admins (pay)
Route::get('/bills/records',      [BillController::class, 'records']);   // admins (accounts ledger)
Route::get('/bills/records/export', [BillController::class, 'recordsExport']); // admins (xlsx + summary)
Route::post('/bills/{bill}/mark-paid', [BillController::class, 'markPaid']);
Route::post('/bills/{bill}/reject',    [BillController::class, 'reject']);
Route::delete('/bills/{bill}',    [BillController::class, 'destroy']);
```

## Feature gating (sidebar visibility)

In `DashboardController::roleConfig()`, alongside the `rewards`/`salary_tool` per-user blocks, `bills` is added to `$features` when the user is on any of the three lists:
```php
$billsAllowedIds = array_unique(array_merge(
    config('bills_access.bill_submitter_ids', []),
    config('bills_access.reimbursement_submitter_ids', []),
    config('bills_access.admin_user_ids', [])
));
if (in_array($user->id, $billsAllowedIds, true) && ! in_array('bills', $features, true)) {
    $features[] = 'bills';
}
```
Server-side authorization is independently enforced in `BillController` (the sidebar flag is convenience only).

## Frontend

`resources/views/dashboards/portal.blade.php`: `'bills' => 'Bills'` in `$featureLabels`, a receipt SVG in `$navIconSvgs`, `<section id="billsView" class="hidden"></section>` by the rewards section, and the `js/bills.js` `<script>` before `js/portal.js`.

`public/js/portal.js` `onSwitchView()` (next to the Rewards line):
```js
if (view === 'bills' && window.Bills) window.Bills.render(document.getElementById('billsView'));
```

`public/js/bills.js` — `window.Bills.render(root)` module modeled on `public/js/rewards.js` (self-contained IIFE, own `bl-*` styles, `api()` for JSON + `apiForm()` for multipart with **status-before-json** 413/504 handling). It calls `GET /api/bills`, then builds tabs from the capability flags:
- **My Bills / My Reimbursements** (anyone who can submit, incl. admins): a "Request payment" / "Request reimbursement" button → modal (title, amount, currency, vendor [bills], category, description, file upload with **drag-drop + clipboard paste**). Own request cards with status chip; Cancel on pending; paid rows show payer/txn/proof, rejected rows show the reason.
- **Pay Queue** (admins): a **filter bar** (search / type / uploader / sort oldest↔newest / submitted-date range + Clear) over the pending cards (type badge, submitter, amount, invoice link) → inline **Mark Paid** (txn + proof file + note) / **Reject** (reason). The admin's own pending rows are shown but not payable. The tab badge shows the **unfiltered** total; the header reads "showing N of M" when filtered. Plus recently-paid (30d).
- **Records** (admins): paid-only ledger with **search / type / uploader / sort (newest↔oldest) / paid-date** filters (flex-wrap bar) + an **Excel** download button (`GET /api/bills/records/export` → `.xlsx` with a per-type summary). The same filter state drives both the on-screen list and the export link.

## Gotchas honored
- **Storage ownership:** `storage/app/public/bills` and `…/bill-proofs` pre-created `www-data:www-data` (else uploads silently store `path='0'` and 404).
- **Prod caches routes/config:** ran `bin/refresh-routes.sh` after adding the config + routes.
- **Web portal only** — no tessa-desktop work.
- **Slack DMs** here are transactional 1:1 (not a fanout) — the dry-run rule doesn't apply; the quiet-window `.env` still silences them overnight automatically.

## Files
**Created:** `database/migrations/2026_06_02_000001_create_bills_table.php`, `database/migrations/2026_06_02_000002_add_travel_type_to_bills.php`, `app/Models/Bill.php`, `app/Http/Controllers/Api/Finance/BillController.php`, `app/Services/BillService.php`, `config/bills_access.php`, `routes/api/bills.php`, `public/js/bills.js`.
**Modified:** `routes/api.php` (require bills routes), `app/Http/Controllers/DashboardController.php` (gating block), `resources/views/dashboards/portal.blade.php` (label + icon + section + script), `public/js/portal.js` (one dispatch line).

## Verification
Done: PHP + JS lint clean; 7 routes registered; `bills` table created (22 cols); `config('bills_access')` loads `admin_user_ids [4,32]`; DI graph resolves (`BillController`→`BillService`→`SlackService`); model casts/relations/scopes work; storage dirs www-data-owned.

Deferred (avoids real Slack DMs to Ayush/Shoyab before launch) — run after seeding a test id:
1. Seed `bill_submitter_ids`/`reimbursement_submitter_ids` with a test id (e.g. Fida #41); `bin/refresh-routes.sh`. Confirm **Bills** appears for that user, not for an un-listed one.
2. Submit a **bill** (PDF) and a **reimbursement** (pasted image) → both *pending*; admins get a Slack DM.
3. As Ayush (#4): **Pay Queue** → Mark Paid with UTR + screenshot → *paid*; submitter DM'd. Reject another → reason DM.
4. As Shoyab (#32): same section (submit + Pay Queue + Records). Submit his own; Ayush pays it (and vice-versa). **Records** shows only paid rows + proof; filter by uploader/date/type/sort and download the **Excel** (summary + detail).
5. Self-payment guard: an admin's own pending row has no Mark-Paid button; `POST /bills/{id}/mark-paid` on it → 403.
6. Negatives: un-listed user → 403 on `POST /api/bills`; non-admin → 403 on `/bills/queue` and `/bills/records`; cannot cancel a paid row.

---

## Group confirmation message (post before seeding the allow-lists)

> 📢 **New on Tessa: Bills & Reimbursements**
>
> We're adding a simple way to get company **bills** paid and personal **reimbursements** settled — straight from your Tessa portal, directly with our finance team (Ayush), no chasing in between.
>
> **How it'll work:**
> • **Bills** — if you hold invoices for the company (agency invoices, software subscriptions, tools, etc.), you'll upload/paste the invoice, add a one-line description, and hit **Request payment**.
> • **Reimbursements** — if the company reimburses you for things like **PG/room rent, travel, or other approved expenses**, you'll paste the receipt/payment screenshot, add a note, and hit **Request reimbursement**.
>
> Your request goes straight to finance. Once it's paid, you'll get a Slack confirmation with the **transaction ID / payment proof**, and the record is filed for accounts.
>
> We're switching this on only for the people it actually applies to, so please reply here:
> 1. Reply **"Bills"** if you regularly hold company invoices/subscriptions to be paid.
> 2. Reply **"Reimburse"** if you receive reimbursements (rent / travel / etc.).
> *(Reply with both if both apply.)*
>
> Drop your name below and we'll add you. 🙌

## Rollout — seeded access (2026-06-02)

Eligibility was collected via a group poll, then seeded in `config/bills_access.php` (each id carries a `// Name` comment for audit):

- **Bills** (9): JP (1), Bala (2), Nandha (3), Ayush (4), Sneha Sunoj (5), Krishnan (20), Shoyab (32), Yuvanesh (34), Meghana (45).
- **Reimbursements** (21): the "Both"-voters above (1, 2, 3, 4, 32, 34, 45) + Fida Taneem (41), Maari (38), Perumal (37), Disha (40), Swapna M (55), Karuna Behal (54), Sooraj (19), Tamil Arasan (12), Laxmi (23), Bhuvan Prasad (59), Bhoomika (60), Soundarya Balaraddi (62), Tiyasa (21), Irisha (46).
- **Travel Allowance** (14): the reimbursement claimants (the 14 names just listed), flat ₹3,000/month each.
- **Admins** (Pay Queue + Records): Ayush (4) + Shoyab (32).

First real submission confirming the end-to-end flow live: Fida Taneem — "PG rent" ₹7,000 reimbursement (2026-06-02).

## Launch announcement (posted to the team)

> 🎉 **Bills is now live on Tessa!**
>
> You'll find a new **Bills** item in your sidebar (just above Org Chart). It's the one place to get company bills paid and your expenses reimbursed — straight from your portal to finance, no chasing in between. You'll see the tabs that apply to you:
>
> 🧾 **Bills** — for company invoices/subscriptions you hold (agency invoices, software, tools). Upload the invoice, add a one-line description, hit **Request payment**.
>
> 💸 **Reimbursements** — for things the company pays you back for (rent, approved expenses, etc.). Paste the receipt/payment screenshot, add a note, hit **Request reimbursement**.
>
> 🚕 **Travel Allowance** — for your daily commute. Upload your payment screenshot each day; you're reimbursed up to **₹3,000 per month** (the tab shows how much you have left, and it resets on the 1st).
>
> **What happens next:** your request goes straight to **Ayush & Shoyab**. They verify and pay, attaching the **transaction ID / payment proof** — you'll get a Slack confirmation, and the record is filed for accounts automatically. You can paste a screenshot or upload a PDF/photo, and cancel any request while it's still pending.
>
> Give it a try and reach out if anything's unclear. 🙌
