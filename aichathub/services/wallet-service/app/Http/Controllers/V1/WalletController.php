<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    /** GET /wallet — balance display for the authenticated user */
    public function balance(Request $request): JsonResponse
    {
        $wallet = Wallet::where('user_id', $this->authUserId($request))->first();

        if (! $wallet) {
            return response()->json(['message' => 'Wallet not found.', 'error' => 'wallet_not_found'], 404);
        }

        return response()->json([
            'balance'           => (float) $wallet->balance,
            'reserved_balance'  => (float) $wallet->reserved_balance,
            'available_balance' => $wallet->availableBalance(),
            'currency'          => $wallet->currency,
        ]);
    }

    /** GET /wallet/credit — credit buffer status */
    public function creditStatus(Request $request): JsonResponse
    {
        $wallet = Wallet::where('user_id', $this->authUserId($request))->first();

        if (! $wallet) {
            return response()->json(['message' => 'Wallet not found.', 'error' => 'wallet_not_found'], 404);
        }

        return response()->json([
            'credit_balance'   => (float) $wallet->credit_balance,
            'credit_limit'     => (float) $wallet->credit_limit,
            'remaining_credit' => $wallet->remainingCredit(),
            'in_credit'        => (float) $wallet->credit_balance < 0,
        ]);
    }
}
