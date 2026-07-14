<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        then: function () {
            Route::prefix('api/internal')
                ->middleware('auth.internal')
                ->group(base_path('routes/internal.php'));
        }
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Treat all requests as API — never redirect, always return JSON
        $middleware->statefulApi();

        // Register custom middleware aliases
        $middleware->alias([
            'auth.jwt'      => \App\Http\Middleware\JwtAuthMiddleware::class,
            'auth.internal' => \App\Http\Middleware\InternalServiceMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Any unauthenticated request on API routes → JSON 401, never redirect
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'error'   => 'unauthenticated',
                ], 401);
            }
        });
    })->create();
