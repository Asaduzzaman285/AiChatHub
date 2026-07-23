'use client'

import Link from 'next/link'
import { usePathname, useRouter } from 'next/navigation'
import { useEffect, useState } from 'react'
import { AxiosError } from 'axios'
import { ChevronDown, CreditCard, LogOut, MessageSquare, Receipt, Sparkles, UserRound, Wallet } from 'lucide-react'
import { useAuthStore } from '@/stores/auth-store'
import apiClient from '@/lib/api-client'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/DropdownMenu'
import type { User } from '@/types'

const NAV_ITEMS = [
  { href: '/chat', label: 'Chat', icon: MessageSquare },
  { href: '/pricing', label: 'Pricing', icon: CreditCard },
  { href: '/wallet', label: 'Wallet', icon: Wallet },
  { href: '/billing', label: 'Billing', icon: Receipt },
]

/**
 * JWTs live in localStorage (via zustand persist), not cookies, so real
 * Next.js middleware can't see them — this client-side guard is the Phase 1
 * substitute. On refresh, only tokens survive (see auth-store's partialize),
 * so `user` is re-fetched here before rendering protected content.
 */
export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter()
  const pathname = usePathname()
  const { user, accessToken, isAuthenticated, hasHydrated, setUser, clearAuth } = useAuthStore()
  const [checking, setChecking] = useState(true)
  // Bumped on an ambiguous (non-401) /auth/me failure to trigger one more
  // attempt — this call alone times out roughly 1 in 5 times in this
  // environment, so a single failure shouldn't leave the profile blank for
  // the rest of the session.
  const [retryTick, setRetryTick] = useState(0)

  useEffect(() => {
    // Not a real "logged out" reading yet — zustand-persist hasn't finished
    // reading localStorage. Matters most right after a full page reload (e.g.
    // returning from an external redirect like Stripe Checkout), where this
    // effect can otherwise run before rehydration completes and wrongly bounce
    // an actually-logged-in user to /login.
    if (!hasHydrated) return

    if (!isAuthenticated || !accessToken) {
      router.replace('/login')
      return
    }

    if (user) {
      setChecking(false)
      return
    }

    let cancelled = false

    apiClient
      .get<User>('/api/v1/auth/me')
      .then(({ data }) => setUser(data))
      .catch((err: unknown) => {
        // A real 401 means the token itself was rejected — that's a genuine
        // "not logged in," clear it and send them to /login. Anything else
        // (network error, timeout, 5xx) is this environment being slow, not
        // an authentication failure. Wiping a valid token over infrastructure
        // flakiness was bouncing people to /login mid-session, most visibly
        // right after returning from Stripe Checkout when the backend is
        // under fresh load.
        const isRealAuthFailure = err instanceof AxiosError && err.response?.status === 401
        if (isRealAuthFailure) {
          clearAuth()
          router.replace('/login')
          return
        }

        if (retryTick < 3) {
          setTimeout(() => {
            if (!cancelled) setRetryTick((n) => n + 1)
          }, 2000)
        }
        // Otherwise (retries exhausted): leave isAuthenticated/tokens alone
        // and let the dashboard render anyway — `user` just stays null, the
        // header falls back to a placeholder (see below) instead of forcing
        // a login the person doesn't actually need.
      })
      .finally(() => setChecking(false))

    return () => {
      cancelled = true
    }
  }, [hasHydrated, isAuthenticated, accessToken, user, retryTick, setUser, clearAuth, router])

  const handleLogout = async () => {
    try {
      await apiClient.post('/api/v1/auth/logout')
    } catch {
      // Even if the server call fails, clear local state so the user isn't stuck.
    }
    clearAuth()
    router.replace('/login')
  }

  if (checking || !isAuthenticated) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-4 border-primary border-t-transparent" />
      </div>
    )
  }

  return (
    <div className="flex min-h-screen">
      <aside className="hidden w-56 shrink-0 border-r border-border bg-card sm:flex sm:flex-col">
        <div className="flex items-center gap-2 p-6">
          <Sparkles className="h-5 w-5 text-primary" />
          <span className="text-lg font-bold tracking-tight">AI ChatHub</span>
        </div>
        <nav className="space-y-1 px-3">
          {NAV_ITEMS.map((item) => {
            const active = pathname === item.href
            const Icon = item.icon
            return (
              <Link
                key={item.href}
                href={item.href}
                className={`flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition-colors ${
                  active
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                }`}
              >
                <Icon className="h-4 w-4" />
                {item.label}
              </Link>
            )
          })}
        </nav>
      </aside>

      <div className="flex-1">
        <header className="flex items-center justify-end border-b border-border px-6 py-4">
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <button className="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm outline-none transition-colors hover:bg-accent">
                <div className="flex h-7 w-7 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                  {user?.email?.[0]?.toUpperCase() ?? '?'}
                </div>
                <span className="text-muted-foreground">{user?.email}</span>
                <ChevronDown className="h-3.5 w-3.5 text-muted-foreground" />
              </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent>
              <DropdownMenuItem onClick={() => router.push('/profile')}>
                <UserRound className="h-4 w-4" />
                Profile
              </DropdownMenuItem>
              <DropdownMenuItem onClick={handleLogout}>
                <LogOut className="h-4 w-4" />
                Sign out
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </header>
        <main className="p-6">{children}</main>
      </div>
    </div>
  )
}
