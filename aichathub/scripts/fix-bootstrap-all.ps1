$content = @'
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
        $middleware->statefulApi();
        $middleware->alias([
            'auth.jwt'      => \App\Http\Middleware\JwtAuthMiddleware::class,
            'auth.internal' => \App\Http\Middleware\InternalServiceMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'error'   => 'unauthenticated',
                ], 401);
            }
        });
    })->create();
'@

$services = @(
    "subscription-service",
    "wallet-service",
    "payment-service",
    "ai-gateway-service",
    "chat-service",
    "billing-service",
    "notification-service",
    "api-gateway"
)

foreach ($svc in $services) {
    $file = "c:\Users\IT News\Downloads\aichathub\aichathub\services\$svc\bootstrap\app.php"
    Set-Content -Path $file -Value $content -NoNewline
    Write-Host "Fixed: $svc"
}
Write-Host "Done."
