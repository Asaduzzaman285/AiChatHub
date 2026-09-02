'use client'

import Link from 'next/link'
import { Facebook, Instagram, Linkedin, Twitter, Youtube } from 'lucide-react'
import { Logo } from '@/components/Logo'

// Privacy/Terms/Refund Policy are now real basic pages (src/app/privacy,terms,refund-policy).
// Cookie Preferences still has no route anywhere in the app and will 404 until built.
const LEGAL_LINKS = [
  { label: 'Privacy & Policy', href: '/privacy' },
  { label: 'Terms & Policy', href: '/terms' },
  { label: 'Refund Policy', href: '/refund-policy' },
  { label: 'Cookie Preferences', href: '/cookie-preferences' },
]

// Point at real capabilities via the landing page's own #features anchor rather than an
// app route a logged-out visitor can't reach anyway.
const FEATURE_LINKS = [
  { label: 'Top-Up Balance', href: '/#pricing' },
  { label: 'All Model Compare', href: '/#features' },
  { label: 'File Analysis', href: '/#features' },
]

// Twitter (not a dedicated "X" mark) is the closest lucide-react has — it's the old bird
// logo, not the current X wordmark. Flagged rather than silently passed off as accurate.
const SOCIAL_LINKS = [
  { label: 'Facebook', icon: Facebook },
  { label: 'Instagram', icon: Instagram },
  { label: 'LinkedIn', icon: Linkedin },
  { label: 'X', icon: Twitter },
  { label: 'YouTube', icon: Youtube },
]

export function Footer() {
  return (
    <footer className="bg-[#111111] px-6 py-14 text-white sm:px-10">
      <div className="mx-auto grid max-w-6xl gap-10 sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1.2fr]">
        <div>
          <Logo className="h-6 w-auto text-white" />
          <p className="mt-4 max-w-[260px] text-sm leading-relaxed text-white/50">
            One intelligent workspace for every leading AI model.
          </p>
          <div className="mt-5 flex items-center gap-2">
            {SOCIAL_LINKS.map((s) => (
              <a
                key={s.label}
                href="#"
                aria-label={s.label}
                className="flex h-8 w-8 items-center justify-center rounded-full border border-white/15 text-white/70 transition-colors hover:border-white/30 hover:text-white"
              >
                <s.icon className="h-3.5 w-3.5" />
              </a>
            ))}
          </div>
        </div>

        <div>
          <h3 className="text-sm font-semibold">Legal</h3>
          <ul className="mt-4 space-y-3">
            {LEGAL_LINKS.map((l) => (
              <li key={l.href}>
                <Link href={l.href} className="text-sm text-white/50 transition-colors hover:text-white">
                  {l.label}
                </Link>
              </li>
            ))}
          </ul>
        </div>

        <div>
          <h3 className="text-sm font-semibold">Features</h3>
          <ul className="mt-4 space-y-3">
            {FEATURE_LINKS.map((l) => (
              <li key={l.label}>
                <a href={l.href} className="text-sm text-white/50 transition-colors hover:text-white">
                  {l.label}
                </a>
              </li>
            ))}
          </ul>
        </div>

        <div>
          <h3 className="text-sm font-semibold">Subscribe Newsletter</h3>
          <p className="mt-4 text-sm text-white/50">
            Subscribe our newsletter to get the latest news and updates.
          </p>
          {/* No newsletter-capture backend exists anywhere in the codebase — this is
              visual-only for now, submit is inert (preventDefault, no request sent). */}
          <form onSubmit={(e) => e.preventDefault()} className="mt-4 flex items-center gap-1 rounded-full border border-white/15 bg-white/5 p-1">
            <input
              type="email"
              required
              placeholder="Enter your email"
              className="w-full min-w-0 flex-1 bg-transparent px-3 py-1.5 text-sm text-white placeholder:text-white/40 focus:outline-none"
            />
            <button
              type="submit"
              className="shrink-0 rounded-full bg-primary px-4 py-1.5 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90"
            >
              Subscribe
            </button>
          </form>
        </div>
      </div>

      <div className="mx-auto mt-12 max-w-6xl border-t border-white/10 pt-6 text-center text-xs text-white/40">
        Copyright © {new Date().getFullYear()} Alveta. All rights reserved.
      </div>
    </footer>
  )
}
