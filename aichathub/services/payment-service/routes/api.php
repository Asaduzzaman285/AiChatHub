<?php

use App\Http\Controllers\V1\CheckoutController;
use App\Http\Controllers\V1\PaymentMethodController;
use App\Http\Controllers\V1\TopupController;
use App\Http\Controllers\V1\TransactionController;
use App\Http\Controllers\V1\Webhooks\StripeWebhookController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Health
Route::get('/health', fn () => response()->json(['status' => 'ok', 'service' => 'payment']));
Route::get('/ready',  [HealthController::class, 'ready']);

// Webhooks — no JWT, signature-validated. bKash's tokenized Checkout has no
// server-to-server webhook (see CheckoutController::verify()'s docblock) —
// only Stripe has one.
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle']);

// Authenticated
Route::middleware('auth.jwt')->group(function () {
    Route::get('/payment-methods',                    [PaymentMethodController::class, 'index']);
    Route::post('/payment-methods',                   [PaymentMethodController::class, 'store']);
    Route::delete('/payment-methods/{id}',            [PaymentMethodController::class, 'destroy']);
    Route::patch('/payment-methods/{id}/default',     [PaymentMethodController::class, 'setDefault']);

    Route::post('/topup',            [TopupController::class, 'initiate']);
    Route::get('/topup/{id}/status', [TopupController::class, 'status']);

    Route::get('/checkout/{sessionId}/verify', [CheckoutController::class, 'verify']);

    Route::get('/transactions',      [TransactionController::class, 'index']);
    Route::get('/transactions/{id}', [TransactionController::class, 'show']);
});
