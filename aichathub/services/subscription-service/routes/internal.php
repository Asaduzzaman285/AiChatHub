<?php

use App\Http\Controllers\Internal\SubscriptionCheckController;
use Illuminate\Support\Facades\Route;

// Called by AI Gateway, Chat Service to validate model access
Route::prefix('internal')->middleware('auth.internal')->group(function () {
    Route::get('/subscriptions/{userId}/current',  [SubscriptionCheckController::class, 'current']);
    Route::get('/subscriptions/{userId}/can-access/{modelId}', [SubscriptionCheckController::class, 'canAccess']);
});
