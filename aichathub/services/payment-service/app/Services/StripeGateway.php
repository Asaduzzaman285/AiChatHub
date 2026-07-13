<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Str;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class StripeGateway
{
    private StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(config('services.stripe.secret'));
    }

    /**
     * Charge a saved payment method (for subscription purchase / renewal).
     */
    public function charge(
        string $userId,
        string $paymentMethodToken,
        float  $amount,
        string $currency,
        string $idempotencyKey,
        string $description
    ): array {
        try {
            $amountCents = (int) round($amount * 100);

            $intent = $this->stripe->paymentIntents->create([
                'amount'               => $amountCents,
                'currency'             => strtolower($currency),
                'payment_method'       => $paymentMethodToken,
                'confirm'              => true,
                'description'          => $description,
                'metadata'             => ['user_id' => $userId],
                'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
            ], ['idempotency_key' => $idempotencyKey]);

            return [
                'success'           => $intent->status === 'succeeded',
                'gateway_reference' => $intent->id,
                'status'            => $intent->status,
                'error'             => null,
            ];
        } catch (ApiErrorException $e) {
            return [
                'success'           => false,
                'gateway_reference' => null,
                'status'            => 'failed',
                'error'             => $e->getMessage(),
            ];
        }
    }

    /**
     * Refund a completed charge.
     */
    public function refund(string $gatewayReference, float $amount): array
    {
        try {
            $refund = $this->stripe->refunds->create([
                'payment_intent' => $gatewayReference,
                'amount'         => (int) round($amount * 100),
            ]);

            return ['success' => true, 'refund_id' => $refund->id, 'error' => null];
        } catch (ApiErrorException $e) {
            return ['success' => false, 'refund_id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Verify webhook signature to prevent spoofed events.
     */
    public function verifyWebhook(string $payload, string $signature): ?\Stripe\Event
    {
        try {
            return \Stripe\Webhook::constructEvent(
                $payload,
                $signature,
                config('services.stripe.webhook_secret')
            );
        } catch (\Exception) {
            return null;
        }
    }
}
