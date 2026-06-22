<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\TravelExpense;
use App\Models\User;
use App\Services\TravelExpenseService;
use App\Services\TravelLedgerWriter;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Travel-Expense trips — the Travel Allowance tab's "log one commute" flow
 * (date, route, amount, payment screenshot). Employees log trips; Tessa keeps an
 * in-portal ledger + monthly total and rolls each month into ONE pending `travel`
 * Bill that the existing Pay Queue settles. Admins (Ayush #4 / Shoyab #32) get the
 * cross-employee Travel Ledger. Gating reuses config/bills_access.php — same
 * travel allow-list + admins as BillController.
 */
class TravelExpenseController extends Controller
{
    public function __construct(private TravelExpenseService $service) {}

    // ── Gating (mirrors BillController) ───────────────────────────────────────

    private function isAdmin($user): bool
    {
        return in_array($user->id, config('bills_access.admin_user_ids', []), true);
    }

    private function canSubmitTravel($user): bool
    {
        return in_array($user->id, config('bills_access.travel_allowance_user_ids', []), true);
    }

    private function ensureCanSubmit(Request $request): void
    {
        if (! $this->canSubmitTravel($request->user())) {
            abort(403, 'You are not enabled for Travel Allowance.');
        }
    }

    private function ensureAdmin(Request $request): void
    {
        if (! $this->isAdmin($request->user())) {
            abort(403, 'You are not authorized for the Travel Ledger.');
        }
    }

    private function travelMonthlyCap(): float
    {
        return (float) config('bills_access.travel_monthly_cap', 3000);
    }

    /** Current calendar month key (IST), e.g. '2026-06'. The cap resets on the IST 1st. */
    private function monthKeyNow(): string
    {
        return Carbon::now('Asia/Kolkata')->format('Y-m');
    }

    private function monthLabel(string $monthKey): string
    {
        // `!` pins day 1 so parsing a short month on the 29th–31st doesn't overflow.
        return Carbon::createFromFormat('!Y-m', $monthKey)->format('F Y');
    }

    // ── Employee ──────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $this->ensureCanSubmit($request);
        $user = $request->user();

        $thisMonth = $this->monthKeyNow();
        $prevMonth = Carbon::now('Asia/Kolkata')->subMonthNoOverflow()->format('Y-m');

        // Show the current + previous IST month so the list isn't empty right
        // after a month rollover. The cap/total below is the CURRENT month only.
        $trips = TravelExpense::with('bill:id,status,reviewed_at,reviewed_by')
            ->where('user_id', $user->id)
            ->whereIn('month_key', [$thisMonth, $prevMonth])
            ->orderByDesc('trip_date')
            ->orderByDesc('id')
            ->get();

        $cap = $this->travelMonthlyCap();
        $used = round((float) $trips->where('month_key', $thisMonth)->sum('amount'), 2);

        return response()->json([
            'trips' => $trips->map(fn ($t) => $this->format($t)),
            'route_presets' => array_values((array) config('travel_expenses.route_presets', [])),
            'travel' => [
                'cap' => $cap,
                'used' => $used,
                'remaining' => round(max(0, $cap - $used), 2),
                'month_key' => $thisMonth,
                'month_label' => $this->monthLabel($thisMonth),
            ],
            'is_admin' => $this->isAdmin($user),
            'user_id' => $user->id,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureCanSubmit($request);
        $user = $request->user();

        $validated = $request->validate([
            // App runs UTC but employees are IST — anchor "today" to IST so an
            // evening-IST submit of today's trip isn't rejected.
            'trip_date'      => ['required', 'date', 'before_or_equal:' . Carbon::today('Asia/Kolkata')->toDateString()],
            'from_label'     => ['required', 'string', 'max:120'],
            'to_label'       => ['required', 'string', 'max:120'],
            'amount'         => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'note'           => ['nullable', 'string', 'max:300'],
            'screenshots'    => ['required', 'array', 'min:1', 'max:20'],
            'screenshots.*'  => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ], [
            'screenshots.required' => 'Attach the payment screenshot.',
            'screenshots.min'      => 'Attach at least one payment screenshot.',
            'screenshots.max'      => 'You can attach up to 20 screenshots per trip.',
            'trip_date.before_or_equal' => "The trip date can't be in the future.",
        ]);

        $tripDate = Carbon::parse($validated['trip_date']);
        $screenshotsData = [];
        foreach ($request->file('screenshots') as $file) {
            $path = $file->store('travel-expenses/' . $tripDate->format('Y-m'), 'public');
            if (! $path) {
                return response()->json(['error' => 'Upload failed. Please try again.'], 500);
            }
            $screenshotsData[] = [
                'path'          => $path,
                'name'          => $file->getClientOriginalName(),
                'drive_file_id' => null,
                'drive_link'    => null,
            ];
        }

        $trip = $this->service->createTrip($user, [
            'trip_date'   => $validated['trip_date'],
            'from_label'  => $validated['from_label'],
            'to_label'    => $validated['to_label'],
            'amount'      => $validated['amount'],
            'note'        => $validated['note'] ?? null,
            'screenshots' => $screenshotsData,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Trip logged.',
            'trip' => $this->format($trip->load('bill:id,status,reviewed_at,reviewed_by')),
        ], 201);
    }

    public function update(TravelExpense $trip, Request $request): JsonResponse
    {
        $this->ensureCanSubmit($request);
        if ((int) $trip->user_id !== (int) $request->user()->id) {
            return response()->json(['error' => "You can only edit your own trip."], 403);
        }
        if ($this->service->isLocked($trip)) {
            return response()->json(['error' => "This trip is already filed with Finance and can't be edited."], 422);
        }

        $validated = $request->validate([
            'trip_date' => ['required', 'date', 'before_or_equal:' . Carbon::today('Asia/Kolkata')->toDateString()],
            'from_label' => ['required', 'string', 'max:120'],
            'to_label' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'note' => ['nullable', 'string', 'max:300'],
        ], [
            'trip_date.before_or_equal' => "The trip date can't be in the future.",
        ]);

        $trip = $this->service->updateTrip($trip, $validated);

        return response()->json([
            'ok' => true,
            'message' => 'Trip updated.',
            'trip' => $this->format($trip->load('bill:id,status,reviewed_at,reviewed_by')),
        ]);
    }

    public function destroy(TravelExpense $trip, Request $request): JsonResponse
    {
        $this->ensureCanSubmit($request);
        if ((int) $trip->user_id !== (int) $request->user()->id) {
            return response()->json(['error' => "You can only delete your own trip."], 403);
        }
        if ($this->service->isLocked($trip)) {
            return response()->json(['error' => "This trip is already filed with Finance and can't be removed."], 422);
        }

        $this->service->deleteTrip($trip);

        return response()->json(['ok' => true]);
    }

    // ── Admin: Travel Ledger ──────────────────────────────────────────────────

    public function ledger(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $trips = $this->ledgerQuery($request)->limit(3000)->get();

        $uploaders = User::whereIn('id', TravelExpense::distinct()->pluck('user_id'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]);

        $months = TravelExpense::distinct()->orderByDesc('month_key')->pluck('month_key')
            ->map(fn ($m) => ['key' => $m, 'label' => $this->monthLabel($m)])
            ->values();

        return response()->json([
            'trips' => $trips->map(fn ($t) => $this->format($t, true)),
            'uploaders' => $uploaders,
            'months' => $months,
            'total' => round((float) $trips->sum('amount'), 2),
            // Drive/Sheet auto-sync state — drives the "reconnect Google" banner.
            'sync' => app(TravelLedgerWriter::class)->status(),
        ]);
    }

    private function ledgerQuery(Request $request)
    {
        $query = TravelExpense::with(['submitter:id,name', 'bill:id,status,reviewed_at,reviewed_by'])
            ->orderByDesc('trip_date')
            ->orderByDesc('id');

        // Default to the current IST month so the ledger opens on "this month".
        $month = $request->input('month', $this->monthKeyNow());
        if ($month && $month !== 'all') {
            $query->where('month_key', $month);
        }
        if ($request->filled('uploader')) {
            $query->where('user_id', (int) $request->input('uploader'));
        }
        if ($request->filled('search')) {
            $like = '%' . trim((string) $request->input('search')) . '%';
            $query->where(function ($w) use ($like) {
                $w->where('from_label', 'like', $like)
                    ->orWhere('to_label', 'like', $like)
                    ->orWhere('note', 'like', $like)
                    ->orWhereHas('submitter', fn ($s) => $s->where('name', 'like', $like));
            });
        }

        return $query;
    }

    public function ledgerExport(Request $request)
    {
        $this->ensureAdmin($request);

        $rows = $this->ledgerQuery($request)->limit(10000)->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Travel Ledger');

        $sheet->setCellValue('A1', 'Travel Expenses — Ledger');
        $sheet->mergeCells('A1:H1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', 'Generated: ' . Carbon::now('Asia/Kolkata')->format('d M Y, H:i') . ' IST');

        $monthF = $request->input('month', $this->monthKeyNow());
        $monthF = ($monthF && $monthF !== 'all') ? $this->monthLabel($monthF) : 'All months';
        $uploaderName = 'All';
        if ($request->filled('uploader')) {
            $uploaderName = optional(User::find((int) $request->input('uploader')))->name ?? ('#' . $request->input('uploader'));
        }
        $sheet->setCellValue('A3', 'Filters — Month: ' . $monthF . '  |  Employee: ' . $uploaderName
            . ($request->filled('search') ? '  |  Search: ' . $request->input('search') : ''));

        $row = 5;
        $sheet->fromArray(['#', 'Employee', 'Month', 'Date', 'From', 'To', 'Amount (₹)', 'Status', 'Screenshot'], null, 'A' . $row);
        $sheet->getStyle('A' . $row . ':I' . $row)->getFont()->setBold(true);
        $headerRow = $row;
        $row++;
        $n = 1;
        foreach ($rows as $t) {
            $sheet->fromArray([
                $n,
                $t->submitter->name ?? '',
                $t->month_key,
                optional($t->trip_date)->format('Y-m-d'),
                $t->from_label,
                $t->to_label,
                (float) $t->amount,
                ($t->bill && $t->bill->status === 'paid') ? 'Paid' : 'Pending',
                $t->drive_link ?: $t->screenshot_url,
            ], null, 'A' . $row);
            $n++;
            $row++;
        }
        // Total row.
        $sheet->setCellValue('F' . $row, 'Total');
        $sheet->setCellValue('G' . $row, round((float) $rows->sum('amount'), 2));
        $sheet->getStyle('F' . $row . ':G' . $row)->getFont()->setBold(true);

        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A' . ($headerRow + 1));

        $writer = new Xlsx($spreadsheet);
        $tmp = tempnam(sys_get_temp_dir(), 'travel_xlsx_');
        $writer->save($tmp);

        return response()->download($tmp, 'travel-ledger-' . date('Y-m-d') . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    // ── Serializer ────────────────────────────────────────────────────────────

    private function format(TravelExpense $t, bool $withSubmitter = false): array
    {
        $billStatus = $t->relationLoaded('bill') && $t->bill ? $t->bill->status : null;

        $out = [
            'id' => $t->id,
            'trip_date' => optional($t->trip_date)->toDateString(),
            'month_key' => $t->month_key,
            'month_label' => $this->monthLabel($t->month_key),
            'from_label' => $t->from_label,
            'to_label' => $t->to_label,
            'route_label' => $t->route_label,
            'amount' => (float) $t->amount,
            'note' => $t->note,
            'screenshot_url'  => $t->screenshot_url,   // first screenshot URL (Drive preferred)
            'screenshot_urls' => $t->screenshot_urls,  // all screenshots [{url, name, synced}]
            'synced' => (bool) $t->drive_file_id,
            'status' => $billStatus ?: 'pending',  // settled via the monthly rollup bill
            'locked' => $this->service->isLocked($t),
            'created_at' => $t->created_at?->toIso8601String(),
        ];

        if ($withSubmitter) {
            $out['submitter'] = $t->relationLoaded('submitter') && $t->submitter
                ? ['id' => $t->submitter->id, 'name' => $t->submitter->name]
                : null;
        }

        return $out;
    }
}
