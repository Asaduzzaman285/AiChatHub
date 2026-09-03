'use client'

import { useState, type FormEvent } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Skeleton, SkeletonTableRows } from '@/components/ui/Skeleton'
import { PaymentMethodsView } from '@/components/settings/PaymentMethodsView'
import { AutoDebitView } from '@/components/settings/AutoDebitView'
import apiClient from '@/lib/api-client'
import { formatPreciseCurrency, formatDate } from '@/lib/utils'
import { describeError } from '@/lib/errors'
import type { LedgerEntry, WalletBalance } from '@/types'

/** Shared by the Settings modal's Wallet tab — extracted from the old standalone /wallet route,
 * now also hosts saved payment methods and auto-debit settings underneath the ledger. */
export function WalletView() {
  const [amount, setAmount] = useState('10')

  const { data: wallet, isLoading: walletLoading } = useQuery({
    queryKey: ['wallet', 'balance'],
    queryFn: async () => (await apiClient.get<WalletBalance>('/api/v1/wallet')).data,
  })

  const { data: ledger, isLoading: ledgerLoading } = useQuery({
    queryKey: ['wallet', 'ledger'],
    queryFn: async () => (await apiClient.get<{ ledger: LedgerEntry[] }>('/api/v1/wallet/ledger')).data.ledger,
  })

  const topup = useMutation({
    mutationFn: async ({ amountUsd, gateway }: { amountUsd: number; gateway: 'stripe' | 'bkash' }) =>
      apiClient.post<{ checkout_url: string }>('/api/v1/topup', {
        amount: amountUsd,
        currency: 'USD',
        gateway,
      }),
    onSuccess: (res) => {
      // No wallet credit happens here — the gateway's hosted page collects payment, and
      // /billing/checkout-callback verifies + credits once payment actually completes.
      window.location.href = res.data.checkout_url
    },
    onError: (err: unknown) => {
      const { message } = describeError(err, "We didn't hear back from the server in time. Please try again.")
      toast.error(message)
    },
  })

  const handleTopup = (gateway: 'stripe' | 'bkash') => (e: FormEvent) => {
    e.preventDefault()
    const value = parseFloat(amount)
    if (!value || value <= 0) {
      toast.error('Please enter a top-up amount greater than $0.')
      return
    }
    topup.mutate({ amountUsd: value, gateway })
  }

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Balance</CardTitle>
          </CardHeader>
          <CardContent>
            {walletLoading ? (
              <div className="space-y-2">
                <Skeleton className="h-9 w-32" />
                <Skeleton className="h-3.5 w-28" />
                <Skeleton className="h-3.5 w-40" />
              </div>
            ) : wallet ? (
              <div className="space-y-1">
                {/* Always USD, ignoring wallet.currency — every dollar that moves through
                    this wallet is a raw USD-equivalent number with no conversion anywhere
                    in the system. wallet.currency can be a leftover mislabel from before
                    RegisterController.php stopped setting it from preferred_currency
                    (confirmed live: a genuine $10 balance showing as "BDT 10.00" for a
                    user who'd selected BDT at signup) — showing it here would just repeat
                    that bug for every such account until they're individually corrected. */}
                <p className="text-3xl font-bold">{formatPreciseCurrency(wallet.balance)}</p>
                <p className="text-sm text-muted-foreground">balance</p>
                <p className="text-sm text-muted-foreground">
                  {/* Credit buffer is an internal spending-headroom mechanic, deliberately
                      not surfaced to users — this only ever shows the real balance. */}
                  {formatPreciseCurrency(wallet.balance)} available to spend
                  {wallet.reserved_balance > 0 && ` · ${formatPreciseCurrency(wallet.reserved_balance)} reserved`}
                </p>
              </div>
            ) : (
              <p className="text-sm text-muted-foreground">Wallet not found.</p>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Top up</CardTitle>
          </CardHeader>
          <CardContent>
            <form className="space-y-3">
              <div className="flex items-center gap-2">
                <span className="text-sm text-muted-foreground">$</span>
                <input
                  type="number"
                  min="1"
                  step="1"
                  value={amount}
                  onChange={(e) => setAmount(e.target.value)}
                  className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                />
              </div>
              <Button type="submit" className="w-full" disabled={topup.isPending} onClick={handleTopup('stripe')}>
                {topup.isPending ? 'Processing…' : 'Pay with Card (Stripe)'}
              </Button>
              {/* bKash temporarily disabled — commented out, not removed; uncomment to
                  re-enable (same pair in PlansView.tsx's subscribe/upgrade branches). */}
              {/*
              <Button type="submit" variant="outline" className="w-full" disabled={topup.isPending} onClick={handleTopup('bkash')}>
                {topup.isPending ? 'Processing…' : 'Pay with bKash'}
              </Button>
              */}
            </form>
          </CardContent>
        </Card>
      </div>

      <PaymentMethodsView />

      <AutoDebitView />

      <Card>
        <CardHeader>
          <CardTitle>Transaction history</CardTitle>
        </CardHeader>
        <CardContent>
          {ledgerLoading ? (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <tbody><SkeletonTableRows columns={5} /></tbody>
              </table>
            </div>
          ) : !ledger?.length ? (
            <p className="text-sm text-muted-foreground">No wallet activity yet.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-muted-foreground">
                    <th className="py-2 pr-4 font-medium">Date</th>
                    <th className="px-4 py-2 font-medium">Type</th>
                    <th className="px-4 py-2 font-medium">Description</th>
                    <th className="px-4 py-2 font-medium text-right">Amount</th>
                    <th className="py-2 pl-4 font-medium text-right">Balance after</th>
                  </tr>
                </thead>
                <tbody>
                  {ledger.map((entry) => (
                    <tr key={entry.id} className="border-b border-border last:border-0">
                      <td className="py-2.5 pr-4">{formatDate(entry.created_at)}</td>
                      <td className="px-4 py-2.5 capitalize">{entry.type}</td>
                      <td className="px-4 py-2.5 text-muted-foreground">{entry.description}</td>
                      <td className={`px-4 py-2.5 text-right tabular-nums ${entry.type === 'credit' || entry.type === 'refund' ? 'text-green-600' : 'text-destructive'}`}>
                        {entry.type === 'credit' || entry.type === 'refund' ? '+' : '−'}
                        {formatPreciseCurrency(entry.amount)}
                      </td>
                      <td className="py-2.5 pl-4 text-right tabular-nums">{formatPreciseCurrency(entry.balance_after)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
