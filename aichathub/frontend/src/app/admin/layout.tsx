'use client'

import Link from 'next/link'
import { usePathname, useRouter } from 'next/navigation'
import { useEffect, useState } from 'react'
import { AxiosError } from 'axios'
import {
  BrainCircuit,
  ChevronDown,
  ClipboardList,
  CreditCard,
  LayoutDashboard,
  LogOut,
  MessagesSquare,
  Package,
  Receipt,
  ShieldCheck,
  Sparkles,
  UserCog,
  UserRound,
  Users,
  Wallet,
} from 'lucide-react'
import { useAuthStore } from '@/stores/auth-store'
import apiClient from '@/lib/api-client'
import { hasPermission } from '@/lib/permissions'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/DropdownMenu'
import type { User } from '@/types'

const NAV_ITEMS = [
  { href: '/admin', label: 'Dashboard', icon: LayoutDashboard, permission: 'dashboard.view' },
  { href: '/admin/users', label: 'Users', icon: Users, permission: 'users.view' },
  { href: '/admin/subscriptions', label: 'Subscriptions', icon: MessagesSquare, permission: 'subscriptions.view' },
  { href: '/admin/packages', label: 'Packages', icon: Package, permission: 'packages.manage' },
  { href: '/admin/transactions', label: 'Transactions', icon: CreditCard, permission: 'payments.view' },
  { href: '/admin/wallet', label: 'Wallet', icon: Wallet, permission: 'wallet.view' },
  { href: '/admin/ai-usage', label: 'AI Usage', icon: Receipt, permission: 'ai_usage.view' },
  { href: '/admin/ai-models', label: 'AI Models', icon: BrainCircuit, permission: 'models.manage' },
  { href: '/admin/admins', label: 'Admins', icon: ShieldCheck, permission: 'admins.manage' },
  { href: '/admin/roles', label: 'Roles', icon: UserCog, permission: 'admins.manage' },
  { href: '/admin/audit-logs', label: 'Audit Logs', icon: ClipboardList, permission: 'audit_logs.view' },
]

/**
 * Mirrors (dashboard)/layout.tsx's hydration-wait + /auth/me fetch pattern,
 * with one addition: redirect non-admins to /chat (not /login — they ARE
 * authenticated, just not an admin). The sidebar only renders links the
 * admin's own admin_permissions cover, matching what each backend route
 * actually gates — a support admin never sees "Admins" or "Refund" links.
 */
export default function AdminLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter()
  const pathname = usePathname()
  const { user, accessToken, isAuthenticated, hasHydrated, setUser, clearAuth } = useAuthStore()
  const [checking, setChecking] = useState(true)
  const [retryTick, setRetryTick] = useState(0)

  useEffect(() => {
    if (!hasHydrated) return

    if (!isAuthenticated || !accessToken) {
      router.replace('/login')
      return
    }

    if (user) {
      if (!user.is_admin) {
        router.replace('/chat')
        return
      }
      setChecking(false)
      return
    }

    let cancelled = false

    apiClient
      .get<User>('/api/v1/auth/me')
      .then(({ data }) => {
        setUser(data)
        if (!data.is_admin) {
          router.replace('/chat')
        }
      })
      .catch((err: unknown) => {
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

  if (checking || !isAuthenticated || !user?.is_admin) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-4 border-primary border-t-transparent" />
      </div>
    )
  }

  const visibleItems = NAV_ITEMS.filter((item) => hasPermission(user, item.permission))

  return (
    <div className="flex min-h-screen">
      <aside className="hidden w-56 shrink-0 border-r border-border bg-card sm:flex sm:flex-col">
        <div className="flex items-center gap-2 p-6">
          <Sparkles className="h-5 w-5 text-primary" />
          <div>
            <span className="block text-lg font-bold tracking-tight">Admin</span>
            <span className="block text-xs capitalize text-muted-foreground">{user.admin_role?.replace('_', ' ')}</span>
          </div>
        </div>
        <nav className="space-y-1 px-3">
          {visibleItems.map((item) => {
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
        <div className="mt-auto p-3">
          <Link
            href="/chat"
            className="flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
          >
            ← Back to app
          </Link>
        </div>
      </aside>

      <div className="flex-1">
        <header className="flex items-center justify-end border-b border-border px-6 py-4">
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <button className="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm outline-none transition-colors hover:bg-accent">
                <div className="flex h-7 w-7 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                  {user.email?.[0]?.toUpperCase() ?? '?'}
                </div>
                <span className="text-muted-foreground">{user.email}</span>
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
