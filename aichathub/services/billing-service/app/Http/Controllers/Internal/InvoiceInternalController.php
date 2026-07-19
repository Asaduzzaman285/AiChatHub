<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InvoiceInternalController extends Controller
{
    /**
     * POST /internal/invoices/create
     * Called by subscription-service after a purchase/upgrade/renewal completes.
     * Phase 1 has no real payment gateway yet, so invoices are recorded as
     * already paid at creation time (transaction_id is a synthetic id from
     * the caller, not a real Stripe charge id).
     */
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'         => 'required|uuid',
            'subscription_id' => 'nullable|uuid',
            'description'     => 'nullable|string',
            'amount'          => 'required|numeric|min:0',
            'currency'        => 'nullable|string|size:3',
            'transaction_id'  => 'nullable|uuid',
            'type'            => 'nullable|string',
        ]);

        $amount = (float) $data['amount'];

        $invoice = Invoice::create([
            'user_id'         => $data['user_id'],
            'subscription_id' => $data['subscription_id'] ?? null,
            'invoice_number'  => 'INV-'.now()->format('Ymd').'-'.strtoupper(Str::random(8)),
            'type'            => $data['type'] ?? 'subscription_purchase',
            'amount'          => $amount,
            'currency'        => $data['currency'] ?? 'USD',
            'tax_amount'      => 0,
            'total_amount'    => $amount,
            'status'          => 'paid',
            'transaction_id'  => $data['transaction_id'] ?? null,
            'issued_at'       => now(),
            'paid_at'         => now(),
        ]);

        return response()->json([
            'invoice_id'     => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
        ], 201);
    }
}
