<?php

use App\Http\Controllers\Api\AiUsageController;
use App\Http\Controllers\Api\SalaryToolController;
use App\Http\Controllers\Api\ClaudeContextController;
use App\Http\Controllers\Api\DashboardNoteController;
use App\Http\Controllers\Api\LogEntryController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\ManagerNotificationController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::get('/auth/session', [AuthController::class, 'session']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::get('/auth/google', [AuthController::class, 'googleLogin']);
    Route::get('/auth/google/callback', [AuthController::class, 'googleLoginCallback']);
});
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware(['web', 'auth']);
Route::post('/auth/change-password', [AuthController::class, 'changePassword'])->middleware(['web', 'auth']);

Route::middleware(['web', 'auth'])->group(function () {
    require __DIR__.'/api/meetings.php';
    require __DIR__.'/api/reports.php';
    require __DIR__.'/api/tasks.php';
    require __DIR__.'/api/agile.php';
    require __DIR__.'/api/finance.php';
    require __DIR__.'/api/marketing.php';
    require __DIR__.'/api/hr.php';
    require __DIR__.'/api/workforce.php';
    require __DIR__.'/api/support.php';
    require __DIR__.'/api/tessa.php';
    require __DIR__.'/api/admin.php';
    require __DIR__.'/api/slack.php';
    require __DIR__.'/api/github.php';
    require __DIR__.'/api/google.php';
    require __DIR__.'/api/rewards.php';
    require __DIR__.'/api/bills.php';
    require __DIR__.'/api/hiring.php';

    Route::get('/notes', [DashboardNoteController::class, 'index']);
    Route::post('/notes', [DashboardNoteController::class, 'store']);
    Route::put('/notes/{dashboardNote}', [DashboardNoteController::class, 'update']);
    Route::delete('/notes/{dashboardNote}', [DashboardNoteController::class, 'destroy']);

    Route::get('/logs', [LogEntryController::class, 'index']);
    Route::post('/logs', [LogEntryController::class, 'store']);
    Route::post('/logs/assign-task', [LogEntryController::class, 'assignTask']);
    Route::post('/logs/request-leave', [LogEntryController::class, 'requestLeave']);
    Route::get('/logs/slack-status', [LogEntryController::class, 'slackStatus']);
    Route::patch('/logs/{logEntry}', [LogEntryController::class, 'update']);
    Route::delete('/logs/{logEntry}', [LogEntryController::class, 'destroy']);

    // Claude Context — daily end-of-day summary. POST is the MCP write path
    // (log_claude_context tool via ApiSubRequest); GET serves the portal tab;
    // DELETE is the JP-only reset. There is no edit route — contexts are
    // write-once. Authorization (own vs all, reset) is enforced in the controller.
    Route::get('/claude-context', [ClaudeContextController::class, 'index']);
    // Pending Claude Context days this week — dashboard "pending" card for staff
    // moved off Daily Reports (2026-06-18 rollback).
    Route::get('/claude-context/pending', [ClaudeContextController::class, 'pendingDays']);
    Route::post('/claude-context', [ClaudeContextController::class, 'store']);
    Route::delete('/claude-context/{claudeContext}', [ClaudeContextController::class, 'destroy']);

    Route::get('/ai-usage', [AiUsageController::class, 'summary']);

    Route::post('/salary-tool', [SalaryToolController::class, 'compute']);

    Route::get('/manager-notifications', [ManagerNotificationController::class, 'index']);
    Route::post('/manager-notifications/clear', [ManagerNotificationController::class, 'clear']);

    // Company-wide dashboard announcements (Feature 8) — broadcast to everyone.
    Route::get('/announcements', [AnnouncementController::class, 'index']);
});

Route::middleware(['mcp.token', 'throttle:120,1'])->prefix('mcp')->group(function () {
    Route::get('/auth/session', [AuthController::class, 'session']);
    require __DIR__.'/api/meetings.php';
    require __DIR__.'/api/reports.php';
    require __DIR__.'/api/tasks.php';
    require __DIR__.'/api/agile.php';
    require __DIR__.'/api/finance.php';
    require __DIR__.'/api/marketing.php';
    require __DIR__.'/api/hr.php';
    require __DIR__.'/api/workforce.php';
    require __DIR__.'/api/support.php';
    require __DIR__.'/api/tessa.php';
    require __DIR__.'/api/admin.php';
    require __DIR__.'/api/slack.php';
    require __DIR__.'/api/github.php';
    require __DIR__.'/api/google.php';
    require __DIR__.'/api/rewards.php';

    Route::get('/notes', [DashboardNoteController::class, 'index']);
    Route::post('/notes', [DashboardNoteController::class, 'store']);
    Route::put('/notes/{dashboardNote}', [DashboardNoteController::class, 'update']);
    Route::delete('/notes/{dashboardNote}', [DashboardNoteController::class, 'destroy']);

    Route::get('/logs', [LogEntryController::class, 'index']);
    Route::post('/logs', [LogEntryController::class, 'store']);
    Route::post('/logs/assign-task', [LogEntryController::class, 'assignTask']);
    Route::post('/logs/request-leave', [LogEntryController::class, 'requestLeave']);
    Route::patch('/logs/{logEntry}', [LogEntryController::class, 'update']);
    Route::delete('/logs/{logEntry}', [LogEntryController::class, 'destroy']);

    Route::get('/manager-notifications', [ManagerNotificationController::class, 'index']);
    Route::post('/manager-notifications/clear', [ManagerNotificationController::class, 'clear']);
});
