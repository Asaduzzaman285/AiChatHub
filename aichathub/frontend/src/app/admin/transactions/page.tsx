'use client'

import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Badge } from '@/components/ui/Badge'
import { Pagination } from '@/components/ui/Pagination'
import { SkeletonTableRows } from '@/components/ui/Skeleton'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter, DialogTrigger } from '@/components/ui/Dialog'
import apiClient from '@/lib/api-client'
import { formatCurrency, formatDate } from '@/lib/utils'
import { describeError } from '@/lib/errors'
import { buildQueryString } from '@/lib/query-string'
import { hasPermission } from '@/lib/permissions'
import { useAuthStore } from '@/stores/auth-store'
import { useListFilters } from '@/hooks/useListFilters'
import type { AdminMeta, Transaction } from '@/types'

const STATUS_VARIANT: Record<string, 'success' | 'warning' | 'destructive' | 'neutral'> = {
  completed: 'success',
  pending: 'warning',
  failed: 'destructive',
  refunded: 'neutral',
}

interface Filters {
  user_id: string
  status: string
  type: string
  gateway: string
  from: string
  to: string
}

function RefundButton({ transaction }: { transaction: Transaction }) {
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)

  const refund = useMutation({
    mutationFn: async () => apiClient.post(`/api/v1/transactions/${transaction.id}/refund`, {}),
    onSuccess: () => {
      toast.success('Refund processed.')
      queryClient.invalidateQueries({ queryKey: ['admin', 'transactions'] })
      setOpen(false)
    },
    onError: (err: unknown) => toast.error(describeError(err, "We didn't hear back in time — check the transaction status before retrying.").message),
  })

  if (transaction.status !== 'completed') return null

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button variant="destructive" className="px-2.5 py-1.5 text-xs">Refund</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Refund transaction</DialogTitle>
          <DialogDescription>
            This issues a real refund of {formatCurrency(transaction.amount, transaction.currency)} via {transaction.gateway}. This cannot be undone.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
          <Button variant="destructive" disabled={refund.isPending} onClick={() => refund.mutate()}>
            {refund.isPending ? 'Refunding…' : 'Confirm refund'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

export default function AdminTransactionsPage() {
  const { user: currentAdmin } = useAuthStore()
  const canRefund = hasPermission(currentAdmin, 'payments.refund')

  const { filters, setFilters, applied, page, setPage, applyFilters, clearFilters, hasActiveFilters } =
    useListFilters<Filters>({ user_id: '', status: '', type: '', gateway: '', from: '', to: '' })

  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'transactions', applied, page],
    queryFn: async () => {
      const qs = buildQueryString({ ...applied, page, per_page: 20 })
      return (await apiClient.get<{ transactions: Transaction[]; meta: AdminMeta }>(`/api/v1/transactions/admin${qs}`)).data
    },
  })

  return (
    <div className="max-w-5xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Transactions</h1>
        <p className="mt-1 text-sm text-muted-foreground">Every payment, top-up, and refund across all users.</p>
      </div>

      <Card>
        <CardContent className="pt-6">
          <form onSubmit={applyFilters} className="grid items-start gap-3 sm:grid-cols-5">
            <Input placeholder="User ID" value={filters.user_id} onChange={(e) => setFilters({ ...filters, user_id: e.target.value })} />
            <Select value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })}>
              <option value="">Any status</option>
              <option value="completed">Completed</option>
              <option value="pending">Pending</option>
              <option value="failed">Failed</option>
              <option value="refunded">Refunded</option>
            </Select>
            <Select value={filters.type} onChange={(e) => setFilters({ ...filters, type: e.target.value })}>
              <option value="">Any type</option>
              <option value="subscription_purchase">Subscription</option>
              <option value="wallet_topup">Top-up</option>
              <option value="refund">Refund</option>
            </Select>
            <Select value={filters.gateway} onChange={(e) => setFilters({ ...filters, gateway: e.target.value })}>
              <option value="">Any gateway</option>
              <option value="stripe">Stripe</option>
              <option value="bkash">bKash</option>
            </Select>
            <Input type="date" aria-label="Date from" value={filters.from} onChange={(e) => setFilters({ ...filters, from: e.target.value })} />
            <Input type="date" aria-label="Date to" value={filters.to} onChange={(e) => setFilters({ ...filters, to: e.target.value })} />
            <div className="flex gap-2">
              <Button type="submit" variant="outline">Apply filters</Button>
              <Button type="button" variant="secondary" disabled={!hasActiveFilters} onClick={clearFilters}>Clear</Button>
            </div>
          </form>
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>{data?.meta.total ?? '…'} transactions</CardTitle></CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <tbody><SkeletonTableRows columns={canRefund ? 7 : 6} /></tbody>
              </table>
            </div>
          ) : !data?.transactions.length ? (
            <p className="text-sm text-muted-foreground">No transactions match these filters.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-muted-foreground">
                    <th className="pb-2 font-medium">User</th>
                    <th className="pb-2 font-medium">Type</th>
                    <th className="pb-2 font-medium">Gateway</th>
                    <th className="pb-2 font-medium">Status</th>
                    <th className="pb-2 font-medium text-right">Amount</th>
                    <th className="pb-2 font-medium">Date</th>
                    {canRefund && <th className="pb-2 font-medium text-right">Actions</th>}
                  </tr>
                </thead>
                <tbody>
                  {data.transactions.map((t) => (
                    <tr key={t.id} className="border-b border-border last:border-0">
                      <td className="py-2 font-mono text-xs text-muted-foreground">{t.user_id}</td>
                      <td className="py-2 capitalize">{t.type.replace('_', ' ')}</td>
                      <td className="py-2 capitalize">{t.gateway}</td>
                      <td className="py-2"><Badge variant={STATUS_VARIANT[t.status] ?? 'neutral'}>{t.status}</Badge></td>
                      <td className="py-2 text-right">{formatCurrency(t.amount, t.currency)}</td>
                      <td className="py-2 text-muted-foreground">{formatDate(t.created_at)}</td>
                      {canRefund && <td className="py-2 text-right"><RefundButton transaction={t} /></td>}
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
