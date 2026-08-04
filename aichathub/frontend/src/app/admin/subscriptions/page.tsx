'use client'

import { useQuery } from '@tanstack/react-query'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Badge } from '@/components/ui/Badge'
import { Pagination } from '@/components/ui/Pagination'
import { SkeletonTableRows } from '@/components/ui/Skeleton'
import apiClient from '@/lib/api-client'
import { formatDate } from '@/lib/utils'
import { buildQueryString } from '@/lib/query-string'
import { useListFilters } from '@/hooks/useListFilters'
import type { AdminMeta, AdminSubscription } from '@/types'

const STATUS_VARIANT: Record<string, 'success' | 'warning' | 'destructive' | 'neutral'> = {
  active: 'success',
  past_due: 'warning',
  cancelled: 'destructive',
  expired: 'neutral',
}

interface Filters {
  user_id: string
  status: string
  auto_renew: string
}

export default function AdminSubscriptionsPage() {
  const { filters, setFilters, applied, page, setPage, applyFilters, clearFilters, hasActiveFilters } =
    useListFilters<Filters>({ user_id: '', status: '', auto_renew: '' })

  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'subscriptions', applied, page],
    queryFn: async () => {
      const qs = buildQueryString({ ...applied, page, per_page: 20 })
      return (await apiClient.get<{ subscriptions: AdminSubscription[]; meta: AdminMeta }>(`/api/v1/subscription/admin${qs}`)).data
    },
  })

  return (
    <div className="max-w-5xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Subscriptions</h1>
        <p className="mt-1 text-sm text-muted-foreground">Every user&apos;s active and past subscriptions.</p>
      </div>

      <Card>
        <CardContent className="pt-6">
          <form onSubmit={applyFilters} className="grid gap-3 sm:grid-cols-4">
            <Input placeholder="User ID" value={filters.user_id} onChange={(e) => setFilters({ ...filters, user_id: e.target.value })} />
            <Select value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })}>
              <option value="">Any status</option>
              <option value="active">Active</option>
              <option value="past_due">Past due</option>
              <option value="cancelled">Cancelled</option>
              <option value="expired">Expired</option>
            </Select>
            <Select value={filters.auto_renew} onChange={(e) => setFilters({ ...filters, auto_renew: e.target.value })}>
              <option value="">Auto-renew: any</option>
              <option value="true">Auto-renew on</option>
              <option value="false">Auto-renew off</option>
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
        <CardHeader><CardTitle>{data?.meta.total ?? '…'} subscriptions</CardTitle></CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <tbody><SkeletonTableRows columns={6} /></tbody>
              </table>
            </div>
          ) : !data?.subscriptions.length ? (
            <p className="text-sm text-muted-foreground">No subscriptions match these filters.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-muted-foreground">
                    <th className="pb-2 font-medium">User</th>
                    <th className="pb-2 font-medium">Plan</th>
                    <th className="pb-2 font-medium">Status</th>
                    <th className="pb-2 font-medium">Auto-renew</th>
                    <th className="pb-2 font-medium">Renews</th>
                    <th className="pb-2 font-medium">Scheduled</th>
                  </tr>
                </thead>
                <tbody>
                  {data.subscriptions.map((s) => (
                    <tr key={s.id} className="border-b border-border last:border-0">
                      <td className="py-2 font-mono text-xs text-muted-foreground">{s.user_id}</td>
                      <td className="py-2">{s.package?.name ?? '—'}</td>
                      <td className="py-2"><Badge variant={STATUS_VARIANT[s.status] ?? 'neutral'}>{s.status.replace('_', ' ')}</Badge></td>
                      <td className="py-2">{s.auto_renew ? 'Yes' : 'No'}</td>
                      <td className="py-2 text-muted-foreground">{formatDate(s.renews_at)}</td>
                      <td className="py-2 text-muted-foreground">{s.scheduled_package ? `→ ${s.scheduled_package.name}` : '—'}</td>
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
