type QueryParams = Record<string, string | number | boolean | undefined | null>

/** Strips undefined/null/empty-string values, URL-encodes the rest. No existing helper does this — every admin list endpoint needs it for filters. */
export function buildQueryString(params: QueryParams): string {
  const entries = Object.entries(params).filter(
    ([, value]) => value !== undefined && value !== null && value !== ''
  )
  if (entries.length === 0) return ''

  const search = new URLSearchParams()
  for (const [key, value] of entries) {
    search.set(key, String(value))
  }
  return `?${search.toString()}`
}
