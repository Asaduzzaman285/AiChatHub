'use client'

import Link from 'next/link'
import { X } from 'lucide-react'
import { Logo } from '@/components/Logo'
import { TravelingLines } from './TravelingLines'

// Shared chrome for login/register, matching the Figma reference: a plain header
// (logo + close button) and footer (copyright) with a horizontal divider each, the
// traveling-line background layer spanning the whole page, and centered content in
// between. Rendered once here rather than duplicated per page.
export function AuthShell({ children }: { children: React.ReactNode }) {
  return (
    // h-screen (capped to the viewport), not min-h-screen (only a floor) — same fix
    // as the dashboard sidebar earlier this session: caps the shell so `main` is what
    // scrolls (if anything ever needs to) instead of the whole page growing past
    // 100vh and pinning header/footer out of view.
    <div className="relative flex h-screen flex-col overflow-hidden bg-background">
      <TravelingLines />

      <header className="relative flex shrink-0 items-center justify-between border-b border-border px-6 py-3 sm:px-10">
        <Link href="/" aria-label="Alveta.ai home">
          <Logo className="h-5 w-auto text-foreground" />
        </Link>
        <Link
          href="/"
          aria-label="Close"
          className="flex h-8 w-8 items-center justify-center rounded-lg border border-border text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
        >
          <X className="h-4 w-4" />
        </Link>
      </header>

      <main className="relative flex flex-1 items-center justify-center overflow-y-auto px-4 py-4">
        {children}
      </main>

      <footer className="relative shrink-0 border-t border-border px-6 py-2.5 text-center text-xs text-muted-foreground">
        All rights reserved © {new Date().getFullYear()} Alveta.
      </footer>
    </div>
  )
}
