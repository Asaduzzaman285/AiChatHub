<?php

namespace App\Services;

use App\Models\StripeCustomer;
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
     * Switches this instance to the test-mode Stripe client, but only when $origin
     * exactly matches the configured sandbox origin (staging.alveta.ai) — every other
     * case, including null (no Origin header at all, e.g. a server-to-server call),
     * leaves the live client from the constructor untouched. Deliberately NOT called
     * from resolveOrCreateCustomer()/attachToCustomer()/charge() — a saved card is
     * always tokenized against the frontend's one build-time publishable key (live,
     * since staging shares that build), so a test-mode secret key here would just
     * reject it as a cross-mode object instead of actually sandboxing anything. Only
     * the one-time Checkout Session path (createCheckoutSession(), used by top-up,
     * subscribe, and upgrade) is safe to sandbox this way — it never touches a
     * pre-existing Customer/PaymentMethod object at all.
     */
    public function useSandboxIfOrigin(?string $origin): void
    {
        $sandboxOrigin = config('services.stripe.sandbox_origin');
        if ($origin && $sandboxOrigin && $origin === $sandboxOrigin) {
            $this->stripe = new StripeClient(config('services.stripe.test_secret'));
        }
    }

    /**
     * One Stripe Customer per user, created lazily. Needed so saved-card charges can
     * go through Stripe's off_session flow (see charge() below) instead of a bare
     * payment_method with nothing backing it — real cards under SCA can reject that.
     */
    public function resolveOrCreateCustomer(string $userId): string
    {
        $existing = StripeCustomer::where('user_id', $userId)->first();
        if ($existing) {
            return $existing->stripe_customer_id;
        }

        $customer = $this->stripe->customers->create(['metadata' => ['user_id' => $userId]]);

        StripeCustomer::create(['user_id' => $userId, 'stripe_customer_id' => $customer->id]);

        return $customer->id;
    }

    /**
     * Attach a saved PaymentMethod to the user's Stripe Customer — called once at
     * save time (PaymentMethodController::store()). Stripe errors if a PaymentMethod
     * is attached twice, so "already attached to this customer" is treated as
     * success rather than a failure.
     */
    public function attachToCustomer(string $paymentMethodId, string $customerId): void
    {
        try {
            $this->stripe->paymentMethods->attach($paymentMethodId, ['customer' => $customerId]);
        } catch (ApiErrorException $e) {
            if (! str_contains($e->getMessage(), 'already been attached')) {
                throw $e;
            }
        }
    }

    /**
     * Charge a saved payment method (for subscription purchase / renewal / auto-debit).
     * Goes through the customer + off_session flow rather than a bare payment_method —
     * this is a merchant-initiated charge with no user present, and Stripe needs to
     * know that to apply the right SCA exemption logic instead of just failing outright
     * on cards that require it. attachToCustomer() is called defensively here (not just
     * at save time) so this stays correct even for payment methods saved before this
     * existed, without needing a data backfill.
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
            $customerId = $this->resolveOrCreateCustomer($userId);
            $this->attachToCustomer($paymentMethodToken, $customerId);

            $amountCents = (int) round($amount * 100);

            $intent = $this->stripe->paymentIntents->create([
                'amount'         => $amountCents,
                'currency'       => strtolower($currency),
                'customer'       => $customerId,
                'payment_method' => $paymentMethodToken,
                'off_session'    => true,
                'confirm'        => true,
                'description'    => $description,
                'metadata'       => ['user_id' => $userId],
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
                // 'authentication_required' means the card genuinely needs the customer
                // to come back and confirm — distinct from a plain decline, worth being
                // able to tell apart later even though nothing branches on it yet.
                'error_code'        => $e->getError()->code ?? null,
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
     * Create a hosted Stripe Checkout Session. Always mode=payment (one-time) — this
     * codebase models subscriptions as its own periodic charges rather than Stripe's
     * native recurring Subscription objects, so there's no Dashboard-created
     * Product/Price to reference; the line item is built inline via price_data.
     *
     * @throws ApiErrorException
     */
    public function createCheckoutSession(
        float  $amount,
        string $currency,
        string $description,
        string $successUrl,
        string $cancelUrl,
        array  $metadata,
        string $idempotencyKey
    ): \Stripe\Checkout\Session {
        return $this->stripe->checkout->sessions->create([
            'mode'                => 'payment',
            'line_items'          => [[
                'price_data' => [
                    'currency'     => strtolower($currency),
                    'unit_amount'  => (int) round($amount * 100),
                    'product_data' => ['name' => $description],
                ],
                'quantity' => 1,
            ]],
            'success_url'         => $successUrl,
            'cancel_url'          => $cancelUrl,
            'metadata'            => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
        ], ['idempotency_key' => $idempotencyKey]);
    }

    /** @throws ApiErrorException */
    public function retrieveCheckoutSession(string $sessionId): \Stripe\Checkout\Session
    {
        return $this->stripe->checkout->sessions->retrieve($sessionId);
    }

    /**
     * Used to resolve a dispute webhook (which only carries a payment_intent/charge
     * id) back to our own Transaction — the PaymentIntent's metadata carries
     * transaction_id (set at creation via payment_intent_data.metadata in
     * createCheckoutSession() above), so no separate id-mapping table is needed.
     *
     * @throws ApiErrorException
     */
    public function retrievePaymentIntent(string $paymentIntentId): \Stripe\PaymentIntent
    {
        return $this->stripe->paymentIntents->retrieve($paymentIntentId);
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
