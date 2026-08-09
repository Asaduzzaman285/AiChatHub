<?php

use App\Http\Controllers\V1\Admin\DashboardController;
use App\Http\Controllers\V1\Admin\WalletAdminController;
use App\Http\Controllers\V1\AutoDebitController;
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
    Route::get('/wallet/auto-debit', [AutoDebitController::class, 'show']);
    Route::put('/wallet/auto-debit', [AutoDebitController::class, 'update']);

    // Nested under /wallet (not bare /admin) so it's reachable through
    // api-gateway's existing /wallet/{path?} wildcard.
    Route::get('/wallet/admin/ledger',           [WalletAdminController::class, 'ledger'])->middleware('admin.gate:wallet.view');
    Route::post('/wallet/admin/{userId}/adjust', [WalletAdminController::class, 'adjust'])->middleware('admin.gate:wallet.adjust');
    Route::get('/wallet/admin/dashboard',        [DashboardController::class, 'index'])->middleware('admin.gate:dashboard.view');
});
