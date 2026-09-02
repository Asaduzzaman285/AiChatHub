import { clsx, type ClassValue } from 'clsx'
import { twMerge } from 'tailwind-merge'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

export function formatCurrency(amount: number | string, currency = 'USD'): string {
  const value = typeof amount === 'string' ? parseFloat(amount) : amount
  return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(value)
}

/** AI usage costs are fractions of a cent — Intl's 2-decimal rounding collapses
 * them to $0.00. Shows the full 6-decimal figure matching the database's
 * decimal(12,6) wallet/cost columns instead of rounding it away. */
export function formatPreciseCurrency(amount: number | string, currency = 'USD'): string {
  const value = typeof amount === 'string' ? parseFloat(amount) : amount
  const symbol = currency === 'USD' ? '$' : `${currency} `
  return `${symbol}${value.toFixed(6)}`
}

export function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

export function formatNumber(n: number | string): string {
  const value = typeof n === 'string' ? parseFloat(n) : n
  return new Intl.NumberFormat('en-US').format(value)
}

/** Shared formatting for every place tokens/cost show up (single chat messages, live
 * compare cards, persisted compare cards, aggregate totals) — one format, not several
 * slightly-different ones. Returns null when there's nothing real to show yet (e.g. a
 * still-streaming message, or a user turn, which never has usage data). `cost` accepts
 * string|number because it arrives as both depending on path: the REST API
 * (ChatMessage.cost) serializes Laravel's decimal:6 cast as a JSON STRING
 * ("0.016709"); the live SSE compare stream sends a genuine PHP float. Reuses
 * formatPreciseCurrency, same as every other cost display in the app (Wallet/Usage/
 * Profile views), instead of a one-off .toFixed() that doesn't handle the string case. */
export function formatUsage(promptTokens?: number | null, completionTokens?: number | null, cost?: string | number | null): string | null {
  // Token counts commented out (not removed) at explicit request — the same
  // cost-only treatment already applied to single-chat messages, now extended here
  // too (this function's only remaining callers are compare cards and their
  // aggregate total line).
  // const parts: string[] = []
  // if (promptTokens != null && completionTokens != null) {
  //   parts.push(`${promptTokens.toLocaleString()} in · ${completionTokens.toLocaleString()} out`)
  // }
  // if (cost != null) {
  //   parts.push(formatPreciseCurrency(cost))
  // }
  // return parts.length ? parts.join(' · ') : null
  return cost != null ? formatPreciseCurrency(cost) : null
}
