'use client'

import { useEffect, useState } from 'react'
import { ChevronDown, Mic, Paperclip, ArrowUp } from 'lucide-react'
import { Header } from './Header'

const TYPEWRITER_PROMPTS = [
  'Write a marketing email for our product launch',
  'Debug this Python function',
  'Compare GPT-5.6 and Claude Sonnet 5 for coding',
  'Summarize this PDF in three bullet points',
]

// Cycles through example prompts with a type/pause/backspace rhythm — a small,
// self-contained animation rather than a CSS-only fixed-text typewriter, since it
// needs to cycle through several different phrases, not just reveal one.
function useTypewriter(phrases: string[]) {
  const [text, setText] = useState('')
  const [phraseIndex, setPhraseIndex] = useState(0)
  const [deleting, setDeleting] = useState(false)

  useEffect(() => {
    const current = phrases[phraseIndex]
    let delay = deleting ? 35 : 55

    if (!deleting && text === current) {
      delay = 1400
    } else if (deleting && text === '') {
      delay = 400
    }

    const timeout = setTimeout(() => {
      if (!deleting && text === current) {
        setDeleting(true)
        return
      }
      if (deleting && text === '') {
        setDeleting(false)
        setPhraseIndex((i) => (i + 1) % phrases.length)
        return
      }
      setText(current.slice(0, deleting ? text.length - 1 : text.length + 1))
    }, delay)

    return () => clearTimeout(timeout)
  }, [text, deleting, phraseIndex, phrases])

  return text
}

// Decorative only — deliberately NOT the shared ModelIcon component. ModelIcon's palette
// (emerald/orange/blue/etc.) is a real, established convention used elsewhere for
// compare-mode cards; reusing it here would mean either changing that shared meaning or
// fighting it, for a marketing mockup that isn't tied to any real app state.
const MOCK_PROVIDERS = [
  { label: 'O', className: 'bg-teal-500' },
  { label: 'C', className: 'bg-purple-500' },
  { label: 'G', className: 'bg-gradient-to-br from-blue-400 via-purple-400 to-pink-400' },
  { label: 'X', className: 'bg-amber-400 text-black' },
]

export function Hero() {
  const typed = useTypewriter(TYPEWRITER_PROMPTS)

  return (
    <section className="px-6 pt-6 sm:px-8 sm:pt-8">
      {/* Inset rounded card, not full-bleed — matches the Figma export exactly (the "Bg"
          frame sits inside the page canvas with real margins, not edge-to-edge). Header
          lives inside this card as its first element rather than as a page-wide sticky
          bar, since that's how the reference actually composes it.
          bg-contain (not bg-cover) + a solid backdrop color matching the image's own
          edge tone — the box's height is content-driven, not aspect-locked to the
          image's native 1392x741 ratio, so bg-cover was cropping the image to fill
          whatever shape the box happened to be, cutting the provider-icon decoration
          off the edges. bg-contain never crops; any letterboxing gap just shows the
          matching solid color instead, which reads as seamless. */}
      <div
        // pt-10/sm:pt-12 (not the old pt-6) — the Figma navbar sits ~84px from the
        // frame's top edge; only this top offset changes, headline/subtitle/composer
        // spacing below (mt-16 on the content block) is untouched.
        className="relative mx-auto max-w-[1392px] overflow-hidden rounded-[32px] border border-[#21B8CD]/20 bg-contain bg-center bg-no-repeat px-6 pb-16 pt-10 text-center sm:px-10 sm:pt-12"
        style={{ backgroundColor: '#1a1030', backgroundImage: 'url(/Bg.png)' }}
      >
        <Header />

        <div className="mx-auto mt-16 max-w-[780px] sm:mt-20">
          <h1 className="text-balance text-[44px] font-bold leading-[1.15] tracking-tight text-white sm:text-[64px] lg:text-[80px]">
            All the Best AI Models,
            <br />
            One Place
          </h1>
          <p className="mx-auto mt-4 max-w-[500px] text-balance text-sm leading-relaxed text-white/70 sm:text-base">
            Access, compare, and use the world&apos;s leading AI models all from one powerful
            workspace. Stop jumping between AI tools.
          </p>

          {/* Static composer mockup — not a real input, just previews what the product
              looks like before signing in. White surface (not dark) per the Figma
              reference — this sits on the purple hero, and a dark composer on a dark/
              purple backdrop had almost no separation from the background. */}
          <div className="mx-auto mt-12 rounded-2xl border border-black/5 bg-white p-4 text-left shadow-2xl">
            <div className="flex items-center gap-1 text-[13px] font-medium text-neutral-500">
              General Assistance
              <ChevronDown className="h-3.5 w-3.5" />
            </div>
            <div className="mt-2 h-[22px] text-[15px] text-neutral-400">
              {typed}
              <span className="ml-0.5 inline-block h-[1em] w-[2px] -translate-y-[1px] animate-pulse bg-neutral-400 align-middle" />
            </div>

            <div className="mt-3 flex items-center justify-between">
              <div className="flex items-center gap-2">
                <Paperclip className="h-4 w-4 text-neutral-400" />
                <div className="flex items-center gap-1.5">
                  {MOCK_PROVIDERS.map((p) => (
                    <span
                      key={p.label}
                      className={`flex h-6 w-6 items-center justify-center rounded-full text-[10px] font-bold text-white ${p.className}`}
                    >
                      {p.label}
                    </span>
                  ))}
                  <span className="ml-0.5 flex h-6 w-6 items-center justify-center rounded-full border border-neutral-200 text-xs text-neutral-400">
                    +
                  </span>
                </div>
              </div>
              <div className="flex items-center gap-3">
                <Mic className="h-4 w-4 text-neutral-400" />
                <span className="flex h-9 w-9 items-center justify-center rounded-full bg-primary transition-transform hover:scale-105">
                  <ArrowUp className="h-4 w-4 text-primary-foreground" />
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  )
}
