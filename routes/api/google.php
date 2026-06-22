<?php

use App\Http\Controllers\Api\Gmail\GmailInsightsController;
use App\Http\Controllers\Api\Google\GoogleController;
use Illuminate\Support\Facades\Route;

// OAuth flow
Route::get('/google/connect', [GoogleController::class, 'connect']);
Route::get('/google/callback', [GoogleController::class, 'callback']);
Route::post('/google/disconnect', [GoogleController::class, 'disconnect']);
Route::get('/google/status', [GoogleController::class, 'status']);

// Google API (requires connection)
Route::prefix('google')->middleware('google.connected')->group(function () {
    // Gmail
    Route::get('/gmail/messages', [GoogleController::class, 'gmailMessages']);
    Route::get('/gmail/messages/{messageId}', [GoogleController::class, 'gmailRead']);

    // Calendar
    Route::get('/calendar/events', [GoogleController::class, 'calendarEvents']);
    Route::post('/calendar/events', [GoogleController::class, 'calendarCreateEvent']);

    // Calendar notes (personal Calendar section — month grid + dashboard card)
    Route::get('/calendar/month', [GoogleController::class, 'calendarMonth']);
    Route::get('/calendar/upcoming', [GoogleController::class, 'calendarUpcoming']);
    Route::post('/calendar/notes', [GoogleController::class, 'calendarCreateNote']);
    Route::patch('/calendar/notes/{eventId}', [GoogleController::class, 'calendarUpdateNote']);
    Route::delete('/calendar/notes/{eventId}', [GoogleController::class, 'calendarDeleteNote']);

    // Drive
    Route::get('/drive/files', [GoogleController::class, 'driveFiles']);
});

// Gmail dashboard insights (AI-classified important emails) — mirrors the Slack
// insights endpoints. Reads + actions need only auth (so a user who later
// disconnects Gmail can still clear old cards); the live scan needs a connection.
Route::prefix('gmail')->group(function () {
    Route::get('/insights', [GmailInsightsController::class, 'index']);
    Route::delete('/insights', [GmailInsightsController::class, 'clear']);
    Route::put('/insights/{id}', [GmailInsightsController::class, 'update']);
    Route::post('/insights/{id}/snooze', [GmailInsightsController::class, 'snooze']);
    Route::post('/insights/{id}/create-task', [GmailInsightsController::class, 'createTask']);
    Route::post('/insights/mark-all-seen', [GmailInsightsController::class, 'markAllSeen']);
});

Route::prefix('gmail')->middleware('google.connected')->group(function () {
    Route::post('/insights/scan', [GmailInsightsController::class, 'scan']);
});
