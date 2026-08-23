'use client'

import Link from 'next/link'
import { CheckCircle2, Layers, Brain, FileSearch, ArrowRight } from 'lucide-react'

const FEATURES = [
  {
    icon: CheckCircle2,
    card: 'bg-emerald-50 border-emerald-100',
    badge: 'bg-emerald-100 text-emerald-600',
    title: 'One Chat. Every Modality.',
    body: 'Send text, images, and file attachments in the same thread — no separate tool for each kind of input.',
  },
  {
    icon: Layers,
    card: 'bg-rose-50 border-rose-100',
    badge: 'bg-rose-100 text-rose-600',
    title: 'Compare Models Side by Side.',
    body: 'Run one prompt across several models at once and see every answer next to each other, not tab by tab.',
  },
  {
    icon: Brain,
    card: 'bg-amber-50 border-amber-100',
    badge: 'bg-amber-100 text-amber-600',
    title: 'How AI Models Think Differently.',
    body: 'Reasoning style, tone, and strengths vary by model — Alveta makes those differences visible, not hidden.',
  },
  {
    icon: FileSearch,
    card: 'bg-violet-50 border-violet-100',
    badge: 'bg-violet-100 text-violet-600',
    title: 'Analyze Files Without Leaving Workflow.',
    body: 'Drop a PDF, spreadsheet, presentation, image, or document and get instant, grounded answers in the same conversation.',
  },
]

export function FeaturesSection() {
  return (
    <section id="features" className="px-6 py-20">
      <div className="mx-auto grid max-w-[1200px] gap-12 md:grid-cols-2 md:items-start">
        {/* Sticky too, matching the right column's cards — without this the text just
            scrolled away normally while the cards did their stacking animation, when
            it should stay put in a fixed spot for the whole scroll-through instead. */}
        <div className="sticky top-24 self-start">
          <h2 className="text-balance font-display text-3xl font-bold tracking-tight text-neutral-900 sm:text-4xl">
            Everything you need to work smarter with AI.
          </h2>
          <p className="mt-4 max-w-md text-neutral-500">
            Chat with leading AI models, work with any type of content, compare responses, and keep
            your AI usage always under control — all from Alveta.
          </p>
          <div className="mt-8 flex flex-wrap items-center gap-4">
            <Link
              href="/register"
              className="rounded-full bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90"
            >
              Get AI Access
            </Link>
            <a href="#pricing" className="flex items-center gap-1.5 text-sm font-medium text-neutral-500 hover:text-neutral-900">
              Explore Features <ArrowRight className="h-3.5 w-3.5" />
            </a>
          </div>
        </div>

        {/* Sticky-stack scroll effect, pure CSS — each card gets an increasing `top`
            offset and z-index, so as the page scrolls, each one "sticks" in place
            and the next card scrolls up to overlap it, cascading on top rather than
            just scrolling past underneath. */}
        <div id="models" className="grid gap-4 pb-4">
          {FEATURES.map((f, i) => (
            <div
              key={f.title}
              className={`sticky rounded-xl border p-5 shadow-lg ${f.card}`}
              style={{ top: `${88 + i * 20}px`, zIndex: i + 1 }}
            >
              <div className={`flex h-9 w-9 items-center justify-center rounded-full ${f.badge}`}>
                <f.icon className="h-4.5 w-4.5" />
              </div>
              <h3 className="mt-3 text-sm font-semibold text-neutral-900">{f.title}</h3>
              <p className="mt-1 text-sm text-neutral-600">{f.body}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}
