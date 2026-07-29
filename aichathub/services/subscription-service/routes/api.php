<?php

use App\Http\Controllers\V1\Admin\DashboardController;
use App\Http\Controllers\V1\Admin\SubscriptionAdminController;
use App\Http\Controllers\V1\PackageController;
use App\Http\Controllers\V1\SubscriptionController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Health
Route::get('/health', fn () => response()->json(['status' => 'ok', 'service' => 'subscription']));
Route::get('/ready',  [HealthController::class, 'ready']);

// Public — list packages (no auth needed for pricing page)
Route::get('/packages', [PackageController::class, 'index']);

// Admin package listing — registered before the public GET /packages/{slug}
// wildcard below, otherwise "admin" would be captured as {slug} and 404 in
// show() instead of reaching adminIndex() (same gotcha already hit with
// /transactions/admin vs /transactions/{id}).
Route::middleware('auth.jwt')->group(function () {
    Route::get('/packages/admin', [PackageController::class, 'adminIndex'])->middleware('admin.gate:packages.manage');
    Route::post('/packages',      [PackageController::class, 'store'])->middleware('admin.gate:packages.manage');
});

Route::get('/packages/{slug}', [PackageController::class, 'show']);

// Authenticated
Route::middleware('auth.jwt')->group(function () {
    Route::get('/subscription',            [SubscriptionController::class, 'current']);
    Route::post('/subscription/subscribe', [SubscriptionController::class, 'subscribe']);
    Route::post('/subscription/upgrade',   [SubscriptionController::class, 'upgrade']);
    Route::post('/subscription/downgrade', [SubscriptionController::class, 'downgrade']);
    Route::post('/subscription/cancel',    [SubscriptionController::class, 'cancel']);
    Route::get('/subscription/history',    [SubscriptionController::class, 'history']);

    // Admin — makes existing package fields (including credit_buffer_percentage)
    // genuinely editable instead of DB-only.
    Route::patch('/packages/{slug}', [PackageController::class, 'update'])->middleware('admin.gate:packages.manage');

    // Nested under /subscription (not bare /admin) so it's reachable through
    // api-gateway's existing /subscription/{path?} wildcard — a top-level
    // /admin/* path matches no proxy route at all.
    Route::get('/subscription/admin',           [SubscriptionAdminController::class, 'index'])->middleware('admin.gate:subscriptions.view');
    Route::get('/subscription/admin/dashboard', [DashboardController::class, 'index'])->middleware('admin.gate:dashboard.view');
});
