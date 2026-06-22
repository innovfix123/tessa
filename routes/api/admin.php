<?php

use App\Http\Controllers\Api\AdminApiController;

Route::get('/dashboard-status', [AdminApiController::class, 'dashboardStatus']);

Route::prefix('admin')->group(function () {
    Route::middleware(['role:admin,ceo'])->group(function () {
        Route::get('/meetings-overview', [AdminApiController::class, 'meetingsOverview']);
        Route::get('/daily-reports-overview', [AdminApiController::class, 'dailyReportsOverview']);
        Route::get('/signin-overview', [AdminApiController::class, 'signInOverview']);
        Route::get('/tasks-overview', [AdminApiController::class, 'tasksOverview']);
        Route::get('/signin-status', [AdminApiController::class, 'signinStatus']);
    });
    // Team Status grid — CEO + per-user allow-list (config/team_status_access.php).
    // JP (CEO #1) is listed in that config so he keeps access here.
    Route::middleware('user.allowlist:team_status_access.user_ids')->group(function () {
        Route::get('/employee-overview', [AdminApiController::class, 'employeeOverview']);
    });
    Route::middleware('user.allowlist:signin_status_access.user_ids')->group(function () {
        Route::get('/signin-status', [AdminApiController::class, 'signinStatus']);
    });
});
