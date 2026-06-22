<?php

use App\Http\Controllers\Api\Finance\BillController;
use App\Http\Controllers\Api\Finance\TravelExpenseController;
use Illuminate\Support\Facades\Route;

// Bills & Reimbursements. Access is gated inside BillController via
// config('bills_access.*') — submitters see their own; admins (Ayush #4 /
// Shoyab #32) get the Pay Queue + Records.

// Submitter
Route::get('/bills', [BillController::class, 'index']);
Route::post('/bills', [BillController::class, 'store']);
Route::post('/bills/{bill}/files', [BillController::class, 'addFiles']); // add attachments while still pending
Route::put('/bills/{bill}', [BillController::class, 'update']);          // edit details (amount, title…) while still pending

// Admin (static paths declared before the {bill} param routes)
Route::get('/bills/queue', [BillController::class, 'queue']);       // Pay Queue
Route::get('/bills/queue/export', [BillController::class, 'queueExport']); // xlsx export of the pending queue
Route::get('/bills/records', [BillController::class, 'records']);   // paid accounts ledger
Route::get('/bills/records/export', [BillController::class, 'recordsExport']); // xlsx export
Route::get('/bills/pending-summary', [BillController::class, 'pendingSummary']); // dashboard card + dot

Route::post('/bills/{bill}/mark-paid', [BillController::class, 'markPaid']);
Route::post('/bills/{bill}/reject', [BillController::class, 'reject']);
Route::post('/bills/{bill}/announce-paid', [BillController::class, 'announcePaid']); // admin posts per-user "paid" notification
Route::delete('/bills/{bill}', [BillController::class, 'destroy']); // submitter cancels own pending

// Travel-Expense trips (Travel Allowance tab). Gated inside TravelExpenseController
// via config('bills_access.*') — same travel allow-list + admins (Ayush #4 /
// Shoyab #32) as Bills. Static ledger paths declared before the {trip} param route.
Route::get('/travel-trips', [TravelExpenseController::class, 'index']);            // employee: own trips + cap
Route::post('/travel-trips', [TravelExpenseController::class, 'store']);           // employee: log a trip (multipart)
Route::get('/travel-trips/ledger', [TravelExpenseController::class, 'ledger']);    // admin: cross-employee ledger
Route::get('/travel-trips/ledger/export', [TravelExpenseController::class, 'ledgerExport']); // admin: xlsx
Route::put('/travel-trips/{trip}', [TravelExpenseController::class, 'update']);    // employee: edit own (unlocked) trip
Route::delete('/travel-trips/{trip}', [TravelExpenseController::class, 'destroy']); // employee: delete own (unlocked) trip
