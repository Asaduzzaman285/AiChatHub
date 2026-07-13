<?php

use App\Http\Controllers\V1\PaymentMethodController;
use App\Http\Controllers\V1\TopupController;
use App\Http\Controllers\V1\TransactionController;
use App\Http\Controllers\V1\Webhooks\StripeWebhookController;
use App\Http\Controllers\V1\Webhooks\BkashWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function () {

    Route::get('/health', fn () => response()->json(['status' => 'ok', 'service' => 'payment']));

    // ── Webhooks — no JWT, gateway-specific signature validation ────────
    Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle']);
    Route::post('/webhooks/bkash',  [BkashWebhookController::class,  'handle']);

    // ── Authenticated ────────────────────────────────────────────────────
    Route::middleware('auth.jwt')->group(function () {

        // Payment methods (saved cards / mobile numbers)
        Route::get('/payment-methods',           [PaymentMethodController::class, 'index']);
        Route::post('/payment-methods',          [PaymentMethodController::class, 'store']);
        Route::delete('/payment-methods/{id}',   [PaymentMethodController::class, 'destroy']);
        Route::patch('/payment-methods/{id}/default', [PaymentMethodController::class, 'setDefault']);

        // Wallet top-up
        Route::post('/topup',           [TopupController::class, 'initiate']);
        Route::get('/topup/{id}/status',[TopupController::class, 'status']);

        // Transaction history
        Route::get('/transactions',     [TransactionController::class, 'index']);
        Route::get('/transactions/{id}',[TransactionController::class, 'show']);
    });
});
