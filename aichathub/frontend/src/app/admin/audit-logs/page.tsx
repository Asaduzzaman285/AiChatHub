'use client'

import { useQuery } from '@tanstack/react-query'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Pagination } from '@/components/ui/Pagination'
import { Skeleton } from '@/components/ui/Skeleton'
import apiClient from '@/lib/api-client'
import { formatDate } from '@/lib/utils'
import { buildQueryString } from '@/lib/query-string'
import { useListFilters } from '@/hooks/useListFilters'
import type { AdminMeta, AuditLogEntry } from '@/types'

interface Filters {
  resource_type: string
  action: string
}

export default function AdminAuditLogsPage() {
  const { filters, setFilters, applied, page, setPage, applyFilters, clearFilters, hasActiveFilters } =
    useListFilters<Filters>({ resource_type: '', action: '' })

  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'audit-logs', applied, page],
    queryFn: async () => {
      const qs = buildQueryString({ ...applied, page, per_page: 20 })
      return (await apiClient.get<{ audit_logs: AuditLogEntry[]; meta: AdminMeta }>(`/api/v1/auth/admin/audit-logs${qs}`)).data
    },
  })

  return (
    <div className="max-w-5xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Audit logs</h1>
        <p className="mt-1 text-sm text-muted-foreground">Every sensitive admin action, with before/after values.</p>
      </div>

      <Card>
        <CardContent className="pt-6">
          <form onSubmit={applyFilters} className="grid gap-3 sm:grid-cols-3">
            <Input placeholder="Resource type (e.g. wallet)" value={filters.resource_type} onChange={(e) => setFilters({ ...filters, resource_type: e.target.value })} />
            <Input placeholder="Action (e.g. wallet.adjusted)" value={filters.action} onChange={(e) => setFilters({ ...filters, action: e.target.value })} />
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
        <CardHeader><CardTitle>{data?.meta.total ?? '…'} entries</CardTitle></CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="space-y-3">
              {Array.from({ length: 5 }).map((_, i) => (
                <div key={i} className="space-y-2 rounded-md border border-border p-3">
                  <Skeleton className="h-4 w-2/3" />
                  <Skeleton className="h-3 w-1/3" />
                </div>
              ))}
            </div>
          ) : !data?.audit_logs.length ? (
            <p className="text-sm text-muted-foreground">No audit log entries match these filters.</p>
          ) : (
            <div className="space-y-3">
              {data.audit_logs.map((log) => (
                <div key={log.id} className="rounded-md border border-border p-3 text-sm">
                  <div className="flex items-center justify-between">
                    <p className="font-medium">
                      {log.action} <span className="font-normal text-muted-foreground">on {log.resource_type}</span>
                    </p>
                    <span className="text-xs text-muted-foreground">{formatDate(log.created_at)}</span>
                  </div>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {log.admin_user ? `${log.admin_user.user.name} (${log.admin_user.role})` : 'Unknown admin'}
                    {log.ip_address && ` · ${log.ip_address}`}
                  </p>
                  {(log.old_values || log.new_values) && (
                    <details className="mt-2">
                      <summary className="cursor-pointer text-xs text-muted-foreground">View changes</summary>
                      <div className="mt-2 grid gap-2 sm:grid-cols-2">
                        <pre className="overflow-x-auto rounded bg-muted p-2 text-xs">{JSON.stringify(log.old_values, null, 2)}</pre>
                        <pre className="overflow-x-auto rounded bg-muted p-2 text-xs">{JSON.stringify(log.new_values, null, 2)}</pre>
                      </div>
                    </details>
                  )}
                </div>
              ))}
              <Pagination currentPage={data.meta.current_page} lastPage={data.meta.last_page} total={data.meta.total} onPageChange={setPage} />
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
