<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Bill;
use App\Models\User;
use App\Services\BillService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Bills & Reimbursements — direct employee → admin (Ayush #4 / Shoyab #32)
 * payment flow. Submitters raise requests with an uploaded invoice/receipt;
 * admins settle them with proof (Pay Queue) and reconcile the paid ledger
 * (Records). Gating lives in config/bills_access.php; the sidebar flag in
 * DashboardController is convenience only — every action is re-checked here.
 */
class BillController extends Controller
{
    public function __construct(private BillService $billService) {}

    // ── Gating helpers ────────────────────────────────────────────────────────

    private function isAdmin($user): bool
    {
        return in_array($user->id, config('bills_access.admin_user_ids', []), true);
    }

    // Bills are a default for everyone (config bills_open_to_all). The
    // bill_submitter_ids allow-list is only the fallback when that flag is off.
    private function canSubmitBill($user): bool
    {
        return (bool) config('bills_access.bills_open_to_all', false)
            || $this->isAdmin($user)
            || in_array($user->id, config('bills_access.bill_submitter_ids', []), true);
    }

    // Reimbursement access IS the per-category allow-lists: a reimbursement is
    // always one of the fixed categories, so a user can submit one iff they have
    // at least one allowed category (config reimbursement_category_user_ids).
    // Admins are NOT auto-granted — they must be listed (e.g. Shoyab on PG).
    private function canSubmitReimbursement($user): bool
    {
        return count($this->reimbursementCategoriesFor($user)) > 0;
    }

    // Travel allowance is an intern benefit — NOT auto-granted to admins.
    private function canSubmitTravel($user): bool
    {
        return in_array($user->id, config('bills_access.travel_allowance_user_ids', []), true);
    }

    private function travelMonthlyCap(): float
    {
        return (float) config('bills_access.travel_monthly_cap', 3000);
    }

    // Full master list of reimbursement categories (the mandatory dropdown).
    // Bills + Travel keep free-text categories.
    private function reimbursementCategories(): array
    {
        return array_values((array) config('bills_access.reimbursement_categories', []));
    }

    // The categories a SPECIFIC user may pick. Most are open to every
    // reimbursement submitter; a few (e.g. Wi-Fi, PG) are restricted to a
    // per-category allow-list in config('bills_access.reimbursement_category_user_ids').
    // A category whose key is ABSENT from that map is open to all; a category
    // whose key is PRESENT is shown only to the ids listed under it. This both
    // builds the dropdown (index) and validates submit/edit, so a restricted
    // category can't be forced via the API.
    private function reimbursementCategoriesFor($user): array
    {
        $restricted = (array) config('bills_access.reimbursement_category_user_ids', []);

        return array_values(array_filter(
            $this->reimbursementCategories(),
            function ($cat) use ($restricted, $user) {
                if (! array_key_exists($cat, $restricted)) {
                    return true; // open to every reimbursement submitter
                }

                return in_array($user->id, (array) $restricted[$cat], true);
            }
        ));
    }

    // The one reimbursement category that carries a hard monetary ceiling.
    private function wifiCategory(): string
    {
        return (string) config('bills_access.wifi_reimbursement_category', 'Wifi reimbursement');
    }

    private function wifiCap(): float
    {
        return (float) config('bills_access.wifi_reimbursement_cap', 700);
    }

    // Wi-Fi is a once-per-employee-per-month ₹700 benefit (NOT an accumulating
    // cap like travel). Returns the BLOCKING status of the employee's Wi-Fi
    // reimbursement for the current IST month — 'paid' (locked till the 1st) or
    // 'pending' (a claim already awaits payment; blocks duplicates so two can't
    // both get paid) — else null. Rejected/cancelled (deleted) claims are gone,
    // so they free it back up. $excludeBillId skips the row being edited.
    private function wifiClaimStatusThisMonth(int $userId, ?int $excludeBillId = null): ?string
    {
        [$start, $end] = $this->currentMonthWindow();
        $query = Bill::where('user_id', $userId)
            ->where('type', 'reimbursement')
            ->where('category', $this->wifiCategory())
            ->whereIn('status', ['pending', 'paid'])
            ->whereBetween('created_at', [$start, $end]);
        if ($excludeBillId) {
            $query->where('id', '!=', $excludeBillId);
        }
        $statuses = $query->pluck('status');

        if ($statuses->contains('paid')) return 'paid';
        if ($statuses->contains('pending')) return 'pending';

        return null;
    }

    // Current calendar month (IST) as UTC bounds + a display label. The app
    // runs UTC but the monthly caps reset on the IST month boundary. Shared by
    // the travel cap and the Wi-Fi once-a-month lock.
    private function currentMonthWindow(): array
    {
        $now = Carbon::now('Asia/Kolkata');
        return [
            $now->copy()->startOfMonth()->utc(),
            $now->copy()->endOfMonth()->utc(),
            $now->format('F Y'),
        ];
    }

    // What counts against the cap: pending + paid travel claims this IST month.
    // Rejected/cancelled (deleted) claims are excluded, so they free the room back up.
    private function travelUsedThisMonth(int $userId): float
    {
        [$start, $end] = $this->currentMonthWindow();
        return (float) Bill::where('user_id', $userId)
            ->where('type', 'travel')
            ->whereIn('status', ['pending', 'paid'])
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');
    }

    private function ensureAdmin(Request $request): void
    {
        if (! $this->isAdmin($request->user())) {
            abort(403, 'You are not authorized for the Bills admin area.');
        }
    }

    // ── Submitter ─────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $mine = Bill::with(['reviewer:id,name'])
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(fn ($b) => $this->format($b));

        [, , $monthLabel] = $this->currentMonthWindow();

        $canTravel = $this->canSubmitTravel($user);
        $travel = null;
        if ($canTravel) {
            $cap = $this->travelMonthlyCap();
            $used = $this->travelUsedThisMonth($user->id);
            $travel = [
                'cap' => $cap,
                'used' => round($used, 2),
                'remaining' => round(max(0, $cap - $used), 2),
                'month_label' => $monthLabel,
            ];
        }

        // Wi-Fi once-a-month lock state for THIS user, so the submit modal can
        // grey it out before they hit a 422.
        $canReimburse = $this->canSubmitReimbursement($user);
        $wifiClaimStatus = $canReimburse ? $this->wifiClaimStatusThisMonth($user->id) : null;

        return response()->json([
            'mine' => $mine,
            'can_submit_bill' => $this->canSubmitBill($user),
            'can_submit_reimbursement' => $canReimburse,
            'can_submit_travel' => $canTravel,
            'travel' => $travel,
            // Drive the mandatory reimbursement-category dropdown + Wi-Fi rules
            // from the server so the front-end never drifts from them. The list
            // is filtered to THIS user (Wi-Fi / PG can be allow-list-only).
            'reimbursement_categories' => $this->reimbursementCategoriesFor($user),
            'wifi_reimbursement_category' => $this->wifiCategory(),
            'wifi_reimbursement_cap' => $this->wifiCap(),
            'wifi_claim_status' => $wifiClaimStatus,   // 'paid' | 'pending' | null
            'wifi_month_label' => $monthLabel,
            'is_admin' => $this->isAdmin($user),
            'user_id' => $user->id,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $type = $request->input('type');

        if ($type === 'bill' && ! $this->canSubmitBill($user)) {
            return response()->json(['error' => 'You are not enabled for Bills.'], 403);
        }
        if ($type === 'reimbursement' && ! $this->canSubmitReimbursement($user)) {
            return response()->json(['error' => 'You are not enabled for Reimbursements.'], 403);
        }
        if ($type === 'travel' && ! $this->canSubmitTravel($user)) {
            return response()->json(['error' => 'You are not enabled for Travel Allowance.'], 403);
        }

        // Travel is now a single sheet LINK: no title/amount/attachment at submit
        // (the admin sets the ₹ amount when they pay it). Bills + Reimbursements
        // are unchanged — they still require an invoice + amount.
        $isTravel = $type === 'travel';

        $request->validate([
            'type' => 'required|in:bill,reimbursement,travel',
            'title' => $isTravel ? 'nullable|string|max:200' : 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            // Reimbursements must pick a category from the fixed list (mandatory
            // dropdown); Bills + Travel keep their optional free-text category.
            'category' => $type === 'reimbursement'
                ? ['required', 'string', Rule::in($this->reimbursementCategoriesFor($user))]
                : ['nullable', 'string', 'max:60'],
            'amount' => $isTravel
                ? 'nullable|numeric|min:0|max:99999999'
                : 'required|numeric|min:0.01|max:99999999',
            'currency' => 'nullable|string|max:8',
            'vendor_name' => 'nullable|string|max:200',
            // Travel: the Google Sheet / Excel link IS the submission.
            'sheet_url' => $isTravel ? ['required', 'url', 'max:1000'] : ['nullable', 'url', 'max:1000'],
            // One or more attachments — e.g. the invoice PLUS a payment QR, or
            // several invoice pages. New clients send files[]; a stale client
            // may still send a single `file`, so accept either. Optional (unused)
            // for Travel.
            'files' => $isTravel ? 'nullable|array|max:6' : 'required_without:file|array|max:6',
            'files.*' => 'file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
            'file' => $isTravel
                ? 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:10240'
                : 'required_without:files|file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
        ], [
            'category.required' => 'Select a reimbursement category.',
            'category.in' => 'Choose a valid reimbursement category.',
            'sheet_url.required' => 'Paste the link to your travel-expenses sheet.',
            'sheet_url.url' => 'Enter a valid link (it should start with http).',
        ]);

        // Wi-Fi reimbursement: ≤ ₹700, claimable ONCE per employee per IST month.
        if ($type === 'reimbursement' && $request->input('category') === $this->wifiCategory()) {
            // (a) per-claim ceiling.
            if ((float) $request->input('amount') > $this->wifiCap()) {
                return response()->json([
                    'error' => 'Wi-Fi reimbursement is capped at ₹' . number_format($this->wifiCap(), 0)
                        . ' — enter ₹' . number_format($this->wifiCap(), 0) . ' or less.',
                ], 422);
            }
            // (b) once a month: a paid claim locks it till the 1st; a pending one
            // blocks a duplicate. Rejected/cancelled claims free it back up.
            if ($status = $this->wifiClaimStatusThisMonth($user->id)) {
                [, , $monthLabel] = $this->currentMonthWindow();
                return response()->json([
                    'error' => $status === 'paid'
                        ? 'You’ve already claimed your Wi-Fi reimbursement for ' . $monthLabel . '. It resets on the 1st.'
                        : 'You already have a Wi-Fi reimbursement pending for ' . $monthLabel . ' — only one per month.',
                ], 422);
            }
        }

        // NB: the old hard ₹3,000/month travel cap is no longer auto-enforced —
        // travel rows carry no amount at submit, so the admin caps it manually
        // by reading the total off the sheet when they pay.

        // Collect every uploaded attachment (files[] preferred; fall back to a
        // single `file` from an older client), store each, and keep the list.
        // Travel rows are link-only — they carry no attachment at all.
        $uploads = $request->file('files');
        $uploads = is_array($uploads) ? array_values(array_filter($uploads)) : [];
        if (! $uploads) {
            $single = $request->file('file');
            if ($single) {
                $uploads = [$single];
            }
        }
        if (! $uploads && ! $isTravel) {
            return response()->json(['error' => 'Attach the invoice / receipt.'], 422);
        }

        $stored = [];
        foreach ($uploads as $f) {
            $path = $f->store('bills/' . date('Y-m'), 'public');
            if (! $path) {
                foreach ($stored as $s) {
                    Storage::disk('public')->delete($s['path']);
                }
                return response()->json(['error' => 'Upload failed. Please try again.'], 500);
            }
            $stored[] = [
                'path' => $path,
                'name' => $f->getClientOriginalName(),
                'size' => $f->getSize(),
            ];
        }

        // Travel: auto-title with the IST month so the Pay Queue row reads well
        // ("Travel expenses — June 2026") when the submitter only pasted a link.
        $title = trim((string) $request->input('title'));
        if ($isTravel && $title === '') {
            $title = 'Travel expenses — ' . Carbon::now('Asia/Kolkata')->format('F Y');
        }

        try {
            $bill = $this->billService->submit($user, [
                'type' => $type,
                'title' => $title,
                'description' => $request->input('description'),
                'category' => $request->input('category'),
                'amount' => $isTravel ? 0 : $request->input('amount'),
                'currency' => $request->input('currency'),
                'vendor_name' => $request->input('vendor_name'),
                'sheet_url' => $request->input('sheet_url'),
                'file_path' => $stored[0]['path'] ?? null,
                'file_name' => $stored[0]['name'] ?? null,
                'file_size' => $stored[0]['size'] ?? null,
                'files' => $stored ?: null,
            ]);
        } catch (\InvalidArgumentException $e) {
            foreach ($stored as $s) {
                Storage::disk('public')->delete($s['path']);
            }
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Request submitted. The finance team has been notified.',
            'bill' => $this->format($bill->load(['submitter:id,name', 'reviewer:id,name'])),
        ], 201);
    }

    // Submitter tops up an OPEN request with more attachments (e.g. a forgotten
    // invoice). Owner-only, and locked once the request leaves pending — a paid
    // or rejected request can no longer be changed.
    public function addFiles(Bill $bill, Request $request): JsonResponse
    {
        $user = $request->user();
        if ((int) $bill->user_id !== (int) $user->id) {
            return response()->json(['error' => 'You can only add files to your own request.'], 403);
        }
        if ($bill->status !== 'pending') {
            return response()->json([
                'error' => $bill->status === 'paid'
                    ? 'This request is already paid — its attachments are locked.'
                    : 'This request is closed — files can only be added while it is pending.',
            ], 422);
        }

        $request->validate([
            'files' => 'required|array|min:1|max:6',
            'files.*' => 'file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
        ]);

        $existing = $bill->attachments();
        $uploads = array_values(array_filter((array) $request->file('files')));
        if (count($existing) + count($uploads) > 6) {
            return response()->json(['error' => 'A request can have at most 6 files.'], 422);
        }

        $added = [];
        foreach ($uploads as $f) {
            $path = $f->store('bills/' . date('Y-m'), 'public');
            if (! $path) {
                foreach ($added as $a) {
                    Storage::disk('public')->delete($a['path']);
                }
                return response()->json(['error' => 'Upload failed. Please try again.'], 500);
            }
            $added[] = [
                'path' => $path,
                'name' => $f->getClientOriginalName(),
                'size' => $f->getSize(),
            ];
        }

        $merged = array_merge($existing, $added);
        $bill->update([
            'files' => $merged,
            // Backfill the legacy single-file columns for rows that predate
            // multi-file support (they stay as the first attachment).
            'file_path' => $bill->file_path ?: $merged[0]['path'],
            'file_name' => $bill->file_name ?: ($merged[0]['name'] ?? null),
            'file_size' => $bill->file_size ?: ($merged[0]['size'] ?? null),
        ]);

        return response()->json([
            'ok' => true,
            'message' => count($added) > 1 ? 'Files added.' : 'File added.',
            'bill' => $this->format($bill->fresh(['submitter:id,name', 'reviewer:id,name'])),
        ]);
    }

    // Submitter edits an OPEN request's details — e.g. the proof they uploaded
    // shows a different total than first typed, so the amount needs fixing.
    // Owner-only and pending-only (mirrors addFiles); type and attachments are
    // NOT editable here (type is tied to permissions/tab; files have their own
    // add flow). Travel claims are re-checked against the monthly cap, with
    // THIS request's current amount excluded so editing it doesn't double-count.
    public function update(Bill $bill, Request $request): JsonResponse
    {
        $user = $request->user();
        if ((int) $bill->user_id !== (int) $user->id) {
            return response()->json(['error' => 'You can only edit your own request.'], 403);
        }
        if ($bill->status !== 'pending') {
            return response()->json([
                'error' => $bill->status === 'paid'
                    ? 'This request is already paid — it can no longer be edited.'
                    : 'This request is closed — only a pending request can be edited.',
            ], 422);
        }

        // Travel edits only touch the sheet link (no amount — the admin sets it
        // at pay time). Bills/Reimbursements keep editing title/amount/etc.
        $isTravel = $bill->type === 'travel';

        $validated = $request->validate([
            'title' => $isTravel ? 'nullable|string|max:200' : 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            // Reimbursements stay pinned to the user's allowed category list on edit too.
            'category' => $bill->type === 'reimbursement'
                ? ['required', 'string', Rule::in($this->reimbursementCategoriesFor($user))]
                : ['nullable', 'string', 'max:60'],
            'amount' => $isTravel
                ? 'nullable|numeric|min:0|max:99999999'
                : 'required|numeric|min:0.01|max:99999999',
            'currency' => 'nullable|string|max:8',
            'vendor_name' => 'nullable|string|max:200',
            'sheet_url' => $isTravel ? ['required', 'url', 'max:1000'] : ['nullable', 'url', 'max:1000'],
        ], [
            'category.required' => 'Select a reimbursement category.',
            'category.in' => 'Choose a valid reimbursement category.',
            'sheet_url.required' => 'Paste the link to your travel-expenses sheet.',
            'sheet_url.url' => 'Enter a valid link (it should start with http).',
        ]);

        // Wi-Fi reimbursement keeps the ≤ ₹700, once-per-IST-month rule on edit.
        if ($bill->type === 'reimbursement' && ($validated['category'] ?? null) === $this->wifiCategory()) {
            if ((float) $validated['amount'] > $this->wifiCap()) {
                return response()->json([
                    'error' => 'Wi-Fi reimbursement is capped at ₹' . number_format($this->wifiCap(), 0)
                        . ' — enter ₹' . number_format($this->wifiCap(), 0) . ' or less.',
                ], 422);
            }
            // Switching another claim TO Wi-Fi can't collide with an existing
            // paid/pending Wi-Fi claim this month (this row itself is excluded).
            if ($status = $this->wifiClaimStatusThisMonth($user->id, $bill->id)) {
                [, , $monthLabel] = $this->currentMonthWindow();
                return response()->json([
                    'error' => $status === 'paid'
                        ? 'You’ve already claimed your Wi-Fi reimbursement for ' . $monthLabel . '. It resets on the 1st.'
                        : 'You already have a Wi-Fi reimbursement pending for ' . $monthLabel . ' — only one per month.',
                ], 422);
            }
        }

        // (Travel is no longer cap-checked here — the amount is admin-set at pay
        // time, so there is nothing to measure against the cap on edit.)

        $bill->update([
            'title' => $isTravel
                ? (trim((string) ($validated['title'] ?? '')) ?: $bill->title)
                : trim($validated['title']),
            'description' => trim((string) ($validated['description'] ?? '')) ?: null,
            'category' => trim((string) ($validated['category'] ?? '')) ?: null,
            'amount' => $isTravel ? $bill->amount : round((float) $validated['amount'], 2),
            'currency' => trim((string) ($validated['currency'] ?? '')) ?: $bill->currency,
            'vendor_name' => trim((string) ($validated['vendor_name'] ?? '')) ?: null,
            'sheet_url' => $isTravel ? $validated['sheet_url'] : $bill->sheet_url,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Request updated.',
            'bill' => $this->format($bill->fresh(['submitter:id,name', 'reviewer:id,name'])),
        ]);
    }

    public function destroy(Bill $bill, Request $request): JsonResponse
    {
        $user = $request->user();
        if ((int) $bill->user_id !== (int) $user->id) {
            return response()->json(['error' => 'You can only cancel your own request.'], 403);
        }
        if ($bill->status !== 'pending') {
            return response()->json(['error' => 'Only a pending request can be cancelled.'], 422);
        }

        foreach ($bill->attachments() as $a) {
            if (! empty($a['path']) && Storage::disk('public')->exists($a['path'])) {
                Storage::disk('public')->delete($a['path']);
            }
        }
        $bill->delete();

        return response()->json(['ok' => true]);
    }

    // ── Admin: Pay Queue ──────────────────────────────────────────────────────

    public function queue(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        // Pay Queue is pending-only — an action inbox. Settled requests live in
        // the Records ledger, so no "recently paid" list is shipped here.
        $pending = $this->pendingQuery($request)
            ->get()
            ->map(fn ($b) => $this->format($b));

        // Distinct uploaders with a pending item (for the queue filter dropdown).
        $uploaders = User::whereIn('id', Bill::where('status', 'pending')->distinct()->pluck('user_id'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]);

        return response()->json([
            'pending' => $pending,
            'uploaders' => $uploaders,
            'total_pending' => Bill::where('status', 'pending')->count(), // unfiltered, for the tab badge
            'user_id' => $request->user()->id,
        ]);
    }

    // Filter/sort builder for the Pay Queue (pending only). Same filters as the
    // Records ledger, but date/sort apply to the SUBMITTED date (created_at).
    // Default order is oldest-first (FIFO); pass sort=desc for newest-first.
    private function pendingQuery(Request $request)
    {
        $query = $this->applyIdentityFilters(
            Bill::with(['submitter:id,name'])->where('status', 'pending'),
            $request
        );

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        $dir = strtolower((string) $request->input('sort')) === 'desc' ? 'desc' : 'asc';

        return $query->orderBy('created_at', $dir);
    }

    // The "identity" filters (type / uploader / free-text search) shared by the
    // pending queue and the recently-paid list. Date range + sort stay
    // caller-specific (each list dates off a different column).
    private function applyIdentityFilters($query, Request $request)
    {
        if (in_array($request->input('type'), ['bill', 'reimbursement', 'travel'], true)) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('uploader')) {
            $query->where('user_id', (int) $request->input('uploader'));
        }
        if ($request->filled('search')) {
            $like = '%' . trim((string) $request->input('search')) . '%';
            $query->where(function ($w) use ($like) {
                $w->where('title', 'like', $like)
                    ->orWhere('vendor_name', 'like', $like)
                    ->orWhere('transaction_id', 'like', $like)
                    ->orWhereHas('submitter', fn ($s) => $s->where('name', 'like', $like));
            });
        }

        return $query;
    }

    // Lightweight admin summary for the dashboard card + sidebar red dot.
    // Returns 200 with count 0 for non-admins (no 403), so it's safe to fetch
    // on every dashboard load.
    public function pendingSummary(Request $request): JsonResponse
    {
        if (! $this->isAdmin($request->user())) {
            return response()->json(['count' => 0, 'items' => []]);
        }

        $items = Bill::with(['submitter:id,name'])
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'type' => $b->type,
                'title' => $b->title,
                'amount' => (float) $b->amount,
                'submitter' => $b->submitter?->name,
                'at' => $b->created_at->toIso8601String(),
            ]);

        return response()->json(['count' => $items->count(), 'items' => $items]);
    }

    public function markPaid(Bill $bill, Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $admin = $request->user();

        if ((int) $bill->user_id === (int) $admin->id) {
            return response()->json([
                'error' => 'You cannot pay your own request — the other admin settles it.',
            ], 403);
        }

        $validated = $request->validate([
            'transaction_id' => 'nullable|string|max:80',
            'note' => 'nullable|string|max:1000',
            'amount' => 'nullable|numeric|min:0.01|max:99999999',
            'proof_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:5120',
        ]);

        $txn = trim((string) ($validated['transaction_id'] ?? '')) ?: null;

        // Travel rows arrive with amount 0 — the admin enters what they're paying
        // now (read off the sheet). Other types had their amount fixed at submit.
        $amount = $request->filled('amount') ? round((float) $validated['amount'], 2) : null;
        if ($bill->type === 'travel' && (float) $bill->amount <= 0 && ($amount === null || $amount <= 0)) {
            return response()->json([
                'error' => "Enter the amount you're paying for this travel sheet.",
            ], 422);
        }

        $proofPath = null;
        $proofName = null;
        if ($request->hasFile('proof_file')) {
            $proofPath = $request->file('proof_file')->store('bill-proofs/' . date('Y-m'), 'public');
            $proofName = $request->file('proof_file')->getClientOriginalName();
        }

        if (! $txn && ! $proofPath) {
            if ($proofPath) Storage::disk('public')->delete($proofPath);
            return response()->json([
                'error' => 'Add a transaction ID or attach a payment screenshot as proof.',
            ], 422);
        }

        try {
            $bill = $this->billService->markPaid(
                $admin,
                $bill,
                $txn,
                $proofPath,
                $proofName,
                $validated['note'] ?? null,
                $amount
            );
        } catch (\InvalidArgumentException $e) {
            if ($proofPath) Storage::disk('public')->delete($proofPath);
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Marked as paid. Employee notified.',
            'bill' => $this->format($bill),
        ]);
    }

    public function reject(Bill $bill, Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        try {
            $bill = $this->billService->reject($request->user(), $bill, $validated['rejection_reason']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Rejected. Employee notified.',
            'bill' => $this->format($bill),
        ]);
    }

    // Post a PERSONAL "your travel expense is paid" card to the submitter's
    // dashboard. Admin-only, travel + paid only, and idempotent (the
    // paid_announced_at flag stops a second send). The submitter already got the
    // Slack DM at mark-paid; this is the explicit in-portal Tessa notification.
    public function announcePaid(Bill $bill, Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        if ($bill->type !== 'travel') {
            return response()->json(['error' => 'Announcements are only for travel expenses.'], 422);
        }
        if ($bill->status !== 'paid') {
            return response()->json(['error' => 'Only a paid travel expense can be announced.'], 422);
        }
        if ($bill->paid_announced_at) {
            return response()->json(['error' => 'Already announced to this employee.'], 422);
        }

        try {
            Announcement::announceTravelPaid($bill, $request->user());
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Could not post the announcement. Please try again.'], 500);
        }

        $bill->update(['paid_announced_at' => now()]);
        $bill->loadMissing('submitter:id,name');

        return response()->json([
            'ok' => true,
            'message' => 'Posted a “paid” notification to ' . ($bill->submitter->name ?? 'the employee') . '.',
            'bill' => $this->format($bill->fresh(['submitter:id,name', 'reviewer:id,name'])),
        ]);
    }

    // ── Admin: Records (paid-only accounts ledger) ────────────────────────────

    public function records(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $rows = $this->recordsQuery($request)->limit(2000)->get();

        // Distinct uploaders across ALL paid records (not just the filtered set)
        // so the dropdown is stable regardless of the current filter.
        $uploaders = User::whereIn('id', Bill::where('status', 'paid')->distinct()->pluck('user_id'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]);

        return response()->json([
            'records' => $rows->map(fn ($b) => $this->format($b)),
            'uploaders' => $uploaders,
        ]);
    }

    // Shared filter/sort builder for the Records ledger (paid only). Supports
    // type, uploader, paid-date range (from/to), free-text search, and sort
    // direction on the paid date. Used by both records() and recordsExport().
    private function recordsQuery(Request $request)
    {
        $query = Bill::with(['submitter:id,name', 'reviewer:id,name'])
            ->where('status', 'paid');

        if (in_array($request->input('type'), ['bill', 'reimbursement', 'travel'], true)) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('uploader')) {
            $query->where('user_id', (int) $request->input('uploader'));
        }
        if ($request->filled('from')) {
            $query->whereDate('reviewed_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('reviewed_at', '<=', $request->input('to'));
        }
        if ($request->filled('search')) {
            $like = '%' . trim((string) $request->input('search')) . '%';
            $query->where(function ($w) use ($like) {
                $w->where('title', 'like', $like)
                    ->orWhere('vendor_name', 'like', $like)
                    ->orWhere('transaction_id', 'like', $like)
                    ->orWhereHas('submitter', fn ($s) => $s->where('name', 'like', $like));
            });
        }

        $dir = strtolower((string) $request->input('sort')) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy('reviewed_at', $dir);
    }

    // Excel (.xlsx) export of the filtered Records ledger, with a per-type
    // summary on top. Admin-only (Ayush/Shoyab). Same filters as records().
    public function recordsExport(Request $request)
    {
        $this->ensureAdmin($request);

        $rows = $this->recordsQuery($request)->limit(5000)->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Paid Records');

        $sheet->setCellValue('A1', 'Bills, Reimbursements & Travel — Paid Records');
        $sheet->mergeCells('A1:F1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', 'Generated: ' . Carbon::now('Asia/Kolkata')->format('d M Y, H:i') . ' IST');

        $typeF = in_array($request->input('type'), ['bill', 'reimbursement', 'travel'], true)
            ? ucfirst((string) $request->input('type')) : 'All';
        $uploaderName = 'All';
        if ($request->filled('uploader')) {
            $uploaderName = optional(User::find((int) $request->input('uploader')))->name ?? ('#' . $request->input('uploader'));
        }
        $sheet->setCellValue('A3', 'Filters — Type: ' . $typeF
            . '  |  Paid date: ' . ($request->input('from') ?: '—') . ' to ' . ($request->input('to') ?: '—')
            . '  |  Uploader: ' . $uploaderName
            . ($request->filled('search') ? '  |  Search: ' . $request->input('search') : ''));

        // Per-type summary.
        $byType = ['bill' => [0, 0.0], 'reimbursement' => [0, 0.0], 'travel' => [0, 0.0]];
        foreach ($rows as $b) {
            if (! isset($byType[$b->type])) $byType[$b->type] = [0, 0.0];
            $byType[$b->type][0]++;
            $byType[$b->type][1] += (float) $b->amount;
        }

        $row = 5;
        $sheet->setCellValue('A' . $row, 'Summary');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;
        $sheet->fromArray(['Type', 'Count', 'Total (₹)'], null, 'A' . $row);
        $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true);
        $row++;
        foreach (['bill' => 'Bills', 'reimbursement' => 'Reimbursements', 'travel' => 'Travel'] as $k => $label) {
            $sheet->fromArray([$label, $byType[$k][0], round($byType[$k][1], 2)], null, 'A' . $row);
            $row++;
        }
        $sheet->fromArray(['Total', $rows->count(), round((float) $rows->sum('amount'), 2)], null, 'A' . $row);
        $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true);
        $row += 2;

        // Detail table.
        $sheet->setCellValue('A' . $row, 'Details');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;
        $sheet->fromArray(
            ['#', 'Paid Date', 'Type', 'Title', 'Submitter', 'Amount', 'Currency', 'Vendor', 'Category', 'Transaction ID', 'Paid By', 'Submitted At', 'Payment Note', 'Sheet'],
            null,
            'A' . $row
        );
        $sheet->getStyle('A' . $row . ':N' . $row)->getFont()->setBold(true);
        $headerRow = $row;
        $row++;
        $n = 1;
        foreach ($rows as $b) {
            $sheet->fromArray([
                $n,
                optional($b->reviewed_at)->timezone('Asia/Kolkata')->format('Y-m-d'),
                ucfirst($b->type),
                $b->title,
                $b->submitter->name ?? '',
                (float) $b->amount,
                $b->currency ?: 'INR',
                $b->vendor_name ?? '',
                $b->category ?? '',
                $b->transaction_id ?? '',
                $b->reviewer->name ?? '',
                optional($b->created_at)->timezone('Asia/Kolkata')->format('Y-m-d H:i'),
                $b->payment_note ?? '',
                $b->sheet_url ?? '',
            ], null, 'A' . $row);
            $n++;
            $row++;
        }

        foreach (range('A', 'N') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A' . ($headerRow + 1));

        $writer = new Xlsx($spreadsheet);
        $tmp = tempnam(sys_get_temp_dir(), 'bills_xlsx_');
        $writer->save($tmp);

        return response()->download($tmp, 'bills-records-' . date('Y-m-d') . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    // Excel (.xlsx) export of the Pay Queue (pending). Admin-only. Honours the same
    // filters as queue(); if `ids` (comma-separated) is passed, exports only those
    // selected rows.
    public function queueExport(Request $request)
    {
        $this->ensureAdmin($request);

        $ids = array_values(array_filter(array_map('intval',
            explode(',', (string) $request->input('ids', '')))));

        $rows = $ids
            ? Bill::with(['submitter:id,name'])->where('status', 'pending')
                  ->whereIn('id', $ids)->orderBy('created_at')->limit(5000)->get()
            : $this->pendingQuery($request)->limit(5000)->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pay Queue');
        $sheet->setCellValue('A1', 'Bills, Reimbursements & Travel — Pay Queue (Pending)');
        $sheet->mergeCells('A1:F1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', 'Generated: ' . Carbon::now('Asia/Kolkata')->format('d M Y, H:i') . ' IST');

        // Per-type summary (mirrors recordsExport).
        $byType = ['bill' => [0, 0.0], 'reimbursement' => [0, 0.0], 'travel' => [0, 0.0]];
        foreach ($rows as $b) {
            if (! isset($byType[$b->type])) $byType[$b->type] = [0, 0.0];
            $byType[$b->type][0]++;
            $byType[$b->type][1] += (float) $b->amount;
        }
        $row = 4;
        $sheet->setCellValue('A' . $row, 'Summary'); $sheet->getStyle('A' . $row)->getFont()->setBold(true); $row++;
        $sheet->fromArray(['Type', 'Count', 'Total (₹)'], null, 'A' . $row);
        $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true); $row++;
        foreach (['bill' => 'Bills', 'reimbursement' => 'Reimbursements', 'travel' => 'Travel'] as $k => $label) {
            $sheet->fromArray([$label, $byType[$k][0], round($byType[$k][1], 2)], null, 'A' . $row); $row++;
        }
        $sheet->fromArray(['Total', $rows->count(), round((float) $rows->sum('amount'), 2)], null, 'A' . $row);
        $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true); $row += 2;

        // Detail table — one column per Pay-Queue field.
        $sheet->setCellValue('A' . $row, 'Details'); $sheet->getStyle('A' . $row)->getFont()->setBold(true); $row++;
        $sheet->fromArray(['#', 'Submitted At', 'Type', 'Title', 'Submitter', 'Amount', 'Currency',
            'Vendor', 'Category', 'Description', 'Attachments', 'Sheet', 'Status'], null, 'A' . $row);
        $sheet->getStyle('A' . $row . ':M' . $row)->getFont()->setBold(true);
        $headerRow = $row; $row++; $n = 1;
        foreach ($rows as $b) {
            $attach = collect($b->attachments())->pluck('name')->filter()->implode(', ');
            $sheet->fromArray([
                $n,
                optional($b->created_at)->timezone('Asia/Kolkata')->format('Y-m-d H:i'),
                ucfirst($b->type), $b->title, $b->submitter->name ?? '',
                (float) $b->amount, $b->currency ?: 'INR',
                $b->vendor_name ?? '', $b->category ?? '', $b->description ?? '', $attach,
                $b->sheet_url ?? '', $b->status,
            ], null, 'A' . $row);
            $n++; $row++;
        }

        foreach (range('A', 'M') as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }
        $sheet->freezePane('A' . ($headerRow + 1));

        $writer = new Xlsx($spreadsheet);
        $tmp = tempnam(sys_get_temp_dir(), 'bills_queue_xlsx_');
        $writer->save($tmp);
        return response()->download($tmp, 'bills-pay-queue-' . date('Y-m-d') . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    // ── Serializer ────────────────────────────────────────────────────────────

    private function format(Bill $b): array
    {
        return [
            'id' => $b->id,
            'type' => $b->type,
            'title' => $b->title,
            'description' => $b->description,
            'category' => $b->category,
            'amount' => (float) $b->amount,
            'currency' => $b->currency ?: 'INR',
            'vendor_name' => $b->vendor_name,
            'sheet_url' => $b->sheet_url,
            'file_url' => $b->file_path ? asset('storage/' . $b->file_path) : null,
            'file_name' => $b->file_name,
            // All attachments (invoice + payment QR + extra pages). file_url
            // above stays as the first one for any older consumer.
            'files' => collect($b->attachments())->map(fn ($a) => [
                'url' => asset('storage/' . $a['path']),
                'name' => $a['name'] ?: 'Attachment',
            ])->values()->all(),
            'status' => $b->status,
            'submitter' => $b->relationLoaded('submitter') && $b->submitter
                ? ['id' => $b->submitter->id, 'name' => $b->submitter->name]
                : null,
            'reviewer' => $b->relationLoaded('reviewer') && $b->reviewer
                ? ['id' => $b->reviewer->id, 'name' => $b->reviewer->name]
                : null,
            'reviewed_at' => $b->reviewed_at?->toIso8601String(),
            'paid_announced_at' => $b->paid_announced_at?->toIso8601String(),
            'transaction_id' => $b->transaction_id,
            'proof_url' => $b->proof_path ? asset('storage/' . $b->proof_path) : null,
            'payment_note' => $b->payment_note,
            'rejection_reason' => $b->rejection_reason,
            'created_at' => $b->created_at->toIso8601String(),
        ];
    }
}
