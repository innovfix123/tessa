<?php

use App\Http\Controllers\Api\Slack\SlackController;
use App\Http\Controllers\Api\Slack\SlackInsightsController;
use Illuminate\Support\Facades\Route;

// OAuth flow (no slack.connected — user isn't connected yet)
Route::get('/slack/connect', [SlackController::class, 'connect']);
Route::get('/slack/callback', [SlackController::class, 'callback']);
Route::post('/slack/disconnect', [SlackController::class, 'disconnect']);
Route::get('/slack/status', [SlackController::class, 'status']);

// Slack API (requires active connection)
Route::prefix('slack')->middleware('slack.connected')->group(function () {
    // Channels
    Route::get('/channels', [SlackController::class, 'channels']);
    Route::get('/channels/{channelId}', [SlackController::class, 'channelInfo']);
    Route::get('/channels/{channelId}/history', [SlackController::class, 'channelHistory']);
    Route::get('/channels/{channelId}/threads/{ts}', [SlackController::class, 'threadReplies']);

    // Messages
    Route::post('/messages', [SlackController::class, 'sendMessage']);
    Route::put('/messages', [SlackController::class, 'updateMessage']);
    Route::delete('/messages', [SlackController::class, 'deleteMessage']);

    // DMs & Group DMs
    Route::get('/dms', [SlackController::class, 'directMessages']);
    Route::get('/dms/{channelId}/history', [SlackController::class, 'dmHistory']);
    Route::get('/group-dms', [SlackController::class, 'groupDMs']);
    Route::post('/dms/open', [SlackController::class, 'openDM']);

    // Search
    Route::get('/search/messages', [SlackController::class, 'searchMessages']);
    Route::get('/search/files', [SlackController::class, 'searchFiles']);

    // Users
    Route::get('/users', [SlackController::class, 'users']);
    Route::get('/users/{userId}', [SlackController::class, 'userInfo']);
    Route::get('/users/{userId}/presence', [SlackController::class, 'userPresence']);

    // Files
    Route::get('/files', [SlackController::class, 'files']);
    Route::post('/files', [SlackController::class, 'uploadFile']);

    // Reactions
    Route::post('/reactions', [SlackController::class, 'addReaction']);
    Route::delete('/reactions', [SlackController::class, 'removeReaction']);

    // Huddle AI Notes
    Route::get('/huddle-notes', [SlackController::class, 'huddleNotes']);
    Route::post('/huddle-notes/sync', [SlackController::class, 'syncAllHuddleNotes']);
    Route::post('/huddle-notes/sync-one', [SlackController::class, 'syncOneHuddleNote']);

    // Manual pipeline trigger — requires the caller's own Slack OAuth token
    Route::post('/insights/scan', [SlackInsightsController::class, 'scan']);
});

// Insight reads + per-user actions: open to all authenticated users, since
// meeting attendees may be recipients of shared insights without having
// personally connected Slack to Tessa. The data was extracted server-side
// from one Slack-connected user's view.
Route::prefix('slack')->group(function () {
    Route::get('/insights', [SlackInsightsController::class, 'index']);
    Route::delete('/insights', [SlackInsightsController::class, 'clear']);
    Route::put('/insights/{id}', [SlackInsightsController::class, 'update']);
    Route::post('/insights/{id}/create-task', [SlackInsightsController::class, 'createTask']);
    Route::post('/insights/{id}/snooze', [SlackInsightsController::class, 'snooze']);
    Route::post('/insights/mark-all-seen', [SlackInsightsController::class, 'markAllSeen']);
});
