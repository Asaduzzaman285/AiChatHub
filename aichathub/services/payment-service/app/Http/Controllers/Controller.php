<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /** User id forwarded by the API Gateway via the X-User-Id header (see JwtAuthMiddleware). */
    protected function authUserId(Request $request): string
    {
        return $request->attributes->get('auth_user_id');
    }
}
