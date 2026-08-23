'use client'

import Link from 'next/link'
import { useQuery } from '@tanstack/react-query'
import apiClient from '@/lib/api-client'
import { PricingCard } from '@/components/pricing/PricingCard'
import { cn } from '@/lib/utils'
import type { Package } from '@/types'

export function PricingSection() {
  // Public, unauthenticated fetch — see api-gateway/routes/api.php's explicit
  // GET /packages route carved out of the auth-required group for this reason.
  const { data: packages, isLoading } = useQuery({
    queryKey: ['packages', 'public'],
    queryFn: async () => (await apiClient.get<{ packages: Package[] }>('/api/v1/packages')).data.packages,
  })

  return (
    <section id="pricing" className="px-6 py-20">
      <div className="mx-auto max-w-5xl text-center">
        <h2 className="font-display text-3xl font-bold tracking-tight text-neutral-900 sm:text-4xl">
          Pricing for every stage.
        </h2>
        <p className="mx-auto mt-4 max-w-xl text-neutral-500">
          Bring the world&apos;s leading AI models together in one place — GPT, Claude, Gemini,
          DeepSeek, Grok, and more.
        </p>

        <div className="mx-auto mt-12 grid gap-6 sm:grid-cols-3">
          {isLoading ? (
            Array.from({ length: 3 }).map((_, i) => (
              <div key={i} className="h-80 animate-pulse rounded-2xl border border-neutral-200 bg-neutral-50" />
            ))
          ) : (
            packages?.map((pkg) => {
              const featured = pkg.slug === 'standard'
              return (
                <PricingCard
                  key={pkg.id}
                  pkg={pkg}
                  featured={featured}
                  cta={
                    <Link
                      href="/register"
                      className={cn(
                        'mt-6 rounded-full px-4 py-2.5 text-center text-sm font-semibold transition-opacity hover:opacity-90',
                        featured ? 'bg-primary text-primary-foreground' : 'bg-neutral-900 text-white'
                      )}
                    >
                      Get Started
                    </Link>
                  }
                />
              )
            })
          )}
        </div>
      </div>
    </section>
  )
}
