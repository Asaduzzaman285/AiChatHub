<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /** GET /admin/dashboard — payment-service's own contribution to the admin dashboard. */
    public function index(Request $request): JsonResponse
    {
        $gatewayBreakdown = Transaction::query()
            ->where('status', 'completed')
            ->selectRaw('gateway, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('gateway')
            ->get();

        return response()->json([
            'total_revenue'      => (float) Transaction::where('status', 'completed')->where('type', '!=', 'refund')->sum('amount'),
            'completed_count'    => Transaction::where('status', 'completed')->count(),
            'failed_count'       => Transaction::where('status', 'failed')->count(),
            'pending_count'      => Transaction::where('status', 'pending')->count(),
            'refunded_count'     => Transaction::where('status', 'refunded')->count(),
            'gateway_breakdown'  => $gatewayBreakdown,
        ]);
    }
}
