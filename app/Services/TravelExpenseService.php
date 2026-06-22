<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\TravelExpense;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Travel-Expense trip flow. The controller validates + stores the screenshot;
 * this service owns the trip row, the monthly rollup `travel` Bill (so the
 * existing Pay Queue settles them), and registering the dormant-ready Google
 * sync after the response (mirrors EmployeeController::deferDriveUpload).
 *
 * Rollup rule: trips already settled by a PAID bill keep that bill; all other
 * ("open") trips for the month share ONE pending `travel` bill whose amount is
 * their live sum. So a trip added after the month is paid forms a fresh top-up
 * bill instead of re-billing the paid ones.
 */
class TravelExpenseService
{
    /** Create a trip, refresh its month's rollup bill, and queue the Google sync. */
    public function createTrip(User $user, array $data): TravelExpense
    {
        $tripDate = Carbon::parse($data['trip_date']);
        $screenshots = $data['screenshots'] ?? null;

        $trip = TravelExpense::create([
            'user_id'         => $user->id,
            'trip_date'       => $tripDate->toDateString(),
            'month_key'       => $tripDate->format('Y-m'),
            'from_label'      => trim((string) $data['from_label']),
            'to_label'        => trim((string) $data['to_label']),
            'amount'          => round((float) $data['amount'], 2),
            'note'            => trim((string) ($data['note'] ?? '')) ?: null,
            'screenshots'     => $screenshots,
            // Keep scalar columns in sync with the first screenshot for legacy queries.
            'screenshot_path' => $screenshots[0]['path'] ?? ($data['screenshot_path'] ?? null),
            'screenshot_name' => $screenshots[0]['name'] ?? ($data['screenshot_name'] ?? null),
        ]);

        $this->syncMonthlyRollupBill($user->id, $trip->month_key);
        $this->registerDeferredSync($trip->id);

        return $trip->fresh();
    }

    /** Edit a trip's details (locked once filed to Drive or its month is paid — see isLocked). */
    public function updateTrip(TravelExpense $trip, array $data): TravelExpense
    {
        $oldMonth = $trip->month_key;
        $oldBillId = $trip->bill_id;
        $tripDate = Carbon::parse($data['trip_date']);
        $monthChanged = $oldMonth !== $tripDate->format('Y-m');

        $trip->update([
            'trip_date' => $tripDate->toDateString(),
            'month_key' => $tripDate->format('Y-m'),
            'from_label' => trim((string) $data['from_label']),
            'to_label' => trim((string) $data['to_label']),
            'amount' => round((float) $data['amount'], 2),
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
            // Moving months: unlink from the old bill so the new month re-links cleanly.
            'bill_id' => $monthChanged ? null : $trip->bill_id,
        ]);

        if ($monthChanged) {
            $this->syncMonthlyRollupBill($trip->user_id, $oldMonth, $oldBillId);
        }
        $this->syncMonthlyRollupBill($trip->user_id, $trip->month_key);

        return $trip->fresh();
    }

    /** Remove a trip + its local screenshot, then re-roll the month. */
    public function deleteTrip(TravelExpense $trip): void
    {
        $userId = $trip->user_id;
        $month = $trip->month_key;
        $billId = $trip->bill_id;
        $screenshots = $trip->screenshots ?? ($trip->screenshot_path ? [['path' => $trip->screenshot_path]] : []);

        $trip->delete();

        foreach ($screenshots as $s) {
            $path = $s['path'] ?? null;
            if ($path && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }

        // Pass the deleted trip's bill id so an emptied month can still drop its
        // now-orphaned pending bill (no trips left to recover the id from).
        $this->syncMonthlyRollupBill($userId, $month, $billId);
    }

    /**
     * A trip is locked from the employee once it's filed to the accounts Drive
     * (sheet_synced_at) or its month has been paid — Finance adjusts after that.
     */
    public function isLocked(TravelExpense $trip): bool
    {
        if ($trip->sheet_synced_at) {
            return true;
        }
        $trip->loadMissing('bill');

        return $trip->bill && $trip->bill->status === 'paid';
    }

    /**
     * Maintain ONE pending `travel` bill for the month's still-open trips
     * (sum = their live total). Trips linked to a PAID bill are left settled.
     * $knownBillId lets a caller (delete / month-move) name the affected bill so
     * an emptied month can drop its orphaned pending bill even with no trips left.
     */
    public function syncMonthlyRollupBill(int $userId, string $monthKey, ?int $knownBillId = null): void
    {
        $trips = TravelExpense::forMonth($userId, $monthKey)->get();

        // Candidate rollup bills = those linked from this month's trips, plus the
        // caller's hint (so a fully-emptied month can still find its bill).
        $billIds = $trips->pluck('bill_id')->filter();
        if ($knownBillId) {
            $billIds->push($knownBillId);
        }
        $billIds = $billIds->unique()->values();

        // Bills already paid → those trips are settled, left untouched.
        $paidBillIds = $billIds->isEmpty()
            ? collect()
            : Bill::whereIn('id', $billIds)->where('status', 'paid')->pluck('id');

        $open = $trips->filter(fn ($t) => ! $t->bill_id || ! $paidBillIds->contains($t->bill_id));

        // The single pending rollup bill for the open trips (if one exists yet).
        $pendingBill = $billIds->isEmpty()
            ? null
            : Bill::whereIn('id', $billIds)->where('status', 'pending')->first();

        if ($open->isEmpty()) {
            // Nothing open — drop an orphaned pending bill so the Pay Queue stays clean.
            if ($pendingBill) {
                $pendingBill->delete();
            }

            return;
        }

        $sum = round((float) $open->sum('amount'), 2);

        if (! $pendingBill) {
            // Create directly (no Slack per-trip noise) — Shoyab works the pending
            // travel bill from the Pay Queue + the Travel Ledger tab.
            $pendingBill = Bill::create([
                'user_id' => $userId,
                'type' => 'travel',
                'title' => 'Travel expenses — ' . $this->monthLabel($monthKey),
                'amount' => $sum,
                'currency' => 'INR',
                'status' => 'pending',
            ]);
        } else {
            $pendingBill->update(['amount' => $sum]);
        }

        // Point every open trip at the pending bill.
        $relink = $open->filter(fn ($t) => (int) $t->bill_id !== (int) $pendingBill->id)->pluck('id');
        if ($relink->isNotEmpty()) {
            TravelExpense::whereIn('id', $relink)->update(['bill_id' => $pendingBill->id]);
        }
    }

    /** 'YYYY-MM' → 'June 2026'. The `!` pins day 1 so short months don't overflow. */
    private function monthLabel(string $monthKey): string
    {
        return Carbon::createFromFormat('!Y-m', $monthKey)->format('F Y');
    }

    /** Queue the Drive + ledger sync to run AFTER the response (no-op while dormant). */
    private function registerDeferredSync(int $tripId): void
    {
        $svc = app(TravelExpenseSyncService::class);
        if (! $svc->isConfigured()) {
            return;
        }
        app()->terminating(function () use ($svc, $tripId) {
            $trip = TravelExpense::find($tripId);
            if ($trip) {
                $svc->syncTrip($trip);
            }
        });
    }
}
