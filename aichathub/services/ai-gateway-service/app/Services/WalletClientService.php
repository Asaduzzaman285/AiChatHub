<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WalletClientService
{
    private string $baseUrl;
    private string $internalKey;

    public function __construct()
    {
        $this->baseUrl     = rtrim(config('services.wallet_url', 'http://wallet-nginx'), '/');
        $this->internalKey = config('services.internal_key');
    }

    /**
     * True = reserved. False = wallet-service responded and genuinely denied it (real
     * insufficient-balance case). Null = we couldn't reach wallet-service at all (timeout /
     * network error) — distinct from a real denial so the caller doesn't tell the user
     * "top up your wallet" when the actual problem is a transient infrastructure hiccup.
     */
    public function reserve(string $userId, float $amount): ?bool
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders(['X-Internal-Service-Key' => $this->internalKey])
                ->post("{$this->baseUrl}/api/internal/wallet/reserve", [
                    'user_id' => $userId,
                    'amount'  => $amount,
                ]);

            if (! $response->successful()) {
                // 422 insufficient_balance is the one genuine-denial case; anything else
                // (500, unexpected shape) is treated as unreachable, same as a network error.
                return $response->status() === 422 ? false : null;
            }

            return $response->json('success') === true;
        } catch (\Exception $e) {
            Log::error('WalletClientService::reserve failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function deduct(string $userId, float $actual, float $reserved, string $description, ?string $referenceId = null): void
    {
        try {
            Http::timeout(15)
                ->withHeaders(['X-Internal-Service-Key' => $this->internalKey])
                ->post("{$this->baseUrl}/api/internal/wallet/deduct", [
                    'user_id'         => $userId,
                    'amount'          => $actual,
                    'reserved_amount' => $reserved,
                    'description'     => $description,
                    'reference_id'    => $referenceId,
                ]);
        } catch (\Exception $e) {
            Log::error('WalletClientService::deduct failed', ['error' => $e->getMessage()]);
        }
    }

    public function refund(string $userId, float $amount, float $reserved, string $reason, ?string $referenceId = null): void
    {
        try {
            Http::timeout(15)
                ->withHeaders(['X-Internal-Service-Key' => $this->internalKey])
                ->post("{$this->baseUrl}/api/internal/wallet/refund", [
                    'user_id'         => $userId,
                    'amount'          => $amount,
                    'reserved_amount' => $reserved,
                    'reason'          => $reason,
                    'reference_id'    => $referenceId,
                ]);
        } catch (\Exception $e) {
            Log::error('WalletClientService::refund failed', ['error' => $e->getMessage()]);
        }
    }
}
