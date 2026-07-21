<?php

use App\Http\Controllers\Internal\SubscriptionActivationController;
use App\Http\Controllers\Internal\SubscriptionCheckController;
use Illuminate\Support\Facades\Route;

// Called by AI Gateway, Chat Service to validate model access.
// Already mounted at api/internal with auth.internal middleware by bootstrap/app.php —
// do not add another prefix/middleware group here, it would double the path segment.
Route::get('/subscriptions/{userId}/current', [SubscriptionCheckController::class, 'current']);
Route::get('/subscriptions/{userId}/can-access/{modelId}', [SubscriptionCheckController::class, 'canAccess']);

// Called by Payment Service once a card-funded package purchase is verified paid.
Route::post('/subscriptions/activate', [SubscriptionActivationController::class, 'activate']);
