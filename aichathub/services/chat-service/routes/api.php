<?php

use App\Http\Controllers\V1\SessionController;
use App\Http\Controllers\V1\MessageController;
use App\Http\Controllers\V1\FileAttachmentController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Health
Route::get('/health', fn () => response()->json(['status' => 'ok', 'service' => 'chat']));
Route::get('/ready',  [HealthController::class, 'ready']);

// Authenticated
Route::middleware('auth.jwt')->group(function () {
    Route::get('/sessions',                           [SessionController::class, 'index']);
    Route::post('/sessions',                          [SessionController::class, 'store']);
    Route::get('/sessions/{id}',                      [SessionController::class, 'show']);
    Route::patch('/sessions/{id}',                    [SessionController::class, 'update']);
    Route::delete('/sessions/{id}',                   [SessionController::class, 'destroy']);
    Route::get('/sessions/{id}/export',               [SessionController::class, 'export']);

    Route::get('/sessions/{sessionId}/messages',      [MessageController::class, 'index']);
    Route::post('/sessions/{sessionId}/messages',     [MessageController::class, 'store']);

    Route::post('/upload',   [FileAttachmentController::class, 'upload']);
    Route::delete('/upload/{id}', [FileAttachmentController::class, 'destroy']);
});
