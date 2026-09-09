<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\CurrencyRate;
use App\Services\AuditLogClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Manages the currency catalog + its versioned currency_rates rows — the
 * admin-configurable conversion policy behind "show BD visitors BDT pricing"
 * and "display a BD user's wallet/transactions in BDT." Deliberately the same
 * shape as ai-gateway-service's AiModelAdminController/model_pricing: a rate
 * is never edited in place, the active row is closed (effective_until = now)
 * and a fresh one inserted instead — so a policy change tomorrow can never
 * retroactively alter what a past transaction's own stored exchange_rate
 * snapshot says was true when it happened. "Delete" here means deactivate,
 * same convention as models/admins/packages elsewhere in this platform.
 */
class CurrencyAdminController extends Controller
{
    /** GET /subscription/currencies/admin — every currency, active or not, with its current rate embedded. */
    public function index(): JsonResponse
    {
        $currencies = Currency::orderBy('code')->get()->map(fn (Currency $c) => $this->format($c));

        return response()->json(['currencies' => $currencies]);
    }

    /**
     * POST /subscription/currencies/admin — creates the currency and its initial
     * rate together. A currency with no active rate can't be converted to at
     * all (see the internal endpoint below), so requiring one up front avoids
     * silently shipping a currency nothing can actually price in.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateCurrency($request, isCreate: true);

        $currency = DB::transaction(function () use ($data) {
            $currency = Currency::create([
                'code'           => strtoupper($data['code']),
                'name'           => $data['name'],
                'symbol'         => $data['symbol'],
                'decimal_places' => $data['decimal_places'] ?? 2,
                'is_active'      => $data['is_active'] ?? true,
            ]);

            $this->createRate($currency, $data);

            return $currency;
        });

        app(AuditLogClient::class)->log(
            $this->adminId($request), 'currency.created', 'currency', $currency->id,
            null, $this->format($currency->fresh()), $request->ip(), $request->userAgent(),
        );

        return response()->json(['currency' => $this->format($currency->fresh())], 201);
    }

    /**
     * PATCH /subscription/currencies/admin/{code} — edits currency fields
     * directly. If any rate component is included, the current active rate is
     * closed and a new one inserted — see class docblock for why.
     */
    public function update(Request $request, string $code): JsonResponse
    {
        $currency = Currency::where('code', strtoupper($code))->first();

        if (! $currency) {
            return response()->json(['message' => 'Currency not found.', 'error' => 'not_found'], 404);
        }

        $data = $this->validateCurrency($request, isCreate: false);
        $old  = $this->format($currency);

        $currencyFields = array_intersect_key($data, array_flip(['name', 'symbol', 'decimal_places', 'is_active']));

        DB::transaction(function () use ($currency, $currencyFields, $data) {
            if ($currencyFields) {
                $currency->update($currencyFields);
            }

            if (isset($data['exchange_rate'])) {
                $currency->activeRate()?->update(['is_active' => false, 'effective_until' => now()]);
                $this->createRate($currency, $data);
            }
        });

        app(AuditLogClient::class)->log(
            $this->adminId($request), 'currency.updated', 'currency', $currency->id,
            $old, $this->format($currency->fresh()), $request->ip(), $request->userAgent(),
        );

        return response()->json(['currency' => $this->format($currency->fresh())]);
    }

    /** PATCH /subscription/currencies/admin/{code}/deactivate */
    public function deactivate(Request $request, string $code): JsonResponse
    {
        return $this->setActive($request, $code, false);
    }

    /** PATCH /subscription/currencies/admin/{code}/activate */
    public function activate(Request $request, string $code): JsonResponse
    {
        return $this->setActive($request, $code, true);
    }

    private function setActive(Request $request, string $code, bool $active): JsonResponse
    {
        $currency = Currency::where('code', strtoupper($code))->first();

        if (! $currency) {
            return response()->json(['message' => 'Currency not found.', 'error' => 'not_found'], 404);
        }

        $old = $this->format($currency);
        $currency->update(['is_active' => $active]);

        app(AuditLogClient::class)->log(
            $this->adminId($request), $active ? 'currency.activated' : 'currency.deactivated', 'currency', $currency->id,
            $old, $this->format($currency->fresh()), $request->ip(), $request->userAgent(),
        );

        return response()->json(['currency' => $this->format($currency->fresh())]);
    }

    private function createRate(Currency $currency, array $data): void
    {
        $exchangeRate = (float) $data['exchange_rate'];
        $margin       = (float) ($data['margin_percentage'] ?? 0);
        $tax          = (float) ($data['tax_percentage'] ?? 0);
        $vat          = (float) ($data['vat_percentage'] ?? 0);
        $withholding  = (float) ($data['withholding_tax_percentage'] ?? 0);

        CurrencyRate::create([
            'currency_code'              => $currency->code,
            'exchange_rate'              => $exchangeRate,
            'margin_percentage'          => $margin,
            'tax_percentage'             => $tax,
            'vat_percentage'             => $vat,
            'withholding_tax_percentage' => $withholding,
            'effective_rate'             => CurrencyRate::computeEffectiveRate($exchangeRate, $margin, $tax, $vat, $withholding),
            'effective_from'             => now(),
            'is_active'                  => true,
        ]);
    }

    private function validateCurrency(Request $request, bool $isCreate): array
    {
        $codeRule = $isCreate ? Rule::unique('currencies', 'code') : null;

        return $request->validate([
            'code'                       => array_filter([$isCreate ? 'required' : 'prohibited', 'string', 'size:3', $codeRule]),
            'name'                       => [$isCreate ? 'required' : 'sometimes', 'string', 'max:100'],
            'symbol'                     => [$isCreate ? 'required' : 'sometimes', 'string', 'max:10'],
            'decimal_places'             => 'nullable|integer|min:0|max:4',
            'is_active'                  => 'sometimes|boolean',
            // Rate — required together at creation; optional as a group on update.
            // effective_rate is never accepted directly, only ever computed from
            // these components — see createRate().
            'exchange_rate'              => [$isCreate ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'margin_percentage'          => 'nullable|numeric|min:0',
            // Tax is applied as a gross-up (divide by 1 - tax/100), so 100
            // would divide by zero and anything above would go negative —
            // see CurrencyRate::computeEffectiveRate().
            'tax_percentage'             => 'nullable|numeric|min:0|max:99.99',
            'vat_percentage'             => 'nullable|numeric|min:0',
            'withholding_tax_percentage' => 'nullable|numeric|min:0',
        ]);
    }

    private function format(Currency $currency): array
    {
        $rate = $currency->activeRate();

        return [
            'id'             => $currency->id,
            'code'           => $currency->code,
            'name'           => $currency->name,
            'symbol'         => $currency->symbol,
            'decimal_places' => $currency->decimal_places,
            'is_active'      => $currency->is_active,
            'created_at'     => $currency->created_at,
            'rate'           => $rate ? [
                'exchange_rate'              => $rate->exchange_rate,
                'margin_percentage'          => $rate->margin_percentage,
                'tax_percentage'             => $rate->tax_percentage,
                'vat_percentage'             => $rate->vat_percentage,
                'withholding_tax_percentage' => $rate->withholding_tax_percentage,
                'effective_rate'             => $rate->effective_rate,
                'effective_from'             => $rate->effective_from,
            ] : null,
        ];
    }
}
