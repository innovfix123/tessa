<?php

use App\Http\Controllers\Api\Rewards\RewardPoolController;
use App\Http\Controllers\Api\Rewards\RewardTaskController;
use App\Http\Controllers\Api\Rewards\RewardWalletController;
use App\Http\Controllers\Api\Rewards\RewardWithdrawalController;

// Wallet (everyone)
Route::get('/rewards/wallet', [RewardWalletController::class, 'show']);

// Tasks — assignee view
Route::get('/rewards/tasks/mine', [RewardTaskController::class, 'mine']);
Route::get('/rewards/tasks/{task}', [RewardTaskController::class, 'show']);
Route::post('/rewards/tasks/{task}/updates', [RewardTaskController::class, 'postUpdate']);
Route::post('/rewards/tasks/{task}/submit', [RewardTaskController::class, 'submit']);

// Tasks — JP admin (gated inside controller via config('rewards.reviewers'))
Route::get('/rewards/tasks/manage/all', [RewardTaskController::class, 'manage']);
Route::post('/rewards/tasks', [RewardTaskController::class, 'store']);
Route::patch('/rewards/tasks/{task}', [RewardTaskController::class, 'update']);
Route::post('/rewards/tasks/{task}/approve', [RewardTaskController::class, 'approve']);
Route::post('/rewards/tasks/{task}/reject', [RewardTaskController::class, 'reject']);

// Withdrawals — assignee view (read-only; no self-request)
Route::get('/rewards/withdrawals/me', [RewardWithdrawalController::class, 'mine']);

// Withdrawals — Ayush queue (gated inside controller via config('rewards.payers'))
Route::get('/rewards/withdrawals/pending', [RewardWithdrawalController::class, 'pending']);
Route::post('/rewards/withdrawals/{withdrawal}/mark-paid', [RewardWithdrawalController::class, 'markPaid']);

// Reward Pools — manager (Krishnan) logs a team reward → payer (Ayush) settles.
// Creator endpoints gated by config('rewards.pool_creators'); pay endpoints by
// config('rewards.payers'), both inside RewardPoolController.
Route::get('/rewards/pools/mine', [RewardPoolController::class, 'mine']);
Route::post('/rewards/pools', [RewardPoolController::class, 'store']);
Route::get('/rewards/pools/pending', [RewardPoolController::class, 'pending']);
Route::post('/rewards/pools/{pool}/mark-paid', [RewardPoolController::class, 'markPaid']);
