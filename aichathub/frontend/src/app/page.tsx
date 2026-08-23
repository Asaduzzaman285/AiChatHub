'use client'

import { useEffect } from 'react'
import { useRouter } from 'next/navigation'
import { useAuthStore } from '@/stores/auth-store'
import { postLoginPath } from '@/lib/post-login-redirect'
import { Hero } from '@/components/landing/Hero'
import { FeaturesSection } from '@/components/landing/FeaturesSection'
import { PricingSection } from '@/components/landing/PricingSection'
import { CtaBanner } from '@/components/landing/CtaBanner'
import { FaqSection } from '@/components/landing/FaqSection'
import { Footer } from '@/components/landing/Footer'

export default function RootPage() {
  const router = useRouter()
  const { isAuthenticated, hasHydrated, user } = useAuthStore()

  useEffect(() => {
    // Only ever redirect *away* — an authenticated visitor has no reason to see the
    // marketing page. An unauthenticated one stays here and sees the real landing
    // page below instead of being bounced straight to /login like before.
    if (hasHydrated && isAuthenticated) router.replace(postLoginPath(user))
  }, [hasHydrated, isAuthenticated, user, router])

  // Deliberately does NOT gate the render on `hasHydrated` — this is the one page in
  // the app whose entire purpose is public-facing (SEO, link-preview crawlers that
  // don't run JS, first paint before hydration finishes), so it renders optimistically
  // by default. The rare case of an actually-authenticated visitor landing on "/" gets
  // a brief flash of the marketing page before the effect above redirects them, which
  // is far preferable to every anonymous visitor — the overwhelming majority of "/"
  // traffic — seeing a blank page until hydration completes (confirmed live: gating on
  // hasHydrated produced an essentially empty static export with no landing content at
  // all, just the <title>).
  if (hasHydrated && isAuthenticated) return null

  // Only the Hero and CtaBanner are dark, self-contained cards inset into an otherwise
  // light page (confirmed against the real Figma export) — no global .dark wrapper here.
  return (
    <div className="min-h-screen bg-white text-neutral-900">
      <main>
        <Hero />
        <FeaturesSection />
        <PricingSection />
        <CtaBanner />
        <FaqSection />
      </main>
      <Footer />
    </div>
  )
}
