<?php

use App\Http\Controllers\V1\Admin\AiModelAdminController;
use App\Http\Controllers\V1\Admin\DashboardController;
use App\Http\Controllers\V1\Admin\UsageLogAdminController;
use App\Http\Controllers\V1\ChatController;
use App\Http\Controllers\V1\ImageController;
use App\Http\Controllers\V1\AudioController;
use App\Http\Controllers\V1\TranscriptionController;
use App\Http\Controllers\V1\ModelController;
use App\Http\Controllers\V1\UsageController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Health
Route::get('/health', fn () => response()->json(['status' => 'ok', 'service' => 'ai-gateway']));
Route::get('/ready',  [HealthController::class, 'ready']);

// Authenticated
Route::middleware('auth.jwt')->group(function () {
    Route::get('/models',           [ModelController::class,       'index']);

    // Wallet-touching routes — CostTrackingMiddleware assumes a consumer wallet exists,
    // which pure admin accounts (mode: 'create') never have. See BlockAdminMiddleware.
    Route::middleware('block.admin')->group(function () {
        Route::post('/chat/stream',     [ChatController::class,        'stream']);
        Route::post('/chat/compare',    [ChatController::class,        'compare']);
        Route::post('/generate/image',  [ImageController::class,       'generate']);
        Route::post('/generate/audio',  [AudioController::class,       'generate']);
        Route::post('/transcribe',      [TranscriptionController::class,'transcribe']);

        // Customer-scoped counterpart to /models/admin/usage-logs — own usage only.
        Route::get('/models/usage/summary', [UsageController::class, 'summary']);
        Route::get('/models/usage',         [UsageController::class, 'index']);
    });

    // Nested under /models (not bare /admin) so it's reachable through
    // api-gateway's existing /models/{path?} wildcard.
    Route::get('/models/admin/usage-logs', [UsageLogAdminController::class, 'index'])->middleware('admin.gate:ai_usage.view');
    Route::get('/models/admin/dashboard',  [DashboardController::class, 'index'])->middleware('admin.gate:dashboard.view');

    // No existing GET /models/{id} route to collide with, unlike the
    // packages/transactions admin routes — no ordering hazard here.
    Route::get('/models/admin',              [AiModelAdminController::class, 'index'])->middleware('admin.gate:models.manage');
    Route::post('/models/admin',             [AiModelAdminController::class, 'store'])->middleware('admin.gate:models.manage');
    Route::patch('/models/admin/{id}',       [AiModelAdminController::class, 'update'])->middleware('admin.gate:models.manage');
    Route::patch('/models/admin/{id}/deactivate', [AiModelAdminController::class, 'deactivate'])->middleware('admin.gate:models.manage');
    Route::patch('/models/admin/{id}/activate',   [AiModelAdminController::class, 'activate'])->middleware('admin.gate:models.manage');
});
