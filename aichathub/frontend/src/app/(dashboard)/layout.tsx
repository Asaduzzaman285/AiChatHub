'use client'

import Link from 'next/link'
import { usePathname, useRouter } from 'next/navigation'
import { useEffect, useState } from 'react'
import { useAuthStore } from '@/stores/auth-store'
import apiClient from '@/lib/api-client'
import type { User } from '@/types'

const NAV_ITEMS = [
  { href: '/chat', label: 'Home' },
  { href: '/pricing', label: 'Pricing' },
  { href: '/wallet', label: 'Wallet' },
  { href: '/billing', label: 'Billing' },
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
  const { user, accessToken, isAuthenticated, setUser, clearAuth } = useAuthStore()
  const [checking, setChecking] = useState(true)

  useEffect(() => {
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
  }, [isAuthenticated, accessToken, user, setUser, clearAuth, router])

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
      <aside className="hidden w-56 shrink-0 border-r border-border bg-card sm:block">
        <div className="p-6">
          <span className="text-lg font-bold tracking-tight">AI ChatHub</span>
        </div>
        <nav className="space-y-1 px-3">
          {NAV_ITEMS.map((item) => {
            const active = pathname === item.href
            return (
              <Link
                key={item.href}
                href={item.href}
                className={`block rounded-md px-3 py-2 text-sm font-medium ${
                  active
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                }`}
              >
                {item.label}
              </Link>
            )
          })}
        </nav>
      </aside>

      <div className="flex-1">
        <header className="flex items-center justify-between border-b border-border px-6 py-4">
          <span className="text-sm text-muted-foreground">{user?.email}</span>
          <button
            onClick={handleLogout}
            className="text-sm font-medium text-muted-foreground hover:text-foreground"
          >
            Sign out
          </button>
        </header>
        <main className="p-6">{children}</main>
      </div>
    </div>
  )
}
