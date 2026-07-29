<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /** GET /admin/dashboard — wallet-service's own contribution to the admin dashboard. */
    public function index(Request $request): JsonResponse
    {
        $since = now()->subDays(30);

        return response()->json([
            'total_balance'        => (float) Wallet::sum('balance'),
            'total_credit_owed'    => (float) Wallet::where('credit_balance', '<', 0)->sum('credit_balance'),
            'deposits_30d'         => (float) WalletLedgerEntry::where('type', 'credit')->where('created_at', '>=', $since)->sum('amount'),
            'withdrawals_30d'      => (float) WalletLedgerEntry::where('type', 'debit')->where('created_at', '>=', $since)->sum('amount'),
            'admin_adjustments_30d'=> WalletLedgerEntry::where('type', 'admin_adjustment')->where('created_at', '>=', $since)->count(),
        ]);
    }
}
