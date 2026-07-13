<?php

use App\Http\Controllers\V1\InvoiceController;
use App\Http\Controllers\V1\ReceiptController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function () {

    Route::get('/health', fn () => response()->json(['status' => 'ok', 'service' => 'billing']));

    Route::middleware('auth.jwt')->group(function () {
        Route::get('/invoices',         [InvoiceController::class, 'index']);
        Route::get('/invoices/{id}',    [InvoiceController::class, 'show']);
        Route::get('/invoices/{id}/download', [InvoiceController::class, 'download']);

        Route::get('/receipts',         [ReceiptController::class, 'index']);
        Route::get('/receipts/{id}',    [ReceiptController::class, 'show']);
    });
});
