<?php

namespace App\Http\Controllers\V1\Auth;

use App\Events\UserRegistered;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    public function __construct(private JwtService $jwtService) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'email'              => $request->email,
            'password'           => $request->password,
            'name'               => $request->name,
            'preferred_currency' => $request->currency ?? 'USD',
            'status'             => 'pending_verification',
        ]);

        event(new UserRegistered($user));

        return response()->json([
            'message' => 'Registration successful. Please check your email to verify your account.',
            'user'    => ['id' => $user->id, 'email' => $user->email, 'name' => $user->name],
        ], 201);
    }
}
