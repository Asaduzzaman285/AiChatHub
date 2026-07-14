<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Health
Route::get('/health', fn () => response()->json(['status' => 'ok', 'service' => 'notification']));
Route::get('/ready',  [HealthController::class, 'ready']);
