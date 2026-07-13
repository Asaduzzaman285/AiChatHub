<?php

use App\Http\Controllers\Proxy\ProxyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Gateway — Routes
| Validates JWT, then proxies to appropriate microservice.
| All external traffic enters here. Services are NOT exposed directly.
|--------------------------------------------------------------------------
*/

Route::get('/health', fn () => response()->json([
    'status'  => 'ok',
    'service' => 'api-gateway',
    'version' => 'v1',
]));

Route::prefix('api/v1')->group(function () {

    // ── Auth routes (no JWT needed — handled by auth-service itself) ─────
    Route::any('/auth/{path?}', [ProxyController::class, 'proxyAuth'])
        ->where('path', '.*');

    // ── Protected routes — JWT validated here before proxying ────────────
    Route::middleware('auth.jwt.gateway')->group(function () {

        // Subscription service
        Route::any('/packages/{path?}',      [ProxyController::class, 'proxySubscription'])->where('path', '.*');
        Route::any('/subscription/{path?}',  [ProxyController::class, 'proxySubscription'])->where('path', '.*');

        // Wallet service
        Route::any('/wallet/{path?}',        [ProxyController::class, 'proxyWallet'])->where('path', '.*');

        // Payment service
        Route::any('/payment-methods/{path?}',[ProxyController::class, 'proxyPayment'])->where('path', '.*');
        Route::any('/topup/{path?}',          [ProxyController::class, 'proxyPayment'])->where('path', '.*');
        Route::any('/transactions/{path?}',   [ProxyController::class, 'proxyPayment'])->where('path', '.*');

        // AI Gateway service
        Route::any('/models/{path?}',         [ProxyController::class, 'proxyAiGateway'])->where('path', '.*');
        Route::any('/chat/{path?}',           [ProxyController::class, 'proxyAiGateway'])->where('path', '.*');
        Route::any('/generate/{path?}',       [ProxyController::class, 'proxyAiGateway'])->where('path', '.*');
        Route::any('/transcribe',             [ProxyController::class, 'proxyAiGateway']);

        // Chat service
        Route::any('/sessions/{path?}',       [ProxyController::class, 'proxyChat'])->where('path', '.*');
        Route::any('/upload/{path?}',         [ProxyController::class, 'proxyChat'])->where('path', '.*');

        // Billing service
        Route::any('/invoices/{path?}',       [ProxyController::class, 'proxyBilling'])->where('path', '.*');
        Route::any('/receipts/{path?}',       [ProxyController::class, 'proxyBilling'])->where('path', '.*');
    });

    // ── Payment webhooks — bypass JWT, gateway validates its own signatures ──
    Route::any('/webhooks/{path?}', [ProxyController::class, 'proxyPayment'])->where('path', '.*');
});
