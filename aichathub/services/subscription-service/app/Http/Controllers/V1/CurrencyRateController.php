<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use Illuminate\Http\JsonResponse;

/**
 * Public, read-only counterpart to CurrencyInternalController's internal
 * version — lets the frontend convert a USD figure (wallet balance, a past
 * transaction, usage spend) into the viewer's own preferred_currency for
 * display, without exposing the admin-only conversion-policy CRUD endpoints.
 * No auth required: an exchange rate isn't sensitive, and the register page
 * needs this before a session exists.
 */
class CurrencyRateController extends Controller
{
    /** GET /subscription/currencies/{code}/rate */
    public function show(string $code): JsonResponse
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
            'effective_rate' => (float) $rate->effective_rate,
        ]);
    }
}
