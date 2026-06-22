<?php

use App\Http\Controllers\Api\Tasks\ChecklistController;
use App\Http\Controllers\Api\Tasks\TaskAttachmentController;
use App\Http\Controllers\Api\Tasks\TaskBlockerController;
use App\Http\Controllers\Api\Tasks\TaskRecurrenceController;
use App\Http\Controllers\Api\Tasks\TaskSubtaskController;
use App\Http\Controllers\Api\Tasks\TaskThreadController;
use App\Http\Controllers\Api\Tasks\TaskCheckinController;
use App\Http\Controllers\Api\Tasks\TessaTaskController;

Route::prefix('tessa')->group(function () {
    Route::get('tasks/my-action-needed', [TessaTaskController::class, 'myActionNeeded']);
    Route::get('tasks/checkin-questions', [TessaTaskController::class, 'checkinQuestions']);
    Route::get('tasks/team-action-needed', [TessaTaskController::class, 'teamActionNeeded']);
    Route::get('tasks/extension-inbox', [TessaTaskController::class, 'extensionInbox']);
    Route::get('tasks/blocker-inbox', [TaskBlockerController::class, 'inbox']);
    Route::post('tasks/blockers/dismiss-all', [TaskBlockerController::class, 'dismissAll']);
    Route::get('tasks/verification-inbox', [TessaTaskController::class, 'verificationInbox']);
    Route::get('tasks/dependencies-options', [TessaTaskController::class, 'dependenciesOptions']);
    Route::get('tasks/my-assignees-options', [TessaTaskController::class, 'myAssigneesOptions']);
    Route::post('tasks/ai-expand', [TessaTaskController::class, 'aiExpand']);
    Route::get('tasks', [TessaTaskController::class, 'index']);
    Route::post('tasks', [TessaTaskController::class, 'store']);
    Route::get('tasks/{task}', [TessaTaskController::class, 'show']);
    Route::put('tasks/{task}', [TessaTaskController::class, 'update']);
    Route::delete('tasks/{task}', [TessaTaskController::class, 'destroy']);

    Route::prefix('tasks/{task}')->group(function () {
        // Checkin question (AI)
        Route::get('checkin-question', [TessaTaskController::class, 'checkinQuestion']);
        // Thread
        Route::get('thread', [TaskThreadController::class, 'messages']);
        Route::post('thread', [TaskThreadController::class, 'postMessage']);
        Route::post('invite', [TaskThreadController::class, 'invite']);
        // Subtasks
        Route::get('subtasks', [TaskSubtaskController::class, 'index']);
        Route::post('subtasks', [TaskSubtaskController::class, 'store']);
        Route::put('subtasks/{subtask}', [TaskSubtaskController::class, 'toggle']);
        Route::delete('subtasks/{subtask}', [TaskSubtaskController::class, 'destroy']);
        // Extend deadline (assignee only, first free, subsequent need approval)
        Route::post('extend-deadline', [TessaTaskController::class, 'extendDeadline']);
        Route::post('approve-extension', [TessaTaskController::class, 'approveExtension']);
        Route::post('deny-extension', [TessaTaskController::class, 'denyExtension']);
        Route::post('clear-extension-notice', [TessaTaskController::class, 'clearExtensionNotice']);
        // Verification flow (reporter or shared assigner)
        Route::post('verify', [TessaTaskController::class, 'verify']);
        Route::post('reopen', [TessaTaskController::class, 'reopen']);
        // Redirect — assignee or shared assigner passes the task on to someone else
        Route::post('redirect', [TessaTaskController::class, 'redirect']);
        // Mandatory-task: assignee confirms they filled the required form/sheet
        Route::post('confirm-form', [TessaTaskController::class, 'confirmFormSubmission']);
        // Escalate overdue task
        Route::post('escalate', [TessaTaskController::class, 'escalate']);
        // Nudge assignee
        Route::post('nudge', [TessaTaskController::class, 'nudge']);
        // Checkins (progress updates)
        Route::get('checkins', [TaskCheckinController::class, 'index']);
        Route::post('checkins', [TaskCheckinController::class, 'store']);
        Route::delete('checkins/{checkin}', [TaskCheckinController::class, 'destroy']);
        // Attachments
        Route::get('attachments', [TaskAttachmentController::class, 'index']);
        Route::post('attachments', [TaskAttachmentController::class, 'store']);
        Route::get('attachments/{attachment}/download', [TaskAttachmentController::class, 'download']);
        Route::delete('attachments/{attachment}', [TaskAttachmentController::class, 'destroy']);
        // Blockers — assignee-only manual list
        Route::get('blockers', [TaskBlockerController::class, 'index']);
        Route::post('blockers', [TaskBlockerController::class, 'store']);
        Route::delete('blockers/{blocker}', [TaskBlockerController::class, 'destroy']);
    });

    // Recurring tasks
    Route::get('recurrences', [TaskRecurrenceController::class, 'index']);
    Route::post('recurrences', [TaskRecurrenceController::class, 'store']);
    Route::put('recurrences/{recurrence}', [TaskRecurrenceController::class, 'update']);
    Route::delete('recurrences/{recurrence}', [TaskRecurrenceController::class, 'destroy']);

    // Daily checklists — assigner picks items, assignee ticks boxes daily.
    Route::get('checklists/assignees', [ChecklistController::class, 'assignees']);
    Route::post('checklists/updates/clear', [ChecklistController::class, 'clearUpdates']);
    Route::get('checklists', [ChecklistController::class, 'index']);
    Route::post('checklists', [ChecklistController::class, 'store']);
    Route::patch('checklists/{checklist}', [ChecklistController::class, 'update']);
    Route::delete('checklists/{checklist}', [ChecklistController::class, 'destroy']);
    Route::post('checklists/{checklist}/items/{item}/toggle', [ChecklistController::class, 'toggleCheck']);
    Route::post('checklists/{checklist}/items/{item}/note', [ChecklistController::class, 'saveNote']);
});
