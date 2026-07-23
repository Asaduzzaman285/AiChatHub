'use client'

import { useState, type FormEvent } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import apiClient from '@/lib/api-client'
import { formatCurrency, formatDate } from '@/lib/utils'
import { describeError } from '@/lib/errors'
import type { LedgerEntry, WalletBalance } from '@/types'

export default function WalletPage() {
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
      toast.error('Enter a valid amount.')
      return
    }
    topup.mutate({ amountUsd: value, gateway })
  }

  return (
    <div className="max-w-3xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Wallet</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Top up via Stripe (test mode) or bKash (sandbox) — you&apos;ll be redirected to the gateway&apos;s hosted page, and your balance updates once payment is confirmed.
        </p>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Balance</CardTitle>
          </CardHeader>
          <CardContent>
            {walletLoading ? (
              <p className="text-sm text-muted-foreground">Loading…</p>
            ) : wallet ? (
              <div className="space-y-1">
                <p className="text-3xl font-bold">{formatCurrency(wallet.available_balance, wallet.currency)}</p>
                <p className="text-sm text-muted-foreground">available to spend</p>
                <p className="text-sm text-muted-foreground">
                  {formatCurrency(wallet.balance, wallet.currency)} balance
                  {wallet.reserved_balance > 0 && ` · ${formatCurrency(wallet.reserved_balance, wallet.currency)} reserved`}
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
              <Button type="submit" variant="outline" className="w-full" disabled={topup.isPending} onClick={handleTopup('bkash')}>
                {topup.isPending ? 'Processing…' : 'Pay with bKash'}
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Transaction history</CardTitle>
        </CardHeader>
        <CardContent>
          {ledgerLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !ledger?.length ? (
            <p className="text-sm text-muted-foreground">No wallet activity yet.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-muted-foreground">
                    <th className="pb-2 font-medium">Date</th>
                    <th className="pb-2 font-medium">Type</th>
                    <th className="pb-2 font-medium">Description</th>
                    <th className="pb-2 font-medium text-right">Amount</th>
                    <th className="pb-2 font-medium text-right">Balance after</th>
                  </tr>
                </thead>
                <tbody>
                  {ledger.map((entry) => (
                    <tr key={entry.id} className="border-b border-border last:border-0">
                      <td className="py-2">{formatDate(entry.created_at)}</td>
                      <td className="py-2 capitalize">{entry.type}</td>
                      <td className="py-2 text-muted-foreground">{entry.description}</td>
                      <td className={`py-2 text-right ${entry.type === 'credit' || entry.type === 'refund' ? 'text-green-600' : 'text-destructive'}`}>
                        {entry.type === 'credit' || entry.type === 'refund' ? '+' : '−'}
                        {formatCurrency(entry.amount, entry.currency)}
                      </td>
                      <td className="py-2 text-right">{formatCurrency(entry.balance_after, entry.currency)}</td>
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
