'use client'

import { useQuery } from '@tanstack/react-query'
import apiClient from '@/lib/api-client'
import { useAuthStore } from '@/stores/auth-store'
import { formatCurrency, formatPreciseCurrency } from '@/lib/utils'

interface CurrencyRate {
  currency_code: string
  symbol: string
  decimal_places: number
  effective_rate: number
}

/**
 * Converts a real USD figure (wallet balance, a transaction amount, usage
 * spend) into the signed-in user's preferred_currency for DISPLAY only — the
 * underlying stored value is always real USD (see WalletView.tsx's own
 * comment on why the actual balance never gets relabeled into another
 * currency without an actual conversion). Live-rate conversion, not
 * historical: a wallet balance is a live, ongoing figure, so it converts at
 * today's admin-configured rate rather than whatever rate was true when each
 * contributing transaction happened — unlike a package's own fixed BDT
 * sticker price (PricingCard.tsx), which is never derived this way.
 *
 * Falls back to plain USD display whenever preferred_currency is USD, the
 * rate hasn't loaded yet, or no active rate exists for the currency (e.g. the
 * admin hasn't configured BDT yet) — never blocks rendering on this.
 */
export function useDisplayCurrency() {
  const user = useAuthStore((s) => s.user)
  const currency = user?.preferred_currency ?? 'USD'

  const { data: rate } = useQuery({
    queryKey: ['currency-rate', currency],
    queryFn: async () => (await apiClient.get<CurrencyRate>(`/api/v1/subscription/currencies/${currency}/rate`)).data,
    enabled: currency !== 'USD',
    staleTime: 5 * 60_000,
    retry: false,
  })

  const effectiveRate = currency !== 'USD' && rate ? rate.effective_rate : null

  const convert = (amountUsd: number | string): number => {
    const value = typeof amountUsd === 'string' ? parseFloat(amountUsd) : amountUsd
    return effectiveRate ? value * effectiveRate : value
  }

  // Inverse of convert() — for an input where the visitor types an amount in
  // their own currency (the top-up field) and the backend needs real USD.
  const toUsd = (amountInDisplayCurrency: number | string): number => {
    const value = typeof amountInDisplayCurrency === 'string' ? parseFloat(amountInDisplayCurrency) : amountInDisplayCurrency
    return effectiveRate ? value / effectiveRate : value
  }

  const displayCurrency = effectiveRate ? currency : 'USD'
  const symbol = CURRENCY_PREFIXES[displayCurrency] ?? `${displayCurrency} `

  return {
    currency: displayCurrency,
    symbol,
    convert,
    toUsd,
    format: (amountUsd: number | string) => formatCurrency(convert(amountUsd), displayCurrency),
    formatPrecise: (amountUsd: number | string) => formatPreciseCurrency(convert(amountUsd), displayCurrency),
  }
}

// Mirrors utils.ts's own CURRENCY_SYMBOLS map (not exported from there) — just
// the prefix shown next to a bare input field, e.g. the top-up amount box.
const CURRENCY_PREFIXES: Record<string, string> = { USD: '$', BDT: 'BDT' }
