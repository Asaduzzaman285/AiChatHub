<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around the direct internal-HTTP-call pattern already used
 * elsewhere in this codebase (e.g. auth-service crediting wallet on
 * registration) — shared here because both TopupController and
 * ProcessStripeWebhookJob need to credit the wallet / create a receipt.
 */
class InternalServiceClient
{
    public function creditWallet(string $userId, float $amount, string $description, string $referenceId): bool
    {
        if ($amount <= 0) {
            return false;
        }

        $walletUrl   = rtrim((string) config('services.wallet_url'), '/');
        $internalKey = config('services.internal_key');

        if (! $walletUrl || ! $internalKey) {
            Log::error('Wallet credit skipped — wallet_url/internal_key not configured.', ['user_id' => $userId]);
            return false;
        }

        try {
            $response = Http::withHeaders([
                'X-Internal-Service-Key' => $internalKey,
                'Accept'                 => 'application/json',
            ])->timeout(15)->post("{$walletUrl}/api/internal/wallet/credit", [
                'user_id'        => $userId,
                'amount'         => $amount,
                'description'    => $description,
                'reference_type' => 'transaction',
                'reference_id'   => $referenceId,
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Wallet credit failed: '.$e->getMessage(), ['user_id' => $userId, 'reference_id' => $referenceId]);
            return false;
        }
    }

    public function createReceipt(string $userId, float $amount, string $currency, string $transactionId, string $type = 'wallet_topup'): bool
    {
        $billingUrl  = rtrim((string) config('services.billing_url'), '/');
        $internalKey = config('services.internal_key');

        if (! $billingUrl || ! $internalKey) {
            return false;
        }

        try {
            $response = Http::withHeaders([
                'X-Internal-Service-Key' => $internalKey,
                'Accept'                 => 'application/json',
            ])->timeout(15)->post("{$billingUrl}/api/internal/receipts/create", [
                'user_id'        => $userId,
                'type'           => $type,
                'amount'         => $amount,
                'currency'       => $currency,
                'transaction_id' => $transactionId,
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Receipt creation failed: '.$e->getMessage(), ['user_id' => $userId, 'transaction_id' => $transactionId]);
            return false;
        }
    }

    public function sendReceiptEmail(string $userId, float $amount, string $currency, string $description, string $idempotencyKey): void
    {
        $authUrl         = rtrim((string) config('services.auth_url'), '/');
        $notificationUrl = rtrim((string) config('services.notification_url'), '/');
        $internalKey     = config('services.internal_key');

        if (! $authUrl || ! $notificationUrl || ! $internalKey) {
            return;
        }

        try {
            $user = Http::withHeaders(['X-Internal-Service-Key' => $internalKey, 'Accept' => 'application/json'])
                ->timeout(15)->get("{$authUrl}/api/internal/users/{$userId}");

            if (! $user->successful()) {
                return;
            }

            Http::withHeaders(['X-Internal-Service-Key' => $internalKey, 'Accept' => 'application/json'])
                ->timeout(15)->post("{$notificationUrl}/api/internal/notifications/send", [
                    'type'            => 'receipt',
                    'user_id'         => $userId,
                    'email'           => $user->json('email'),
                    'data'            => ['name' => $user->json('name'), 'amount' => $amount, 'currency' => $currency, 'description' => $description],
                    'idempotency_key' => $idempotencyKey,
                ]);
        } catch (\Exception $e) {
            Log::error('Receipt email failed: '.$e->getMessage(), ['user_id' => $userId]);
        }
    }
}
