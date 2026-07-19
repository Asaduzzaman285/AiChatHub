'use client'

import { useQuery } from '@tanstack/react-query'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import apiClient from '@/lib/api-client'
import { formatCurrency, formatDate } from '@/lib/utils'
import type { Invoice, Receipt, Transaction } from '@/types'

const STATUS_STYLES: Record<string, string> = {
  completed: 'text-green-600',
  paid: 'text-green-600',
  pending: 'text-yellow-600',
  failed: 'text-destructive',
  refunded: 'text-muted-foreground',
}

export default function BillingPage() {
  const { data: transactions, isLoading: txLoading } = useQuery({
    queryKey: ['transactions'],
    queryFn: async () => (await apiClient.get<{ transactions: Transaction[] }>('/api/v1/transactions')).data.transactions,
  })

  const { data: invoices, isLoading: invLoading } = useQuery({
    queryKey: ['invoices'],
    queryFn: async () => (await apiClient.get<{ invoices: Invoice[] }>('/api/v1/invoices')).data.invoices,
  })

  const { data: receipts, isLoading: rcpLoading } = useQuery({
    queryKey: ['receipts'],
    queryFn: async () => (await apiClient.get<{ receipts: Receipt[] }>('/api/v1/receipts')).data.receipts,
  })

  return (
    <div className="max-w-4xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Billing</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Payment transactions, subscription invoices, and top-up receipts.
        </p>
      </div>

      <Card>
        <CardHeader><CardTitle>Transactions</CardTitle></CardHeader>
        <CardContent>
          {txLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !transactions?.length ? (
            <p className="text-sm text-muted-foreground">No transactions yet.</p>
          ) : (
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border text-left text-muted-foreground">
                  <th className="pb-2 font-medium">Date</th>
                  <th className="pb-2 font-medium">Type</th>
                  <th className="pb-2 font-medium">Gateway</th>
                  <th className="pb-2 font-medium text-right">Amount</th>
                  <th className="pb-2 font-medium text-right">Status</th>
                </tr>
              </thead>
              <tbody>
                {transactions.map((t) => (
                  <tr key={t.id} className="border-b border-border last:border-0">
                    <td className="py-2">{formatDate(t.created_at)}</td>
                    <td className="py-2 capitalize">{t.type.replace(/_/g, ' ')}</td>
                    <td className="py-2 capitalize">{t.gateway}</td>
                    <td className="py-2 text-right">{formatCurrency(t.amount, t.currency)}</td>
                    <td className={`py-2 text-right capitalize ${STATUS_STYLES[t.status] ?? ''}`}>
                      {t.status}
                      {t.error_message && (
                        <span className="block text-xs text-muted-foreground" title={t.error_message}>failed</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>Invoices</CardTitle></CardHeader>
        <CardContent>
          {invLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !invoices?.length ? (
            <p className="text-sm text-muted-foreground">No invoices yet.</p>
          ) : (
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border text-left text-muted-foreground">
                  <th className="pb-2 font-medium">Invoice #</th>
                  <th className="pb-2 font-medium">Date</th>
                  <th className="pb-2 font-medium text-right">Total</th>
                  <th className="pb-2 font-medium text-right">Status</th>
                </tr>
              </thead>
              <tbody>
                {invoices.map((inv) => (
                  <tr key={inv.id} className="border-b border-border last:border-0">
                    <td className="py-2 font-mono text-xs">{inv.invoice_number}</td>
                    <td className="py-2">{formatDate(inv.issued_at)}</td>
                    <td className="py-2 text-right">{formatCurrency(inv.total_amount, inv.currency)}</td>
                    <td className={`py-2 text-right capitalize ${STATUS_STYLES[inv.status] ?? ''}`}>{inv.status}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>Receipts</CardTitle></CardHeader>
        <CardContent>
          {rcpLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !receipts?.length ? (
            <p className="text-sm text-muted-foreground">No receipts yet.</p>
          ) : (
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border text-left text-muted-foreground">
                  <th className="pb-2 font-medium">Receipt #</th>
                  <th className="pb-2 font-medium">Date</th>
                  <th className="pb-2 font-medium">Type</th>
                  <th className="pb-2 font-medium text-right">Amount</th>
                </tr>
              </thead>
              <tbody>
                {receipts.map((r) => (
                  <tr key={r.id} className="border-b border-border last:border-0">
                    <td className="py-2 font-mono text-xs">{r.receipt_number}</td>
                    <td className="py-2">{formatDate(r.issued_at)}</td>
                    <td className="py-2 capitalize">{r.type.replace(/_/g, ' ')}</td>
                    <td className="py-2 text-right">{formatCurrency(r.amount, r.currency)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
