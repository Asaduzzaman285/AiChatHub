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

export function ModelIcon({ provider, className }: { provider: string; className?: string }) {
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
