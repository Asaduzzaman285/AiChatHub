'use client'

import { useQuery } from '@tanstack/react-query'
import { ArrowUp } from 'lucide-react'
import apiClient from '@/lib/api-client'
import { formatCurrency } from '@/lib/utils'
import type { WalletBalance } from '@/types'

/** Same query key WalletView.tsx already uses — shares its cache instead of a second
 * independent fetch. */
export function WalletBalanceChip() {
  const { data: wallet } = useQuery({
    queryKey: ['wallet', 'balance'],
    queryFn: async () => (await apiClient.get<WalletBalance>('/api/v1/wallet')).data,
  })

  return (
    <div className="flex shrink-0 items-center gap-1 rounded-full bg-accent px-2.5 py-1 text-xs font-medium text-muted-foreground">
      <ArrowUp className="h-3 w-3" />
      {wallet ? formatCurrency(wallet.available_balance) : '—'}
    </div>
  )
}
