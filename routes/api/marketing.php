<?php

use App\Http\Controllers\Api\Marketing\GoogleAdReportController;
use App\Http\Controllers\Api\Marketing\MetaAdReportController;
use App\Http\Controllers\Api\Marketing\ScriptGenerationController;

Route::get('/meta-ad-reports', [MetaAdReportController::class, 'index']);
Route::post('/meta-ad-reports', [MetaAdReportController::class, 'store']);
Route::get('/google-ad-reports', [GoogleAdReportController::class, 'index']);
Route::post('/google-ad-reports', [GoogleAdReportController::class, 'store']);
Route::get('/scripts/stats', [ScriptGenerationController::class, 'stats']);
Route::get('/scripts', [ScriptGenerationController::class, 'index']);
Route::post('/scripts/generate', [ScriptGenerationController::class, 'generate']);
Route::post('/scripts/library', [ScriptGenerationController::class, 'saveLibrary']);
Route::delete('/scripts/library/{id}', [ScriptGenerationController::class, 'destroyLibrary']);
