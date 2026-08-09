'use client'

import { useEffect, useState } from 'react'
import { usePathname, useRouter, useSearchParams } from 'next/navigation'
import { AxiosError } from 'axios'
import { ChevronDown, LogOut, Pencil, Plus, Settings as SettingsIcon, Sparkles, MessageSquare, Trash2 } from 'lucide-react'
import { useAuthStore } from '@/stores/auth-store'
import apiClient from '@/lib/api-client'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/DropdownMenu'
import { SkeletonListItem } from '@/components/ui/Skeleton'
import { SettingsModal, type SettingsTab } from '@/components/settings/SettingsModal'
import { ChatSessionProvider, useChatSession } from '@/contexts/ChatSessionContext'
import { cn } from '@/lib/utils'
import type { User } from '@/types'

/**
 * ChatGPT-style shell — chat history is the sidebar's primary content (moved up from
 * chat/page.tsx into ChatSessionContext so it's the same list regardless of which page
 * renders), Settings lives in a modal opened from the bottom of the sidebar rather than
 * /billing, /wallet, /pricing, /profile being separate routed pages.
 *
 * JWTs live in localStorage (via zustand persist), not cookies, so real Next.js
 * middleware can't see them — this client-side guard is the Phase 1 substitute.
 */
function DashboardShell({ children }: { children: React.ReactNode }) {
  const router = useRouter()
  const pathname = usePathname()
  const searchParams = useSearchParams()
  const { user, clearAuth } = useAuthStore()
  const { sessions, sessionsLoading, activeSessionId, setActiveSessionId, createSession, renameSession, deleteSession } = useChatSession()
  const [settingsOpen, setSettingsOpen] = useState(false)
  const [settingsTab, setSettingsTab] = useState<SettingsTab>('account')
  const [renamingId, setRenamingId] = useState<string | null>(null)
  const [renameValue, setRenameValue] = useState('')

  const openSettings = (tab: SettingsTab) => {
    setSettingsTab(tab)
    setSettingsOpen(true)
  }

  // Old standalone routes (/billing, /wallet, /pricing, /profile) now redirect to
  // /chat?settings=<tab> — this opens the right Settings modal tab instead of 404ing
  // old bookmarks/links now that those pages no longer exist as their own routes.
  useEffect(() => {
    const settingsParam = searchParams.get('settings')
    if (settingsParam && ['account', 'billing', 'wallet', 'usage', 'plans'].includes(settingsParam)) {
      openSettings(settingsParam as SettingsTab)
      router.replace('/chat')
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchParams])

  const handleLogout = async () => {
    try {
      await apiClient.post('/api/v1/auth/logout')
    } catch {
      // Even if the server call fails, clear local state so the user isn't stuck.
    }
    clearAuth()
    router.replace('/login')
  }

  const startRename = (id: string, title: string) => {
    setRenamingId(id)
    setRenameValue(title)
  }

  const commitRename = () => {
    const title = renameValue.trim()
    if (!renamingId) return
    if (!title) { setRenamingId(null); return }
    renameSession.mutate({ id: renamingId, title })
    setRenamingId(null)
  }

  const confirmDelete = (id: string, title: string) => {
    if (window.confirm(`Delete "${title}"? This can't be undone.`)) {
      deleteSession.mutate(id)
    }
  }

  return (
    <div className="flex min-h-screen">
      <aside className="hidden w-64 shrink-0 border-r border-border bg-card sm:flex sm:flex-col">
        <div className="flex items-center gap-2 p-4">
          <Sparkles className="h-5 w-5 text-primary" />
          <span className="font-display text-lg font-bold tracking-tight">AI ChatHub</span>
        </div>

        <div className="px-3 pb-2">
          <button
            onClick={() => { setActiveSessionId(null); router.push('/chat') }}
            className="flex w-full items-center gap-2 rounded-md border border-border px-3 py-2 text-sm font-medium transition-colors hover:bg-accent"
          >
            <Plus className="h-4 w-4" />
            New chat
          </button>
        </div>

        <nav className="flex-1 overflow-y-auto px-3">
          {sessionsLoading ? (
            <>{Array.from({ length: 6 }).map((_, i) => <SkeletonListItem key={i} />)}</>
          ) : !sessions?.length ? (
            <div className="p-6 text-center space-y-2">
              <MessageSquare className="h-8 w-8 mx-auto text-muted-foreground/40" />
              <p className="text-xs text-muted-foreground">No chats yet — start one above.</p>
            </div>
          ) : (
            sessions.map((s) => (
              <div
                key={s.id}
                className={cn(
                  'group flex items-center gap-1 rounded-md px-2.5 py-2 text-sm transition-colors hover:bg-accent',
                  s.id === activeSessionId && pathname === '/chat' && 'bg-accent'
                )}
              >
                {renamingId === s.id ? (
                  <input
                    autoFocus
                    value={renameValue}
                    onChange={(e) => setRenameValue(e.target.value)}
                    onBlur={commitRename}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') commitRename()
                      if (e.key === 'Escape') setRenamingId(null)
                    }}
                    className="flex-1 min-w-0 rounded border border-input bg-background px-1.5 py-0.5 text-sm"
                  />
                ) : (
                  <button
                    onClick={() => { setActiveSessionId(s.id); router.push('/chat') }}
                    className="flex-1 min-w-0 truncate text-left"
                  >
                    {s.title}
                  </button>
                )}
                {renamingId !== s.id && (
                  <div className="flex shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button onClick={() => startRename(s.id, s.title)} className="p-1 text-muted-foreground hover:text-foreground" aria-label="Rename chat">
                      <Pencil className="h-3.5 w-3.5" />
                    </button>
                    <button onClick={() => confirmDelete(s.id, s.title)} className="p-1 text-muted-foreground hover:text-destructive" aria-label="Delete chat">
                      <Trash2 className="h-3.5 w-3.5" />
                    </button>
                  </div>
                )}
              </div>
            ))
          )}
        </nav>

        <div className="border-t border-border p-3">
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <button className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm outline-none transition-colors hover:bg-accent">
                <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                  {user?.email?.[0]?.toUpperCase() ?? '?'}
                </div>
                <span className="min-w-0 flex-1 truncate text-left text-muted-foreground">{user?.email}</span>
                <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
              </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-56">
              <DropdownMenuItem onClick={() => openSettings('account')}>
                <SettingsIcon className="h-4 w-4" />
                Settings
              </DropdownMenuItem>
              <DropdownMenuItem onClick={handleLogout}>
                <LogOut className="h-4 w-4" />
                Sign out
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </aside>

      <div className="flex-1 overflow-hidden">{children}</div>

      <SettingsModal open={settingsOpen} onOpenChange={setSettingsOpen} defaultTab={settingsTab} />
    </div>
  )
}

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter()
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
      if (user.is_admin) {
        router.replace('/admin')
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
        if (data.is_admin) {
          router.replace('/admin')
        }
      })
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

  if (checking || !isAuthenticated || user?.is_admin) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-4 border-primary border-t-transparent" />
      </div>
    )
  }

  return (
    <ChatSessionProvider>
      <DashboardShell>{children}</DashboardShell>
    </ChatSessionProvider>
  )
}
