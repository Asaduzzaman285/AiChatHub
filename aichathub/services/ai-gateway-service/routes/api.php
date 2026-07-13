<?php

use App\Http\Controllers\V1\ChatController;
use App\Http\Controllers\V1\ImageController;
use App\Http\Controllers\V1\AudioController;
use App\Http\Controllers\V1\TranscriptionController;
use App\Http\Controllers\V1\ModelController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function () {

    Route::get('/health', fn () => response()->json(['status' => 'ok', 'service' => 'ai-gateway']));

    Route::middleware('auth.jwt')->group(function () {

        // Available models (filtered by user's subscription)
        Route::get('/models', [ModelController::class, 'index']);

        // Text chat — streaming SSE
        Route::post('/chat/stream', [ChatController::class, 'stream']);

        // Multi-model comparison (Standard/Pro only)
        Route::post('/chat/compare', [ChatController::class, 'compare']);

        // Image generation (Pro only)
        Route::post('/generate/image', [ImageController::class, 'generate']);

        // Audio TTS (Pro only)
        Route::post('/generate/audio', [AudioController::class, 'generate']);

        // Audio STT / Transcription (Pro only)
        Route::post('/transcribe', [TranscriptionController::class, 'transcribe']);
    });
});
