import { useState, type FormEvent } from 'react'

/**
 * Extracts the filter-form pattern repeated identically across every admin list
 * page: a draft `filters` object (edited live by inputs) and a committed `applied`
 * object (only updated on submit — this is what actually drives the useQuery key,
 * so resetting `filters` alone would leave stale results on screen).
 */
export function useListFilters<T extends object>(defaults: T) {
  const [filters, setFilters] = useState<T>(defaults)
  const [applied, setApplied] = useState<T>(defaults)
  const [page, setPage] = useState(1)

  const applyFilters = (e: FormEvent) => {
    e.preventDefault()
    setPage(1)
    setApplied(filters)
  }

  const clearFilters = () => {
    setFilters(defaults)
    setApplied(defaults)
    setPage(1)
  }

  // Cast is safe — every field on every Filters shape used with this hook is a string.
  const hasActiveFilters = Object.values(applied as Record<string, unknown>).some((v) => v !== '')

  return { filters, setFilters, applied, page, setPage, applyFilters, clearFilters, hasActiveFilters }
}
