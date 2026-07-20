<?php

use App\Jobs\ReleaseWalletReservationJob;
use App\Services\PendingReservationTracker;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Exceptions\AiException;

$app = Application::configure(basePath: dirname(__DIR__))
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
        // Pure API service — no sessions, no cookies, no redirects ever.
        // Do NOT call statefulApi() — that enables Sanctum session middleware
        // which redirects unauthenticated requests instead of returning JSON.

        $middleware->alias([
            'auth.jwt'      => \App\Http\Middleware\JwtAuthMiddleware::class,
            'auth.internal' => \App\Http\Middleware\InternalServiceMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Always return JSON for API routes — never redirect
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'error'   => 'unauthenticated',
            ], 401);
        });

        // A provider HTTP call (OpenAI/Anthropic/Gemini/etc.) failing — bad/missing API key,
        // rate limit, provider outage — happens deep inside a StreamedResponse's lazy
        // generator, outside any controller try/catch. Without this it leaks a full stack
        // trace (file paths, provider request details) straight into the JSON response.
        $exceptions->render(function (RequestException $e, Request $request) {
            Log::error('AI provider request failed', ['error' => $e->getMessage(), 'status' => $e->response->status()]);

            return response()->json([
                'error' => $e->response->status() === 401
                    ? 'This model is not configured correctly (invalid provider API key). Please try a different model.'
                    : 'The AI provider request failed. Please try again.',
            ], 502);
        });

        // laravel/ai's own exception family (InsufficientCreditsException,
        // RateLimitedException, ProviderOverloadedException, NoSuchToolException, ...)
        // all extend this one base class — catch it here instead of one subclass at a
        // time, same lazy-generator reasoning as the RequestException handler above.
        $exceptions->render(function (AiException $e, Request $request) {
            Log::error('AI provider call failed', ['exception' => get_class($e), 'error' => $e->getMessage()]);

            return response()->json(['error' => 'This model is temporarily unavailable. Please try a different model.'], 502);
        });
    })->create();

$app->singleton(PendingReservationTracker::class);

// Safety net for CostTrackingMiddleware: releases a wallet reservation that was made
// but never settled (deduct() never ran). Deliberately NOT $app->terminating() —
// the actual provider HTTP call runs inside a StreamedResponse's lazy generator,
// which Application::handleRequest() invokes via `$kernel->handle($request)->send()`
// with no try/catch of its own. An exception thrown during send() propagates out as
// truly uncaught, so `$kernel->terminate()` on the next line never runs and neither
// would terminating(). register_shutdown_function() is the hook PHP guarantees fires
// regardless of how the script ends — but PHP-FPM does NOT reliably give a shutdown
// function time to complete a fresh synchronous HTTP call once the response has
// already been sent (confirmed live: the refund() call started but never finished).
// Dispatching a queued job instead — a fast Redis write, not a network round-trip —
// is what's actually reliable here. Requires a queue worker running for this service:
// docker exec -d aichathub-ai-gateway php artisan queue:work redis --tries=3
register_shutdown_function(function () use ($app) {
    $pending = $app->make(PendingReservationTracker::class)->pending();
    if ($pending) {
        ReleaseWalletReservationJob::dispatch($pending['user_id'], $pending['amount']);
    }
});

return $app;