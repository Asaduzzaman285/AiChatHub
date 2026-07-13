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

    public function reserve(string $userId, float $amount): bool
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['X-Internal-Key' => $this->internalKey])
                ->post("{$this->baseUrl}/internal/wallet/reserve", [
                    'user_id' => $userId,
                    'amount'  => $amount,
                ]);

            return $response->successful() && $response->json('success') === true;
        } catch (\Exception $e) {
            Log::error('WalletClientService::reserve failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function deduct(string $userId, float $actual, float $reserved, string $description, ?string $referenceId = null): void
    {
        try {
            Http::timeout(5)
                ->withHeaders(['X-Internal-Key' => $this->internalKey])
                ->post("{$this->baseUrl}/internal/wallet/deduct", [
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
            Http::timeout(5)
                ->withHeaders(['X-Internal-Key' => $this->internalKey])
                ->post("{$this->baseUrl}/internal/wallet/refund", [
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
