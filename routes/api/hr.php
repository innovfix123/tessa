<?php

use App\Http\Controllers\Api\HR\AttendanceController;
use App\Http\Controllers\Api\HR\DepartmentController;
use App\Http\Controllers\Api\HR\DesignationController;
use App\Http\Controllers\Api\HR\EmployeeController;
use App\Http\Controllers\Api\HR\FreelanceHrApplicantController;
use App\Http\Controllers\Api\HR\HRDashboardController;
use App\Http\Controllers\Api\HR\LeaveController;
use App\Http\Controllers\Api\HR\LetterController;
use App\Http\Controllers\Api\HR\NdaController;
use App\Http\Controllers\Api\HR\TimesheetAssistantController;
use App\Http\Controllers\Api\HR\TimesheetController;
use App\Http\Controllers\Api\HR\TimesheetTrackerController;
use App\Http\Controllers\Api\HR\WeeklyTimesheetController;

Route::get('/employees', [EmployeeController::class, 'index']);
Route::post('/employees', [EmployeeController::class, 'store']);
Route::get('/employees/export-fields', [EmployeeController::class, 'exportFields']);
Route::post('/employees/export', [EmployeeController::class, 'export']);
Route::get('/employees/export-document-fields', [EmployeeController::class, 'exportDocumentFields']);
Route::post('/employees/export-documents', [EmployeeController::class, 'exportDocuments']);
// Employee Documents browser — writer-backed Drive folder listing (in-Tessa, no new tabs).
Route::get('/employees/drive-folder', [EmployeeController::class, 'driveFolder']);
// Move a Drive file/folder to Trash from the Employee Documents browser (double-confirmed in the UI).
Route::post('/employees/drive-item/trash', [EmployeeController::class, 'trashDriveItem']);
Route::get('/employees/{userId}/salary-history', [EmployeeController::class, 'salaryHistory']);
Route::get('/employees/{userId}/promotion-history', [EmployeeController::class, 'promotionHistory']);
Route::get('/profile', [EmployeeController::class, 'profile']);
Route::post('/profile', [EmployeeController::class, 'profileStore']);
Route::post('/profile/photo', [EmployeeController::class, 'uploadProfilePhoto']);
Route::delete('/profile/photo', [EmployeeController::class, 'removeProfilePhoto']);
Route::get('/leave/types', [LeaveController::class, 'types']);
Route::get('/leave/requests', [LeaveController::class, 'index']);
Route::post('/leave/requests', [LeaveController::class, 'store']);
Route::post('/leave/requests/{leaveRequest}/review', [LeaveController::class, 'review']);
Route::post('/leave/requests/{leaveRequest}/cancel', [LeaveController::class, 'cancel']);
Route::post('/leave/requests/{leaveRequest}/request-cancellation', [LeaveController::class, 'requestCancellation']);
Route::post('/leave/requests/{leaveRequest}/review-cancellation', [LeaveController::class, 'reviewCancellation']);
Route::get('/leave/team-requests', [LeaveController::class, 'teamRequests']);
Route::get('/leave/team-pending', [LeaveController::class, 'teamPending']);
Route::get('/leave/team-on-leave-today', [LeaveController::class, 'teamOnLeaveToday']);
Route::get('/leave/team-overview', [LeaveController::class, 'teamOverview']);
Route::get('/hr-applicants', [FreelanceHrApplicantController::class, 'index']);
Route::patch('/hr-applicants/{hrApplicant}', [FreelanceHrApplicantController::class, 'update']);

// HR Dashboard
Route::get('/hr/dashboard', [HRDashboardController::class, 'index']);
// Probation lifecycle actions (HR-gated in-controller, same ALLOWED_ROLES).
Route::post('/hr/probation/confirm', [HRDashboardController::class, 'confirm']);
Route::post('/hr/probation/extend', [HRDashboardController::class, 'extend']);

// Departments & Designations
Route::get('/departments', [DepartmentController::class, 'index']);
Route::post('/departments', [DepartmentController::class, 'store']);
Route::get('/designations', [DesignationController::class, 'index']);
Route::post('/designations', [DesignationController::class, 'store']);

// Offer / Appointment Letters — HR-gated (CEO, COO, CFO, HR, BA).
// No ->name() here: this file is included from both the auth and MCP groups,
// so named routes would collide during route:cache.
Route::get('/letters', [LetterController::class, 'index']);
Route::get('/letters/template-config', [LetterController::class, 'templateConfig']);
Route::get('/letters/prefill', [LetterController::class, 'prefill']);
Route::post('/letters/preview', [LetterController::class, 'preview']);
Route::post('/letters/preview-breakup', [LetterController::class, 'previewBreakup']);
Route::post('/letters/draft', [LetterController::class, 'saveDraft']);
Route::post('/letters', [LetterController::class, 'store']);
Route::get('/letters/{id}/download', [LetterController::class, 'download'])->where('id', '[0-9]+');
Route::get('/letters/{id}', [LetterController::class, 'show'])->where('id', '[0-9]+');
Route::delete('/letters/{id}', [LetterController::class, 'destroy'])->where('id', '[0-9]+');

// Auto-filled employee NDA (download → hand-sign → upload back). Self-service for the
// caller; HR can generate any employee's NDA. No ->name() (file is shared with the MCP group).
Route::get('/documents/nda', [NdaController::class, 'mine']);
Route::get('/employees/{id}/nda', [NdaController::class, 'forEmployee'])->where('id', '[0-9]+');

// Timesheets + AI assistant — per-user allow-list (see config/timesheet_access.php).
Route::middleware('user.allowlist:timesheet_access.self_log_user_ids')->group(function () {
    Route::get('/timesheets/week', [TimesheetController::class, 'week']);
    Route::get('/timesheets/summary', [TimesheetController::class, 'summary']);
    Route::post('/timesheets', [TimesheetController::class, 'store']);
    Route::delete('/timesheets/{timesheet}', [TimesheetController::class, 'destroy']);

    Route::post('/timesheet-assistant/message', [TimesheetAssistantController::class, 'message']);
    Route::post('/timesheet-assistant/submit', [TimesheetAssistantController::class, 'submit']);
});

// Timesheet submission tracker — per-user allow-list (see config/timesheet_access.php).
Route::middleware('user.allowlist:timesheet_access.tracker_user_ids')->group(function () {
    Route::get('/timesheet-tracker/daily', [TimesheetTrackerController::class, 'daily']);
    Route::get('/timesheet-tracker/weekly', [TimesheetTrackerController::class, 'weekly']);
    Route::get('/timesheet-tracker/monthly', [TimesheetTrackerController::class, 'monthly']);
    Route::post('/timesheet-tracker/nudge/{userId}', [TimesheetTrackerController::class, 'nudge']);
    Route::post('/timesheet-tracker/nudge-bulk', [TimesheetTrackerController::class, 'nudgeBulk']);
});

// Weekly Timesheet — company-wide Friday work record (config/weekly_timesheet.php).
// No allow-list middleware: everyone fills (except excluded_user_ids). `mine`/`store`
// act on the caller's own week; the `team` review is gated in-controller.
// No ->name(): this file is included from both the auth and MCP groups.
Route::get('/weekly-timesheet/mine', [WeeklyTimesheetController::class, 'mine']);
Route::post('/weekly-timesheet', [WeeklyTimesheetController::class, 'store']);
Route::get('/weekly-timesheet/team', [WeeklyTimesheetController::class, 'team']);

// HR/Accountant attendance roster — per-user allow-list
// (see config/attendance_view_access.php).
Route::middleware('user.allowlist:attendance_view_access.user_ids')->group(function () {
    Route::get('/hr/attendance/daily', [AttendanceController::class, 'daily']);
    Route::get('/hr/attendance/monthly', [AttendanceController::class, 'monthly']);
});
