'use client'

import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Skeleton } from '@/components/ui/Skeleton'
import apiClient from '@/lib/api-client'
import { formatCurrency, formatDate } from '@/lib/utils'
import { describeError } from '@/lib/errors'
import { useAuthStore } from '@/stores/auth-store'
import type { Package, Subscription } from '@/types'

/** Shared by the Settings modal's Plans tab — extracted from the old standalone /pricing route. */
export function PlansView() {
  const queryClient = useQueryClient()
  const user = useAuthStore((s) => s.user)
  // Informational display only, and which card-currency button is shown first
  // (see renderPaymentButtons() below) — the currency actually charged always
  // follows whichever specific payment button is clicked, explicitly, not this.
  const displayCurrency = user?.preferred_currency ?? 'USD'
  // Identifies exactly which button was clicked for which package (e.g.
  // { slug: 'pro', key: 'card-BDT' }) — not just the package, since a package
  // now has up to three payment buttons (card/USD, card/BDT, bKash) and only
  // the one actually clicked should ever show its own "…ing" loading text.
  // The other buttons for that same package still disable (only one purchase
  // per package can be in flight at once) but keep showing their real price,
  // rather than every button switching to loading text together. Confirmed
  // live: clicking any one of a package's buttons previously put ALL of that
  // package's buttons into "Subscribing…"/"Upgrading…", including ones never
  // clicked.
  const [pendingAction, setPendingAction] = useState<{ slug: string; key: string } | null>(null)
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

  // Wallet is deliberately not a payment_source here — wallet balance (topped
  // up directly or granted as a plan allowance) is meant for AI-usage
  // spending, not for funding the subscription itself. Same rule as upgrade
  // below. A $0 package still activates with no payment step at all — that's
  // handled entirely server-side, not a client-visible "source" choice.
  const subscribe = useMutation({
    mutationFn: async (
      { slug, source, currency, key }: { slug: string; source: 'card' | 'bkash'; currency: 'USD' | 'BDT'; key: string }
    ) => {
      setPendingAction({ slug, key })
      // bKash only ever settles in BDT — card can now charge either currency
      // directly using the package's own real sticker price (see
      // SubscriptionController::resolveCurrency()), so this follows whichever
      // specific button was actually clicked rather than being derived from
      // `source` alone.
      return apiClient.post<{ checkout_url?: string }>('/api/v1/subscription/subscribe', {
        package_slug: slug,
        payment_source: source,
        currency,
      })
    },
    onSuccess: (res) => {
      if (res.data.checkout_url) {
        // Card/bKash path — nothing is activated yet, /billing/checkout-callback
        // verifies the payment and activates the package once the gateway confirms it.
        window.location.href = res.data.checkout_url
        return
      }
      toast.success('Subscribed! Your wallet has been credited.')
      setChoosingSlug(null)
      queryClient.invalidateQueries({ queryKey: ['subscription'] })
      queryClient.invalidateQueries({ queryKey: ['wallet'] })
      // Without this, chat/page.tsx's useAvailableModels() kept serving the pre-purchase
      // model list from cache — the newly-unlocked models (and the chat access that
      // depends on there being at least one available model) only showed up after a
      // full reload, which creates a brand-new React Query client from scratch.
      // Confirmed live: purchasing a plan left chat unusable until a manual reload.
      queryClient.invalidateQueries({ queryKey: ['models'] })
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
        queryClient.invalidateQueries({ queryKey: ['models'] })
      }
    },
    onSettled: () => setPendingAction(null),
  })

  // Upgrade charges the full new plan price immediately via a real gateway (card
  // or bKash) — wallet is deliberately not an option here, unlike a fresh
  // subscribe: wallet balance (topped up directly or granted as a plan
  // allowance) is meant for AI-usage spending, not for self-funding an upgrade
  // with credit that was itself a free perk. Downgrade never charges anything —
  // it's scheduled for the next renewal, so it needs no payment_source at all.
  const changePlan = useMutation({
    mutationFn: async (
      { slug, direction, source, currency, key }:
      { slug: string; direction: 'upgrade'; source: 'card' | 'bkash'; currency: 'USD' | 'BDT'; key: string } |
      { slug: string; direction: 'downgrade'; source?: undefined; currency?: undefined; key: string }
    ) => {
      setPendingAction({ slug, key })
      return apiClient.post<{ checkout_url?: string; message?: string }>(`/api/v1/subscription/${direction}`, {
        package_slug: slug,
        ...(direction === 'upgrade' ? { payment_source: source, currency } : {}),
      })
    },
    onSuccess: (res, variables) => {
      if (res.data.checkout_url) {
        // Card/bKash upgrade — nothing applied yet, /billing/checkout-callback
        // verifies the payment and applies the upgrade once the gateway confirms it.
        window.location.href = res.data.checkout_url
        return
      }
      toast.success(variables.direction === 'upgrade' ? 'Upgraded successfully.' : (res.data.message ?? 'Downgrade scheduled.'))
      setChoosingSlug(null)
      queryClient.invalidateQueries({ queryKey: ['subscription'] })
      queryClient.invalidateQueries({ queryKey: ['wallet'] })
      queryClient.invalidateQueries({ queryKey: ['models'] })
    },
    onError: (err: unknown) => {
      const { ambiguous, message } = describeError(
        err,
        "We didn't hear back in time — check your current plan below before trying again."
      )
      toast.error(message)
      if (ambiguous) {
        setChoosingSlug(null)
        queryClient.invalidateQueries({ queryKey: ['subscription'] })
        queryClient.invalidateQueries({ queryKey: ['wallet'] })
        queryClient.invalidateQueries({ queryKey: ['models'] })
      }
    },
    onSettled: () => setPendingAction(null),
  })

  // Shared by the subscribe and upgrade "choosing" views below — a package now
  // has up to three payment buttons (card/BDT, card/USD, bKash/BDT) instead of
  // one card + one bKash, since Stripe can charge the package's own real BDT
  // sticker price directly now (not just USD) — see
  // SubscriptionController::resolveCurrency(). Whichever currency the card
  // already shows as its price (cardCurrency, driven by the visitor's own
  // preferred_currency) is the PRIMARY card button; the other currency is
  // still offered, just secondary, so a card payer always has a real choice
  // rather than being defaulted into whichever currency happens to be shown.
  const renderPaymentButtons = (
    pkg: Package,
    isBusy: boolean,
    isPendingKey: (key: string) => boolean,
    verb: string,
    onPay: (source: 'card' | 'bkash', currency: 'USD' | 'BDT', key: string) => void,
    onCancel: () => void
  ) => {
    const hasBdt = pkg.price.bdt !== null
    const primaryCardCurrency: 'USD' | 'BDT' = displayCurrency === 'BDT' && hasBdt ? 'BDT' : 'USD'
    const secondaryCardCurrency: 'USD' | 'BDT' | null = hasBdt ? (primaryCardCurrency === 'BDT' ? 'USD' : 'BDT') : null

    const cardButton = (currency: 'USD' | 'BDT', primary: boolean) => {
      const key = `card-${currency}`
      const price = currency === 'BDT' ? pkg.price.bdt! : pkg.price.usd
      return (
        <Button
          key={key}
          className="w-full"
          variant={primary ? 'primary' : 'outline'}
          disabled={isBusy}
          onClick={() => onPay('card', currency, key)}
        >
          {isPendingKey(key) ? `${verb}…` : `Pay ${formatCurrency(price, currency)} with Card (Stripe)`}
        </Button>
      )
    }

    return (
      <div className="space-y-2">
        {cardButton(primaryCardCurrency, true)}
        {secondaryCardCurrency && cardButton(secondaryCardCurrency, false)}
        <Button
          className="w-full"
          variant="outline"
          disabled={isBusy}
          onClick={() => onPay('bkash', 'BDT', 'bkash-BDT')}
        >
          {isPendingKey('bkash-BDT')
            ? `${verb}…`
            : `Pay ${hasBdt ? formatCurrency(pkg.price.bdt!, 'BDT') : formatCurrency(pkg.price.usd)} with bKash`}
        </Button>
        <button
          type="button"
          className="w-full text-xs text-muted-foreground hover:text-foreground"
          disabled={isBusy}
          onClick={onCancel}
        >
          Cancel
        </button>
      </div>
    )
  }

  const cancelSubscription = useMutation({
    mutationFn: async () => apiClient.post<{ access_until: string }>('/api/v1/subscription/cancel', {}),
    onSuccess: (res) => {
      toast.success(`Your plan will end on ${formatDate(res.data.access_until)}. You'll keep access until then.`)
      queryClient.invalidateQueries({ queryKey: ['subscription'] })
    },
    onError: (err: unknown) => toast.error(describeError(err, "We didn't hear back in time — check your plan status before trying again.").message),
  })

  return (
    <div className="space-y-6">
      <p className="text-sm text-muted-foreground">
        Subscribing and upgrading always charge the full plan price right away — never from wallet
        balance, which is reserved for AI usage.
      </p>

      {subscription?.scheduled_package && (
        <div className="rounded-md border border-border bg-accent/50 p-4 text-sm">
          You&apos;re switching to <strong>{subscription.scheduled_package.name}</strong> on{' '}
          {formatDate(subscription.renews_at)}. You keep your current plan&apos;s access until then.
        </div>
      )}

      {isLoading ? (
        <div className="grid gap-4 sm:grid-cols-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Card key={i}>
              <CardHeader><Skeleton className="h-5 w-24" /></CardHeader>
              <CardContent className="space-y-4">
                <Skeleton className="h-8 w-20" />
                <Skeleton className="h-3.5 w-full" />
                <Skeleton className="h-3.5 w-32" />
                <Skeleton className="h-9 w-full" />
              </CardContent>
            </Card>
          ))}
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-3">
          {packages?.map((pkg) => {
            const isCurrent = subscription?.package?.slug === pkg.slug
            const currentPrice = subscription?.package?.monthly_price_usd
            const isUpgrade = currentPrice !== undefined && pkg.price.usd > currentPrice
            const isBusy = (subscribe.isPending || changePlan.isPending) && pendingAction?.slug === pkg.slug
            const isPendingKey = (key: string) => isBusy && pendingAction?.key === key
            const isChoosing = choosingSlug === pkg.slug

            return (
              <Card key={pkg.id} className={isCurrent ? 'border-primary' : ''}>
                <CardHeader>
                  <CardTitle>{pkg.name}</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                  {(() => {
                    const cardCurrency = displayCurrency === 'BDT' && pkg.price.bdt !== null ? 'BDT' : 'USD'
                    const cardPrice = cardCurrency === 'BDT' ? pkg.price.bdt! : pkg.price.usd
                    // The real, admin-typed BDT entitlement a bKash purchase
                    // actually grants (see PackageActivationService::computeWalletCredit())
                    // — shown as-is, not derived from a formula, so this card
                    // never promises a number the backend won't match. Falls
                    // back to the USD figure when a package has no BDT credit
                    // configured, same rule the backend itself uses.
                    const cardWalletCredit = cardCurrency === 'BDT' && pkg.wallet_credit_bdt !== null ? pkg.wallet_credit_bdt : pkg.wallet_credit_usd
                    return (
                      <>
                        <div>
                          <span className="text-3xl font-bold">{formatCurrency(cardPrice, cardCurrency)}</span>
                          <span className="text-sm text-muted-foreground">/mo</span>
                        </div>
                        <p className="text-sm text-muted-foreground">{pkg.description}</p>
                        <p className="text-sm">
                          Includes <strong>{formatCurrency(cardWalletCredit, cardCurrency)}</strong> monthly wallet credit
                        </p>
                      </>
                    )
                  })()}
                  {pkg.features.vision && (
                    <p className="text-xs text-muted-foreground">Includes image/file upload &amp; vision models</p>
                  )}

                  {isCurrent ? (
                    <Button className="w-full" variant="outline" disabled>
                      Current plan
                    </Button>
                  ) : !subscription ? (
                    isChoosing ? (
                      renderPaymentButtons(
                        pkg,
                        isBusy,
                        isPendingKey,
                        'Subscribing',
                        (source, currency, key) => subscribe.mutate({ slug: pkg.slug, source, currency, key }),
                        () => setChoosingSlug(null)
                      )
                    ) : (
                      <Button className="w-full" disabled={isBusy} onClick={() => setChoosingSlug(pkg.slug)}>
                        Subscribe
                      </Button>
                    )
                  ) : isUpgrade ? (
                    isChoosing ? (
                      renderPaymentButtons(
                        pkg,
                        isBusy,
                        isPendingKey,
                        'Upgrading',
                        (source, currency, key) =>
                          changePlan.mutate({ slug: pkg.slug, direction: 'upgrade', source, currency, key }),
                        () => setChoosingSlug(null)
                      )
                    ) : (
                      <Button className="w-full" disabled={isBusy} onClick={() => setChoosingSlug(pkg.slug)}>
                        Upgrade — full price charged now
                      </Button>
                    )
                  ) : null /* Downgrade UI removed for now (2026-09-02) — changePlan's
                       'downgrade' direction and the backend endpoint are untouched,
                       this just hides the entry point. Restore this button (and the
                       isPending/scheduled_package branch above it) to re-enable. */}
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
