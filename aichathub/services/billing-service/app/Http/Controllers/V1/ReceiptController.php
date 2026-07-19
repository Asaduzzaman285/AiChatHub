<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Receipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReceiptController extends Controller
{
    /** GET /receipts */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $receipts = Receipt::where('user_id', $this->authUserId($request))
            ->orderByDesc('issued_at')
            ->paginate($perPage);

        return response()->json([
            'receipts' => $receipts->items(),
            'meta'     => [
                'current_page' => $receipts->currentPage(),
                'last_page'    => $receipts->lastPage(),
                'total'        => $receipts->total(),
            ],
        ]);
    }

    /** GET /receipts/{id} */
    public function show(Request $request, string $id): JsonResponse
    {
        $receipt = Receipt::where('id', $id)
            ->where('user_id', $this->authUserId($request))
            ->first();

        if (! $receipt) {
            return response()->json(['message' => 'Receipt not found.', 'error' => 'receipt_not_found'], 404);
        }

        return response()->json(['receipt' => $receipt]);
    }
}
