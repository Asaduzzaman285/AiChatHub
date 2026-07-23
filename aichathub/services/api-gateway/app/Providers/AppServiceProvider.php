<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Brute-force-sensitive auth endpoints (login/register/password-forgot/firebase) —
        // keyed by IP since there's no authenticated user yet at this point.
        RateLimiter::for('auth-strict', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Rest of /auth/* (refresh, verify, logout, password/reset, password/set).
        RateLimiter::for('auth-general', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        // Stripe/bKash webhook deliveries — generous headroom for legitimate retry bursts.
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        // Authenticated API traffic — keyed by user ID rather than IP, so one user's
        // usage never throttles another. This service has no Auth guard/User model at
        // all (pure proxy, no DB) — JwtGatewayMiddleware decodes the JWT and sets
        // X-User-Id as a plain header for downstream services; read that same header
        // here rather than $request->user() (which would always be null). Route order
        // guarantees auth.jwt.gateway runs before this, so the header is already set.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(
            (int) env('RATE_LIMIT_PER_MINUTE', 100)
        )->by($request->header('X-User-Id') ?? $request->ip()));
    }
}
