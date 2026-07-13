<?php

use App\Http\Controllers\Internal\PaymentInternalController;
use Illuminate\Support\Facades\Route;

// Called by Subscription Service to charge for packages / renewals
Route::prefix('internal')->middleware('auth.internal')->group(function () {
    Route::post('/payments/charge',  [PaymentInternalController::class, 'charge']);
    Route::post('/payments/refund',  [PaymentInternalController::class, 'refund']);
    Route::get('/payments/{id}',     [PaymentInternalController::class, 'show']);
});
