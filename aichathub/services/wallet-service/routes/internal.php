<?php

use App\Http\Controllers\Internal\WalletInternalController;
use Illuminate\Support\Facades\Route;

// Prefix: /api/internal (set in bootstrap/app.php)
// Auth:   X-Internal-Service-Key header
Route::post('/wallet/create',  [WalletInternalController::class, 'create']);
Route::post('/wallet/credit',  [WalletInternalController::class, 'credit']);
Route::post('/wallet/reserve', [WalletInternalController::class, 'reserve']);
Route::post('/wallet/deduct',  [WalletInternalController::class, 'deduct']);
Route::post('/wallet/refund',  [WalletInternalController::class, 'refund']);
Route::get('/wallet/{userId}', [WalletInternalController::class, 'show']);
