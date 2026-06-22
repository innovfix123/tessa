<?php

use App\Http\Controllers\Api\JpAI\JpAiCommandController;
use App\Http\Controllers\Api\Tessa\TessaChatController;

// JP AI Command Center — gated to JP + JP_AI_MODE flag inside the controller.
Route::post('/jp/ai-command', [JpAiCommandController::class, 'handle']);

Route::get('/tessa/chats', [TessaChatController::class, 'index']);
Route::get('/tessa/chats/{chat}/messages', [TessaChatController::class, 'messages']);
Route::patch('/tessa/chats/{chat}', [TessaChatController::class, 'update']);
Route::post('/tessa/chat', [TessaChatController::class, 'chat']);
Route::delete('/tessa/chats/{chat}', [TessaChatController::class, 'destroy']);
Route::get('/tessa/dream-story', [TessaChatController::class, 'dreamStory']);
Route::get('/tessa/morning-quote', [TessaChatController::class, 'morningQuote']);
Route::post('/tessa/grammar', [TessaChatController::class, 'grammar']);
