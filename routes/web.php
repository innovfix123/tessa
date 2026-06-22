<?php

use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AiFirstController;
use App\Http\Controllers\Api\HR\LetterController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrgChartController;
use App\Http\Controllers\PublicRecruiterPortalController;
use App\Http\Controllers\Settings\ConnectClaudeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// `/` shows the marketing landing page for guests and falls through to the
// dashboard for authenticated users — keeping the existing in-app home URL
// stable so bookmarks and role redirects don't break.
Route::get('/', function (Request $request) {
    if (Auth::check()) {
        return app(DashboardController::class)->index($request);
    }
    return view('landing');
});

// Always-public preview of the landing page so logged-in employees can view
// it without signing out (useful for sharing the URL externally too).
Route::get('/welcome', fn () => view('landing'))->name('welcome');

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/admin', [AdminDashboardController::class, 'index']);
});

Route::middleware(['auth'])->group(function () {
    Route::get('/org', [OrgChartController::class, 'index']);
    Route::get('/settings/connect-claude', [ConnectClaudeController::class, 'show'])
        ->name('settings.connect-claude');
});

// Public tokenized download for share-via-Gmail / share-via-WhatsApp links.
// Token is 40-char random; the recipient gets only the PDF, no portal access.
Route::get('/letters/share/{token}', [LetterController::class, 'publicShare'])
    ->where('token', '[A-Za-z0-9]+')
    ->name('letters.share');

// Freelance recruiter's passwordless "open portal" — one permanent per-recruiter
// link. A dashboard SPA: view assigned JDs, upload several candidates at once,
// see history + each candidate's live hiring stage. No login; scoped entirely to
// the token's recruiter. The JSON endpoints are CSRF-exempt (bootstrap/app.php:
// 'r/*') because the 48-char token is the credential; throttled for abuse.
Route::get('/r/{token}', [PublicRecruiterPortalController::class, 'show'])
    ->where('token', '[A-Za-z0-9]+')
    ->name('recruiter.portal');
Route::middleware('throttle:120,1')->group(function () {
    Route::get('/r/{token}/jobs', [PublicRecruiterPortalController::class, 'jobs'])
        ->where('token', '[A-Za-z0-9]+')
        ->name('recruiter.portal.jobs');
    Route::get('/r/{token}/jobs/{jd}', [PublicRecruiterPortalController::class, 'job'])
        ->where(['token' => '[A-Za-z0-9]+', 'jd' => '[0-9]+'])
        ->name('recruiter.portal.job');
    Route::post('/r/{token}/jobs/{jd}/candidates', [PublicRecruiterPortalController::class, 'storeCandidate'])
        ->where(['token' => '[A-Za-z0-9]+', 'jd' => '[0-9]+'])
        ->name('recruiter.portal.candidate.store');
});

// AI First — single public page where everyone sees every name + a checkbox
// to mark their own Claude activation. Trust-based, no login required.
// Toggle endpoint is CSRF-protected (token comes from the page's meta tag).
Route::get('/ai-first', [AiFirstController::class, 'index'])->name('ai-first.index');
Route::post('/ai-first/{participant}/toggle', [AiFirstController::class, 'toggle'])
    ->where('participant', '[0-9]+')
    ->name('ai-first.toggle');

// AI First — public, view-only assessment page. Shows every assessee with
// their assessor, 30-min slot and role-matched questions. URL is
// /ai-first/assessment (the old /ai-first/exam path 301-redirects here so any
// already-shared links keep working).
Route::get('/ai-first/assessment', [AiFirstController::class, 'exam'])->name('ai-first.assessment');
Route::post('/ai-first/assessment/{participant}/mark', [AiFirstController::class, 'examMark'])
    ->where('participant', '[0-9]+')
    ->name('ai-first.assessment.mark');
Route::permanentRedirect('/ai-first/exam', '/ai-first/assessment');

// AI First — admin matrix view (CEO only). Same data, plus the ability to
// move people between squads. Self-update of Claude status happens on the
// public page above.
Route::middleware(['auth'])->group(function () {
    Route::get('/ai-first/admin', [AiFirstController::class, 'admin'])->name('ai-first.admin');
    Route::post('/ai-first/admin/move', [AiFirstController::class, 'adminMove'])->name('ai-first.admin.move');
});
