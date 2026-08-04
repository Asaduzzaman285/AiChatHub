'use client'

import { useQuery } from '@tanstack/react-query'
import { Bar, BarChart, Cell, LabelList, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Skeleton, SkeletonChartCard, SkeletonStat } from '@/components/ui/Skeleton'
import apiClient from '@/lib/api-client'
import { formatCurrency } from '@/lib/utils'
import type {
  AiAdminDashboard,
  AuthAdminDashboard,
  PaymentAdminDashboard,
  SubscriptionAdminDashboard,
  WalletAdminDashboard,
} from '@/types'

function Stat({ label, value }: { label: string; value: string | number }) {
  return (
    <div>
      <p className="text-2xl font-bold">{value}</p>
      <p className="text-sm text-muted-foreground">{label}</p>
    </div>
  )
}

const CHART_TOOLTIP_STYLE = {
  backgroundColor: 'hsl(var(--card))',
  borderColor: 'hsl(var(--border))',
  borderRadius: 'var(--radius)',
  boxShadow: '0 4px 16px hsl(var(--foreground) / 0.08)',
  fontSize: 12,
  padding: '6px 10px',
}

// A curated multi-hue palette (not just --primary repeated) so a breakdown with several
// categories reads as intentionally colorful, cycling if there are more rows than hues.
const CHART_COLORS = ['hsl(var(--chart-1))', 'hsl(var(--chart-2))', 'hsl(var(--chart-3))', 'hsl(var(--chart-4))', 'hsl(var(--chart-5))']

function BreakdownChart({ data, barKey = 'value' }: { data: { name: string; value: number }[]; barKey?: string }) {
  return (
    <ResponsiveContainer width="100%" height={Math.max(120, data.length * 40)}>
      <BarChart data={data} layout="vertical" margin={{ left: 0, right: 28, top: 4, bottom: 4 }} barCategoryGap={10}>
        <XAxis type="number" hide />
        <YAxis
          type="category"
          dataKey="name"
          width={90}
          tick={{ fontSize: 12, fontWeight: 500, fill: 'hsl(var(--foreground))' }}
          axisLine={false}
          tickLine={false}
        />
        <Tooltip contentStyle={CHART_TOOLTIP_STYLE} cursor={{ fill: 'hsl(var(--muted) / 0.5)' }} />
        <Bar dataKey={barKey} radius={[8, 8, 8, 8]} maxBarSize={16}>
          {data.map((_, i) => <Cell key={i} fill={CHART_COLORS[i % CHART_COLORS.length]} />)}
          <LabelList
            dataKey={barKey}
            position="right"
            style={{ fontSize: 12, fontWeight: 600, fill: 'hsl(var(--foreground))' }}
          />
        </Bar>
      </BarChart>
    </ResponsiveContainer>
  )
}

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

  return (
    <div className="max-w-5xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Dashboard</h1>
        <p className="mt-1 text-sm text-muted-foreground">Platform overview across every service.</p>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <Card>
          <CardHeader><CardTitle>Users</CardTitle></CardHeader>
          <CardContent>
            {authLoading ? (
              <div className="grid grid-cols-2 gap-4">
                {Array.from({ length: 4 }).map((_, i) => <SkeletonStat key={i} />)}
              </div>
            ) : authStats ? (
              <div className="grid grid-cols-2 gap-4">
                <Stat label="Total users" value={authStats.total_users} />
                <Stat label="Active" value={authStats.active_users} />
                <Stat label="Suspended" value={authStats.suspended_users} />
                <Stat label="New (7d)" value={authStats.new_registrations_7d} />
              </div>
            ) : null}
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>Subscriptions</CardTitle></CardHeader>
          <CardContent>
            {subLoading ? (
              <div className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <SkeletonStat /><SkeletonStat />
                </div>
                <SkeletonChartCard barRows={3} />
              </div>
            ) : subStats ? (
              <div className="space-y-3">
                <div className="grid grid-cols-2 gap-4">
                  <Stat label="Active" value={subStats.active_subscriptions} />
                  <Stat label="Past due" value={subStats.past_due_subscriptions} />
                </div>
                {Object.entries(subStats.plan_breakdown).length > 0 && (
                  <BreakdownChart
                    data={Object.entries(subStats.plan_breakdown).map(([plan, count]) => ({ name: plan, value: count }))}
                  />
                )}
              </div>
            ) : null}
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>Revenue</CardTitle></CardHeader>
          <CardContent>
            {payLoading ? (
              <div className="space-y-4">
                <SkeletonStat />
                <div className="grid grid-cols-3 gap-2">
                  <SkeletonStat /><SkeletonStat /><SkeletonStat />
                </div>
                <SkeletonChartCard barRows={3} />
              </div>
            ) : payStats ? (
              <div className="space-y-3">
                <Stat label="Total revenue" value={formatCurrency(payStats.total_revenue)} />
                <div className="grid grid-cols-3 gap-2 text-sm">
                  <Stat label="Completed" value={payStats.completed_count} />
                  <Stat label="Failed" value={payStats.failed_count} />
                  <Stat label="Pending" value={payStats.pending_count} />
                </div>
                {payStats.gateway_breakdown.length > 0 && (
                  <BreakdownChart
                    data={payStats.gateway_breakdown.map((g) => ({ name: g.gateway, value: g.count }))}
                  />
                )}
              </div>
            ) : null}
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>Wallet</CardTitle></CardHeader>
          <CardContent>
            {walletLoading ? (
              <div className="grid grid-cols-2 gap-4">
                {Array.from({ length: 4 }).map((_, i) => <SkeletonStat key={i} />)}
              </div>
            ) : walletStats ? (
              <div className="grid grid-cols-2 gap-4">
                <Stat label="Total balance" value={formatCurrency(walletStats.total_balance)} />
                <Stat label="Credit owed" value={formatCurrency(Math.abs(walletStats.total_credit_owed))} />
                <Stat label="Deposits (30d)" value={formatCurrency(walletStats.deposits_30d)} />
                <Stat label="Withdrawals (30d)" value={formatCurrency(walletStats.withdrawals_30d)} />
              </div>
            ) : null}
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>AI Usage (7d)</CardTitle></CardHeader>
          <CardContent>
            {aiLoading ? (
              <div className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <SkeletonStat /><SkeletonStat />
                </div>
                <SkeletonChartCard barRows={3} />
              </div>
            ) : aiStats ? (
              <div className="space-y-3">
                <div className="grid grid-cols-2 gap-4">
                  <Stat label="Tokens" value={aiStats.total_tokens_7d.toLocaleString()} />
                  <Stat label="Failed requests" value={aiStats.failed_requests_7d} />
                </div>
                {Object.entries(aiStats.provider_breakdown).length > 0 && (
                  <BreakdownChart
                    data={Object.entries(aiStats.provider_breakdown).map(([provider, stats]) => ({
                      name: provider,
                      value: stats.requests,
                    }))}
                  />
                )}
              </div>
            ) : null}
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>Provider health</CardTitle></CardHeader>
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
              <p className="text-sm text-muted-foreground">No circuit-breaker activity recorded.</p>
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
