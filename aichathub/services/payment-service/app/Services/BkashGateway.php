<?php

namespace App\Services;

use Ihasan\Bkash\Facades\Bkash;
use Illuminate\Support\Str;

/**
 * Wraps theihasan/laravel-bkash's Bkash facade — mirrors StripeGateway's role
 * (this app calls the gateway SDK directly, no Cashier-style scaffolding) but
 * not its shape, since bKash's tokenized Checkout API is fundamentally
 * different from Stripe's: no Session object, no signed webhooks — completion
 * is driven entirely by the return-redirect calling executePayment() once.
 *
 * bKash only settles in BDT; this app's wallet/package prices are USD-only,
 * so every amount crossing this boundary is converted via a fixed rate
 * (services.bkash.usd_to_bdt_rate) rather than a live FX lookup.
 */
class BkashGateway
{
    public function usdToBdt(float $amountUsd): float
    {
        return round($amountUsd * (float) config('services.bkash.usd_to_bdt_rate'), 2);
    }

    /**
     * @return array{payment_id: ?string, bkash_url: ?string, amount_bdt: ?float, error: ?string}
     */
    public function createCheckoutSession(
        float  $amountUsd,
        string $description,
        string $callbackUrl,
        array  $metadata,
    ): array {
        $amountBdt = $this->usdToBdt($amountUsd);

        try {
            $response = Bkash::createPayment([
                'amount'                 => number_format($amountBdt, 2, '.', ''),
                'currency'               => 'BDT',
                'payer_reference'        => (string) ($metadata['user_id'] ?? 'customer'),
                'callback_url'           => $callbackUrl,
                'merchant_invoice_number' => (string) ($metadata['transaction_id'] ?? Str::uuid()),
            ]);

            return [
                'payment_id' => $response['paymentID'] ?? null,
                'bkash_url'  => $response['bkashURL'] ?? null,
                'amount_bdt' => $amountBdt,
                'error'      => null,
            ];
        } catch (\Exception $e) {
            return ['payment_id' => null, 'bkash_url' => null, 'amount_bdt' => $amountBdt, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{success: bool, status: ?string, trx_id: ?string, error: ?string}
     */
    public function executePayment(string $paymentId): array
    {
        try {
            $response = Bkash::executePayment($paymentId);

            return [
                'success' => ($response['transactionStatus'] ?? null) === 'Completed',
                'status'  => $response['transactionStatus'] ?? null,
                'trx_id'  => $response['trxID'] ?? null,
                'error'   => null,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'status' => null, 'trx_id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{success: bool, status: ?string, trx_id: ?string, error: ?string}
     */
    public function queryPayment(string $paymentId): array
    {
        try {
            $response = Bkash::queryPayment($paymentId);

            return [
                'success' => ($response['transactionStatus'] ?? null) === 'Completed',
                'status'  => $response['transactionStatus'] ?? null,
                'trx_id'  => $response['trxID'] ?? null,
                'error'   => null,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'status' => null, 'trx_id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{success: bool, refund_trx_id: ?string, error: ?string}
     */
    public function refund(string $paymentId, string $trxId, float $amountBdt, string $reason): array
    {
        try {
            $response = Bkash::refundPayment([
                'payment_id' => $paymentId,
                'trx_id'     => $trxId,
                'amount'     => number_format($amountBdt, 2, '.', ''),
                'reason'     => $reason,
            ]);

            return ['success' => true, 'refund_trx_id' => $response['refundTrxID'] ?? null, 'error' => null];
        } catch (\Exception $e) {
            return ['success' => false, 'refund_trx_id' => null, 'error' => $e->getMessage()];
        }
    }
}
