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

        // Immediately create wallet via direct HTTP call (sync, fast)
        // This runs AFTER the response is returned via deferred dispatch
        dispatch(function () use ($user) {
            try {
                $walletUrl = rtrim(env('WALLET_SERVICE_URL', 'http://wallet-nginx'), '/');
                \Illuminate\Support\Facades\Http::withHeaders([
                    'X-Internal-Service-Key' => env('INTERNAL_SERVICE_KEY'),
                    'Accept'                 => 'application/json',
                ])->timeout(5)->post("{$walletUrl}/api/internal/wallet/create", [
                    'user_id'  => (string) $user->id,
                    'currency' => $user->preferred_currency ?? 'USD',
                ]);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Wallet create failed: ' . $e->getMessage());
            }
        })->afterResponse();

        return response()->json([
            'message' => 'Registration successful. Please check your email to verify your account.',
            'user'    => ['id' => $user->id, 'email' => $user->email, 'name' => $user->name],
        ], 201);
    }
}
