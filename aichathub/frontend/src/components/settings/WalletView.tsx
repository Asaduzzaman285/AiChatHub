'use client'

import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Skeleton, SkeletonTableRows } from '@/components/ui/Skeleton'
import { PaymentMethodsView } from '@/components/settings/PaymentMethodsView'
import { AutoDebitView } from '@/components/settings/AutoDebitView'
import apiClient from '@/lib/api-client'
import { formatDate } from '@/lib/utils'
import { describeError } from '@/lib/errors'
import { useDisplayCurrency } from '@/hooks/useDisplayCurrency'
import type { LedgerEntry, WalletBalance } from '@/types'

/** Shared by the Settings modal's Wallet tab — extracted from the old standalone /wallet route,
 * now also hosts saved payment methods and auto-debit settings underneath the ledger. */
export function WalletView() {
  const [amount, setAmount] = useState('10')
  const amountTouched = useRef(false)
  const { formatPrecise, format, symbol, currency, convert, toUsd } = useDisplayCurrency()

  // "10" as a default only makes sense in USD — once the field switches to
  // showing BDT (or any non-USD currency), that same "10" would mean 10 BDT,
  // converting to a few cents and tripping the backend's real $1 USD minimum
  // with a confusing raw-USD error message. Re-seed it as a round number in
  // whatever currency is actually showing, once the rate has loaded — but
  // never overwrite an amount the visitor already typed themselves.
  useEffect(() => {
    if (!amountTouched.current && currency !== 'USD') {
      setAmount(String(Math.round(convert(10))))
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [currency])

  const { data: wallet, isLoading: walletLoading } = useQuery({
    queryKey: ['wallet', 'balance'],
    queryFn: async () => (await apiClient.get<WalletBalance>('/api/v1/wallet')).data,
  })

  const { data: ledger, isLoading: ledgerLoading } = useQuery({
    queryKey: ['wallet', 'ledger'],
    queryFn: async () => (await apiClient.get<{ ledger: LedgerEntry[] }>('/api/v1/wallet/ledger')).data.ledger,
  })

  // Which gateway button was actually clicked — only that one shows "Processing…";
  // the other just disables instead of both switching to loading text together.
  // Confirmed live: clicking either button previously put both into "Processing…"
  // even though only one checkout was actually starting.
  const [pendingGateway, setPendingGateway] = useState<'stripe' | 'bkash' | null>(null)

  const topup = useMutation({
    mutationFn: async ({ amountUsd, gateway }: { amountUsd: number; gateway: 'stripe' | 'bkash' }) => {
      setPendingGateway(gateway)
      return apiClient.post<{ checkout_url: string }>('/api/v1/topup', {
        amount: amountUsd,
        currency: 'USD',
        gateway,
      })
    },
    onSuccess: (res) => {
      // No wallet credit happens here — the gateway's hosted page collects payment, and
      // /billing/checkout-callback verifies + credits once payment actually completes.
      window.location.href = res.data.checkout_url
    },
    onError: (err: unknown) => {
      const { message } = describeError(err, "We didn't hear back from the server in time. Please try again.")
      toast.error(message)
    },
    onSettled: () => setPendingGateway(null),
  })

  const handleTopup = (gateway: 'stripe' | 'bkash') => (e: FormEvent) => {
    e.preventDefault()
    const value = parseFloat(amount)
    if (!value || value <= 0) {
      toast.error('Enter an amount.')
      return
    }
    // Card's floor is a real $1 USD figure, checkable here via toUsd() same as
    // before. bKash's floor is a native ৳20 BDT figure instead (see
    // TopupController's own validation) — not a USD amount converted through
    // the currency policy, so it can't be checked correctly from this hook
    // alone (it only ever loads a rate for the viewer's own preferred
    // currency, not necessarily BDT). Left to the backend's own error message,
    // which always has the live rate regardless of the viewer's currency.
    if (gateway === 'stripe' && toUsd(value) < 1) {
      toast.error(`Minimum top-up is ${format(1)} when paying by card.`)
      return
    }
    // The field itself is in the viewer's preferred currency (see the input's
    // own prefix below) — the backend always wants real USD, same rule as
    // everywhere else this hook is used.
    topup.mutate({ amountUsd: toUsd(value), gateway })
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
                {/* wallet.balance is always real USD in storage — every dollar that moves
                    through this wallet is a raw USD-equivalent number with no conversion
                    anywhere in the ledger (wallet.currency itself is ignored, not used
                    here — it can be a leftover mislabel from before RegisterController.php
                    stopped setting it from preferred_currency, confirmed live as a genuine
                    $10 balance literally relabeled "BDT 10.00" with no real conversion).
                    useDisplayCurrency() converts using the live admin-configured rate for
                    display only, the correct fix for that old bug rather than repeating it. */}
                <p className="text-3xl font-bold">{formatPrecise(wallet.balance)}</p>
                <p className="text-sm text-muted-foreground">balance</p>
                <p className="text-sm text-muted-foreground">
                  {/* Credit buffer is an internal spending-headroom mechanic, deliberately
                      not surfaced to users — this only ever shows the real balance. */}
                  {formatPrecise(wallet.balance)} available to spend
                  {wallet.reserved_balance > 0 && ` · ${formatPrecise(wallet.reserved_balance)} reserved`}
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
                <span className="text-sm text-muted-foreground">{symbol}</span>
                <input
                  type="number"
                  // A soft hint only (real gating happens in handleTopup() + the
                  // backend) — set to the lower of the two gateways' actual
                  // floors (bKash's native ৳20, card's $1) converted into
                  // whatever currency is showing, so it never blocks a
                  // legitimately small bKash amount the way a $1-derived floor
                  // (~৳202) used to.
                  min={currency === 'BDT' ? 20 : 1}
                  step="1"
                  value={amount}
                  onChange={(e) => {
                    amountTouched.current = true
                    setAmount(e.target.value)
                  }}
                  className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                />
              </div>
              <Button type="submit" className="w-full" disabled={topup.isPending} onClick={handleTopup('stripe')}>
                {topup.isPending && pendingGateway === 'stripe'
                  ? 'Processing…'
                  : `Pay with Card (Stripe)${currency !== 'USD' ? ' — charged in USD' : ''}`}
              </Button>
              <Button type="submit" variant="outline" className="w-full" disabled={topup.isPending} onClick={handleTopup('bkash')}>
                {topup.isPending && pendingGateway === 'bkash'
                  ? 'Processing…'
                  : `Pay with bKash${currency !== 'USD' ? ' — charged in BDT' : ''}`}
              </Button>
              <p className="text-xs text-muted-foreground">
                Minimum $1.00 by card, ৳20 by bKash.
                {currency !== 'USD' && ' Entered in ' + currency + ', converted internally at the current admin-configured rate — Card always charges USD, bKash always charges BDT, regardless of this amount’s currency.'}
              </p>
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
                        {formatPrecise(entry.amount)}
                      </td>
                      <td className="py-2.5 pl-4 text-right tabular-nums">{formatPrecise(entry.balance_after)}</td>
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
