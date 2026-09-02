'use client'

import { useQuery } from '@tanstack/react-query'
import { CheckCircle2, CreditCard, DollarSign, Users as UsersIcon, Wallet as WalletIcon } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Skeleton, SkeletonKpiCard, SkeletonChartCard } from '@/components/ui/Skeleton'
import { KpiCard, BarRow, DotRow } from '@/components/admin/DashboardWidgets'
import apiClient from '@/lib/api-client'
import { formatCurrency } from '@/lib/utils'
import type {
  AiAdminDashboard,
  AuthAdminDashboard,
  PaymentAdminDashboard,
  SubscriptionAdminDashboard,
  WalletAdminDashboard,
} from '@/types'

const HEALTH_VARIANT: Record<string, 'success' | 'warning' | 'destructive'> = {
  closed: 'success',
  half_open: 'warning',
  open: 'destructive',
}

export default function AdminDashboardPage() {
  const { data: authStats, isLoading: authLoading } = useQuery({
    queryKey: ['admin', 'dashboard', 'auth'],
    queryFn: async () => (await apiClient.get<AuthAdminDashboard>('/api/v1/auth/admin/dashboard')).data,
  })
  const { data: subStats, isLoading: subLoading } = useQuery({
    queryKey: ['admin', 'dashboard', 'subscription'],
    queryFn: async () => (await apiClient.get<SubscriptionAdminDashboard>('/api/v1/subscription/admin/dashboard')).data,
  })
  const { data: payStats, isLoading: payLoading } = useQuery({
    queryKey: ['admin', 'dashboard', 'payment'],
    queryFn: async () => (await apiClient.get<PaymentAdminDashboard>('/api/v1/transactions/admin/dashboard')).data,
  })
  const { data: walletStats, isLoading: walletLoading } = useQuery({
    queryKey: ['admin', 'dashboard', 'wallet'],
    queryFn: async () => (await apiClient.get<WalletAdminDashboard>('/api/v1/wallet/admin/dashboard')).data,
  })
  const { data: aiStats, isLoading: aiLoading } = useQuery({
    queryKey: ['admin', 'dashboard', 'ai'],
    queryFn: async () => (await apiClient.get<AiAdminDashboard>('/api/v1/models/admin/dashboard')).data,
  })

  const planEntries = subStats ? Object.entries(subStats.plan_breakdown) : []
  const planMax = Math.max(1, ...planEntries.map(([, v]) => v))
  const gatewayMax = Math.max(1, ...(payStats?.gateway_breakdown.map((g) => g.count) ?? []))
  const providerEntries = aiStats ? Object.entries(aiStats.provider_breakdown) : []
  const providerMax = Math.max(1, ...providerEntries.map(([, v]) => v.requests))

  const allProvidersHealthy = !!aiStats?.provider_health.length && aiStats.provider_health.every((e) => e.state === 'closed')

  return (
    <div className="max-w-6xl space-y-5">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Dashboard</h1>
        <p className="mt-1 text-sm text-muted-foreground">Platform overview across every service.</p>
      </div>

      {/* Row 1 — KPI hero cards */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {authLoading ? (
          <SkeletonKpiCard />
        ) : authStats ? (
          <KpiCard
            label="Total users" value={authStats.total_users} icon={UsersIcon} tone="primary" status="ok"
            trend={authStats.new_registrations_7d > 0
              ? { direction: 'up', label: `+${authStats.new_registrations_7d} new` }
              : { direction: 'flat', label: '0 new' }}
            secondary={`${authStats.active_users} active`}
          />
        ) : null}

        {subLoading ? (
          <SkeletonKpiCard />
        ) : subStats ? (
          <KpiCard
            label="Active subscriptions" value={subStats.active_subscriptions} icon={CreditCard}
            tone={subStats.past_due_subscriptions > 0 ? 'warning' : 'success'}
            status={subStats.past_due_subscriptions > 0 ? 'watch' : 'ok'}
            trend={{ direction: 'flat', label: `${subStats.past_due_subscriptions} past due` }}
            secondary={`${planEntries.length} tier${planEntries.length === 1 ? '' : 's'}`}
          />
        ) : null}

        {payLoading ? (
          <SkeletonKpiCard />
        ) : payStats ? (
          <KpiCard
            label="Total revenue" value={formatCurrency(payStats.total_revenue)} icon={DollarSign}
            tone={payStats.failed_count > 0 ? 'warning' : 'success'}
            status={payStats.failed_count > 0 ? 'watch' : 'ok'}
            trend={payStats.failed_count > 0
              ? { direction: 'down', label: `${payStats.failed_count} failed` }
              : { direction: 'flat', label: '0 failed' }}
            secondary={`${payStats.completed_count} completed`}
          />
        ) : null}

        {walletLoading ? (
          <SkeletonKpiCard />
        ) : walletStats ? (
          <KpiCard
            label="Wallet balance" value={formatCurrency(walletStats.total_balance)} icon={WalletIcon} tone="info" status="ok"
            trend={{ direction: 'flat', label: `${formatCurrency(walletStats.deposits_30d)} in` }}
            secondary={`${formatCurrency(walletStats.withdrawals_30d)} out (30d)`}
          />
        ) : null}
      </div>

      {/* Row 2 — breakdown detail cards */}
      <div className="grid gap-4 lg:grid-cols-3">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
            <CardTitle className="text-sm">Subscriptions by tier</CardTitle>
            <span className="text-xs text-muted-foreground">{subStats?.active_subscriptions ?? '…'} active</span>
          </CardHeader>
          <CardContent className="space-y-3">
            {subLoading ? (
              <SkeletonChartCard barRows={3} />
            ) : planEntries.length === 0 ? (
              <p className="text-sm text-muted-foreground">No active subscriptions yet.</p>
            ) : (
              planEntries.map(([plan, count], i) => <BarRow key={plan} name={plan} value={count} max={planMax} index={i} />)
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
            <CardTitle className="text-sm">Transaction status</CardTitle>
            <span className="text-xs text-muted-foreground">
              {payStats ? payStats.completed_count + payStats.failed_count + payStats.pending_count : '…'} total
            </span>
          </CardHeader>
          <CardContent>
            {payLoading ? (
              <div className="space-y-2">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-6 w-full" />)}</div>
            ) : payStats ? (
              <>
                <DotRow name="Completed" value={payStats.completed_count} tone="success" />
                <DotRow name="Pending" value={payStats.pending_count} tone="warning" />
                <DotRow name="Failed" value={payStats.failed_count} tone="destructive" />
              </>
            ) : null}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
            <CardTitle className="text-sm">Payment gateway</CardTitle>
            <span className="text-xs text-muted-foreground">{payStats?.completed_count ?? '…'} completed</span>
          </CardHeader>
          <CardContent className="space-y-3">
            {payLoading ? (
              <SkeletonChartCard barRows={2} />
            ) : !payStats?.gateway_breakdown.length ? (
              <p className="text-sm text-muted-foreground">No completed transactions yet.</p>
            ) : (
              payStats.gateway_breakdown.map((g, i) => <BarRow key={g.gateway} name={g.gateway} value={g.count} max={gatewayMax} index={i} />)
            )}
          </CardContent>
        </Card>
      </div>

      {/* Row 3 — AI usage + provider health */}
      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
            <CardTitle className="text-sm">AI usage (7d)</CardTitle>
            <span className="text-xs text-muted-foreground">{aiStats?.total_tokens_7d.toLocaleString() ?? '…'} tokens</span>
          </CardHeader>
          <CardContent>
            {aiLoading ? (
              <div className="space-y-2">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-6 w-full" />)}</div>
            ) : aiStats ? (
              <>
                <DotRow name="Failed requests" value={aiStats.failed_requests_7d} tone={aiStats.failed_requests_7d > 0 ? 'destructive' : 'success'} />
                <DotRow name="Total cost" value={formatCurrency(aiStats.total_cost_7d)} tone="info" />
                {providerEntries.length > 0 && (
                  <div className="mt-3 space-y-3 border-t border-border pt-3">
                    {providerEntries.map(([provider, stats], i) => (
                      <BarRow key={provider} name={provider} value={stats.requests} max={providerMax} index={i} />
                    ))}
                  </div>
                )}
              </>
            ) : null}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm">Provider health</CardTitle>
          </CardHeader>
          <CardContent>
            {aiLoading ? (
              <div className="space-y-2">
                {Array.from({ length: 3 }).map((_, i) => (
                  <div key={i} className="flex items-center justify-between">
                    <Skeleton className="h-4 w-32" />
                    <Skeleton className="h-5 w-16 rounded-full" />
                  </div>
                ))}
              </div>
            ) : !aiStats?.provider_health.length ? (
              <div className="flex items-center gap-2 rounded-md border border-dashed border-border bg-muted/50 px-3 py-2.5 text-xs text-muted-foreground">
                No circuit-breaker activity recorded in the last 7 days.
              </div>
            ) : allProvidersHealthy ? (
              <div className="flex flex-wrap gap-2">
                {aiStats.provider_health.map((entry, i) => (
                  <span key={i} className="inline-flex items-center gap-1.5 rounded-full bg-success-soft px-2.5 py-1 text-xs font-semibold text-success">
                    <CheckCircle2 className="h-3 w-3" />
                    {entry.model ?? entry.provider ?? 'Unknown'}
                  </span>
                ))}
              </div>
            ) : (
              <div className="space-y-2">
                {aiStats.provider_health.map((entry, i) => (
                  <div key={i} className="flex items-center justify-between text-sm">
                    <span>{entry.model ?? 'Unknown model'}</span>
                    <Badge variant={HEALTH_VARIANT[entry.state] ?? 'neutral'}>{entry.state}</Badge>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
