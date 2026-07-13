<?php

use App\Http\Controllers\V1\Auth\RegisterController;
use App\Http\Controllers\V1\Auth\LoginController;
use App\Http\Controllers\V1\Auth\LogoutController;
use App\Http\Controllers\V1\Auth\EmailVerificationController;
use App\Http\Controllers\V1\Auth\PasswordResetController;
use App\Http\Controllers\V1\Auth\TokenRefreshController;
use App\Http\Controllers\V1\Auth\GoogleOAuthController;
use App\Http\Controllers\V1\Auth\SocialAccountController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth Service — Public API Routes (v1)
|--------------------------------------------------------------------------
*/

Route::prefix('api/v1')->group(function () {

    // ── Health ──────────────────────────────────────────────────────────
    Route::get('/health', fn () => response()->json(['status' => 'ok', 'service' => 'auth']));
    Route::get('/ready', [\App\Http\Controllers\HealthController::class, 'ready']);

    // ── Email / Password Auth ────────────────────────────────────────────
    Route::post('/auth/register',         [RegisterController::class,          'register']);
    Route::post('/auth/login',            [LoginController::class,             'login']);
    Route::post('/auth/refresh',          [TokenRefreshController::class,      'refresh']);
    Route::get('/auth/verify/{token}',    [EmailVerificationController::class, 'verify']);
    Route::post('/auth/verify/resend',    [EmailVerificationController::class, 'resend']);
    Route::post('/auth/password/forgot',  [PasswordResetController::class,     'forgot']);
    Route::post('/auth/password/reset',   [PasswordResetController::class,     'reset']);

    // ── Google OAuth ─────────────────────────────────────────────────────
    Route::get('/auth/google/redirect',  [GoogleOAuthController::class, 'redirect']);
    Route::get('/auth/google/callback',  [GoogleOAuthController::class, 'callback']);

    // ── Authenticated Routes ─────────────────────────────────────────────
    Route::middleware('auth.jwt')->group(function () {
        Route::post('/auth/logout',             [LogoutController::class,       'logout']);
        Route::get('/auth/me',                  [LoginController::class,         'me']);

        // Manage connected social accounts
        Route::get('/auth/social',              [SocialAccountController::class, 'index']);
        Route::post('/auth/social/google/link', [SocialAccountController::class, 'linkGoogle']);
        Route::delete('/auth/social/google',    [SocialAccountController::class, 'unlinkGoogle']);
    });
});
