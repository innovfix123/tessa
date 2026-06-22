<?php

use App\Http\Controllers\Api\Meetings\AgendaSectionController;
use App\Http\Controllers\Api\Meetings\AgendaTemplateController;
use App\Http\Controllers\Api\Meetings\DiscussionPointController;
use App\Http\Controllers\Api\Meetings\MeetingController;
use App\Http\Controllers\Api\Meetings\MeetingNoteController;
use App\Http\Controllers\Api\Meetings\MeetingAttendanceController;
use App\Http\Controllers\Api\Meetings\MeetingSchedulerController;

Route::get('/meetings/pending-notes', [MeetingController::class, 'pendingNotes']);
Route::get('/meetings', [MeetingController::class, 'index']);
Route::post('/meetings', [MeetingController::class, 'store']);
Route::get('/meeting-notes', [MeetingNoteController::class, 'index']);
Route::post('/meeting-notes', [MeetingNoteController::class, 'store']);
Route::get('/discussion-points', [DiscussionPointController::class, 'index']);
Route::post('/discussion-points', [DiscussionPointController::class, 'store']);
Route::get('/action-items', [MeetingController::class, 'actionItems']);
Route::get('/agenda-sections', [AgendaSectionController::class, 'index']);
Route::post('/agenda-sections', [AgendaSectionController::class, 'store']);
Route::get('/agenda-templates', [AgendaTemplateController::class, 'index']);
Route::post('/agenda-templates', [AgendaTemplateController::class, 'store']);

Route::get('/meeting-attendance', [MeetingAttendanceController::class, 'index']);
Route::post('/meeting-attendance', [MeetingAttendanceController::class, 'store']);
Route::get('/meeting-attendance/summary', [MeetingAttendanceController::class, 'summary']);
Route::get('/meeting-attendance/overview', [MeetingAttendanceController::class, 'overview']);

Route::post('/meetings/schedule/analyze', [MeetingSchedulerController::class, 'analyze']);
Route::post('/meetings/schedule/create', [MeetingSchedulerController::class, 'create']);
Route::post('/meetings/schedule/reschedule', [MeetingSchedulerController::class, 'reschedule']);
Route::post('/meetings/schedule/skip', [MeetingSchedulerController::class, 'skip']);
Route::get('/meetings/schedule/list', [MeetingSchedulerController::class, 'list']);
Route::post('/meetings/schedule/delete', [MeetingSchedulerController::class, 'delete']);
