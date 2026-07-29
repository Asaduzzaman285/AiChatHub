<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\AuditLogClient;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionAdminController extends Controller
{
    public function __construct(private RefundService $refunds) {}

    /**
     * POST /admin/transactions/{id}/refund — Finance Administrator's "process
     * refunds" responsibility. Thin wrapper around the same RefundService the
     * internal subscription/topup-reversal path already uses.
     */
    public function refund(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['amount' => 'nullable|numeric|min:0.01']);

        $transaction = Transaction::find($id);
        if (! $transaction) {
            return response()->json(['message' => 'Transaction not found.', 'error' => 'not_found'], 404);
        }

        $old    = $transaction->toArray();
        $result = $this->refunds->refund($transaction, isset($data['amount']) ? (float) $data['amount'] : null);

        if (! $result['success']) {
            return response()->json(['message' => 'Refund failed.', 'error' => $result['error']], 422);
        }

        app(AuditLogClient::class)->log(
            $this->adminId($request), 'transaction.refunded', 'transaction', $transaction->id,
            $old, $transaction->fresh()->toArray(), $request->ip(), $request->userAgent(),
        );

        return response()->json([
            'transaction_id' => $transaction->id,
            'status'         => 'refunded',
            'refund_id'      => $result['refund_id'],
        ]);
    }
}
