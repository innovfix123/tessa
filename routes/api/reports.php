<?php

use App\Http\Controllers\Api\Marketing\CreativeUploadController;
use App\Http\Controllers\Api\Reports\CreativeCategoryController;
use App\Http\Controllers\Api\Reports\DailyReportController;
use App\Http\Controllers\Api\Reports\KpiController;
use App\Http\Controllers\Api\Reports\KpiDefinitionController;
use App\Http\Controllers\Api\Reports\KpiReportController;
use App\Http\Controllers\Api\Reports\KraScorecardController;
use App\Http\Controllers\Api\Reports\ManagerReviewController;
use App\Http\Controllers\Api\Reports\MissionController;
use App\Http\Controllers\Api\Reports\PendingWorkController;
use App\Http\Controllers\Api\Reports\NetworkLeverageController;
use App\Http\Controllers\Api\Reports\SignoffController;
use App\Http\Controllers\Api\Reports\VideoHandoffController;

Route::get('/network-leverage', [NetworkLeverageController::class, 'index']);
Route::post('/network-leverage', [NetworkLeverageController::class, 'store']);
Route::delete('/network-leverage/{id}', [NetworkLeverageController::class, 'destroy']);

Route::get('/daily-reports/pending', [DailyReportController::class, 'pendingDays']);
Route::get('/daily-reports/export', [DailyReportController::class, 'export']);
Route::get('/daily-reports', [DailyReportController::class, 'index']);
Route::post('/daily-reports', [DailyReportController::class, 'store']);
Route::get('/creative-uploads', [CreativeUploadController::class, 'index']);
Route::post('/creative-uploads', [CreativeUploadController::class, 'store']);
Route::get('/video-handoffs', [VideoHandoffController::class, 'index']);
Route::get('/video-handoffs/zip', [VideoHandoffController::class, 'downloadZip']);
Route::post('/video-handoffs', [VideoHandoffController::class, 'store']);
Route::get('/kpi', [KpiController::class, 'index']);
Route::post('/kpi', [KpiController::class, 'store']);
Route::get('/kpi-definitions', [KpiDefinitionController::class, 'index']);
Route::post('/kpi-definitions', [KpiDefinitionController::class, 'store']);
Route::get('/signoff-status', [SignoffController::class, 'status']);
Route::get('/dashboard-state', [SignoffController::class, 'dailyState']);
Route::post('/signoff', [SignoffController::class, 'store']);
Route::delete('/signoff', [SignoffController::class, 'destroy']);
Route::post('/signin', [SignoffController::class, 'signIn']);
Route::delete('/signin', [SignoffController::class, 'undoSignIn']);
Route::get('/pending-work', [PendingWorkController::class, 'index']);
Route::get('/my-kras', [KraScorecardController::class, 'show']);
Route::get('/my-kras/users', [KraScorecardController::class, 'users']);
Route::get('/my-kras/team-table', [KraScorecardController::class, 'teamTable']);
Route::get('/my-kras/history', [KraScorecardController::class, 'history']);
Route::get('/mission', [MissionController::class, 'index']);
Route::get('/manager-review', [ManagerReviewController::class, 'index']);
Route::post('/manager-review', [ManagerReviewController::class, 'store']);
Route::get('/manager-ratings/overview', [ManagerReviewController::class, 'overview']);

// Creative category — Krishnan/Kishore set a daily work-focus note shown to
// their direct reports (dashboard card + sign-in modal).
Route::get('/creative-category', [CreativeCategoryController::class, 'show']);
Route::post('/creative-category', [CreativeCategoryController::class, 'store']);

// KPI Report — managers add weekly KPI tracking notes (Fri–Sun, never locked);
// employees view their own read-only; JP views everyone + manages KPI defs.
Route::get('/kpi-report/people', [KpiReportController::class, 'people']);
Route::get('/kpi-report/pending', [KpiReportController::class, 'pending']);
Route::get('/kpi-report/user/{id}', [KpiReportController::class, 'show'])->whereNumber('id');
Route::post('/kpi-report/user/{id}/week', [KpiReportController::class, 'saveWeek'])->whereNumber('id');
Route::post('/kpi-report/items', [KpiReportController::class, 'storeItem']);
Route::patch('/kpi-report/items/{id}', [KpiReportController::class, 'updateItem'])->whereNumber('id');
Route::delete('/kpi-report/items/{id}', [KpiReportController::class, 'destroyItem'])->whereNumber('id');
