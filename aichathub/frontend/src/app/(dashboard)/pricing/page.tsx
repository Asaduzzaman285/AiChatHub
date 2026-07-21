'use client'

import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import apiClient from '@/lib/api-client'
import { formatCurrency, formatDate } from '@/lib/utils'
import { describeError } from '@/lib/errors'
import type { Package, Subscription, WalletBalance } from '@/types'

export default function PricingPage() {
  const queryClient = useQueryClient()
  const [pendingSlug, setPendingSlug] = useState<string | null>(null)
  // Which package the payment-source picker is currently open for — null means
  // no picker is showing (either nothing clicked yet, or a single-option package).
  const [choosingSlug, setChoosingSlug] = useState<string | null>(null)

  const { data: packages, isLoading } = useQuery({
    queryKey: ['packages'],
    queryFn: async () => (await apiClient.get<{ packages: Package[] }>('/api/v1/packages')).data.packages,
  })

  const { data: subscription } = useQuery({
    queryKey: ['subscription', 'current'],
    queryFn: async () => (await apiClient.get<{ subscription: Subscription | null }>('/api/v1/subscription')).data.subscription,
  })

  const { data: wallet } = useQuery({
    queryKey: ['wallet', 'balance'],
    queryFn: async () => (await apiClient.get<WalletBalance>('/api/v1/wallet')).data,
  })

  const subscribe = useMutation({
    mutationFn: async ({ slug, source }: { slug: string; source: 'wallet' | 'card' }) => {
      setPendingSlug(slug)
      return apiClient.post<{ checkout_url?: string }>('/api/v1/subscription/subscribe', {
        package_slug: slug,
        payment_source: source,
        currency: 'USD',
      })
    },
    onSuccess: (res) => {
      if (res.data.checkout_url) {
        // Card path — nothing is activated yet, /billing/checkout-callback verifies
        // the payment and activates the package once Stripe confirms it.
        window.location.href = res.data.checkout_url
        return
      }
      toast.success('Subscribed! Your wallet has been credited.')
      setChoosingSlug(null)
      queryClient.invalidateQueries({ queryKey: ['subscription'] })
      queryClient.invalidateQueries({ queryKey: ['wallet'] })
    },
    onError: (err: unknown) => {
      const { ambiguous, message } = describeError(
        err,
        "We didn't hear back in time — check your current plan below before subscribing again."
      )
      toast.error(message)
      if (ambiguous) {
        setChoosingSlug(null)
        queryClient.invalidateQueries({ queryKey: ['subscription'] })
        queryClient.invalidateQueries({ queryKey: ['wallet'] })
      }
    },
    onSettled: () => setPendingSlug(null),
  })

  const changePlan = useMutation({
    mutationFn: async ({ slug, direction }: { slug: string; direction: 'upgrade' | 'downgrade' }) => {
      setPendingSlug(slug)
      return apiClient.post(`/api/v1/subscription/${direction}`, { package_slug: slug })
    },
    onSuccess: (_res, variables) => {
      toast.success(variables.direction === 'upgrade' ? 'Upgraded successfully.' : 'Downgraded successfully.')
      queryClient.invalidateQueries({ queryKey: ['subscription'] })
      queryClient.invalidateQueries({ queryKey: ['wallet'] })
    },
    onError: (err: unknown) => {
      const { ambiguous, message } = describeError(
        err,
        "We didn't hear back in time — check your current plan below before trying again."
      )
      toast.error(message)
      if (ambiguous) {
        queryClient.invalidateQueries({ queryKey: ['subscription'] })
        queryClient.invalidateQueries({ queryKey: ['wallet'] })
      }
    },
    onSettled: () => setPendingSlug(null),
  })

  const cancelSubscription = useMutation({
    mutationFn: async () => apiClient.post<{ access_until: string }>('/api/v1/subscription/cancel', {}),
    onSuccess: (res) => {
      toast.success(`Your plan will end on ${formatDate(res.data.access_until)}. You'll keep access until then.`)
      queryClient.invalidateQueries({ queryKey: ['subscription'] })
    },
    onError: (err: unknown) => toast.error(describeError(err, "We didn't hear back in time — check your plan status before trying again.").message),
  })

  return (
    <div className="max-w-4xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Pricing</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Pay from your wallet balance if you have enough, or with a card via Stripe&apos;s hosted checkout
          (test mode — no real money moves).
        </p>
      </div>

      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading plans…</p>
      ) : (
        <div className="grid gap-4 sm:grid-cols-3">
          {packages?.map((pkg) => {
            const isCurrent = subscription?.package?.slug === pkg.slug
            const currentPrice = subscription?.package?.monthly_price_usd
            const isUpgrade = currentPrice !== undefined && pkg.price.usd > currentPrice
            const isPending = (subscribe.isPending || changePlan.isPending) && pendingSlug === pkg.slug
            const canUseWallet = (wallet?.available_balance ?? 0) >= pkg.price.usd
            const isChoosing = choosingSlug === pkg.slug

            return (
              <Card key={pkg.id} className={isCurrent ? 'border-primary' : ''}>
                <CardHeader>
                  <CardTitle>{pkg.name}</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                  <div>
                    <span className="text-3xl font-bold">{formatCurrency(pkg.price.usd)}</span>
                    <span className="text-sm text-muted-foreground">/mo</span>
                  </div>
                  <p className="text-sm text-muted-foreground">{pkg.description}</p>
                  <p className="text-sm">
                    Includes <strong>{formatCurrency(pkg.wallet_credit_usd)}</strong> monthly wallet credit
                  </p>
                  {pkg.features.vision && (
                    <p className="text-xs text-muted-foreground">Includes image/file upload &amp; vision models</p>
                  )}

                  {isCurrent ? (
                    <Button className="w-full" variant="outline" disabled>
                      Current plan
                    </Button>
                  ) : !subscription ? (
                    isChoosing ? (
                      <div className="space-y-2">
                        <Button
                          className="w-full"
                          disabled={isPending}
                          onClick={() => subscribe.mutate({ slug: pkg.slug, source: 'wallet' })}
                        >
                          {isPending ? 'Subscribing…' : `Use Wallet Balance (${formatCurrency(wallet?.available_balance ?? 0)} Available)`}
                        </Button>
                        <Button
                          className="w-full"
                          variant="outline"
                          disabled={isPending}
                          onClick={() => subscribe.mutate({ slug: pkg.slug, source: 'card' })}
                        >
                          {isPending ? 'Subscribing…' : 'Pay with Card'}
                        </Button>
                        <button
                          type="button"
                          className="w-full text-xs text-muted-foreground hover:text-foreground"
                          disabled={isPending}
                          onClick={() => setChoosingSlug(null)}
                        >
                          Cancel
                        </button>
                      </div>
                    ) : (
                      <Button
                        className="w-full"
                        disabled={isPending}
                        onClick={() =>
                          canUseWallet ? setChoosingSlug(pkg.slug) : subscribe.mutate({ slug: pkg.slug, source: 'card' })
                        }
                      >
                        {isPending ? 'Subscribing…' : 'Subscribe'}
                      </Button>
                    )
                  ) : (
                    <Button
                      className="w-full"
                      variant={isUpgrade ? 'primary' : 'outline'}
                      disabled={isPending}
                      onClick={() => changePlan.mutate({ slug: pkg.slug, direction: isUpgrade ? 'upgrade' : 'downgrade' })}
                    >
                      {isPending ? (isUpgrade ? 'Upgrading…' : 'Downgrading…') : isUpgrade ? 'Upgrade' : 'Downgrade'}
                    </Button>
                  )}
                </CardContent>
              </Card>
            )
          })}
        </div>
      )}

      {subscription && !subscription.cancelled_at && (
        <div className="flex items-center justify-between rounded-md border border-border p-4">
          <div>
            <p className="text-sm font-medium">Cancel subscription</p>
            <p className="text-xs text-muted-foreground">
              You&apos;ll keep access until the end of your current billing cycle ({formatDate(subscription.renews_at)}).
            </p>
          </div>
          <Button variant="destructive" disabled={cancelSubscription.isPending} onClick={() => cancelSubscription.mutate()}>
            {cancelSubscription.isPending ? 'Cancelling…' : 'Cancel'}
          </Button>
        </div>
      )}

      {subscription?.cancelled_at && (
        <p className="text-sm text-muted-foreground">
          Your subscription is set to end on {formatDate(subscription.renews_at)}.
        </p>
      )}
    </div>
  )
}
