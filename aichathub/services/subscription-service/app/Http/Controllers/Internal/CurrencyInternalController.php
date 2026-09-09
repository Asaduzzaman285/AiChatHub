<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use Illuminate\Http\JsonResponse;

/**
 * Lets other services (wallet-service, billing-service) snapshot "what's the
 * current effective USD-to-X rate" onto a transaction/invoice row at the
 * moment it's created — the one place outside this service that ever needs
 * to know a rate, and the only correct time to look it up. A transaction
 * should never be re-priced later against whatever the rate happens to be
 * when someone views it; it should freeze the rate that was true when it
 * actually happened, the same way ai-gateway-service's usage_logs already do
 * for AI model pricing.
 */
class CurrencyInternalController extends Controller
{
    /** GET /internal/currencies/{code}/rate */
    public function rate(string $code): JsonResponse
    {
        $currency = Currency::where('code', strtoupper($code))->where('is_active', true)->first();
        $rate = $currency?->activeRate();

        if (! $currency || ! $rate) {
            return response()->json(['message' => 'No active rate for this currency.', 'error' => 'not_found'], 404);
        }

        return response()->json([
            'currency_code'  => $currency->code,
            'symbol'         => $currency->symbol,
            'decimal_places' => $currency->decimal_places,
            'exchange_rate'  => $rate->exchange_rate,
            'effective_rate' => $rate->effective_rate,
        ]);
    }
}
