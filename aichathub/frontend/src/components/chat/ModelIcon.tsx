import { cn } from '@/lib/utils'

const PROVIDER_STYLES: Record<string, string> = {
  openai: 'bg-emerald-500',
  anthropic: 'bg-orange-500',
  gemini: 'bg-blue-500',
  xai: 'bg-neutral-800',
  deepseek: 'bg-indigo-500',
  perplexity: 'bg-teal-500',
  qwen: 'bg-violet-500',
  moonshot: 'bg-slate-700',
  elevenlabs: 'bg-pink-500',
}

// Real provider marks (see public/modellogos/ — user-supplied). Each file is a
// pre-designed square badge with its own background baked in (not a transparent
// glyph meant to be composited onto something), so it's cropped to a circle below to
// match every other circular model icon in the app rather than layered on top of one.
// Not every provider has a mark yet (elevenlabs, moonshot) — those still fall back to
// the colored-letter placeholder rather than a broken image.
const PROVIDER_LOGOS: Record<string, string> = {
  openai: '/modellogos/openai.svg',
  anthropic: '/modellogos/anthropic.svg',
  gemini: '/modellogos/gemini.svg',
  xai: '/modellogos/xai.svg',
  deepseek: '/modellogos/deepseek.svg',
  perplexity: '/modellogos/perplexity.svg',
  qwen: '/modellogos/qwen.svg',
}

export function ModelIcon({ provider, className }: { provider: string; className?: string }) {
  const logo = PROVIDER_LOGOS[provider]

  if (logo) {
    return (
      <span className={cn('inline-block shrink-0 overflow-hidden rounded-full', className)}>
        {/* eslint-disable-next-line @next/next/no-img-element -- arbitrarily sized via
            className across dozens of call sites (h-4 to h-7); next/image needs fixed
            width/height, which fights that. Same plain-<img> pattern already used for
            attachments elsewhere in the app. */}
        <img src={logo} alt="" className="h-full w-full object-cover" />
      </span>
    )
  }

  return (
    <span
      className={cn(
        'inline-flex shrink-0 items-center justify-center rounded-full text-[9px] font-bold uppercase text-white',
        PROVIDER_STYLES[provider] ?? 'bg-muted-foreground',
        className
      )}
    >
      {provider.slice(0, 1)}
    </span>
  )
}
