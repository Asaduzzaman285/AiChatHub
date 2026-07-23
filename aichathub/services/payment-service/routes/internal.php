<?php

use App\Http\Controllers\Internal\PaymentInternalController;
use Illuminate\Support\Facades\Route;

// Called by Subscription Service to charge for packages / renewals.
// Already mounted at api/internal with auth.internal middleware by bootstrap/app.php —
// do not add another prefix/middleware group here, it would double the path segment.
Route::post('/payments/charge', [PaymentInternalController::class, 'charge']);
Route::post('/payments/checkout', [PaymentInternalController::class, 'createCheckoutSession']);
Route::post('/payments/refund', [PaymentInternalController::class, 'refund']);
Route::get('/payments/{id}', [PaymentInternalController::class, 'show']);
Route::get('/payment-methods/{userId}/default', [PaymentInternalController::class, 'defaultPaymentMethod']);
