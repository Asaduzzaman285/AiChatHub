<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class PaymentMethodController extends Controller
{
    /** GET /payment-methods */
    public function index(Request $request): JsonResponse
    {
        $methods = PaymentMethod::where('user_id', $this->authUserId($request))
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['payment_methods' => $methods]);
    }

    /** POST /payment-methods — save a Stripe PaymentMethod created client-side via Stripe.js */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_method_token' => 'required|string',
            'set_default'          => 'nullable|boolean',
        ]);

        $userId = $this->authUserId($request);

        try {
            $client = new StripeClient(config('services.stripe.secret'));
            $pm     = $client->paymentMethods->retrieve($data['payment_method_token']);
        } catch (ApiErrorException $e) {
            return response()->json(['message' => 'Invalid payment method.', 'error' => $e->getMessage()], 422);
        }

        if ($pm->type !== 'card' || ! $pm->card) {
            return response()->json(['message' => 'Only card payment methods are supported.', 'error' => 'unsupported_type'], 422);
        }

        $makeDefault = (bool) ($data['set_default'] ?? false);

        $method = DB::transaction(function () use ($userId, $data, $pm, $makeDefault) {
            $hasExisting = PaymentMethod::where('user_id', $userId)->where('is_active', true)->exists();

            if ($makeDefault || ! $hasExisting) {
                PaymentMethod::where('user_id', $userId)->update(['is_default' => false]);
            }

            return PaymentMethod::create([
                'user_id'    => $userId,
                'gateway'    => 'stripe',
                'type'       => 'card',
                'token'      => $data['payment_method_token'],
                'last_four'  => $pm->card->last4,
                'card_brand' => $pm->card->brand,
                'expires_at' => sprintf('%04d-%02d-01', $pm->card->exp_year, $pm->card->exp_month),
                'is_default' => $makeDefault || ! $hasExisting,
                'is_active'  => true,
            ]);
        });

        return response()->json(['payment_method' => $method], 201);
    }

    /** DELETE /payment-methods/{id} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $method = PaymentMethod::where('id', $id)->where('user_id', $this->authUserId($request))->first();

        if (! $method) {
            return response()->json(['message' => 'Payment method not found.', 'error' => 'not_found'], 404);
        }

        $method->update(['is_active' => false, 'is_default' => false]);

        return response()->json(['message' => 'Payment method removed.']);
    }

    /** PATCH /payment-methods/{id}/default */
    public function setDefault(Request $request, string $id): JsonResponse
    {
        $userId = $this->authUserId($request);
        $method = PaymentMethod::where('id', $id)->where('user_id', $userId)->where('is_active', true)->first();

        if (! $method) {
            return response()->json(['message' => 'Payment method not found.', 'error' => 'not_found'], 404);
        }

        DB::transaction(function () use ($userId, $method) {
            PaymentMethod::where('user_id', $userId)->update(['is_default' => false]);
            $method->update(['is_default' => true]);
        });

        return response()->json(['payment_method' => $method->fresh()]);
    }
}
