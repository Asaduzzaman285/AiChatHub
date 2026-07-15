<?php

use App\Http\Controllers\Internal\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth Service — Internal Routes
| Prefix: /api/internal  (set in bootstrap/app.php)
| Auth:   X-Internal-Service-Key header (InternalServiceMiddleware)
|--------------------------------------------------------------------------
*/

Route::get('/users/{userId}',          [UserController::class, 'show']);
Route::get('/users/email/{email}',     [UserController::class, 'findByEmail']);
Route::post('/users/{userId}/suspend', [UserController::class, 'suspend']);
Route::post('/users/{userId}/unsuspend', [UserController::class, 'unsuspend']);
