import { useState } from 'react'
import { useSortable } from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import rehypeHighlight from 'rehype-highlight'
import { Check, Copy, Download, GripVertical, Loader2, ThumbsDown, ThumbsUp, X } from 'lucide-react'
import { cn } from '@/lib/utils'
import { ModelIcon } from './ModelIcon'

export interface CompareCardData {
  cardKey: string
  // Always the AiModel.id UUID by the time it reaches this component — the two
  // sources (persisted messages vs. live SSE turns) use different id spaces
  // upstream and must be normalized before building this object. See the
  // buildCompareCards() comment in CompareCardGroup for why that matters.
  modelId: string | null
  // The real backend message UUID — only set once this card is persisted (chat/
  // page.tsx's renderItems path). null while a comparison is still live-streaming
  // (compareTurns), since chat-service hasn't assigned a row yet; "Choose the best"
  // is disabled below whenever this is null; PATCH .../messages/{id}/choose is what
  // actually needs it.
  messageId: string | null
  // Real persisted state (metadata.is_chosen) for a persisted card; always false for
  // a still-streaming one, since nothing's saved yet to be chosen.
  isChosen: boolean
  modelName: string
  provider: string | null
  content: string
  error?: string
  // Pre-formatted (via lib/utils's formatUsage()) for this card's own footer.
  usageText: string | null
  // Raw values alongside the formatted string above — CompareCardGroup needs these
  // to compute the group's aggregate total, which can't be derived from an already-
  // formatted per-card string.
  promptTokens: number | null
  completionTokens: number | null
  cost: string | number | null
}

export function CompareCard({
  card,
  fillWidth,
  isChoosing,
  isDeemphasized,
  onChoose,
  onDismiss,
}: {
  card: CompareCardData
  // true for 2-3 models — each card stretches to fill an equal share of the row
  // (CompareCardGroup handles the actual division via flex). false at 4 models,
  // where equal quarters would be too narrow to read — cards keep a fixed, readable
  // width and the row scrolls horizontally instead (see CompareCardGroup).
  fillWidth: boolean
  // True while this specific card's "Choose the best" PATCH is in flight — shows a
  // spinner in place of the button label instead of the click silently appearing to
  // do nothing (confirmed live feedback: no loading state read as broken).
  isChoosing?: boolean
  // True once a sibling card has been chosen as best and this one wasn't — fades
  // and disables it rather than removing it, so the comparison stays available for
  // reference while still making the choice read clearly at a glance.
  isDeemphasized?: boolean
  onChoose: (card: CompareCardData) => void
  onDismiss: (modelId: string, cardKey: string) => void
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: card.cardKey })

  // Local-only for now — no feedback endpoint exists yet to persist this to. Kept as
  // a visual affordance matching the reference design; wire to a real endpoint if/when
  // there's somewhere for it to go.
  const [vote, setVote] = useState<'up' | 'down' | null>(null)
  const [copied, setCopied] = useState(false)

  const handleCopy = () => {
    navigator.clipboard.writeText(card.content)
    setCopied(true)
    setTimeout(() => setCopied(false), 1500)
  }

  const handleDownload = () => {
    const safeName = card.modelName.replace(/[^\w\- ]+/g, '').trim() || 'response'
    const blob = new Blob([card.content], { type: 'text/markdown' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `${safeName}.md`
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
  }

  return (
    <div
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={cn(
        'group flex flex-col rounded-xl border bg-card transition-opacity',
        fillWidth ? 'min-w-0 flex-1' : 'w-80 shrink-0',
        card.isChosen ? 'border-primary ring-1 ring-primary' : 'border-border',
        isDeemphasized && 'opacity-50 pointer-events-none'
      )}
      // isDragging affects opacity only (not display/position) — avoids the card
      // popping in/out of the horizontal-scroll flow mid-drag. Read via the
      // group-data variant below since the attribute lives on this wrapper, not
      // the header div itself.
      data-dragging={isDragging || undefined}
    >
      <div className="flex items-center justify-between gap-1.5 border-b border-border px-3 py-2 opacity-100 group-data-[dragging=true]:opacity-50">
        <div className="flex min-w-0 items-center gap-1.5">
          {/* Only the handle is draggable, not the whole card — the card row still
              needs to work as a plain horizontal-scroll/swipe area on touch, which
              would otherwise fight the drag sensor. */}
          <button
            type="button"
            {...attributes}
            {...listeners}
            className="cursor-grab touch-none text-muted-foreground/40 hover:text-muted-foreground active:cursor-grabbing"
            aria-label="Drag to reorder"
          >
            <GripVertical className="h-3.5 w-3.5" />
          </button>
          {card.provider && <ModelIcon provider={card.provider} className="h-5 w-5 shrink-0" />}
          <span className="truncate text-sm font-medium">{card.modelName}</span>
        </div>
        <button
          type="button"
          onClick={() => card.modelId && onDismiss(card.modelId, card.cardKey)}
          disabled={!card.modelId}
          className="shrink-0 text-muted-foreground/60 hover:text-destructive disabled:opacity-30"
          aria-label={`Not preferred — remove ${card.modelName} from this comparison`}
          title="Not preferred — remove from comparison"
        >
          <X className="h-3.5 w-3.5" />
        </button>
      </div>

      {/* min-w-0 + overflow-x-auto — without both, a wide markdown table (or an
          unbroken long code line) in the response just overflows the card instead of
          scrolling inside it, breaking the row's layout. min-w-0 matters specifically
          because this is a flex child (fillWidth cards are flex-1): flex items don't
          shrink below their content's natural width by default, so without it the
          overflow-x-auto below never actually gets a chance to kick in — the card
          itself grows past its share of the row first. Confirmed live (a wide test-case
          table from a real response broke the 3-card row). */}
      {/* min-h reserves the same footprint from the very first render (empty/loading)
          through a full response — real user feedback (a video review) identified
          this exact card growing from near-empty to full height as the actual cause
          of a "jarring jump" complaint, not general slowness: the row's height was
          never reserved up front, so it visibly reflowed as content streamed in.
          Loading state now lives inside the card (the animated-dots pattern
          MessageBubble already uses for the same "thinking" moment) instead of a
          differently-shaped card being swapped in for the whole group — see
          chat/page.tsx's compareTurns render block for the other half of this fix. */}
      <div className="prose prose-sm min-h-[88px] min-w-0 max-w-none flex-1 overflow-x-auto p-3 text-sm [&>*:first-child]:mt-0 [&>*:last-child]:mb-0">
        {card.error ? (
          <span className="text-destructive">{card.error}</span>
        ) : card.content ? (
          <ReactMarkdown remarkPlugins={[remarkGfm]} rehypePlugins={[rehypeHighlight]}>
            {card.content}
          </ReactMarkdown>
        ) : (
          <span className="flex items-center gap-1 py-0.5" aria-label="Thinking">
            <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-current opacity-60 [animation-delay:-0.3s]" />
            <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-current opacity-60 [animation-delay:-0.15s]" />
            <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-current opacity-60" />
          </span>
        )}
      </div>

      {/* Rendered directly from the raw fields (not card.usageText) so input/output can
          each get their own color, matching MessageBubble's treatment — a single
          pre-joined string from formatUsage() can't be split into two colors. */}
      {card.promptTokens != null && card.completionTokens != null && (
        <p className="border-t border-border px-3 py-1 text-[10px] tabular-nums">
          <span className="text-info">{card.promptTokens.toLocaleString()} in</span>
          {' · '}
          <span className="text-success">{card.completionTokens.toLocaleString()} out</span>
        </p>
      )}

      <div className="flex items-center gap-1 border-t border-border px-2 py-1.5">
        <button
          type="button"
          onClick={handleCopy}
          disabled={!card.content}
          aria-label="Copy response"
          title="Copy"
          className="flex h-6 w-6 items-center justify-center rounded-md text-muted-foreground/60 hover:text-foreground disabled:opacity-30"
        >
          {copied ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}
        </button>
        <button
          type="button"
          onClick={() => setVote((v) => (v === 'up' ? null : 'up'))}
          aria-pressed={vote === 'up'}
          aria-label="Good response"
          className={cn(
            'flex h-6 w-6 items-center justify-center rounded-md',
            vote === 'up' ? 'text-emerald-600' : 'text-muted-foreground/60 hover:text-foreground'
          )}
        >
          <ThumbsUp className="h-3.5 w-3.5" />
        </button>
        <button
          type="button"
          onClick={() => setVote((v) => (v === 'down' ? null : 'down'))}
          aria-pressed={vote === 'down'}
          aria-label="Bad response"
          className={cn(
            'flex h-6 w-6 items-center justify-center rounded-md',
            vote === 'down' ? 'text-destructive' : 'text-muted-foreground/60 hover:text-foreground'
          )}
        >
          <ThumbsDown className="h-3.5 w-3.5" />
        </button>
        <button
          type="button"
          onClick={handleDownload}
          disabled={!card.content}
          aria-label="Download this response"
          title="Download"
          className="flex h-6 w-6 items-center justify-center rounded-md text-muted-foreground/60 hover:text-foreground disabled:opacity-30"
        >
          <Download className="h-3.5 w-3.5" />
        </button>
      </div>

      <div className="px-2 pb-2">
        <button
          type="button"
          onClick={() => onChoose(card)}
          // Already-chosen is disabled too, not just clickable-but-a-no-op — re-firing
          // the same choice would just be a wasted request with nothing to show for it.
          disabled={!card.content || !!card.error || !card.messageId || card.isChosen || isChoosing}
          title={!card.messageId ? 'Wait for this response to finish' : undefined}
          className={cn(
            'flex w-full items-center justify-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors disabled:cursor-not-allowed',
            card.isChosen
              ? 'bg-primary text-primary-foreground disabled:opacity-100'
              : 'border border-border text-foreground hover:bg-accent disabled:opacity-40'
          )}
        >
          {isChoosing ? (
            <Loader2 className="h-3.5 w-3.5 animate-spin" />
          ) : card.isChosen ? (
            'Best response'
          ) : (
            'Choose the best'
          )}
        </button>
      </div>
    </div>
  )
}
