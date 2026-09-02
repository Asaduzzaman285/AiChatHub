'use client'

import { useMemo, useState } from 'react'
import { DndContext, PointerSensor, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core'
import { SortableContext, arrayMove, horizontalListSortingStrategy } from '@dnd-kit/sortable'
import { cn, formatUsage } from '@/lib/utils'
import { CompareCard, type CompareCardData } from './CompareCard'

export type { CompareCardData }

export function CompareCardGroup({
  cards,
  onDismiss,
  onChoose,
  pendingChooseCardKey,
}: {
  cards: CompareCardData[]
  onDismiss: (modelId: string, cardKey: string) => void
  onChoose: (card: CompareCardData) => void
  // Which card (if any) has a "Choose the best" request currently in flight — shows a
  // spinner on that one card instead of the button just silently doing nothing while
  // the PATCH is pending (confirmed live: no loading state at all read as "did that
  // even do anything?"). Undefined outside the persisted-cards path, where choosing
  // isn't even possible yet (no messageId until the turn finishes).
  pendingChooseCardKey?: string
}) {
  // Explicit key order — empty until the user actually drags something, so cards
  // start in whatever order the caller provided them. Local/display-only: reset in
  // chat/page.tsx's session-switch effect alongside the rest of this session's
  // compare-related state, not persisted to the backend or across reloads.
  const [order, setOrder] = useState<string[]>([])

  const orderedCards = useMemo(() => {
    if (order.length === 0) return cards
    const byKey = new Map(cards.map((c) => [c.cardKey, c]))
    const ordered = order.map((key) => byKey.get(key)).filter((c): c is CompareCardData => !!c)
    // A card not yet present in `order` (e.g. one just streamed in) appends at the end
    // rather than disappearing.
    const missing = cards.filter((c) => !order.includes(c.cardKey))
    return [...ordered, ...missing]
  }, [cards, order])

  const sensors = useSensors(
    // A small activation distance keeps a plain tap/swipe on the handle from being
    // misread as a drag — matters most on touch, where the row also needs to keep
    // scrolling horizontally on an ordinary swipe.
    useSensor(PointerSensor, { activationConstraint: { distance: 8 } })
  )

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event
    if (!over || active.id === over.id) return

    const keys = orderedCards.map((c) => c.cardKey)
    const oldIndex = keys.indexOf(String(active.id))
    const newIndex = keys.indexOf(String(over.id))
    if (oldIndex === -1 || newIndex === -1) return

    setOrder(arrayMove(keys, oldIndex, newIndex))
  }

  const aggregateText = useMemo(() => {
    const usable = cards.filter((c) => !c.error && c.promptTokens != null && c.completionTokens != null)
    if (usable.length === 0) return null

    const promptTotal = usable.reduce((sum, c) => sum + (c.promptTokens ?? 0), 0)
    const completionTotal = usable.reduce((sum, c) => sum + (c.completionTokens ?? 0), 0)
    const costTotal = usable.reduce((sum, c) => sum + Number(c.cost ?? 0), 0)

    return formatUsage(promptTotal, completionTotal, costTotal)
  }, [cards])

  // 2-3 models: each card stretches to fill an equal share of the panel's width
  // (vw/2, vw/3 against the chat panel, not the literal browser viewport — the
  // sidebar/composer chrome still take their own space). 4 models: equal quarters
  // would be too narrow to read comfortably, so cards keep a fixed, readable width
  // and the row scrolls horizontally instead — same as dnd-kit's drag-to-reorder
  // already assumed for the overflow case.
  const fillWidth = cards.length <= 3

  return (
    <div className="space-y-1.5">
      {aggregateText && (
        <p className="px-1 text-[10px] font-medium tabular-nums text-muted-foreground">
          {cards.length} models · Total {aggregateText}
        </p>
      )}
      <DndContext sensors={sensors} onDragEnd={handleDragEnd}>
        <SortableContext items={orderedCards.map((c) => c.cardKey)} strategy={horizontalListSortingStrategy}>
          <div className={cn('flex gap-3 pb-1', fillWidth ? 'w-full' : 'overflow-x-auto')}>
            {orderedCards.map((card) => (
              <CompareCard
                key={card.cardKey}
                card={card}
                fillWidth={fillWidth}
                onChoose={onChoose}
                onDismiss={onDismiss}
                isChoosing={card.cardKey === pendingChooseCardKey}
              />
            ))}
          </div>
        </SortableContext>
      </DndContext>
    </div>
  )
}
