<?php

use App\Http\Controllers\V1\WalletController;
use App\Http\Controllers\V1\LedgerController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function () {

    Route::get('/health', fn () => response()->json(['status' => 'ok', 'service' => 'wallet']));

    Route::middleware('auth.jwt')->group(function () {
        Route::get('/wallet',           [WalletController::class, 'balance']);
        Route::get('/wallet/ledger',    [LedgerController::class, 'index']);
        Route::get('/wallet/credit',    [WalletController::class, 'creditStatus']);
    });
});
