<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The two ways a subscription-service action can actually move money — shared
 * between the interactive purchase flow (SubscriptionController::subscribe(),
 * wallet branch) and the background renewal job (ProcessRenewalJob), which has
 * no browser to redirect through and so can't use Checkout Sessions at all.
 */
class PaymentChargeService
{
    /** Reserve+deduct against the wallet — same two-step Wallet Service uses for AI cost. */
    public function chargeWallet(string $userId, float $amount, string $transactionId, string $description): bool
    {
        $walletUrl   = rtrim((string) config('services.wallet_url'), '/');
        $internalKey = config('services.internal_key');

        if (! $walletUrl || ! $internalKey) {
            Log::error('Wallet charge skipped — wallet_url/internal_key not configured.', ['user_id' => $userId]);
            return false;
        }

        try {
            $reserve = Http::withHeaders([
                'X-Internal-Service-Key' => $internalKey,
                'Accept'                 => 'application/json',
            ])->timeout(15)->post("{$walletUrl}/api/internal/wallet/reserve", [
                'user_id' => $userId,
                'amount'  => $amount,
            ]);

            if (! $reserve->successful()) {
                return false;
            }

            // This environment routinely has calls that succeed server-side but time
            // out client-side (documented throughout this project) — a bare timeout
            // here does NOT mean the deduct didn't happen. Retrying is safe specifically
            // because deduct() is idempotent on (reference_type, reference_id): if the
            // first attempt actually landed, the retry finds the existing ledger entry
            // and no-ops instead of double-charging.
            $deduct = Http::withHeaders([
                'X-Internal-Service-Key' => $internalKey,
                'Accept'                 => 'application/json',
            ])->timeout(15)->retry(2, 2000)->post("{$walletUrl}/api/internal/wallet/deduct", [
                'user_id'         => $userId,
                'amount'          => $amount,
                'reserved_amount' => $amount,
                'description'     => $description,
                'reference_type'  => 'subscription_purchase',
                'reference_id'    => $transactionId,
            ]);

            return $deduct->successful();
        } catch (\Exception $e) {
            Log::error('Wallet charge failed: '.$e->getMessage(), ['user_id' => $userId]);
            return false;
        }
    }

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
