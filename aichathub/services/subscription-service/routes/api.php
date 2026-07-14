<?php

use App\Http\Controllers\V1\PackageController;
use App\Http\Controllers\V1\SubscriptionController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Health
Route::get('/health', fn () => response()->json(['status' => 'ok', 'service' => 'subscription']));
Route::get('/ready',  [HealthController::class, 'ready']);

// Public — list packages (no auth needed for pricing page)
Route::get('/packages',       [PackageController::class, 'index']);
Route::get('/packages/{slug}',[PackageController::class, 'show']);

// Authenticated
Route::middleware('auth.jwt')->group(function () {
    Route::get('/subscription',            [SubscriptionController::class, 'current']);
    Route::post('/subscription/subscribe', [SubscriptionController::class, 'subscribe']);
    Route::post('/subscription/upgrade',   [SubscriptionController::class, 'upgrade']);
    Route::post('/subscription/downgrade', [SubscriptionController::class, 'downgrade']);
    Route::post('/subscription/cancel',    [SubscriptionController::class, 'cancel']);
    Route::get('/subscription/history',    [SubscriptionController::class, 'history']);
});
