'use client'

import { useEffect, useRef, useState } from 'react'
import { useRouter } from 'next/navigation'
import { Search, User as UserIcon, Receipt } from 'lucide-react'
import { Badge } from '@/components/ui/Badge'
import apiClient from '@/lib/api-client'
import { cn, formatCurrency } from '@/lib/utils'
import type { AdminUserSummary, Transaction } from '@/types'

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

export function AdminSearch() {
  const router = useRouter()
  const [query, setQuery] = useState('')
  const [open, setOpen] = useState(false)
  const [users, setUsers] = useState<AdminUserSummary[] | null>(null)
  const [transaction, setTransaction] = useState<Transaction | null | undefined>(undefined)
  const [loading, setLoading] = useState(false)
  const wrapRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const q = query.trim()
    if (q.length < 2) {
      setUsers(null)
      setTransaction(undefined)
      return
    }

    setLoading(true)
    const timeout = setTimeout(async () => {
      try {
        const requests: Promise<void>[] = [
          apiClient
            .get<{ users: AdminUserSummary[] }>(`/api/v1/auth/admin/users?search=${encodeURIComponent(q)}&per_page=5`)
            .then(({ data }) => setUsers(data.users)),
        ]

        if (UUID_RE.test(q)) {
          requests.push(
            apiClient
              .get<{ transactions: Transaction[] }>(`/api/v1/transactions/admin?id=${q}`)
              .then(({ data }) => setTransaction(data.transactions[0] ?? null))
          )
        } else {
          setTransaction(undefined)
        }

        await Promise.all(requests)
      } catch {
        setUsers([])
      } finally {
        setLoading(false)
      }
    }, 300)

    return () => clearTimeout(timeout)
  }, [query])

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  const hasResults = (users && users.length > 0) || transaction
  const showPanel = open && query.trim().length >= 2

  const selectUser = (email: string) => {
    setOpen(false)
    setQuery('')
    router.push(`/admin/users?email=${encodeURIComponent(email)}`)
  }

  return (
    <div ref={wrapRef} className="relative w-72">
      <div className="flex items-center gap-2 rounded-md border border-input bg-muted px-3 py-1.5 text-sm text-muted-foreground focus-within:ring-2 focus-within:ring-ring">
        <Search className="h-3.5 w-3.5 shrink-0" />
        <input
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          onFocus={() => setOpen(true)}
          onKeyDown={(e) => e.key === 'Escape' && setOpen(false)}
          placeholder="Search users, transactions…"
          className="w-full bg-transparent text-foreground outline-none placeholder:text-muted-foreground"
        />
      </div>

      {showPanel && (
        <div className="absolute left-0 top-full z-50 mt-1.5 w-full overflow-hidden rounded-md border border-border bg-card shadow-md">
          {loading ? (
            <p className="px-3 py-3 text-xs text-muted-foreground">Searching…</p>
          ) : !hasResults ? (
            <p className="px-3 py-3 text-xs text-muted-foreground">No matches for &quot;{query}&quot;.</p>
          ) : (
            <div className="max-h-80 overflow-y-auto py-1">
              {users?.map((u) => (
                <button
                  key={u.id}
                  onClick={() => selectUser(u.email)}
                  className="flex w-full items-center gap-2.5 px-3 py-2 text-left text-sm transition-colors hover:bg-accent"
                >
                  <UserIcon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate font-medium">{u.name}</span>
                    <span className="block truncate text-xs text-muted-foreground">{u.email}</span>
                  </span>
                  <Badge variant={u.status === 'active' ? 'success' : 'neutral'}>{u.status}</Badge>
                </button>
              ))}
              {transaction && (
                <div className={cn('flex items-center gap-2.5 px-3 py-2 text-sm', users && users.length > 0 && 'border-t border-border')}>
                  <Receipt className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate font-medium">{formatCurrency(transaction.amount, transaction.currency)} · {transaction.gateway}</span>
                    <span className="block truncate text-xs text-muted-foreground">{transaction.type.replace(/_/g, ' ')}</span>
                  </span>
                  <Badge variant={transaction.status === 'completed' ? 'success' : transaction.status === 'failed' ? 'destructive' : 'warning'}>
                    {transaction.status}
                  </Badge>
                </div>
              )}
            </div>
          )}
        </div>
      )}
    </div>
  )
}
