<?php

namespace App\Http\Controllers\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;

class FirebaseAuthController extends Controller
{
    public function __construct(
        private FirebaseAuth $firebaseAuth,
        private JwtService   $jwtService
    ) {}

    /**
     * POST /api/v1/auth/firebase
     *
     * Receives a Firebase ID token from the frontend.
     * Verifies it server-side, then creates or finds our platform user,
     * and returns our own JWT pair.
     */
    public function authenticate(Request $request): JsonResponse
    {
        $request->validate([
            'id_token' => ['required', 'string'],
        ]);

        // 1. Verify the Firebase token using the service account
        try {
            $firebaseToken = $this->firebaseAuth->verifyIdToken($request->id_token);
        } catch (FailedToVerifyToken $e) {
            return response()->json([
                'message' => 'Invalid or expired Firebase token.',
                'error'   => 'firebase_token_invalid',
            ], 401);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Firebase authentication failed.',
                'error'   => 'firebase_error',
            ], 401);
        }

        // 2. Extract user data from the verified token
        $claims    = $firebaseToken->claims();
        $firebaseUid   = $claims->get('sub');           // Firebase UID
        $email         = $claims->get('email');
        $emailVerified = $claims->get('email_verified', false);
        $name          = $claims->get('name', '');
        $picture       = $claims->get('picture', null);
        $provider      = $this->detectProvider($claims->get('firebase', []));

        if (! $email) {
            return response()->json([
                'message' => 'Email not provided by identity provider.',
                'error'   => 'email_required',
            ], 422);
        }

        // 3. Find or create the platform user — wrapped in a transaction
        $user = DB::transaction(function () use (
            $firebaseUid, $email, $emailVerified, $name, $picture, $provider
        ) {
            // Check if this Firebase UID is already linked
            $social = SocialAccount::where('provider', $provider)
                ->where('provider_user_id', $firebaseUid)
                ->with('user')
                ->first();

            if ($social) {
                // Existing social login — update avatar if changed
                $user = $social->user;
                if ($picture && $user->avatar_url !== $picture) {
                    $user->update(['avatar_url' => $picture]);
                }
                return $user;
            }

            // No social account — check if email exists
            $user = User::where('email', $email)->first();

            if (! $user) {
                // Brand new user
                $user = User::create([
                    'email'              => $email,
                    'name'               => $name ?: explode('@', $email)[0],
                    'avatar_url'         => $picture,
                    'status'             => 'active',   // Social logins skip verification
                    'email_verified_at'  => $emailVerified ? now() : null,
                    'preferred_currency' => 'USD',
                ]);
            } else {
                // Existing email user — link the social account
                if ($emailVerified && ! $user->email_verified_at) {
                    $user->update(['email_verified_at' => now(), 'status' => 'active']);
                }
            }

            // Link the social account
            SocialAccount::create([
                'user_id'          => $user->id,
                'provider'         => $provider,
                'provider_user_id' => $firebaseUid,
                'avatar'           => $picture,
                'token'            => null, // Firebase tokens are short-lived; we don't store them
            ]);

            return $user;
        });

        // 4. Update last login
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        // 5. Auto-create wallet if user is new (after response to avoid blocking)
        if ($user->wasRecentlyCreated) {
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
                    \Illuminate\Support\Facades\Log::warning('Firebase wallet create: ' . $e->getMessage());
                }
            })->afterResponse();
        }

        // 6. Issue our own JWT pair
        $tokens = $this->jwtService->issueTokens($user);

        return response()->json(array_merge($tokens, [
            'user' => [
                'id'                 => $user->id,
                'name'               => $user->name,
                'email'              => $user->email,
                'avatar_url'         => $user->avatar_url,
                'preferred_currency' => $user->preferred_currency,
                'status'             => $user->status,
            ],
            'is_new_user' => ! $user->wasRecentlyCreated ? false : true,
        ]));
    }

    /**
     * Detect which provider was used from the Firebase token's firebase claim.
     */
    private function detectProvider(array $firebaseClaim): string
    {
        $signInProvider = $firebaseClaim['sign_in_provider'] ?? 'unknown';

        return match ($signInProvider) {
            'google.com'   => 'google',
            'facebook.com' => 'facebook',
            'apple.com'    => 'apple',
            'github.com'   => 'github',
            'twitter.com'  => 'twitter',
            default        => $signInProvider,
        };
    }
}
