'use client'

import { Logo } from '@/components/Logo'

export function Footer() {
  return (
    <footer className="border-t border-neutral-200 px-6 py-10">
      <div className="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 sm:flex-row">
        <Logo iconOnly className="h-6 w-6 text-neutral-400" />
        <p className="text-xs text-neutral-400">© {new Date().getFullYear()} Alveta.ai. All rights reserved.</p>
      </div>
    </footer>
  )
}
