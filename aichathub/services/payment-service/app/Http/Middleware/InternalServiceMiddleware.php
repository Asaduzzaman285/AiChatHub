<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InternalServiceMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $key      = $request->header('X-Internal-Service-Key');
        $expected = env('INTERNAL_SERVICE_KEY', '');

        if (! $expected || $key !== $expected) {
            return response()->json([
                'message' => 'Unauthorized. Internal service key required.',
                'error'   => 'internal_auth_required',
            ], 401);
        }

        return $next($request);
    }
}
