<?php

use App\Http\Controllers\Internal\InvoiceInternalController;
use App\Http\Controllers\Internal\ReceiptInternalController;
use Illuminate\Support\Facades\Route;

// Already mounted at api/internal with auth.internal middleware by bootstrap/app.php.
Route::post('/invoices/create', [InvoiceInternalController::class, 'create']);
Route::post('/receipts/create', [ReceiptInternalController::class, 'create']);
