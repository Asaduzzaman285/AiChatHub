<?php

namespace App\Services;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class JwtService
{
    /**
     * Issue both access token (JWT) and refresh token for a user.
     */
    public function issueTokens(User $user): array
    {
        $accessToken = JWTAuth::fromUser($user);

        $rawRefreshToken = Str::random(80);

        RefreshToken::create([
            'user_id'    => $user->id,
            'token_hash' => hash('sha256', $rawRefreshToken),
            'expires_at' => now()->addMinutes((int) config('jwt.refresh_ttl', 43200)),
        ]);

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $rawRefreshToken,
            'token_type'    => 'bearer',
            'expires_in'    => (int) config('jwt.ttl', 1440) * 60,
        ];
    }

    /**
     * Rotate refresh token — revoke old one, issue new pair.
     */
    public function rotateRefreshToken(string $rawToken): array
    {
        $hash = hash('sha256', $rawToken);

        $record = RefreshToken::where('token_hash', $hash)
            ->where('revoked', false)
            ->where('expires_at', '>', now())
            ->with('user')
            ->firstOrFail();

        $record->update(['revoked' => true]);

        return $this->issueTokens($record->user);
    }

    /**
     * Revoke all refresh tokens for a user (logout from all devices).
     */
    public function revokeAll(User $user): void
    {
        RefreshToken::where('user_id', $user->id)
            ->where('revoked', false)
            ->update(['revoked' => true]);
    }
}
