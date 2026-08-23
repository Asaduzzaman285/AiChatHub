'use client'

import Link from 'next/link'
import { ArrowRight, ChevronDown, Lock } from 'lucide-react'
import { Logo } from '@/components/Logo'

const NAV_LINKS = [
  { label: 'Models', href: '#models' },
  { label: 'Features', href: '#features', hasChevron: true },
  { label: 'Pricing', href: '#pricing' },
]

// Pixel-accurate reproduction of the Figma "Navigation Bar" component: a SOLID WHITE
// floating pill (fill #FFFFFF, not glass/translucent) sitting over the purple hero
// background — width 1020px / height 76px / radius 24px / 16px padding / space-between,
// per the design's own property panel. Every child uses dark text/icons instead of the
// previous white-on-dark treatment now that the background is white; only "Get Started"
// keeps the purple accent (white text on a purple fill) as the one CTA color.
export function Header() {
  return (
    <header className="mx-auto flex h-[76px] w-full max-w-[1020px] items-center justify-between rounded-3xl bg-white px-4 shadow-[0_8px_30px_rgba(0,0,0,0.12)]">
      <Logo className="h-6 w-auto text-neutral-900" />

      <nav className="hidden items-center gap-8 text-sm font-medium text-neutral-700 md:flex">
        {NAV_LINKS.map((link) => (
          <a key={link.href} href={link.href} className="flex items-center gap-1 transition-colors hover:text-neutral-900">
            {link.label}
            {link.hasChevron && <ChevronDown className="h-3.5 w-3.5" />}
          </a>
        ))}
      </nav>

      <div className="flex items-center gap-4">
        <Link
          href="/login"
          className="flex items-center gap-1.5 text-sm font-medium text-neutral-700 transition-colors hover:text-neutral-900"
        >
          Sign In
          <Lock className="h-3.5 w-3.5" />
        </Link>
        <Link
          href="/register"
          className="flex items-center gap-1.5 rounded-full bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90"
        >
          Get Started
          <ArrowRight className="h-3.5 w-3.5" />
        </Link>
      </div>
    </header>
  )
}
