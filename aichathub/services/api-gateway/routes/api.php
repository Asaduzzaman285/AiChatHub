<?php

use App\Http\Controllers\Proxy\ProxyController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Health (outside v1 prefix — accessible at /api/v1/health via apiPrefix in bootstrap)
Route::get('/health', fn () => response()->json([
    'status'  => 'ok',
    'service' => 'api-gateway',
    'version' => 'v1',
]));
Route::get('/ready', [HealthController::class, 'ready']);

// Auth routes — no JWT needed here, auth-service handles it
Route::any('/auth/{path?}', [ProxyController::class, 'proxyAuth'])->where('path', '.*');

// Webhook routes — bypass JWT, payment-service validates signatures
Route::any('/webhooks/{path?}', [ProxyController::class, 'proxyPayment'])->where('path', '.*');

// Protected routes — JWT validated at gateway before proxying
Route::middleware('auth.jwt.gateway')->group(function () {
    Route::any('/packages/{path?}',       [ProxyController::class, 'proxySubscription'])->where('path', '.*');
    Route::any('/subscription/{path?}',   [ProxyController::class, 'proxySubscription'])->where('path', '.*');
    Route::any('/wallet/{path?}',         [ProxyController::class, 'proxyWallet'])->where('path', '.*');
    Route::any('/payment-methods/{path?}',[ProxyController::class, 'proxyPayment'])->where('path', '.*');
    Route::any('/topup/{path?}',          [ProxyController::class, 'proxyPayment'])->where('path', '.*');
    Route::any('/transactions/{path?}',   [ProxyController::class, 'proxyPayment'])->where('path', '.*');
    Route::any('/models/{path?}',         [ProxyController::class, 'proxyAiGateway'])->where('path', '.*');
    Route::any('/chat/{path?}',           [ProxyController::class, 'proxyAiGateway'])->where('path', '.*');
    Route::any('/generate/{path?}',       [ProxyController::class, 'proxyAiGateway'])->where('path', '.*');
    Route::any('/transcribe',             [ProxyController::class, 'proxyAiGateway']);
    Route::any('/sessions/{path?}',       [ProxyController::class, 'proxyChat'])->where('path', '.*');
    Route::any('/upload/{path?}',         [ProxyController::class, 'proxyChat'])->where('path', '.*');
    Route::any('/invoices/{path?}',       [ProxyController::class, 'proxyBilling'])->where('path', '.*');
    Route::any('/receipts/{path?}',       [ProxyController::class, 'proxyBilling'])->where('path', '.*');
});
