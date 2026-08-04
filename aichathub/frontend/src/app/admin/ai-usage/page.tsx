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
import { formatCurrency, formatDate } from '@/lib/utils'
import { buildQueryString } from '@/lib/query-string'
import { useListFilters } from '@/hooks/useListFilters'
import type { AdminMeta, AdminUsageLog } from '@/types'

const STATUS_VARIANT: Record<string, 'success' | 'warning' | 'destructive' | 'neutral'> = {
  completed: 'success',
  failed: 'destructive',
  refunded: 'neutral',
}

interface Filters {
  user_id: string
  status: string
}

export default function AdminAiUsagePage() {
  const { filters, setFilters, applied, page, setPage, applyFilters, clearFilters, hasActiveFilters } =
    useListFilters<Filters>({ user_id: '', status: '' })

  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'usage-logs', applied, page],
    queryFn: async () => {
      const qs = buildQueryString({ ...applied, page, per_page: 20 })
      return (await apiClient.get<{ usage_logs: AdminUsageLog[]; meta: AdminMeta }>(`/api/v1/models/admin/usage-logs${qs}`)).data
    },
  })

  return (
    <div className="max-w-5xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">AI usage</h1>
        <p className="mt-1 text-sm text-muted-foreground">Per-request token consumption and cost across every user.</p>
      </div>

      <Card>
        <CardContent className="pt-6">
          <form onSubmit={applyFilters} className="grid gap-3 sm:grid-cols-3">
            <Input placeholder="User ID" value={filters.user_id} onChange={(e) => setFilters({ ...filters, user_id: e.target.value })} />
            <Select value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })}>
              <option value="">Any status</option>
              <option value="completed">Completed</option>
              <option value="failed">Failed</option>
              <option value="refunded">Refunded</option>
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
        <CardHeader><CardTitle>{data?.meta.total ?? '…'} requests</CardTitle></CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <tbody><SkeletonTableRows columns={7} /></tbody>
              </table>
            </div>
          ) : !data?.usage_logs.length ? (
            <p className="text-sm text-muted-foreground">No usage logs match these filters.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-muted-foreground">
                    <th className="pb-2 font-medium">User</th>
                    <th className="pb-2 font-medium">Model</th>
                    <th className="pb-2 font-medium">Status</th>
                    <th className="pb-2 font-medium text-right">Tokens</th>
                    <th className="pb-2 font-medium text-right">Cost</th>
                    <th className="pb-2 font-medium text-right">Latency</th>
                    <th className="pb-2 font-medium">Date</th>
                  </tr>
                </thead>
                <tbody>
                  {data.usage_logs.map((log) => (
                    <tr key={log.id} className="border-b border-border last:border-0">
                      <td className="py-2 font-mono text-xs text-muted-foreground">{log.user_id}</td>
                      <td className="py-2">{log.model ? `${log.model.name}` : '—'}</td>
                      <td className="py-2"><Badge variant={STATUS_VARIANT[log.status] ?? 'neutral'}>{log.status}</Badge></td>
                      <td className="py-2 text-right">{log.total_tokens.toLocaleString()}</td>
                      <td className="py-2 text-right">{formatCurrency(log.actual_cost)}</td>
                      <td className="py-2 text-right text-muted-foreground">{log.duration_ms ? `${log.duration_ms}ms` : '—'}</td>
                      <td className="py-2 text-muted-foreground">{formatDate(log.created_at)}</td>
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
