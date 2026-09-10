import { clsx, type ClassValue } from 'clsx'
import { twMerge } from 'tailwind-merge'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

/**
 * Forces a real "Save As" download instead of a browser navigation. A plain
 * `<a href={crossOriginUrl} download>` only forces a save prompt reliably for
 * same-origin links — confirmed live: attachment storage_urls are R2-signed URLs
 * on a different origin than the app, and clicking those links was opening a new
 * tab instead of downloading (a Content-Disposition header from R2 would fix it
 * server-side too, but this works regardless of what the storage response sends).
 * Fetches the bytes as a blob and downloads from a same-origin blob: URL instead,
 * which every browser honors unconditionally.
 */
export async function downloadFile(url: string, filename: string): Promise<void> {
  try {
    const response = await fetch(url)
    const blob = await response.blob()
    const blobUrl = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = blobUrl
    a.download = filename
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(blobUrl)
  } catch {
    // CORS or network failure — fall back to a plain navigation so the user still
    // gets the file (as a new tab, the old behavior) rather than nothing at all.
    window.open(url, '_blank', 'noopener,noreferrer')
  }
}

// en-US's Intl currency data doesn't reliably carry a native symbol for every
// ISO code — this map only needs to cover currencies the app actually charges
// in. BDT deliberately spells out "BDT" rather than using the "৳" glyph
// (inconsistent font support made it look broken in some places).
const CURRENCY_SYMBOLS: Record<string, string> = { USD: '$', BDT: 'BDT ' }

export function formatCurrency(amount: number | string, currency = 'USD'): string {
  const value = typeof amount === 'string' ? parseFloat(amount) : amount
  const symbol = CURRENCY_SYMBOLS[currency]
  if (symbol) {
    return `${symbol}${new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)}`
  }
  return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(value)
}

/** AI usage costs are fractions of a cent — Intl's 2-decimal rounding collapses
 * them to $0.00. Shows the full 6-decimal figure matching the database's
 * decimal(12,6) wallet/cost columns instead of rounding it away. */
export function formatPreciseCurrency(amount: number | string, currency = 'USD'): string {
  const value = typeof amount === 'string' ? parseFloat(amount) : amount
  const symbol = CURRENCY_SYMBOLS[currency] ?? `${currency} `
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
  // Reversed back at explicit request (2026-09-02) — cost commented out (not
  // removed), token counts shown again. This function's only remaining callers
  // are compare cards and their aggregate total line.
  if (promptTokens != null && completionTokens != null) {
    return `${promptTokens.toLocaleString()} in · ${completionTokens.toLocaleString()} out`
  }
  return null
  // return cost != null ? formatPreciseCurrency(cost) : null
}
