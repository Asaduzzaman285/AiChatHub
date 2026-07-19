<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    /** GET /invoices — paginated invoice history for the authenticated user */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $invoices = Invoice::where('user_id', $this->authUserId($request))
            ->orderByDesc('issued_at')
            ->paginate($perPage);

        return response()->json([
            'invoices' => $invoices->items(),
            'meta'     => [
                'current_page' => $invoices->currentPage(),
                'last_page'    => $invoices->lastPage(),
                'total'        => $invoices->total(),
            ],
        ]);
    }

    /** GET /invoices/{id} */
    public function show(Request $request, string $id): JsonResponse
    {
        $invoice = Invoice::where('id', $id)
            ->where('user_id', $this->authUserId($request))
            ->first();

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.', 'error' => 'invoice_not_found'], 404);
        }

        return response()->json(['invoice' => $invoice]);
    }

    /** GET /invoices/{id}/download — PDF generation not yet implemented (Phase 1 backlog) */
    public function download(Request $request, string $id): JsonResponse
    {
        return response()->json(['message' => 'Invoice PDF generation is not yet available.', 'error' => 'not_implemented'], 501);
    }
}
