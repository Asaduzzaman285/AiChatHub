<?php

use App\Http\Controllers\V1\ChatController;
use App\Http\Controllers\V1\ImageController;
use App\Http\Controllers\V1\AudioController;
use App\Http\Controllers\V1\TranscriptionController;
use App\Http\Controllers\V1\ModelController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Health
Route::get('/health', fn () => response()->json(['status' => 'ok', 'service' => 'ai-gateway']));
Route::get('/ready',  [HealthController::class, 'ready']);

// Authenticated
Route::middleware('auth.jwt')->group(function () {
    Route::get('/models',           [ModelController::class,       'index']);
    Route::post('/chat/stream',     [ChatController::class,        'stream']);
    Route::post('/chat/compare',    [ChatController::class,        'compare']);
    Route::post('/generate/image',  [ImageController::class,       'generate']);
    Route::post('/generate/audio',  [AudioController::class,       'generate']);
    Route::post('/transcribe',      [TranscriptionController::class,'transcribe']);
});
