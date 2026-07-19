<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Receipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReceiptInternalController extends Controller
{
    /**
     * POST /internal/receipts/create
     * Called by payment-service after a wallet top-up (or refund) completes.
     */
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'        => 'required|uuid',
            'type'           => 'required|string',
            'amount'         => 'required|numeric|min:0',
            'currency'       => 'nullable|string|size:3',
            'transaction_id' => 'nullable|uuid',
        ]);

        $receipt = Receipt::create([
            'user_id'        => $data['user_id'],
            'receipt_number' => 'RCT-'.now()->format('Ymd').'-'.strtoupper(Str::random(8)),
            'type'           => $data['type'],
            'amount'         => (float) $data['amount'],
            'currency'       => $data['currency'] ?? 'USD',
            'transaction_id' => $data['transaction_id'] ?? null,
            'issued_at'      => now(),
        ]);

        return response()->json([
            'receipt_id'     => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
        ], 201);
    }
}
