<?php

use App\Http\Controllers\V1\SessionController;
use App\Http\Controllers\V1\MessageController;
use App\Http\Controllers\V1\FileAttachmentController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function () {

    Route::get('/health', fn () => response()->json(['status' => 'ok', 'service' => 'chat']));

    Route::middleware('auth.jwt')->group(function () {

        // Sessions
        Route::get('/sessions',            [SessionController::class, 'index']);
        Route::post('/sessions',           [SessionController::class, 'store']);
        Route::get('/sessions/{id}',       [SessionController::class, 'show']);
        Route::patch('/sessions/{id}',     [SessionController::class, 'update']);  // rename title
        Route::delete('/sessions/{id}',    [SessionController::class, 'destroy']); // soft-delete
        Route::get('/sessions/{id}/export',[SessionController::class, 'export']);

        // Messages within a session
        Route::get('/sessions/{sessionId}/messages',  [MessageController::class, 'index']);
        Route::post('/sessions/{sessionId}/messages', [MessageController::class, 'store']); // save after stream

        // File uploads
        Route::post('/upload',             [FileAttachmentController::class, 'upload']);
        Route::delete('/upload/{id}',      [FileAttachmentController::class, 'destroy']);
    });
});
