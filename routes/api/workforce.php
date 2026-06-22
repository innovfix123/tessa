<?php

use App\Http\Controllers\Api\Workforce\WorkforcePaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware('role:admin,accountant,ceo,cfo')->group(function () {
    Route::get('/workforce/payments', [WorkforcePaymentController::class, 'index']);
    Route::get('/workforce/payments/week-summary', [WorkforcePaymentController::class, 'weekSummary']);
    Route::get('/workforce/payments/user/{userId}', [WorkforcePaymentController::class, 'userWeek']);
    Route::post('/workforce/payments/mark-paid', [WorkforcePaymentController::class, 'markPaid']);
    Route::post('/workforce/payments/bulk-mark-paid', [WorkforcePaymentController::class, 'bulkMarkPaid']);
    Route::get('/workforce/payments/{payment}/screenshot', [WorkforcePaymentController::class, 'screenshot']);
});
