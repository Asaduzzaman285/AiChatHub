'use client'

import Link from 'next/link'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { MessagesSquare } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Badge } from '@/components/ui/Badge'
import { Pagination } from '@/components/ui/Pagination'
import { SkeletonTableRows } from '@/components/ui/Skeleton'
import apiClient from '@/lib/api-client'
import { formatDate } from '@/lib/utils'
import { describeError } from '@/lib/errors'
import { buildQueryString } from '@/lib/query-string'
import { hasPermission } from '@/lib/permissions'
import { useAuthStore } from '@/stores/auth-store'
import { useListFilters } from '@/hooks/useListFilters'
import type { AdminMeta, AdminUserSummary } from '@/types'

const STATUS_VARIANT: Record<string, 'success' | 'warning' | 'destructive' | 'neutral'> = {
  active: 'success',
  pending_verification: 'warning',
  suspended: 'destructive',
}

interface Filters {
  name: string
  email: string
  status: string
}

export default function AdminUsersPage() {
  const { user: currentAdmin } = useAuthStore()
  const canSuspend = hasPermission(currentAdmin, 'users.suspend')
  const canViewChat = hasPermission(currentAdmin, 'chat_logs.view')
  const queryClient = useQueryClient()

  const { filters, setFilters, applied, page, setPage, applyFilters, clearFilters, hasActiveFilters } =
    useListFilters<Filters>({ name: '', email: '', status: '' })

  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'users', applied, page],
    queryFn: async () => {
      const qs = buildQueryString({ ...applied, page, per_page: 20 })
      return (await apiClient.get<{ users: AdminUserSummary[]; meta: AdminMeta }>(`/api/v1/auth/admin/users${qs}`)).data
    },
  })

  const toggleSuspend = useMutation({
    mutationFn: async ({ id, suspend }: { id: string; suspend: boolean }) =>
      apiClient.post(`/api/v1/auth/admin/users/${id}/${suspend ? 'suspend' : 'unsuspend'}`),
    onSuccess: () => {
      toast.success('User updated.')
      queryClient.invalidateQueries({ queryKey: ['admin', 'users'] })
    },
    onError: (err: unknown) => toast.error(describeError(err, "We couldn't reach the server in time — check the user's status before trying again.").message),
  })

  return (
    <div className="max-w-5xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Users</h1>
        <p className="mt-1 text-sm text-muted-foreground">Search, filter, and manage user accounts.</p>
      </div>

      <Card>
        <CardContent className="pt-6">
          <form onSubmit={applyFilters} className="grid gap-3 sm:grid-cols-4">
            <Input placeholder="Name" value={filters.name} onChange={(e) => setFilters({ ...filters, name: e.target.value })} />
            <Input placeholder="Email" value={filters.email} onChange={(e) => setFilters({ ...filters, email: e.target.value })} />
            <Select value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })}>
              <option value="">Any status</option>
              <option value="active">Active</option>
              <option value="pending_verification">Pending verification</option>
              <option value="suspended">Suspended</option>
            </Select>
            <div className="flex gap-2">
              <Button type="submit" variant="outline">Apply filters</Button>
              {hasActiveFilters && (
                <Button type="button" variant="secondary" onClick={clearFilters}>Clear</Button>
              )}
            </div>
          </form>
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>{data?.meta.total ?? '…'} users</CardTitle></CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <tbody><SkeletonTableRows columns={6} /></tbody>
              </table>
            </div>
          ) : !data?.users.length ? (
            <p className="text-sm text-muted-foreground">No users match these filters.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-muted-foreground">
                    <th className="pb-2 font-medium">Name</th>
                    <th className="pb-2 font-medium">Email</th>
                    <th className="pb-2 font-medium">Status</th>
                    <th className="pb-2 font-medium">Verified</th>
                    <th className="pb-2 font-medium">Joined</th>
                    <th className="pb-2 font-medium text-right">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {data.users.map((u) => (
                    <tr key={u.id} className="border-b border-border last:border-0">
                      <td className="py-2">{u.name}</td>
                      <td className="py-2 text-muted-foreground">{u.email}</td>
                      <td className="py-2"><Badge variant={STATUS_VARIANT[u.status] ?? 'neutral'}>{u.status.replace('_', ' ')}</Badge></td>
                      <td className="py-2">{u.email_verified_at ? 'Yes' : 'No'}</td>
                      <td className="py-2 text-muted-foreground">{formatDate(u.created_at)}</td>
                      <td className="py-2">
                        <div className="flex justify-end gap-2">
                          {canViewChat && (
                            <Link href={`/admin/users/${u.id}/chat`} title="View chat history">
                              <Button variant="outline" className="px-2 py-1.5"><MessagesSquare className="h-3.5 w-3.5" /></Button>
                            </Link>
                          )}
                          {canSuspend && (
                            <Button
                              variant={u.status === 'suspended' ? 'outline' : 'destructive'}
                              className="px-2.5 py-1.5 text-xs"
                              disabled={toggleSuspend.isPending}
                              onClick={() => toggleSuspend.mutate({ id: u.id, suspend: u.status !== 'suspended' })}
                            >
                              {u.status === 'suspended' ? 'Unsuspend' : 'Suspend'}
                            </Button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              <Pagination currentPage={data.meta.current_page} lastPage={data.meta.last_page} total={data.meta.total} onPageChange={setPage} />
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
