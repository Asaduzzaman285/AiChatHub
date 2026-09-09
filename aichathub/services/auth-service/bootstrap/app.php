<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        \App\Providers\EventServiceProvider::class,
    ])
    // Application::configure() above already calls ->withEvents() internally with
    // discovery ON by default — this app registers listeners explicitly via
    // EventServiceProvider's $listen array instead, so that auto-discovery is
    // pure downside here: any listener whose class/method naturally matches the
    // discovery convention (a `handle(EventType $event)` method under
    // app/Listeners) gets registered a SECOND time, under a different internal
    // string key ("Class@handle" from discovery vs "Class" from $listen), which
    // Laravel's dispatcher does not deduplicate. Confirmed live: this is exactly
    // why SendVerificationEmail ran twice per registration, sending two
    // verification emails with two different tokens (only the second/newer
    // token stayed valid — clicking the first email's link failed as
    // "invalid_token"). discover: false turns this off.
    ->withEvents(discover: false)
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        then: function () {
            // Force root URL so redirects never use internal container hostname
            URL::forceRootUrl(config('app.url'));

            Route::prefix('api/internal')
                ->middleware('auth.internal')
                ->group(base_path('routes/internal.php'));
        }
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Pure API service — no sessions, no cookies, no redirects ever.
        // Do NOT call statefulApi() — that enables Sanctum session middleware
        // which redirects unauthenticated requests instead of returning JSON.

        $middleware->alias([
            'auth.jwt'      => \App\Http\Middleware\JwtAuthMiddleware::class,
            'auth.internal' => \App\Http\Middleware\InternalServiceMiddleware::class,
            'admin.gate'    => \App\Http\Middleware\AdminGateMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);

        // Always return JSON for API routes — never redirect
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'error'   => 'unauthenticated',
            ], 401);
        });
    })->create();
