'use client'

import { useState } from 'react'
import { useRouter } from 'next/navigation'
import { useMutation, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import apiClient from '@/lib/api-client'
import { PricingCard } from '@/components/pricing/PricingCard'
import { Button } from '@/components/ui/Button'
import { describeError } from '@/lib/errors'
import { formatCurrency } from '@/lib/utils'
import { useAuthStore } from '@/stores/auth-store'
import type { Package } from '@/types'

/** Same PricingCard the public landing page uses. The CTA used to just route into
 * /chat?settings=plans — opening the Settings modal's Plans tab and requiring a
 * second click there to actually start checkout (confirmed live: read as "why did
 * clicking Pay just show me a settings screen?"). Calls the same subscribe endpoint
 * PlansView.tsx uses directly instead, going straight to the payment gateway.
 *
 * A logged-in user already has a real preferred_currency (set at registration
 * via geo-detection) rather than needing a fresh IP lookup like the anonymous
 * landing page does — used here purely for which card-currency button shows
 * first (see ctaFor()'s cardButton). The currency actually charged always
 * follows whichever specific payment button is clicked, explicitly — card can
 * now charge either USD or the package's own real BDT sticker price directly
 * (see subscription-service's SubscriptionController::resolveCurrency()), not
 * just bKash, same rule as PlansView.tsx. */
export function WelcomePricingSection() {
  const router = useRouter()
  const user = useAuthStore((s) => s.user)
  const displayCurrency = user?.preferred_currency ?? 'USD'
  const [choosingSlug, setChoosingSlug] = useState<string | null>(null)
  // Which specific button was clicked (e.g. 'card-BDT') — only that one shows
  // its own "Starting checkout…" text; the package's other buttons just
  // disable instead of all switching to loading text together (same fix as
  // PlansView.tsx's pendingAction).
  const [pendingKey, setPendingKey] = useState<string | null>(null)
  const { data: packages, isLoading } = useQuery({
    queryKey: ['packages', 'public'],
    queryFn: async () => (await apiClient.get<{ packages: Package[] }>('/api/v1/packages')).data.packages,
  })

  const subscribe = useMutation({
    mutationFn: async (
      { slug, source, currency, key }: { slug: string; source: 'card' | 'bkash'; currency: 'USD' | 'BDT'; key: string }
    ) => {
      setPendingKey(key)
      return apiClient.post<{ checkout_url?: string }>('/api/v1/subscription/subscribe', {
        package_slug: slug,
        payment_source: source,
        currency,
      })
    },
    onSuccess: (res) => {
      if (res.data.checkout_url) {
        window.location.href = res.data.checkout_url
        return
      }
      // Free ($0) package — activates synchronously, nothing to redirect to.
      router.push('/chat')
    },
    onError: (err: unknown) => {
      // 409 (already subscribed — e.g. re-opening this popup after subscribing once
      // already) isn't a real error from this screen's point of view — just get them
      // into the app instead of showing a confusing "error."
      if ((err as { response?: { status?: number } })?.response?.status === 409) {
        router.push('/chat')
        return
      }
      const { message } = describeError(err, "We couldn't start checkout — please try again.")
      toast.error(message)
      setChoosingSlug(null)
    },
    onSettled: () => setPendingKey(null),
  })

  const ctaFor = (pkg: Package) => {
    const slug = pkg.slug
    if (choosingSlug === slug) {
      const hasBdt = pkg.price.bdt !== null
      const primaryCardCurrency: 'USD' | 'BDT' = displayCurrency === 'BDT' && hasBdt ? 'BDT' : 'USD'
      const secondaryCardCurrency: 'USD' | 'BDT' | null = hasBdt ? (primaryCardCurrency === 'BDT' ? 'USD' : 'BDT') : null
      const isPending = subscribe.isPending

      const cardButton = (currency: 'USD' | 'BDT', primary: boolean) => {
        const key = `card-${currency}`
        const price = currency === 'BDT' ? pkg.price.bdt! : pkg.price.usd
        return (
          <Button
            key={key}
            className="w-full rounded-full"
            variant={primary ? 'primary' : 'outline'}
            disabled={isPending}
            onClick={() => subscribe.mutate({ slug, source: 'card', currency, key })}
          >
            {isPending && pendingKey === key ? 'Starting checkout…' : `Pay ${formatCurrency(price, currency)} with Card (Stripe)`}
          </Button>
        )
      }

      return (
        <div className="mt-6 space-y-2">
          {cardButton(primaryCardCurrency, true)}
          {secondaryCardCurrency && cardButton(secondaryCardCurrency, false)}
          <Button
            className="w-full rounded-full"
            variant="outline"
            disabled={isPending}
            onClick={() => subscribe.mutate({ slug, source: 'bkash', currency: 'BDT', key: 'bkash-BDT' })}
          >
            {isPending && pendingKey === 'bkash-BDT'
              ? 'Starting checkout…'
              : `Pay ${hasBdt ? formatCurrency(pkg.price.bdt!, 'BDT') : formatCurrency(pkg.price.usd)} with bKash`}
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
      )
    }

    return (
      <Button className="mt-6 w-full rounded-full" onClick={() => setChoosingSlug(slug)}>
        Get Started
      </Button>
    )
  }

  return (
    <div className="mt-16">
      <h2 className="text-center text-2xl font-bold tracking-tight text-foreground">
        Sustainable Pricing for everyone
      </h2>
      <p className="mx-auto mt-2 max-w-lg text-center text-sm text-muted-foreground">
        Bring the world&apos;s leading AI models together in one place — GPT, Claude, Gemini,
        DeepSeek, Grok, Mistral, LLaMA, and more.
      </p>

      {/* Pro renders separately below as a horizontal banner — see PricingSection.tsx's
          matching comment for why (4 real packages now, a plain 3-column grid left it
          wrapping onto its own row as a single narrow vertical card). */}
      <div className="mt-8 grid gap-4 sm:grid-cols-3">
        {isLoading ? (
          Array.from({ length: 3 }).map((_, i) => (
            <div key={i} className="h-80 animate-pulse rounded-2xl border border-border bg-card" />
          ))
        ) : (
          packages?.filter((pkg) => pkg.slug !== 'pro').map((pkg) => (
            <PricingCard
              key={pkg.id}
              pkg={pkg}
              featured={pkg.slug === 'standard'}
              currency={displayCurrency}
              cta={ctaFor(pkg)}
            />
          ))
        )}
      </div>

      {!isLoading && packages?.find((pkg) => pkg.slug === 'pro') && (
        <div className="mt-4">
          <PricingCard
            pkg={packages.find((pkg) => pkg.slug === 'pro')!}
            featured
            popularBadge={false}
            layout="horizontal"
            currency={displayCurrency}
            cta={ctaFor(packages.find((pkg) => pkg.slug === 'pro')!)}
          />
        </div>
      )}
    </div>
  )
}
