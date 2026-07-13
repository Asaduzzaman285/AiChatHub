<?php

namespace App\Http\Controllers\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\GoogleOAuthService;
use App\Services\JwtService;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

class GoogleOAuthController extends Controller
{
    public function __construct(
        private GoogleOAuthService $googleService,
        private JwtService $jwtService
    ) {}

    /**
     * Step 1 — Redirect user to Google consent screen.
     * GET /api/v1/auth/google/redirect
     */
    public function redirect(): \Illuminate\Http\JsonResponse
    {
        $url = Socialite::driver('google')
            ->stateless()
            ->with(['prompt' => 'select_account'])
            ->redirect()
            ->getTargetUrl();

        return response()->json(['redirect_url' => $url]);
    }

    /**
     * Step 2 — Handle callback from Google.
     * GET /api/v1/auth/google/callback?code=xxx
     */
    public function callback(Request $request): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
        try {
            // Exchange code for Google user profile
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Exception $e) {
            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
            return redirect("{$frontendUrl}/login?error=google_auth_failed");
        }

        // Resolve or create our platform user
        $user = $this->googleService->findOrCreate($googleUser);

        // Issue JWT tokens
        $tokens = $this->jwtService->issueTokens($user);

        // Redirect frontend with tokens
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

        return redirect(
            "{$frontendUrl}/auth/callback" .
            "?access_token={$tokens['access_token']}" .
            "&refresh_token={$tokens['refresh_token']}"
        );
    }
}
