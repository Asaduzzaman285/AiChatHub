<?php

use App\Http\Controllers\Internal\WalletInternalController;
use Illuminate\Support\Facades\Route;

// All called by AI Gateway and Subscription Service
Route::prefix('internal')->middleware('auth.internal')->group(function () {
    Route::post('/wallet/credit',  [WalletInternalController::class, 'credit']);
    Route::post('/wallet/reserve', [WalletInternalController::class, 'reserve']);
    Route::post('/wallet/deduct',  [WalletInternalController::class, 'deduct']);
    Route::post('/wallet/refund',  [WalletInternalController::class, 'refund']);
    Route::get('/wallet/{userId}', [WalletInternalController::class, 'show']);
});
