<?php

use App\Http\Controllers\Api\Finance\HimaRevenueSheetController;
use App\Http\Controllers\Api\Finance\InvoiceSubmissionController;
use App\Http\Controllers\Api\Finance\RevenueController;

Route::get('/invoice-submissions', [InvoiceSubmissionController::class, 'index']);
Route::post('/invoice-submissions', [InvoiceSubmissionController::class, 'store']);
Route::get('/invoice-submissions/download-all', [InvoiceSubmissionController::class, 'downloadAll']);
Route::get('/invoice-reconciliation', [InvoiceSubmissionController::class, 'reconciliation']);
Route::get('/revenue/daily-payout', [RevenueController::class, 'dailyRevenuePayout']);

Route::get('/hima-revenue-sheet/months', [HimaRevenueSheetController::class, 'months']);
Route::get('/hima-revenue-sheet', [HimaRevenueSheetController::class, 'index']);
Route::put('/hima-revenue-sheet/{date}', [HimaRevenueSheetController::class, 'update']);
