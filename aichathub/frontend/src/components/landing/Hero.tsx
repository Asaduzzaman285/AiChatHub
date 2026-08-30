'use client'

import { useEffect, useState } from 'react'
import { ChevronDown, Mic, Paperclip, SlidersHorizontal, Telescope, ArrowUp } from 'lucide-react'
import { Header } from './Header'
import { ClaudeIcon, GeminiIcon, OpenAIIcon } from './ProviderIcons'

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
// Real brand marks (supplied directly, not sourced/guessed) — see ProviderIcons.tsx.
// Only 3 logos on hand (no Grok/xAI yet); "4+" reads as "more available", not a literal
// count, matching how it's used in the reference.
const MOCK_PROVIDERS = [OpenAIIcon, ClaudeIcon, GeminiIcon]

export function Hero() {
  const typed = useTypewriter(TYPEWRITER_PROMPTS)

  return (
    <section className="px-6 pt-6 sm:px-8 sm:pt-8">
      {/* Inset rounded card, not full-bleed — matches the Figma export exactly (the "Bg"
          frame sits inside the page canvas with real margins, not edge-to-edge). Header
          lives inside this card as its first element rather than as a page-wide sticky
          bar, since that's how the reference actually composes it.
          bg-cover, not bg-contain — this card's height is content-driven, not locked to
          the image's native 1392x741 aspect ratio, so bg-contain was letterboxing:
          whenever the rendered box was proportionally taller/narrower than the image,
          it fit to one axis and left visible gaps on the other (confirmed live —
          reported as "left and right has gaps"). bg-cover always fills the box
          completely with no gaps, at the cost of cropping a little off whichever edge
          doesn't fit — a smaller, less noticeable defect than an outright gap. */}
      <div
        // pt-10/sm:pt-12 (not the old pt-6) — the Figma navbar sits ~84px from the
        // frame's top edge; only this top offset changes, headline/subtitle/composer
        // spacing below (mt-16 on the content block) is untouched.
        className="relative mx-auto max-w-[1392px] overflow-hidden rounded-[32px] border border-[#21B8CD]/20 bg-cover bg-center bg-no-repeat px-6 pb-16 pt-10 text-center sm:px-10 sm:pt-12"
        style={{ backgroundColor: '#1a1030', backgroundImage: 'url(/Bg.png)' }}
      >
        <Header />

        <div className="mx-auto mt-10 max-w-[780px] sm:mt-12">
          {/* Figma's own text-layer inspector for this exact layer: 588x101px box,
              Inter Display / weight 500 / size 52px / line-height 100% / letter-spacing
              1%, centered — verified numbers, not an estimate. Not literally "Inter
              Display" (never loaded — layout.tsx only loads plain Inter + Plus Jakarta
              Sans via next/font), so this inherits the body's real Inter instead of
              faking a font-family that would silently no-op. Fixed at 52px rather than
              scaling up on larger breakpoints (was 44/64/80px) since that's the design's
              actual desktop size, not a floor to scale from. tracking-tight was reversed
              from what Figma wants (that's negative spacing; 1% is positive). */}
          <h1 className="mx-auto max-w-[588px] text-balance text-[36px] font-medium leading-none tracking-[0.01em] text-white sm:text-[52px]">
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
              purple backdrop had almost no separation from the background.
              max-w-[520px] here is the actual bug fix: this div previously had NO width
              constraint at all, so as a block element it silently stretched to its
              780px parent — visibly wider than the 588px heading above it, which is
              exactly what showed up in the live screenshot. 520px is a reasonable,
              not-falsely-precise width in proportion to the heading; the real Figma
              composer measurement hasn't been verified the way the heading's was. */}
          <div className="mx-auto mt-12 w-full max-w-[520px] rounded-2xl border border-black/5 bg-white p-4 text-left shadow-2xl">
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
                {/* Decorative only, like the rest of this static mockup — no real
                    "explore/discover" feature behind it yet. */}
                <Telescope className="h-4 w-4 text-neutral-400" />

                {/* Overlapping avatar stack, matching the reference — each icon rides a
                    white ring so it stays legible against its neighbor instead of the
                    previous evenly-spaced circles. */}
                <div className="ml-1 flex items-center">
                  {MOCK_PROVIDERS.map((Icon, i) => (
                    <span
                      key={i}
                      className={`flex h-6 w-6 shrink-0 items-center justify-center overflow-hidden rounded-full ring-2 ring-white ${i === 0 ? '' : '-ml-2'}`}
                    >
                      <Icon className="h-full w-full" />
                    </span>
                  ))}
                  <button
                    type="button"
                    className="-ml-1 flex items-center gap-0.5 rounded-full border border-neutral-200 bg-white py-0.5 pl-2 pr-1.5 text-[10px] font-medium text-neutral-500"
                  >
                    4+
                    <ChevronDown className="h-2.5 w-2.5" />
                  </button>
                </div>

                <span className="mx-1.5 h-4 w-px bg-neutral-200" />
                <SlidersHorizontal className="h-4 w-4 text-neutral-400" />
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
