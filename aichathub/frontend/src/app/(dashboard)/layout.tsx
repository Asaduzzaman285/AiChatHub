'use client'

import Link from 'next/link'
import { usePathname, useRouter } from 'next/navigation'
import { useEffect, useState } from 'react'
import { CreditCard, LogOut, MessageSquare, Receipt, Sparkles, Wallet } from 'lucide-react'
import { useAuthStore } from '@/stores/auth-store'
import apiClient from '@/lib/api-client'
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

    apiClient
      .get<User>('/api/v1/auth/me')
      .then(({ data }) => setUser(data))
      .catch(() => {
        clearAuth()
        router.replace('/login')
      })
      .finally(() => setChecking(false))
  }, [hasHydrated, isAuthenticated, accessToken, user, setUser, clearAuth, router])

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
        <header className="flex items-center justify-between border-b border-border px-6 py-4">
          <div className="flex items-center gap-2">
            <div className="flex h-7 w-7 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
              {user?.email?.[0]?.toUpperCase() ?? '?'}
            </div>
            <span className="text-sm text-muted-foreground">{user?.email}</span>
          </div>
          <button
            onClick={handleLogout}
            className="flex items-center gap-1.5 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
          >
            <LogOut className="h-4 w-4" />
            Sign out
          </button>
        </header>
        <main className="p-6">{children}</main>
      </div>
    </div>
  )
}
