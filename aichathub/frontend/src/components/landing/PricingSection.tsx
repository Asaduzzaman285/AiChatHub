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

        {/* Pro renders separately below as a horizontal banner — with 4 real packages
            on the server, a plain 3-column grid left it wrapping onto its own row as a
            single narrow vertical card. Splitting it out keeps the grid clean at
            exactly 3 and gives Pro a width matching the row above instead. */}
        <div className="mx-auto mt-12 grid gap-6 sm:grid-cols-3">
          {isLoading ? (
            Array.from({ length: 3 }).map((_, i) => (
              <div key={i} className="h-80 animate-pulse rounded-2xl border border-neutral-200 bg-neutral-50" />
            ))
          ) : (
            packages?.filter((pkg) => pkg.slug !== 'pro').map((pkg) => {
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

        {!isLoading && packages?.find((pkg) => pkg.slug === 'pro') && (
          <div className="mx-auto mt-6">
            <PricingCard
              pkg={packages.find((pkg) => pkg.slug === 'pro')!}
              featured
              layout="horizontal"
              cta={
                <Link
                  href="/register"
                  className="block rounded-full bg-primary px-4 py-2.5 text-center text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90"
                >
                  Get Started
                </Link>
              }
            />
          </div>
        )}
      </div>
    </section>
  )
}
