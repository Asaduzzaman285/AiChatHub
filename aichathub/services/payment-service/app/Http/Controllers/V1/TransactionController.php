<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /** GET /transactions — the authenticated user's own transactions, filterable. */
    public function index(Request $request): JsonResponse
    {
        $query = Transaction::where('user_id', $this->authUserId($request));
        $this->applyFilters($query, $request);

        return $this->paginatedResponse($query, $request);
    }

    /**
     * GET /transactions/admin — admin-only (admin.gate). Same filters as index(),
     * plus an optional user_id to narrow to one user; without it, spans everyone.
     * Registered before GET /transactions/{id} in routes/api.php so "admin"
     * isn't swallowed by the {id} wildcard.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $query = Transaction::query();
        $query->when($request->filled('user_id'), fn (Builder $q) => $q->where('user_id', $request->query('user_id')));
        $this->applyFilters($query, $request);

        return $this->paginatedResponse($query, $request);
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

    /** Shared by index()/adminIndex() — status/type/gateway/date-range, none of which existed before. */
    private function applyFilters(Builder $query, Request $request): void
    {
        $query
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->query('status')))
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->query('type')))
            ->when($request->filled('gateway'), fn (Builder $q) => $q->where('gateway', $request->query('gateway')))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('created_at', '>=', $request->query('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('created_at', '<=', $request->query('to')))
            ->orderByDesc('created_at');
    }

    private function paginatedResponse(Builder $query, Request $request): JsonResponse
    {
        $perPage      = min((int) $request->query('per_page', 20), 100);
        $transactions = $query->paginate($perPage);

        return response()->json([
            'transactions' => $transactions->items(),
            'meta'         => [
                'current_page' => $transactions->currentPage(),
                'last_page'    => $transactions->lastPage(),
                'total'        => $transactions->total(),
            ],
        ]);
    }
}
