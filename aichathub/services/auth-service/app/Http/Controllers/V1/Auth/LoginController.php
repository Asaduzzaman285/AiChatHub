<?php

namespace App\Http\Controllers\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __construct(private JwtService $jwtService) {}

    public function login(LoginRequest $request): JsonResponse
    {
        // Rate limit: 5 failed attempts per email per 15 minutes
        $recentFailures = LoginAttempt::where('email', $request->email)
            ->where('success', false)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->count();

        if ($recentFailures >= 5) {
            return response()->json([
                'message' => 'Too many failed login attempts. Please try again in 15 minutes.',
            ], 429);
        }

        $user = User::where('email', $request->email)->first();

        $success = $user && Hash::check($request->password, $user->password ?? '');

        // Log the attempt
        LoginAttempt::create([
            'email'      => $request->email,
            'ip_address' => $request->ip(),
            'success'    => $success,
            'user_agent' => $request->userAgent(),
        ]);

        if (! $success) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if (! $user->isActive()) {
            return response()->json(['message' => 'Account is not active. Please verify your email.'], 403);
        }

        $user->update(['last_login_at' => now(), 'last_login_ip' => $request->ip()]);

        return response()->json($this->jwtService->issueTokens($user));
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json([
            'id'                 => $user->id,
            'name'               => $user->name,
            'email'              => $user->email,
            'avatar_url'         => $user->avatar_url,
            'preferred_currency' => $user->preferred_currency,
            'email_verified_at'  => $user->email_verified_at,
            'has_password'       => $user->hasPassword(),
            'google_connected'   => $user->googleAccount()->exists(),
        ]);
    }
}
