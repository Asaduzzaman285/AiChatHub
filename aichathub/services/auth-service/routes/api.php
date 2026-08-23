<?php

use App\Http\Controllers\V1\Admin\AdminUserController;
use App\Http\Controllers\V1\Admin\AuditLogController;
use App\Http\Controllers\V1\Admin\DashboardController;
use App\Http\Controllers\V1\Admin\RoleController;
use App\Http\Controllers\V1\Admin\UserManagementController;
use App\Http\Controllers\V1\Auth\FirebaseAuthController;
use App\Http\Controllers\V1\Auth\RegisterController;
use App\Http\Controllers\V1\Auth\LoginController;
use App\Http\Controllers\V1\Auth\LogoutController;
use App\Http\Controllers\V1\Auth\EmailChangeController;
use App\Http\Controllers\V1\Auth\EmailVerificationController;
use App\Http\Controllers\V1\Auth\PasswordResetController;
use App\Http\Controllers\V1\Auth\TokenRefreshController;
use App\Http\Controllers\V1\Auth\GoogleOAuthController;
use App\Http\Controllers\V1\Auth\SocialAccountController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Health — no auth
Route::get('/health', fn () => response()->json(['status' => 'ok', 'service' => 'auth']));
Route::get('/ready',  [HealthController::class, 'ready']);

// Email / Password Auth
Route::post('/auth/register',        [RegisterController::class,          'register']);
Route::post('/auth/login',           [LoginController::class,             'login']);
Route::post('/auth/refresh',         [TokenRefreshController::class,      'refresh']);
Route::get('/auth/verify/{token}',   [EmailVerificationController::class, 'verify']);
Route::post('/auth/verify/resend',   [EmailVerificationController::class, 'resend']);
Route::post('/auth/password/forgot', [PasswordResetController::class,     'forgot']);
Route::post('/auth/password/reset',  [PasswordResetController::class,     'reset']);

// Google OAuth (Socialite redirect flow — kept for fallback)
Route::get('/auth/google/redirect', [GoogleOAuthController::class, 'redirect']);
Route::get('/auth/google/callback', [GoogleOAuthController::class, 'callback']);

// Firebase Auth — handles Google, Facebook, Apple, GitHub etc via Firebase SDK
Route::post('/auth/firebase', [FirebaseAuthController::class, 'authenticate']);

// Authenticated Routes
Route::middleware('auth.jwt')->group(function () {
    Route::post('/auth/logout',             [LogoutController::class,       'logout']);
    Route::get('/auth/me',                  [LoginController::class,        'me']);
    Route::post('/auth/welcome-seen',       [LoginController::class,        'welcomeSeen']);
    Route::get('/auth/social',              [SocialAccountController::class,'index']);
    Route::post('/auth/social/google/link', [SocialAccountController::class,'linkGoogle']);
    Route::delete('/auth/social/google',    [SocialAccountController::class,'unlinkGoogle']);
    Route::post('/auth/password/set',       [PasswordResetController::class,'setPassword']);
    Route::post('/auth/email/change',       [EmailChangeController::class,  'request']);

    // Admin — nested under /auth (not a bare /admin prefix) so it's reachable
    // through api-gateway's existing /auth/{path?} wildcard, which only proxies
    // to auth-service; a top-level /admin/* path would match no proxy route at
    // all. Each route requires its own permission (see the roles table).
    Route::prefix('auth/admin')->group(function () {
        Route::get('/dashboard',   [DashboardController::class, 'index'])->middleware('admin.gate:dashboard.view');

        Route::get('/admins',      [AdminUserController::class, 'index'])->middleware('admin.gate:admins.manage');
        Route::post('/admins',     [AdminUserController::class, 'store'])->middleware('admin.gate:admins.manage');
        Route::patch('/admins/{id}', [AdminUserController::class, 'update'])->middleware('admin.gate:admins.manage');

        Route::get('/roles',        [RoleController::class, 'index'])->middleware('admin.gate:admins.manage');
        Route::post('/roles',       [RoleController::class, 'store'])->middleware('admin.gate:admins.manage');
        Route::patch('/roles/{id}', [RoleController::class, 'update'])->middleware('admin.gate:admins.manage');
        Route::delete('/roles/{id}', [RoleController::class, 'destroy'])->middleware('admin.gate:admins.manage');

        Route::get('/users',                 [UserManagementController::class, 'index'])->middleware('admin.gate:users.view');
        Route::post('/users/{id}/suspend',   [UserManagementController::class, 'suspend'])->middleware('admin.gate:users.suspend');
        Route::post('/users/{id}/unsuspend', [UserManagementController::class, 'unsuspend'])->middleware('admin.gate:users.suspend');

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('admin.gate:audit_logs.view');
    });
});
