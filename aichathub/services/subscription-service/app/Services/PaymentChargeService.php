<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * How the background renewal job (ProcessRenewalJob) actually moves money —
 * it has no browser to redirect through, so Checkout Sessions (the
 * interactive purchase/upgrade flow) aren't an option; it charges the user's
 * saved default payment method directly instead. Wallet balance is
 * deliberately never a funding source here — see the class docblock on
 * ProcessRenewalJob for why.
 */
class PaymentChargeService
{
    /**
     * Charges the user's saved default card directly (no Checkout redirect) —
     * only usable for a user who has previously saved a card via
     * POST /payment-methods. A background renewal has no browser to send
     * anyone to, so Checkout Sessions (interactive) aren't an option here.
     */
    public function chargeSavedCard(string $userId, float $amount, string $currency, string $transactionId, string $description): bool
    {
        $paymentUrl  = rtrim((string) config('services.payment_url'), '/');
        $internalKey = config('services.internal_key');

        if (! $paymentUrl || ! $internalKey) {
            Log::error('Saved-card charge skipped — payment_url/internal_key not configured.', ['user_id' => $userId]);
            return false;
        }

        $token = $this->defaultCardToken($userId, $paymentUrl, $internalKey);
        if (! $token) {
            return false;
        }

        try {
            // Safe to retry on timeout — PaymentInternalController::charge() has its own
            // idempotency check on idempotency_key, same reasoning as chargeWallet() above.
            $response = Http::withHeaders([
                'X-Internal-Service-Key' => $internalKey,
                'Accept'                 => 'application/json',
            ])->timeout(20)->retry(2, 2000)->post("{$paymentUrl}/api/internal/payments/charge", [
                'user_id'              => $userId,
                'amount'               => $amount,
                'currency'             => $currency,
                'payment_method_token' => $token,
                'idempotency_key'      => $transactionId,
                'description'          => $description,
            ]);

            return $response->successful() && ($response->json('status') === 'completed');
        } catch (\Exception $e) {
            Log::error('Saved-card charge failed: '.$e->getMessage(), ['user_id' => $userId]);
            return false;
        }
    }

    private function defaultCardToken(string $userId, string $paymentUrl, string $internalKey): ?string
    {
        try {
            $response = Http::withHeaders([
                'X-Internal-Service-Key' => $internalKey,
                'Accept'                 => 'application/json',
            ])->timeout(15)->retry(2, 1000)->get("{$paymentUrl}/api/internal/payment-methods/{$userId}/default");

            return $response->successful() ? $response->json('payment_method_token') : null;
        } catch (\Exception $e) {
            Log::error('Default payment method lookup failed: '.$e->getMessage(), ['user_id' => $userId]);
            return null;
        }
    }
}
