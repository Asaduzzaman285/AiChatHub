<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletInternalController extends Controller
{
    public function __construct(private WalletService $walletService) {}

    /** POST /internal/wallet/create — called by auth-service on user registration */
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'  => 'required|string|min:36|max:36',
            'currency' => 'nullable|string|size:3',
        ]);

        $wallet = $this->walletService->createForUser(
            $data['user_id'],
            $data['currency'] ?? 'USD'
        );

        return response()->json([
            'wallet_id' => $wallet->id,
            'user_id'   => $wallet->user_id,
            'balance'   => (float) $wallet->balance,
            'currency'  => $wallet->currency,
            'created'   => $wallet->wasRecentlyCreated,
        ], 201);
    }

    /** POST /internal/wallet/credit — top-up or subscription credit */
    public function credit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'        => 'required|string|min:36|max:36',
            'amount'         => 'required|numeric|min:0.000001',
            'description'    => 'required|string',
            'reference_type' => 'nullable|string',
            'reference_id'   => 'nullable|string',
        ]);

        $wallet = $this->walletService->credit(
            $data['user_id'],
            (float) $data['amount'],
            $data['description'],
            $data['reference_type'] ?? null,
            $data['reference_id'] ?? null,
        );

        return response()->json([
            'success' => true,
            'balance' => (float) $wallet->balance,
        ]);
    }

    /** POST /internal/wallet/reserve — pre-flight for AI request */
    public function reserve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|uuid',
            'amount'  => 'required|numeric|min:0.000001',
        ]);

        $success = $this->walletService->reserve($data['user_id'], (float) $data['amount']);

        if (! $success) {
            return response()->json(['success' => false, 'reason' => 'insufficient_balance'], 422);
        }

        $wallet = Wallet::where('user_id', $data['user_id'])->first();

        return response()->json([
            'success'           => true,
            'available_balance' => $wallet?->availableBalance(),
        ]);
    }

    /** POST /internal/wallet/deduct — actual cost after AI completes */
    public function deduct(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'        => 'required|uuid',
            'amount'         => 'required|numeric|min:0',
            'reserved_amount'=> 'required|numeric|min:0',
            'description'    => 'required|string',
            'reference_type' => 'nullable|string',
            'reference_id'   => 'nullable|uuid',
        ]);

        $this->walletService->deduct(
            $data['user_id'],
            (float) $data['amount'],
            (float) $data['reserved_amount'],
            $data['description'],
            $data['reference_type'] ?? null,
            $data['reference_id'] ?? null,
        );

        return response()->json(['success' => true]);
    }

    /** POST /internal/wallet/refund — failed AI request */
    public function refund(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'        => 'required|uuid',
            'amount'         => 'required|numeric|min:0',
            'reserved_amount'=> 'required|numeric|min:0',
            'reason'         => 'required|string',
            'reference_id'   => 'nullable|uuid',
        ]);

        $this->walletService->refund(
            $data['user_id'],
            (float) $data['amount'],
            (float) $data['reserved_amount'],
            $data['reason'],
            $data['reference_id'] ?? null,
        );

        return response()->json(['success' => true]);
    }

    /** GET /internal/wallet/{userId} — balance check for other services */
    public function show(string $userId): JsonResponse
    {
        $wallet = Wallet::where('user_id', $userId)->firstOrFail();

        return response()->json([
            'balance'           => (float) $wallet->balance,
            'reserved_balance'  => (float) $wallet->reserved_balance,
            'credit_balance'    => (float) $wallet->credit_balance,
            'credit_limit'      => (float) $wallet->credit_limit,
            'available_balance' => $wallet->availableBalance(),
            'remaining_credit'  => $wallet->remainingCredit(),
            'currency'          => $wallet->currency,
        ]);
    }
}
