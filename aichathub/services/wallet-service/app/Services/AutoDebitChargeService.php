<?php

namespace App\Services;

use App\Models\AutoDebitSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Mirrors subscription-service's PaymentChargeService::chargeSavedCard() exactly —
 * same two-call shape (resolve token, then charge), reusing the same already-proven-live
 * internal payment-service endpoints rather than inventing a new charge path.
 */
class AutoDebitChargeService
{
    public function charge(AutoDebitSetting $setting): bool
    {
        $paymentUrl  = rtrim((string) config('services.payment_url'), '/');
        $internalKey = config('services.internal_key');

        if (! $paymentUrl || ! $internalKey) {
            Log::error('Auto-debit charge skipped — payment_url/internal_key not configured.', ['user_id' => $setting->user_id]);
            return false;
        }

        $token = $this->resolveToken($setting, $paymentUrl, $internalKey);
        if (! $token) {
            Log::warning('Auto-debit charge skipped — no usable saved payment method.', ['user_id' => $setting->user_id]);
            return false;
        }

        $transactionId = (string) Str::uuid();

        try {
            $response = Http::withHeaders([
                'X-Internal-Service-Key' => $internalKey,
                'Accept'                 => 'application/json',
            ])->timeout(20)->retry(2, 2000)->post("{$paymentUrl}/api/internal/payments/charge", [
                'user_id'              => $setting->user_id,
                'amount'               => (float) $setting->topup_amount_usd,
                'currency'             => 'USD',
                'payment_method_token' => $token,
                'idempotency_key'      => $transactionId,
                'description'          => 'Auto-debit wallet top-up',
            ]);

            if ($response->successful() && $response->json('status') === 'completed') {
                app(WalletService::class)->credit(
                    $setting->user_id,
                    (float) $setting->topup_amount_usd,
                    'Auto-debit top-up',
                    'auto_debit',
                    $transactionId,
                );
                return true;
            }

            return false;
        } catch (\Throwable $e) {
            Log::error('Auto-debit charge failed: '.$e->getMessage(), ['user_id' => $setting->user_id]);
            return false;
        }
    }

    private function resolveToken(AutoDebitSetting $setting, string $paymentUrl, string $internalKey): ?string
    {
        try {
            $url = $setting->payment_method_id
                ? "{$paymentUrl}/api/internal/payment-method/{$setting->payment_method_id}"
                : "{$paymentUrl}/api/internal/payment-methods/{$setting->user_id}/default";

            $response = Http::withHeaders([
                'X-Internal-Service-Key' => $internalKey,
                'Accept'                 => 'application/json',
            ])->timeout(15)->retry(2, 1000)->get($url);

            return $response->successful() ? $response->json('payment_method_token') : null;
        } catch (\Exception $e) {
            Log::error('Auto-debit payment method lookup failed: '.$e->getMessage(), ['user_id' => $setting->user_id]);
            return null;
        }
    }
}
