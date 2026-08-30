'use client'

import { useEffect, useState } from 'react'

const STORAGE_KEY = 'sidebar-collapsed'

/** Shared between the customer Sidebar and the admin layout's own separate nav —
 * those two are completely independent hand-rolled components (confirmed: the admin
 * layout doesn't import or reuse the customer Sidebar at all), so this hook is what
 * they share instead of the markup. Pure per-viewer UI preference, not real app
 * state, so localStorage is the right place for it (not the backend). */
export function useSidebarCollapsed() {
  const [collapsed, setCollapsed] = useState(false)

  useEffect(() => {
    try {
      setCollapsed(localStorage.getItem(STORAGE_KEY) === '1')
    } catch {
      // Private browsing / storage blocked — fall back to expanded, no crash.
    }
  }, [])

  const toggle = () => {
    setCollapsed((prev) => {
      const next = !prev
      try {
        localStorage.setItem(STORAGE_KEY, next ? '1' : '0')
      } catch {
        // Ignore — the toggle still works for this session, just won't persist.
      }
      return next
    })
  }

  return { collapsed, toggle }
}
