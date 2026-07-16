<?php

namespace App\Jobs;

use App\Services\WalletService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Dispatched when auth-service fires UserRegistered event.
 * wallet-service queue worker picks this up from the 'user-registered' queue.
 */
class HandleUserRegistered implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 10;

    public function __construct(
        public readonly string $userId,
        public readonly string $currency = 'USD',
    ) {
        $this->onQueue('user-registered');
    }

    public function handle(WalletService $walletService): void
    {
        $wallet = $walletService->createForUser($this->userId, $this->currency);

        Log::info('Wallet auto-created for new user', [
            'user_id'   => $this->userId,
            'wallet_id' => $wallet->id,
            'currency'  => $wallet->currency,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Failed to create wallet for new user', [
            'user_id' => $this->userId,
            'error'   => $e->getMessage(),
        ]);
    }
}
