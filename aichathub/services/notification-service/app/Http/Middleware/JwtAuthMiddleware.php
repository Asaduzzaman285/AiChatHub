<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Facades\JWTAuth;

class JwtAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if (! $user) {
                return response()->json(['message' => 'User not found.', 'error' => 'user_not_found'], 401);
            }

            // Inject user into request so controllers can use $request->user()
            $request->setUserResolver(fn () => $user);

        } catch (TokenExpiredException) {
            return response()->json(['message' => 'Token expired.', 'error' => 'token_expired'], 401);
        } catch (TokenInvalidException) {
            return response()->json(['message' => 'Token invalid.', 'error' => 'token_invalid'], 401);
        } catch (JWTException) {
            return response()->json(['message' => 'Token not provided.', 'error' => 'token_missing'], 401);
        }

        return $next($request);
    }
}
