'use client'

import { useRouter } from 'next/navigation'
import { useQuery } from '@tanstack/react-query'
import apiClient from '@/lib/api-client'
import { PricingCard } from '@/components/pricing/PricingCard'
import { Button } from '@/components/ui/Button'
import type { Package } from '@/types'

/** Same PricingCard the public landing page uses, but the CTA routes into the real
 * Settings-modal subscribe flow (/chat?settings=plans, the same redirect-into-Settings
 * convention the old /pricing route stub already used) instead of /register — this
 * visitor is already authenticated. */
export function WelcomePricingSection() {
  const router = useRouter()
  const { data: packages, isLoading } = useQuery({
    queryKey: ['packages', 'public'],
    queryFn: async () => (await apiClient.get<{ packages: Package[] }>('/api/v1/packages')).data.packages,
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

      <div className="mt-8 grid gap-4 sm:grid-cols-3">
        {isLoading ? (
          Array.from({ length: 3 }).map((_, i) => (
            <div key={i} className="h-80 animate-pulse rounded-2xl border border-border bg-card" />
          ))
        ) : (
          packages?.map((pkg) => (
            <PricingCard
              key={pkg.id}
              pkg={pkg}
              featured={pkg.slug === 'standard'}
              cta={
                <Button className="mt-6 w-full rounded-full" onClick={() => router.push('/chat?settings=plans')}>
                  Get Started
                </Button>
              }
            />
          ))
        )}
      </div>
    </div>
  )
}
