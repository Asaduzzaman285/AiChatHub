'use client'

import { useRouter } from 'next/navigation'
import { useMutation, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import apiClient from '@/lib/api-client'
import { PricingCard } from '@/components/pricing/PricingCard'
import { Button } from '@/components/ui/Button'
import { describeError } from '@/lib/errors'
import type { Package } from '@/types'

/** Same PricingCard the public landing page uses. The CTA used to just route into
 * /chat?settings=plans — opening the Settings modal's Plans tab and requiring a
 * second click there to actually start checkout (confirmed live: read as "why did
 * clicking Pay just show me a settings screen?"). Calls the same subscribe endpoint
 * PlansView.tsx uses directly instead, going straight to the payment gateway — no
 * payment_source picker here since bKash is disabled everywhere else in the app
 * right now (card is the only real option; re-add a picker alongside bKash's own
 * re-enable if that ever changes). */
export function WelcomePricingSection() {
  const router = useRouter()
  const { data: packages, isLoading } = useQuery({
    queryKey: ['packages', 'public'],
    queryFn: async () => (await apiClient.get<{ packages: Package[] }>('/api/v1/packages')).data.packages,
  })

  const subscribe = useMutation({
    mutationFn: async (slug: string) =>
      apiClient.post<{ checkout_url?: string }>('/api/v1/subscription/subscribe', {
        package_slug: slug,
        payment_source: 'card',
        currency: 'USD',
      }),
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
    },
  })

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
              cta={
                <Button
                  className="mt-6 w-full rounded-full"
                  disabled={subscribe.isPending}
                  onClick={() => subscribe.mutate(pkg.slug)}
                >
                  {subscribe.isPending ? 'Starting checkout…' : 'Get Started'}
                </Button>
              }
            />
          ))
        )}
      </div>

      {!isLoading && packages?.find((pkg) => pkg.slug === 'pro') && (
        <div className="mt-4">
          <PricingCard
            pkg={packages.find((pkg) => pkg.slug === 'pro')!}
            featured
            layout="horizontal"
            cta={
              <Button
                className="w-full rounded-full"
                disabled={subscribe.isPending}
                onClick={() => subscribe.mutate('pro')}
              >
                {subscribe.isPending ? 'Starting checkout…' : 'Get Started'}
              </Button>
            }
          />
        </div>
      )}
    </div>
  )
}
