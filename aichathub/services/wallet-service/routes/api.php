<?php

use App\Http\Controllers\V1\WalletController;
use App\Http\Controllers\V1\LedgerController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Health
Route::get('/health', fn () => response()->json(['status' => 'ok', 'service' => 'wallet']));
Route::get('/ready',  [HealthController::class, 'ready']);

// Authenticated
Route::middleware('auth.jwt')->group(function () {
    Route::get('/wallet',        [WalletController::class, 'balance']);
    Route::get('/wallet/ledger', [LedgerController::class, 'index']);
    Route::get('/wallet/credit', [WalletController::class, 'creditStatus']);
});
