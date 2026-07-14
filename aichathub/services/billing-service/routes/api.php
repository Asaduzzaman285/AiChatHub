<?php

use App\Http\Controllers\V1\InvoiceController;
use App\Http\Controllers\V1\ReceiptController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Health
Route::get('/health', fn () => response()->json(['status' => 'ok', 'service' => 'billing']));
Route::get('/ready',  [HealthController::class, 'ready']);

// Authenticated
Route::middleware('auth.jwt')->group(function () {
    Route::get('/invoices',                [InvoiceController::class, 'index']);
    Route::get('/invoices/{id}',           [InvoiceController::class, 'show']);
    Route::get('/invoices/{id}/download',  [InvoiceController::class, 'download']);

    Route::get('/receipts',                [ReceiptController::class, 'index']);
    Route::get('/receipts/{id}',           [ReceiptController::class, 'show']);
});
