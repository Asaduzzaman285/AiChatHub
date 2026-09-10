<?php

namespace App\Services;

use Ihasan\Bkash\Facades\Bkash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Wraps theihasan/laravel-bkash's Bkash facade — mirrors StripeGateway's role
 * (this app calls the gateway SDK directly, no Cashier-style scaffolding) but
 * not its shape, since bKash's tokenized Checkout API is fundamentally
 * different from Stripe's: no Session object, no signed webhooks — completion
 * is driven entirely by the return-redirect calling executePayment() once.
 *
 * bKash only settles in BDT; every amount crossing this boundary is either a
 * package's own admin-set fixed BDT sticker price (passed in directly as
 * $fixedAmountBdt — see createCheckoutSession()) or, for amounts with no
 * sticker (wallet top-ups), converted via usdToBdt()'s live currency_rates
 * lookup.
 */
class BkashGateway
{
    /**
     * config('bkash.credentials')/config('bkash.sandbox') as this request
     * actually booted them (straight from .env, before useSandbox() ever
     * touches anything) — captured once so useSandbox(false) can explicitly
     * restore both later rather than assuming nothing already changed them.
     *
     * $baselineIsSandbox matters on its own, separate from the credentials:
     * it's what picks live_base_url vs sandbox_base_url (see
     * BkashServiceProvider::packageBooted()'s Http::macro). Today .env's only
     * real credential set IS a sandbox account (BKASH_SANDBOX=true) — an
     * earlier version of useSandbox(false) hardcoded 'bkash.sandbox' => false
     * instead of restoring this, which pointed those same sandbox credentials
     * at bKash's *live* endpoint on every request with no origin match (i.e.
     * every request today, since sandbox_origin isn't configured yet) —
     * authentication fails outright against the wrong environment. Confirmed
     * live: broke bKash on both app.alveta.ai and staging.alveta.ai at once.
     */
    private array $liveCredentials;
    private bool $baselineIsSandbox;

    public function __construct()
    {
        $this->liveCredentials = config('bkash.credentials');
        $this->baselineIsSandbox = (bool) config('bkash.sandbox');
    }

    /**
     * Switches subsequent bKash calls to sandbox credentials, but only when
     * $origin exactly matches the configured sandbox origin — every other
     * case, including null (no Origin header, e.g. a server-to-server call),
     * uses the live credentials from config/bkash.php. Mirrors
     * StripeGateway::useSandboxIfOrigin() exactly; safe to mutate global
     * config here (unlike ai-gateway-service/chat-service) because
     * payment-service runs on plain PHP-FPM, not Octane — a fresh process per
     * request, so nothing set here can leak into a later request.
     */
    public function useSandboxIfOrigin(?string $origin): void
    {
        $sandboxOrigin = config('services.bkash.sandbox_origin');

        $this->useSandbox((bool) ($origin && $sandboxOrigin && $origin === $sandboxOrigin));
    }

    /**
     * Explicit sandbox/live control, for call sites with no browser Origin to
     * read (the reconciliation sweep, an admin-triggered refund) — those
     * instead read back whichever mode a transaction's own metadata recorded
     * at checkout-creation time (see CreatesCheckoutSessions::beginBkashCheckout()),
     * since re-deriving from *today's* request Origin would be wrong: a
     * sandbox-created paymentID is invisible to a live-mode token and vice
     * versa, and a background job or an admin's own session Origin tells you
     * nothing about which mode actually created the payment being resolved.
     *
     * Fully authoritative both ways — unlike an earlier version of this method
     * that only ever acted on true and silently no-op'd on false. That no-op
     * meant this always fell back to whatever config/bkash.php's own
     * BKASH_SANDBOX said, with nothing here ever able to override it back to
     * live. Confirmed live: with the pre-existing BKASH_SANDBOX=true left in
     * production's .env (real credentials weren't live yet), a checkout
     * created from app.alveta.ai got is_sandbox=true recorded purely because
     * of that stale flag, and verify()'s later useSandbox(true) call then
     * force-swapped in the *dedicated* sandbox credential slots — which were
     * never actually filled in, since only one real credential set has ever
     * existed — breaking executePayment() with blank credentials and
     * cancelling a payment bKash had already completed on its end.
     */
    public function useSandbox(bool $sandbox): void
    {
        config([
            'bkash.sandbox'     => $sandbox ? true : $this->baselineIsSandbox,
            'bkash.credentials' => $sandbox ? $this->sandboxCredentials() : $this->liveCredentials,
        ]);

        // The vendor Bkash class reads config('bkash.credentials') once, in its
        // own constructor, and is bound as a container singleton — force a
        // fresh instance so it actually picks up the sandbox credentials just
        // set above instead of whatever it read (live) the first time
        // something resolved it this request.
        app()->forgetInstance(\Ihasan\Bkash\Bkash::class);

        // The vendor's own bKash token cache key is NOT namespaced by which
        // credential set generated it — getToken() caches under the bare key
        // 'bkash_token' regardless of sandbox vs live (its forTenant() method
        // exists specifically to prefix that key per-tenant, but nothing here
        // was calling it). With one shared payment-service deployment now
        // legitimately switching between two real credential sets (sandbox
        // for staging, live for production), a token cached by one environment
        // was being handed to the other — bKash then rejects create/execute
        // calls made with a token that doesn't match the app_key in the
        // request, surfacing as a generic "Failed to create payment" with no
        // hint it was a stale-token problem. Confirmed live via Redis: a
        // single un-namespaced aichathub_payment_cache_bkash_token key shared
        // by both app.alveta.ai and staging.alveta.ai.
        app(\Ihasan\Bkash\Bkash::class)->forTenant($sandbox ? 'sandbox' : 'live');
    }

    /**
     * Falls back to the live credential for any field with no dedicated
     * sandbox value configured — today that's all four fields, since only one
     * real bKash credential set has ever existed (BKASH_SANDBOX_APP_KEY etc.
     * are for a later phase, once real live credentials arrive and this app
     * genuinely needs two distinct sets). Without this fallback, "sandbox"
     * mode means blank credentials rather than "the same credentials as
     * live," which is the bug described on useSandbox() above.
     */
    private function sandboxCredentials(): array
    {
        return [
            'app_key'    => config('services.bkash.sandbox_app_key') ?: $this->liveCredentials['app_key'],
            'app_secret' => config('services.bkash.sandbox_app_secret') ?: $this->liveCredentials['app_secret'],
            'username'   => config('services.bkash.sandbox_username') ?: $this->liveCredentials['username'],
            'password'   => config('services.bkash.sandbox_password') ?: $this->liveCredentials['password'],
        ];
    }

    public function isSandbox(): bool
    {
        return (bool) config('bkash.sandbox');
    }

    public function usdToBdt(float $amountUsd): float
    {
        return round($amountUsd * $this->currentBdtRate(), 2);
    }

    /**
     * The live admin-configured USD->BDT rate (exchange rate plus margin/tax/
     * VAT/withholding — see subscription-service's CurrencyRate::computeEffectiveRate())
     * for amounts with no fixed sticker price of their own, i.e. wallet
     * top-ups. Cached briefly since this is a synchronous cross-service call
     * on a user-facing checkout path; falls back to the static
     * services.bkash.usd_to_bdt_rate config if subscription-service can't be
     * reached at all, rather than failing the whole top-up.
     */
    public function currentBdtRate(): float
    {
        return Cache::remember('bkash:bdt_effective_rate', now()->addMinutes(15), function () {
            $subscriptionUrl = rtrim((string) config('services.subscription_url'), '/');
            $internalKey     = config('services.internal_key');

            if ($subscriptionUrl && $internalKey) {
                try {
                    $response = Http::withHeaders([
                        'X-Internal-Service-Key' => $internalKey,
                        'Accept'                 => 'application/json',
                    ])->timeout(5)->get("{$subscriptionUrl}/api/internal/currencies/BDT/rate");

                    if ($response->successful() && $response->json('effective_rate')) {
                        return (float) $response->json('effective_rate');
                    }
                } catch (\Exception $e) {
                    Log::warning('BDT rate lookup failed, using static fallback: '.$e->getMessage());
                }
            }

            return (float) config('services.bkash.usd_to_bdt_rate');
        });
    }

    /**
     * @param ?float $fixedAmountBdt Pass a package's own admin-set BDT sticker
     *   price to charge that exact amount instead of deriving one from
     *   usdToBdt() — see the class docblock.
     * @return array{payment_id: ?string, bkash_url: ?string, amount_bdt: ?float, error: ?string}
     */
    public function createCheckoutSession(
        float   $amountUsd,
        string  $description,
        string  $callbackUrl,
        array   $metadata,
        ?float  $fixedAmountBdt = null,
    ): array {
        $amountBdt = $fixedAmountBdt ?? $this->usdToBdt($amountUsd);

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
