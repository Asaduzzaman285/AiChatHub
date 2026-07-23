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

// Auth routes — no JWT needed here, auth-service handles it. The brute-force-sensitive
// paths get their own explicit route (registered before the general wildcard, so they
// match first) with a much stricter per-IP limit than the rest of /auth/*. proxyAuth()
// builds the upstream URL from the `path` route parameter — since these explicit routes
// have no {path} segment in their URI, ->defaults() supplies it exactly as the wildcard
// route would have (omitting it would forward to the wrong, truncated upstream URL).
Route::middleware('throttle:auth-strict')->group(function () {
    Route::any('/auth/login',           [ProxyController::class, 'proxyAuth'])->defaults('path', 'login');
    Route::any('/auth/register',        [ProxyController::class, 'proxyAuth'])->defaults('path', 'register');
    Route::any('/auth/password/forgot', [ProxyController::class, 'proxyAuth'])->defaults('path', 'password/forgot');
    Route::any('/auth/firebase',        [ProxyController::class, 'proxyAuth'])->defaults('path', 'firebase');
});
Route::any('/auth/{path?}', [ProxyController::class, 'proxyAuth'])
    ->where('path', '.*')
    ->middleware('throttle:auth-general');

// Webhook routes — bypass JWT, payment-service validates signatures. Rate-limited
// generously since legitimate gateway retries (Stripe/bKash) can arrive in bursts.
Route::any('/webhooks/{path?}', [ProxyController::class, 'proxyPayment'])
    ->where('path', '.*')
    ->middleware('throttle:webhooks');

// Protected routes — JWT validated at gateway before proxying
Route::middleware(['auth.jwt.gateway', 'throttle:api'])->group(function () {
    Route::any('/packages/{path?}',       [ProxyController::class, 'proxySubscription'])->where('path', '.*');
    Route::any('/subscription/{path?}',   [ProxyController::class, 'proxySubscription'])->where('path', '.*');
    Route::any('/wallet/{path?}',         [ProxyController::class, 'proxyWallet'])->where('path', '.*');
    Route::any('/payment-methods/{path?}',[ProxyController::class, 'proxyPayment'])->where('path', '.*');
    Route::any('/topup/{path?}',          [ProxyController::class, 'proxyPayment'])->where('path', '.*');
    Route::any('/checkout/{path?}',       [ProxyController::class, 'proxyPayment'])->where('path', '.*');
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
