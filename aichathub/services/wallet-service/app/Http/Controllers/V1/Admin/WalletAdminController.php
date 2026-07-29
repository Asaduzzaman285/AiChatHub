<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\AuditLogClient;
use App\Services\WalletService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WalletAdminController extends Controller
{
    public function __construct(private WalletService $wallets) {}

    /** GET /admin/wallet/ledger — filters on columns that actually exist on wallet_ledger_entries. */
    public function ledger(Request $request): JsonResponse
    {
        $query = WalletLedgerEntry::query();

        $query
            ->when($request->filled('user_id'), fn (Builder $q) => $q->where('user_id', $request->query('user_id')))
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->query('type')))
            ->when($request->filled('reference_type'), fn (Builder $q) => $q->where('reference_type', $request->query('reference_type')))
            ->when($request->filled('reference_id'), fn (Builder $q) => $q->where('reference_id', $request->query('reference_id')))
            ->when($request->filled('min_amount'), fn (Builder $q) => $q->where('amount', '>=', $request->query('min_amount')))
            ->when($request->filled('max_amount'), fn (Builder $q) => $q->where('amount', '<=', $request->query('max_amount')))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('created_at', '>=', $request->query('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('created_at', '<=', $request->query('to')))
            ->orderByDesc('created_at');

        $perPage = min((int) $request->query('per_page', 20), 100);
        $entries = $query->paginate($perPage);

        return response()->json([
            'ledger_entries' => $entries->items(),
            'meta'           => [
                'current_page' => $entries->currentPage(),
                'last_page'    => $entries->lastPage(),
                'total'        => $entries->total(),
            ],
        ]);
    }

    /**
     * POST /admin/wallet/{userId}/adjust — manual credit/debit. Uses the exact
     * same WalletService::credit()/deduct() every other wallet-affecting flow
     * uses, tagged type=admin_adjustment (a documented-but-previously-unused
     * value in wallet_ledger_entries.type) so these are distinguishable from
     * regular credits/debits in the ledger.
     */
    public function adjust(Request $request, string $userId): JsonResponse
    {
        $data = $request->validate([
            'direction'   => 'required|in:credit,debit',
            'amount'      => 'required|numeric|min:0.01',
            'description' => 'required|string|max:255',
        ]);

        $referenceId = (string) Str::uuid();
        $description = 'Admin adjustment: '.$data['description'];

        if ($data['direction'] === 'credit') {
            $wallet = $this->wallets->credit($userId, (float) $data['amount'], $description, 'admin_adjustment', $referenceId, null, 'admin_adjustment');
        } else {
            $this->wallets->deduct($userId, (float) $data['amount'], 0, $description, 'admin_adjustment', $referenceId, 'admin_adjustment');
            $wallet = Wallet::where('user_id', $userId)->firstOrFail();
        }

        app(AuditLogClient::class)->log(
            $this->adminId($request), 'wallet.adjusted', 'wallet', $wallet->id,
            null, ['direction' => $data['direction'], 'amount' => $data['amount'], 'description' => $data['description']],
            $request->ip(), $request->userAgent(),
        );

        return response()->json([
            'wallet_id'      => $wallet->id,
            'balance'        => (float) $wallet->balance,
            'credit_balance' => (float) $wallet->credit_balance,
        ]);
    }
}
