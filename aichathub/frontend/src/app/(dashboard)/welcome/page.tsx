'use client'

import { useEffect, useRef } from 'react'
import { Lock } from 'lucide-react'
import apiClient from '@/lib/api-client'
import { useAuthStore } from '@/stores/auth-store'
import { PrivateChatPopover } from '@/components/chat/PrivateChatPopover'
import { WalletBalanceChip } from '@/components/wallet/WalletBalanceChip'
import { WelcomeFeatureGrid } from '@/components/onboarding/WelcomeFeatureGrid'
import { WelcomePricingSection } from '@/components/onboarding/WelcomePricingSection'

/**
 * Replaces the old WelcomeModal popup — shown once, as a full page rather than a modal
 * (see (dashboard)/layout.tsx's redirect: `!user.welcome_seen_at` sends here instead of
 * opening a modal on top of /chat). A full page has no natural "close" gesture, so
 * welcome_seen_at is marked on mount, matching the modal's own original logic: "shown"
 * counts as "seen," not "read to completion." Every real exit from this screen (New
 * Chat, a session row in the sidebar, a pricing card, Private Chat) already routes to
 * /chat or opens Settings, both of which work immediately since the field is already
 * set by the time any of those fire.
 */
export default function WelcomePage() {
  const { user, setUser } = useAuthStore()
  const markedSeen = useRef(false)

  useEffect(() => {
    if (markedSeen.current || !user || user.welcome_seen_at) return
    markedSeen.current = true
    apiClient.post('/api/v1/auth/welcome-seen').catch(() => {})
    setUser({ ...user, welcome_seen_at: new Date().toISOString() })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user])

  const firstName = user?.name?.split(' ')[0]

  return (
    <div className="h-screen overflow-y-auto">
      <div className="mx-auto max-w-[984px] px-6 py-10">
        <div className="flex items-start justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
              Welcome to Alveta{firstName ? `, ${firstName}` : ''}.
            </h1>
            <p className="mt-2 max-w-xl text-sm text-muted-foreground">
              You now have access to an intelligent workspace built around the world&apos;s leading
              AI models. Here&apos;s what&apos;s waiting for you.
            </p>
          </div>
          <div className="flex shrink-0 items-center gap-2">
            <PrivateChatPopover
              trigger={
                <button className="flex items-center gap-1.5 rounded-full border border-border px-3 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground">
                  <Lock className="h-3 w-3" />
                  Private Chat
                </button>
              }
            />
            <WalletBalanceChip />
          </div>
        </div>

        <div className="mt-8">
          <WelcomeFeatureGrid />
        </div>

        <WelcomePricingSection />
      </div>
    </div>
  )
}
