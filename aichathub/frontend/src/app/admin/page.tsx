'use client'

import { useQuery } from '@tanstack/react-query'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
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
              <p className="text-sm text-muted-foreground">Loading…</p>
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
              <p className="text-sm text-muted-foreground">Loading…</p>
            ) : subStats ? (
              <div className="space-y-3">
                <div className="grid grid-cols-2 gap-4">
                  <Stat label="Active" value={subStats.active_subscriptions} />
                  <Stat label="Past due" value={subStats.past_due_subscriptions} />
                </div>
                {Object.entries(subStats.plan_breakdown).length > 0 && (
                  <div className="space-y-1 text-sm">
                    {Object.entries(subStats.plan_breakdown).map(([plan, count]) => (
                      <div key={plan} className="flex justify-between text-muted-foreground">
                        <span>{plan}</span>
                        <span>{count}</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            ) : null}
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>Revenue</CardTitle></CardHeader>
          <CardContent>
            {payLoading ? (
              <p className="text-sm text-muted-foreground">Loading…</p>
            ) : payStats ? (
              <div className="space-y-3">
                <Stat label="Total revenue" value={formatCurrency(payStats.total_revenue)} />
                <div className="grid grid-cols-3 gap-2 text-sm">
                  <Stat label="Completed" value={payStats.completed_count} />
                  <Stat label="Failed" value={payStats.failed_count} />
                  <Stat label="Pending" value={payStats.pending_count} />
                </div>
              </div>
            ) : null}
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>Wallet</CardTitle></CardHeader>
          <CardContent>
            {walletLoading ? (
              <p className="text-sm text-muted-foreground">Loading…</p>
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
              <p className="text-sm text-muted-foreground">Loading…</p>
            ) : aiStats ? (
              <div className="space-y-3">
                <div className="grid grid-cols-2 gap-4">
                  <Stat label="Tokens" value={aiStats.total_tokens_7d.toLocaleString()} />
                  <Stat label="Failed requests" value={aiStats.failed_requests_7d} />
                </div>
                {Object.entries(aiStats.provider_breakdown).length > 0 && (
                  <div className="space-y-1 text-sm">
                    {Object.entries(aiStats.provider_breakdown).map(([provider, stats]) => (
                      <div key={provider} className="flex justify-between text-muted-foreground">
                        <span className="capitalize">{provider}</span>
                        <span>{stats.requests} req · {stats.tokens.toLocaleString()} tok</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            ) : null}
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>Provider health</CardTitle></CardHeader>
          <CardContent>
            {aiLoading ? (
              <p className="text-sm text-muted-foreground">Loading…</p>
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
