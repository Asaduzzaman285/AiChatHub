<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /** GET /transactions */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $transactions = Transaction::where('user_id', $this->authUserId($request))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'transactions' => $transactions->items(),
            'meta'         => [
                'current_page' => $transactions->currentPage(),
                'last_page'    => $transactions->lastPage(),
                'total'        => $transactions->total(),
            ],
        ]);
    }

    /** GET /transactions/{id} */
    public function show(Request $request, string $id): JsonResponse
    {
        $transaction = Transaction::where('id', $id)->where('user_id', $this->authUserId($request))->first();

        if (! $transaction) {
            return response()->json(['message' => 'Transaction not found.', 'error' => 'not_found'], 404);
        }

        return response()->json(['transaction' => $transaction]);
    }
}
