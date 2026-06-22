<?php

use App\Http\Controllers\Api\GitHub\GitHubController;
use Illuminate\Support\Facades\Route;

// OAuth flow
Route::get('/github/connect', [GitHubController::class, 'connect']);
Route::get('/github/callback', [GitHubController::class, 'callback']);
Route::post('/github/disconnect', [GitHubController::class, 'disconnect']);
Route::get('/github/status', [GitHubController::class, 'status']);

// GitHub API (requires connection)
Route::prefix('github')->middleware('github.connected')->group(function () {
    Route::get('/repos', [GitHubController::class, 'repos']);
    Route::get('/repos/{owner}/{repo}/branches', [GitHubController::class, 'branches']);
    Route::get('/repos/{owner}/{repo}/pulls', [GitHubController::class, 'pullRequests']);
    Route::get('/repos/{owner}/{repo}/commits', [GitHubController::class, 'commits']);
    Route::get('/activity', [GitHubController::class, 'activity']);

    // Task ↔ GitHub
    Route::post('/tasks/{taskId}/create-branch', [GitHubController::class, 'createBranchForTask']);
    Route::get('/tasks/{taskId}/status', [GitHubController::class, 'taskStatus']);
});
