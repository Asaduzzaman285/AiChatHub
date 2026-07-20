'use client'

import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import apiClient from '@/lib/api-client'
import { formatCurrency, formatDate } from '@/lib/utils'
import { describeError } from '@/lib/errors'
import type { Package, Subscription } from '@/types'

// Phase 1 has no Stripe Elements integration yet — subscribe() doesn't
// actually charge a card (see SubscriptionController, services/subscription-service).
// This is Stripe's well-known test PaymentMethod id, safe to hardcode for test mode.
const TEST_PAYMENT_METHOD = 'pm_card_visa'

export default function PricingPage() {
  const queryClient = useQueryClient()
  const [pendingSlug, setPendingSlug] = useState<string | null>(null)

  const { data: packages, isLoading } = useQuery({
    queryKey: ['packages'],
    queryFn: async () => (await apiClient.get<{ packages: Package[] }>('/api/v1/packages')).data.packages,
  })

  const { data: subscription } = useQuery({
    queryKey: ['subscription', 'current'],
    queryFn: async () => (await apiClient.get<{ subscription: Subscription | null }>('/api/v1/subscription')).data.subscription,
  })

  const subscribe = useMutation({
    mutationFn: async (slug: string) => {
      setPendingSlug(slug)
      return apiClient.post('/api/v1/subscription/subscribe', {
        package_slug: slug,
        payment_method_token: TEST_PAYMENT_METHOD,
        currency: 'USD',
      })
    },
    onSuccess: () => {
      toast.success('Subscribed! Your wallet has been credited.')
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
          Test mode — subscribing uses Stripe&apos;s test card ({TEST_PAYMENT_METHOD}), no real charge occurs.
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
                    <Button className="w-full" disabled={isPending} onClick={() => subscribe.mutate(pkg.slug)}>
                      {isPending ? 'Subscribing…' : 'Subscribe'}
                    </Button>
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
