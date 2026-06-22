<?php

use App\Http\Controllers\Api\HR\HiringController;
use Illuminate\Support\Facades\Route;

// Hiring / Recruitment (ATS). Access is gated inside HiringController via
// config('hiring_access.*') + role — HR/management see all JDs and assign them;
// panel members (JD authors) create + see their own; freelance recruiters see
// only JDs assigned to them.
Route::get('/hiring/job-descriptions', [HiringController::class, 'index']);
Route::post('/hiring/job-descriptions', [HiringController::class, 'storeJd']);
Route::get('/hiring/job-descriptions/{jobDescription}', [HiringController::class, 'showJd']);
Route::post('/hiring/job-descriptions/{jobDescription}/assign', [HiringController::class, 'assignRecruiters']);

// Freelance recruiters: their permanent open-portal links (/r/{token}) + counts.
Route::get('/hiring/recruiters', [HiringController::class, 'recruiters']);
Route::post('/hiring/recruiters/{user}/regenerate-link', [HiringController::class, 'regenerateRecruiterLink']);

// Candidates (stages 3–4). Scoped in HiringController: HR/panel see all on a
// JD; the assigned freelancer sees + adds only their own uploads.
Route::post('/hiring/job-descriptions/{jobDescription}/candidates', [HiringController::class, 'uploadCandidate']);
Route::get('/hiring/job-descriptions/{jobDescription}/candidates', [HiringController::class, 'candidates']);
Route::get('/hiring/candidates/{candidate}', [HiringController::class, 'showCandidate']);
Route::post('/hiring/candidates/{candidate}/review', [HiringController::class, 'reviewCandidate']);

// Interviews (stages 5–6). HR or the JD owner run the rounds; Send to Tessa is HR-only.
Route::post('/hiring/candidates/{candidate}/interviews/draft', [HiringController::class, 'draftInterviewEmail']);
// Feature 9B: 1-hour interview slots for a date, busy/free from the caller's Google Calendar.
Route::get('/hiring/calendar-slots', [HiringController::class, 'calendarSlots']);
Route::post('/hiring/candidates/{candidate}/interviews', [HiringController::class, 'saveInterview']);
Route::post('/hiring/candidates/{candidate}/interviews/outcome', [HiringController::class, 'setInterviewOutcome']);
Route::post('/hiring/candidates/{candidate}/send-to-tessa', [HiringController::class, 'sendToTessa']);
// Feature 3: one-step "Add to Team Member" — provisions + auto-creates the Tessa account + notifies.
Route::post('/hiring/candidates/{candidate}/add-to-team', [HiringController::class, 'addToTeam']);

// Provisioning + Offer (stages 7–8).
Route::post('/hiring/candidates/{candidate}/provisioning/mark', [HiringController::class, 'markProvisioning']);
Route::post('/hiring/candidates/{candidate}/issue-offer', [HiringController::class, 'issueOffer']);
// Acceptance — HR fallback when the Gmail auto-detector doesn't catch the reply.
Route::post('/hiring/candidates/{candidate}/mark-accepted', [HiringController::class, 'markAccepted']);

// Onboarding (stage 9). onboard = HR creates the Tessa account; onboarding[/complete]
// is the new hire's own checklist (any authenticated user — self-scoped).
Route::get('/hiring/onboard-options', [HiringController::class, 'onboardOptions']);
Route::post('/hiring/candidates/{candidate}/onboard', [HiringController::class, 'onboardCandidate']);
Route::get('/hiring/onboarding', [HiringController::class, 'onboardingStatus']);
Route::post('/hiring/onboarding/complete', [HiringController::class, 'completeOnboarding']);
