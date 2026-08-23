import { useSortable } from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import rehypeHighlight from 'rehype-highlight'
import { GripVertical, X } from 'lucide-react'
import { ModelIcon } from './ModelIcon'

export interface CompareCardData {
  cardKey: string
  // Always the AiModel.id UUID by the time it reaches this component — the two
  // sources (persisted messages vs. live SSE turns) use different id spaces
  // upstream and must be normalized before building this object. See the
  // buildCompareCards() comment in CompareCardGroup for why that matters.
  modelId: string | null
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
  onDismiss,
}: {
  card: CompareCardData
  onDismiss: (modelId: string, cardKey: string) => void
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: card.cardKey })

  return (
    <div
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className="group w-72 shrink-0 rounded-lg border border-border bg-card"
      // isDragging affects opacity only (not display/position) — avoids the card
      // popping in/out of the horizontal-scroll flow mid-drag. Read via the
      // group-data variant below since the attribute lives on this wrapper, not
      // the header div itself.
      data-dragging={isDragging || undefined}
    >
      <div className="flex items-center justify-between gap-1.5 border-b border-border px-2 py-1.5 opacity-100 group-data-[dragging=true]:opacity-50">
        <div className="flex min-w-0 items-center gap-1.5">
          {/* Only the handle is draggable, not the whole card — the card row still
              needs to work as a plain horizontal-scroll/swipe area on touch, which
              would otherwise fight the drag sensor. */}
          <button
            type="button"
            {...attributes}
            {...listeners}
            className="cursor-grab touch-none text-muted-foreground/50 hover:text-muted-foreground active:cursor-grabbing"
            aria-label="Drag to reorder"
          >
            <GripVertical className="h-3.5 w-3.5" />
          </button>
          {card.provider && <ModelIcon provider={card.provider} className="h-4 w-4 shrink-0" />}
          <span className="truncate text-xs font-medium">{card.modelName}</span>
        </div>
        <button
          type="button"
          onClick={() => card.modelId && onDismiss(card.modelId, card.cardKey)}
          disabled={!card.modelId}
          className="shrink-0 text-muted-foreground hover:text-destructive disabled:opacity-30"
          aria-label={`Not preferred — remove ${card.modelName} from this comparison`}
          title="Not preferred — remove from comparison"
        >
          <X className="h-3.5 w-3.5" />
        </button>
      </div>
      <div className="prose prose-sm max-w-none p-3 text-sm [&>*:first-child]:mt-0 [&>*:last-child]:mb-0">
        {card.error ? (
          <span className="text-destructive">{card.error}</span>
        ) : (
          <ReactMarkdown remarkPlugins={[remarkGfm]} rehypePlugins={[rehypeHighlight]}>
            {card.content || '…'}
          </ReactMarkdown>
        )}
      </div>
      {card.usageText && (
        <p className="border-t border-border px-3 py-1 text-[10px] tabular-nums text-muted-foreground">
          {card.usageText}
        </p>
      )}
    </div>
  )
}
