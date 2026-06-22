<?php

use App\Http\Controllers\Api\Support\TicketController;

Route::get('/tickets', [TicketController::class, 'index']);
Route::get('/tickets/assignees', [TicketController::class, 'assignees']);
Route::get('/tickets/pending', [TicketController::class, 'pending']);
Route::post('/tickets', [TicketController::class, 'store']);
Route::put('/tickets/{ticket}', [TicketController::class, 'update']);
