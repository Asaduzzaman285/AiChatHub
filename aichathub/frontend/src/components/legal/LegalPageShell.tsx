import type { ReactNode } from 'react'
import { Header } from '@/components/landing/Header'
import { Footer } from '@/components/landing/Footer'

/** Shared by the three basic legal pages (Privacy, Terms, Refund Policy) — same
 * floating white nav + plain content column + dark Footer as the landing page,
 * just without Hero's purple card (these are plain informational pages, not a
 * marketing moment). */
export function LegalPageShell({ title, updated, children }: { title: string; updated: string; children: ReactNode }) {
  return (
    <div className="min-h-screen bg-white text-neutral-900">
      <div className="px-6 pt-6 sm:px-8 sm:pt-8">
        <Header />
      </div>

      <main className="mx-auto max-w-2xl px-6 py-16 sm:px-8">
        <h1 className="font-display text-3xl font-bold tracking-tight text-neutral-900 sm:text-4xl">{title}</h1>
        <p className="mt-2 text-sm text-neutral-500">Last updated: {updated}</p>
        <div className="prose prose-neutral mt-10 max-w-none prose-headings:font-display prose-headings:font-semibold prose-a:text-primary">
          {children}
        </div>
      </main>

      <Footer />
    </div>
  )
}
