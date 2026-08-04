'use client'

import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Label } from '@/components/ui/Label'
import { Pagination } from '@/components/ui/Pagination'
import { SkeletonTableRows } from '@/components/ui/Skeleton'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogTrigger } from '@/components/ui/Dialog'
import apiClient from '@/lib/api-client'
import { formatCurrency, formatDate } from '@/lib/utils'
import { describeError } from '@/lib/errors'
import { buildQueryString } from '@/lib/query-string'
import { hasPermission } from '@/lib/permissions'
import { useAuthStore } from '@/stores/auth-store'
import { useListFilters } from '@/hooks/useListFilters'
import type { AdminMeta, LedgerEntry } from '@/types'

interface Filters {
  user_id: string
  type: string
}

function AdjustBalanceDialog() {
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [userId, setUserId] = useState('')
  const [direction, setDirection] = useState<'credit' | 'debit'>('credit')
  const [amount, setAmount] = useState('')
  const [description, setDescription] = useState('')

  const adjust = useMutation({
    mutationFn: async () =>
      apiClient.post(`/api/v1/wallet/admin/${userId}/adjust`, { direction, amount: parseFloat(amount), description }),
    onSuccess: () => {
      toast.success('Wallet adjusted.')
      queryClient.invalidateQueries({ queryKey: ['admin', 'wallet-ledger'] })
      setOpen(false)
      setUserId('')
      setAmount('')
      setDescription('')
    },
    onError: (err: unknown) => toast.error(describeError(err, "We didn't hear back in time — check the wallet balance before trying again.").message),
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const value = parseFloat(amount)
    if (!userId || !value || value <= 0 || !description) {
      toast.error('Please fill in a user ID, a positive amount, and a reason before continuing.')
      return
    }
    adjust.mutate()
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button>Adjust balance</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Adjust wallet balance</DialogTitle>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="adjust-user">User ID</Label>
            <Input id="adjust-user" required value={userId} onChange={(e) => setUserId(e.target.value)} placeholder="uuid" />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="adjust-direction">Direction</Label>
              <Select id="adjust-direction" value={direction} onChange={(e) => setDirection(e.target.value as 'credit' | 'debit')}>
                <option value="credit">Credit (add)</option>
                <option value="debit">Debit (remove)</option>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="adjust-amount">Amount (USD)</Label>
              <Input id="adjust-amount" type="number" min="0.01" step="0.01" required value={amount} onChange={(e) => setAmount(e.target.value)} />
            </div>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="adjust-description">Reason</Label>
            <Input id="adjust-description" required value={description} onChange={(e) => setDescription(e.target.value)} placeholder="e.g. goodwill credit for outage" />
          </div>
          <DialogFooter>
            <Button type="submit" disabled={adjust.isPending}>{adjust.isPending ? 'Adjusting…' : 'Apply'}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

export default function AdminWalletPage() {
  const { user: currentAdmin } = useAuthStore()
  const canAdjust = hasPermission(currentAdmin, 'wallet.adjust')

  const { filters, setFilters, applied, page, setPage, applyFilters, clearFilters, hasActiveFilters } =
    useListFilters<Filters>({ user_id: '', type: '' })

  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'wallet-ledger', applied, page],
    queryFn: async () => {
      const qs = buildQueryString({ ...applied, page, per_page: 20 })
      return (await apiClient.get<{ ledger_entries: LedgerEntry[]; meta: AdminMeta }>(`/api/v1/wallet/admin/ledger${qs}`)).data
    },
  })

  return (
    <div className="max-w-5xl space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Wallet ledger</h1>
          <p className="mt-1 text-sm text-muted-foreground">Every credit, debit, and manual adjustment across all wallets.</p>
        </div>
        {canAdjust && <AdjustBalanceDialog />}
      </div>

      <Card>
        <CardContent className="pt-6">
          <form onSubmit={applyFilters} className="grid gap-3 sm:grid-cols-3">
            <Input placeholder="User ID" value={filters.user_id} onChange={(e) => setFilters({ ...filters, user_id: e.target.value })} />
            <Select value={filters.type} onChange={(e) => setFilters({ ...filters, type: e.target.value })}>
              <option value="">Any type</option>
              <option value="credit">Credit</option>
              <option value="debit">Debit</option>
              <option value="refund">Refund</option>
              <option value="admin_adjustment">Admin adjustment</option>
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
        <CardHeader><CardTitle>{data?.meta.total ?? '…'} entries</CardTitle></CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <tbody><SkeletonTableRows columns={6} /></tbody>
              </table>
            </div>
          ) : !data?.ledger_entries.length ? (
            <p className="text-sm text-muted-foreground">No ledger entries match these filters.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-muted-foreground">
                    <th className="pb-2 font-medium">User</th>
                    <th className="pb-2 font-medium">Type</th>
                    <th className="pb-2 font-medium">Description</th>
                    <th className="pb-2 font-medium text-right">Amount</th>
                    <th className="pb-2 font-medium text-right">Balance after</th>
                    <th className="pb-2 font-medium">Date</th>
                  </tr>
                </thead>
                <tbody>
                  {data.ledger_entries.map((entry) => (
                    <tr key={entry.id} className="border-b border-border last:border-0">
                      <td className="py-2 font-mono text-xs text-muted-foreground">{entry.user_id}</td>
                      <td className="py-2 capitalize">{entry.type.replace('_', ' ')}</td>
                      <td className="py-2 text-muted-foreground">{entry.description}</td>
                      <td className={`py-2 text-right ${entry.type === 'credit' || entry.type === 'refund' || entry.type === 'admin_adjustment' ? 'text-green-600' : 'text-destructive'}`}>
                        {formatCurrency(entry.amount, entry.currency)}
                      </td>
                      <td className="py-2 text-right">{formatCurrency(entry.balance_after, entry.currency)}</td>
                      <td className="py-2 text-muted-foreground">{formatDate(entry.created_at)}</td>
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
