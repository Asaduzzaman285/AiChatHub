<?php

use App\Http\Controllers\Internal\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth Service — Internal Routes
| Called by other microservices only (protected by X-Internal-Key header)
|--------------------------------------------------------------------------
*/

Route::prefix('internal')->middleware('auth.internal')->group(function () {
    Route::get('/users/{userId}',    [UserController::class, 'show']);
    Route::get('/users/email/{email}', [UserController::class, 'findByEmail']);
    Route::post('/users/{userId}/suspend',   [UserController::class, 'suspend']);
    Route::post('/users/{userId}/unsuspend', [UserController::class, 'unsuspend']);
});
