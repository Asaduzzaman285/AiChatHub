<?php

namespace App\Jobs;

use App\Models\AutoDebitSetting;
use App\Services\AutoDebitChargeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Dispatched from WalletService::deduct() when a balance crosses the user's configured
 * auto-debit threshold — queued deliberately so the live chat-deduct hot path never blocks
 * on a Stripe round-trip. Re-fetches the setting fresh (not passed in) since some time may
 * pass between dispatch and execution and the user could have disabled it in the meantime.
 */
class TriggerAutoDebitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(public readonly string $userId)
    {
        // Not a `public string $queue` property — Queueable already declares one,
        // and redeclaring it with a differing definition is a fatal trait-composition
        // error in PHP (caught live: "define the same property... considered
        // incompatible"). onQueue() is the trait's own safe way to set it.
        $this->onQueue('wallet');
    }

    public function handle(AutoDebitChargeService $charges): void
    {
        $setting = AutoDebitSetting::where('user_id', $this->userId)->where('enabled', true)->first();
        if (! $setting) {
            return;
        }

        $success = $charges->charge($setting);

        Log::info('Auto-debit attempt', ['user_id' => $this->userId, 'success' => $success]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Auto-debit job failed', ['user_id' => $this->userId, 'error' => $e->getMessage()]);
    }
}
