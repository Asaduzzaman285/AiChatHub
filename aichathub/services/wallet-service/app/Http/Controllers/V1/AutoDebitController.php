<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\AutoDebitSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutoDebitController extends Controller
{
    /** GET /wallet/auto-debit — defaults returned (not persisted) if the user never configured one. */
    public function show(Request $request): JsonResponse
    {
        $setting = AutoDebitSetting::where('user_id', $this->authUserId($request))->first();

        return response()->json(['auto_debit' => $this->format($setting)]);
    }

    /** PUT /wallet/auto-debit — Stripe-only for now; payment_method_id null means "use my default card". */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled'            => 'required|boolean',
            'threshold_usd'      => 'required|numeric|min:0.01',
            'topup_amount_usd'   => 'required|numeric|min:1',
            'payment_method_id'  => 'nullable|uuid',
        ]);

        $setting = AutoDebitSetting::updateOrCreate(
            ['user_id' => $this->authUserId($request)],
            $data,
        );

        return response()->json(['auto_debit' => $this->format($setting)]);
    }

    private function format(?AutoDebitSetting $setting): array
    {
        return [
            'enabled'            => $setting?->enabled ?? false,
            'threshold_usd'      => (float) ($setting?->threshold_usd ?? 1.00),
            'topup_amount_usd'   => (float) ($setting?->topup_amount_usd ?? 10.00),
            'payment_method_id'  => $setting?->payment_method_id,
        ];
    }
}
