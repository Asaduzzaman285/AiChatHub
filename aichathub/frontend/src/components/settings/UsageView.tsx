'use client'

import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Skeleton, SkeletonTableRows } from '@/components/ui/Skeleton'
import apiClient from '@/lib/api-client'
import { cn, formatPreciseCurrency, formatDate, formatNumber } from '@/lib/utils'
import type { UsageLogEntry, UsageSummary } from '@/types'

const PERIODS = [
  { id: '7d', label: '7 days' },
  { id: '30d', label: '30 days' },
  { id: 'all', label: 'All time' },
] as const

/** Customer-facing counterpart to the admin usage-logs view — per-model token/cost
 * breakdown for the signed-in user only. There's no per-model token quota in this
 * system (spending is one shared USD wallet), so this shows consumption, not a
 * "remaining" balance. */
export function UsageView() {
  const [period, setPeriod] = useState<(typeof PERIODS)[number]['id']>('30d')

  const { data: summary, isLoading: summaryLoading } = useQuery({
    queryKey: ['usage', 'summary', period],
    queryFn: async () => (await apiClient.get<UsageSummary>('/api/v1/models/usage/summary', { params: { period } })).data,
  })

  const { data: recent, isLoading: recentLoading } = useQuery({
    queryKey: ['usage', 'recent'],
    queryFn: async () => (await apiClient.get<{ usage_logs: UsageLogEntry[] }>('/api/v1/models/usage', { params: { per_page: 10 } })).data.usage_logs,
  })

  return (
    <div className="space-y-6">
      <div className="flex gap-1 rounded-md bg-muted/30 p-1 w-fit">
        {PERIODS.map((p) => (
          <button
            key={p.id}
            onClick={() => setPeriod(p.id)}
            className={cn(
              'rounded px-3 py-1.5 text-sm font-medium transition-colors',
              period === p.id ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-accent'
            )}
          >
            {p.label}
          </button>
        ))}
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Total tokens</CardTitle>
          </CardHeader>
          <CardContent>
            {summaryLoading ? (
              <Skeleton className="h-9 w-32" />
            ) : (
              <p className="text-3xl font-bold">{formatNumber(summary?.totals.total_tokens ?? 0)}</p>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Total spend</CardTitle>
          </CardHeader>
          <CardContent>
            {summaryLoading ? (
              <Skeleton className="h-9 w-32" />
            ) : (
              <p className="text-3xl font-bold">{formatPreciseCurrency(summary?.totals.cost ?? 0)}</p>
            )}
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Usage by model</CardTitle>
        </CardHeader>
        <CardContent>
          {summaryLoading ? (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <tbody><SkeletonTableRows columns={5} /></tbody>
              </table>
            </div>
          ) : !summary?.models.length ? (
            <p className="text-sm text-muted-foreground">No usage in this period.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-muted-foreground">
                    <th className="py-2 pr-4 font-medium">Model</th>
                    <th className="px-4 py-2 font-medium">Provider</th>
                    <th className="px-4 py-2 font-medium text-right">Prompt tokens</th>
                    <th className="px-4 py-2 font-medium text-right">Completion tokens</th>
                    <th className="px-4 py-2 font-medium text-right">Total tokens</th>
                    <th className="py-2 pl-4 font-medium text-right">Cost</th>
                  </tr>
                </thead>
                <tbody>
                  {summary.models.map((m) => (
                    <tr key={m.model_id} className="border-b border-border last:border-0">
                      <td className="py-2.5 pr-4 font-medium">{m.name}</td>
                      <td className="px-4 py-2.5 capitalize text-muted-foreground">{m.provider}</td>
                      <td className="px-4 py-2.5 text-right tabular-nums">{formatNumber(m.prompt_tokens)}</td>
                      <td className="px-4 py-2.5 text-right tabular-nums">{formatNumber(m.completion_tokens)}</td>
                      <td className="px-4 py-2.5 text-right tabular-nums">{formatNumber(m.total_tokens)}</td>
                      <td className="py-2.5 pl-4 text-right tabular-nums">{formatPreciseCurrency(m.cost)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Recent activity</CardTitle>
        </CardHeader>
        <CardContent>
          {recentLoading ? (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <tbody><SkeletonTableRows columns={5} /></tbody>
              </table>
            </div>
          ) : !recent?.length ? (
            <p className="text-sm text-muted-foreground">No activity yet.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-muted-foreground">
                    <th className="py-2 pr-4 font-medium">Date</th>
                    <th className="px-4 py-2 font-medium">Model</th>
                    <th className="px-4 py-2 font-medium">Type</th>
                    <th className="px-4 py-2 font-medium text-right">Tokens</th>
                    <th className="py-2 pl-4 font-medium text-right">Cost</th>
                  </tr>
                </thead>
                <tbody>
                  {recent.map((log) => (
                    <tr key={log.id} className="border-b border-border last:border-0">
                      <td className="py-2.5 pr-4">{formatDate(log.created_at)}</td>
                      <td className="px-4 py-2.5">{log.model?.name ?? '—'}</td>
                      <td className="px-4 py-2.5 capitalize text-muted-foreground">{log.operation_type.replace('_', ' ')}</td>
                      <td className="px-4 py-2.5 text-right tabular-nums">{formatNumber(log.total_tokens)}</td>
                      <td className="py-2.5 pl-4 text-right tabular-nums">{formatPreciseCurrency(log.actual_cost)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
