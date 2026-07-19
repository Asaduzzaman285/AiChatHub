'use client'

import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import apiClient from '@/lib/api-client'
import { formatCurrency } from '@/lib/utils'
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
                  <Button
                    className="w-full"
                    variant={isCurrent ? 'outline' : 'primary'}
                    disabled={isCurrent || !!subscription || subscribe.isPending}
                    onClick={() => subscribe.mutate(pkg.slug)}
                  >
                    {isCurrent
                      ? 'Current plan'
                      : subscribe.isPending && pendingSlug === pkg.slug
                        ? 'Subscribing…'
                        : subscription
                          ? 'Already subscribed'
                          : 'Subscribe'}
                  </Button>
                </CardContent>
              </Card>
            )
          })}
        </div>
      )}

      {subscription && (
        <p className="text-sm text-muted-foreground">
          Upgrade/downgrade/cancel aren&apos;t wired into the UI yet — those endpoints exist
          (<code>POST /subscription/upgrade</code>, <code>/downgrade</code>, <code>/cancel</code>) but need a REST client to exercise for now.
        </p>
      )}
    </div>
  )
}
