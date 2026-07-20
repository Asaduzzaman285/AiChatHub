<?php

namespace App\Http\Controllers\V1\Auth;

use App\Events\UserRegistered;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        // Resolve env values NOW (before closure) so they are available inside it
        $userId      = (string) $user->id;
        $currency    = $user->preferred_currency ?? 'USD';
        $walletUrl   = rtrim(config('services.wallet_url', 'http://wallet-nginx'), '/');
        $internalKey = config('services.internal_key', '');

        // Fire event + wallet creation AFTER response is sent — never block registration
        dispatch(function () use ($userId, $currency, $walletUrl, $internalKey) {

            // 1. Send verification email via event
            $user = \App\Models\User::find($userId);
            if ($user) {
                event(new UserRegistered($user));
            }

            // 2. Auto-create wallet in wallet-service
            if ($walletUrl && $internalKey) {
                try {
                    Http::withHeaders([
                        'X-Internal-Service-Key' => $internalKey,
                        'Accept'                 => 'application/json',
                    ])->timeout(15)->post("{$walletUrl}/api/internal/wallet/create", [
                        'user_id'  => $userId,
                        'currency' => $currency,
                    ]);
                } catch (\Throwable $e) {
                    // \Throwable, not \Exception — a route-not-found / connection-refused
                    // style failure here (e.g. a stale nginx sidecar after a
                    // --force-recreate of the app container but not its sidecar) can
                    // surface as a type that \Exception alone doesn't catch, silently
                    // killing the rest of this closure with nothing logged.
                    Log::error('Wallet auto-create failed: ' . $e->getMessage(), ['user_id' => $userId, 'class' => get_class($e)]);
                }
            }

        })->afterResponse();

        return response()->json([
            'message' => 'Registration successful. Please check your email to verify your account.',
            'user'    => ['id' => $user->id, 'email' => $user->email, 'name' => $user->name],
        ], 201);
    }
}
