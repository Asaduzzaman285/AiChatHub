<?php

namespace App\Jobs;

use App\Services\WalletClientService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Releases a wallet reservation left over from a chat request that never
 * completed (bad provider API key, timeout, etc.). Dispatched from a
 * register_shutdown_function() in bootstrap/app.php rather than called
 * directly — a synchronous HTTP call made from a shutdown handler isn't
 * reliably given time to finish by PHP-FPM once the response has already
 * been sent, but a fast Redis dispatch is.
 */
class ReleaseWalletReservationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $userId,
        public float $amount,
    ) {}

    public function handle(WalletClientService $walletClient): void
    {
        $walletClient->refund($this->userId, 0, $this->amount, 'AI request did not complete — releasing reservation');
    }
}
