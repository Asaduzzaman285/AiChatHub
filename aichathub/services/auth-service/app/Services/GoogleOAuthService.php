<?php

namespace App\Services;

use App\Events\UserRegistered;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Two\User as GoogleUser;

class GoogleOAuthService
{
    /**
     * Find an existing user by Google ID, or create a new one.
     * Also handles account linking when email already exists.
     */
    public function findOrCreate(GoogleUser $googleUser): User
    {
        return DB::transaction(function () use ($googleUser) {

            // 1. Check if this Google account is already linked
            $social = SocialAccount::where('provider', 'google')
                ->where('provider_user_id', $googleUser->getId())
                ->with('user')
                ->first();

            if ($social) {
                // Known Google user — update tokens and return
                $this->updateTokens($social, $googleUser);
                return $social->user;
            }

            // 2. Check if email already registered (email/password user)
            $user = User::where('email', $googleUser->getEmail())->first();

            if (! $user) {
                // 3. Brand new user — auto-register
                $user = User::create([
                    'email'             => $googleUser->getEmail(),
                    'name'              => $googleUser->getName(),
                    'password'          => null, // Google-only accounts have no password
                    'status'            => 'active',
                    'email_verified_at' => now(), // Google already verified the email
                    'avatar_url'        => $googleUser->getAvatar(),
                ]);

                // Publish event so Wallet + Notification services react
                event(new UserRegistered($user));
            }

            // 4. Link this Google account to the user
            $social = SocialAccount::create([
                'user_id'          => $user->id,
                'provider'         => 'google',
                'provider_user_id' => $googleUser->getId(),
                'access_token'     => $googleUser->token,
                'refresh_token'    => $googleUser->refreshToken,
                'token_expires_at' => $googleUser->expiresIn
                    ? now()->addSeconds($googleUser->expiresIn)
                    : null,
                'avatar_url'       => $googleUser->getAvatar(),
                'raw_data'         => $googleUser->getRaw(),
            ]);

            return $user;
        });
    }

    /**
     * Link Google to an already-authenticated user.
     */
    public function linkToUser(User $user, GoogleUser $googleUser): SocialAccount
    {
        return DB::transaction(function () use ($user, $googleUser) {
            // Guard: this Google account must not be linked to another user
            $existingLink = SocialAccount::where('provider', 'google')
                ->where('provider_user_id', $googleUser->getId())
                ->where('user_id', '!=', $user->id)
                ->first();

            if ($existingLink) {
                throw new \RuntimeException('This Google account is already connected to another user.');
            }

            return SocialAccount::updateOrCreate(
                ['user_id' => $user->id, 'provider' => 'google'],
                [
                    'provider_user_id' => $googleUser->getId(),
                    'access_token'     => $googleUser->token,
                    'refresh_token'    => $googleUser->refreshToken,
                    'token_expires_at' => $googleUser->expiresIn
                        ? now()->addSeconds($googleUser->expiresIn)
                        : null,
                    'avatar_url'       => $googleUser->getAvatar(),
                    'raw_data'         => $googleUser->getRaw(),
                ]
            );
        });
    }

    /**
     * Unlink Google from a user.
     * Requires the user to have a password set first.
     */
    public function unlinkFromUser(User $user): void
    {
        if (is_null($user->password)) {
            throw new \RuntimeException(
                'Set a password before disconnecting Google to avoid losing account access.'
            );
        }

        SocialAccount::where('user_id', $user->id)
            ->where('provider', 'google')
            ->delete();
    }

    private function updateTokens(SocialAccount $social, GoogleUser $googleUser): void
    {
        $social->update([
            'access_token'     => $googleUser->token,
            'refresh_token'    => $googleUser->refreshToken ?? $social->refresh_token,
            'token_expires_at' => $googleUser->expiresIn
                ? now()->addSeconds($googleUser->expiresIn)
                : null,
            'avatar_url'       => $googleUser->getAvatar(),
        ]);
    }
}
