<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\WalletLedgerEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LedgerController extends Controller
{
    /** GET /wallet/ledger — paginated transaction history for the authenticated user */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $entries = WalletLedgerEntry::where('user_id', $this->authUserId($request))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'ledger' => $entries->items(),
            'meta'   => [
                'current_page' => $entries->currentPage(),
                'last_page'    => $entries->lastPage(),
                'total'        => $entries->total(),
            ],
        ]);
    }
}
