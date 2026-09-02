'use client'

import { useRef, useState, type ReactNode } from 'react'
import { ModelIcon } from '@/components/chat/ModelIcon'
import { cn } from '@/lib/utils'
import type { AiModel, PublicAiModel } from '@/types'

// Real capability tags derived from actual data, not invented marketing copy.
function capabilityTags(m: AiModel | PublicAiModel): string[] {
  const tags: string[] = []
  if (m.capabilities.vision) tags.push('Vision')
  if (m.capabilities.reasoning) tags.push('Reasoning')
  if (m.capabilities.web_search) tags.push('Search')
  if (m.capabilities.function_calling) tags.push('Tools')
  return tags.length > 0 ? tags : ['Chat']
}

/** Hover-triggered mega-menu — deliberately NOT Radix HoverCard (that was the first
 * attempt, and it positioned wrong): Radix's Popper positioning always anchors to the
 * TRIGGER element's own bounding box, but this needs to align flush with the *header
 * pill's* left edge (per the Figma spec — the popup spans from the navbar's own left
 * edge, not floating off wherever the small "Models" link happens to sit inside it).
 * That's plain CSS absolute-positioned-inside-a-relative-ancestor instead — the
 * caller must give that ancestor `position: relative` (see Header.tsx's `<header>`);
 * this renders `trigger` and the popup as siblings via a Fragment (no wrapping box of
 * its own) so the popup's centered positioning resolves against that ancestor, not
 * against ModelsPopup itself. `contents` on the trigger's hover-tracking wrapper keeps
 * it invisible to the nav's flex layout, so the wrap doesn't shift anything.
 *
 * Purely presentational otherwise — `models` and `onSelectModel` are supplied by the
 * caller. Groups models by capabilities.flagship, a real admin-controlled field, not
 * a hardcoded list — renders honestly (everything under "More Models") until an admin
 * actually sets one. 834px wide, 16px radius, 1px border, 20px padding, 16px gap,
 * #FCFCFC background, matching the Figma spec's own property panel. */
export function ModelsPopup({
  trigger,
  models,
  onSelectModel,
}: {
  trigger: ReactNode
  models: (AiModel | PublicAiModel)[]
  onSelectModel: (model: AiModel | PublicAiModel) => void
}) {
  const [open, setOpen] = useState(false)
  const timerRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)

  const show = () => {
    clearTimeout(timerRef.current)
    timerRef.current = setTimeout(() => setOpen(true), 150)
  }
  const hide = () => {
    clearTimeout(timerRef.current)
    timerRef.current = setTimeout(() => setOpen(false), 100)
  }

  const flagship = models.filter((m) => m.capabilities.flagship)
  const more = models.filter((m) => !m.capabilities.flagship)

  return (
    <>
      <span className="contents" onMouseEnter={show} onMouseLeave={hide}>
        {trigger}
      </span>

      {open && (
        <div
          onMouseEnter={show}
          onMouseLeave={hide}
          style={{ width: 834, backgroundColor: '#FCFCFC', borderColor: '#E4E4E4' }}
          className="absolute left-1/2 top-full z-50 mt-2 max-h-[80vh] -translate-x-1/2 animate-in overflow-y-auto rounded-2xl border p-5 shadow-lg fade-in-0 zoom-in-95 duration-150"
        >
          <div className="flex flex-col gap-4">
            <div>
              <h3 className="mb-3 text-sm font-semibold text-neutral-900">Flagship Models</h3>
              {flagship.length === 0 ? (
                <p className="text-xs text-neutral-500">
                  No flagship models set yet — mark one in the admin panel.
                </p>
              ) : (
                <div className="grid grid-cols-3 gap-3">
                  {flagship.map((m) => (
                    <button
                      key={m.id}
                      type="button"
                      onClick={() => onSelectModel(m)}
                      className="flex flex-col items-start gap-2 rounded-lg border border-neutral-200 bg-white p-3 text-left transition-colors hover:border-primary"
                    >
                      <ModelIcon provider={m.provider} className="h-7 w-7 text-xs" />
                      <div>
                        <p className="text-sm font-medium text-neutral-900">{m.name}</p>
                        <p className="text-xs capitalize text-neutral-500">{m.provider}</p>
                      </div>
                      <div className="flex flex-wrap gap-1">
                        {capabilityTags(m).map((tag) => (
                          <span key={tag} className="rounded-full bg-neutral-100 px-1.5 py-0.5 text-[10px] text-neutral-500">
                            {tag}
                          </span>
                        ))}
                      </div>
                    </button>
                  ))}
                </div>
              )}
            </div>

            <div>
              <h3 className="mb-2 text-sm font-semibold text-neutral-900">More Models</h3>
              {more.length === 0 ? (
                <p className="text-xs text-neutral-500">No other models yet.</p>
              ) : (
                <div className="flex flex-wrap gap-2">
                  {more.map((m) => (
                    <button
                      key={m.id}
                      type="button"
                      onClick={() => onSelectModel(m)}
                      className={cn(
                        'flex items-center gap-2 rounded-full border border-neutral-200 bg-white px-3 py-1.5 text-xs text-neutral-700 transition-colors hover:border-primary'
                      )}
                    >
                      <ModelIcon provider={m.provider} className="h-4 w-4 text-[8px]" />
                      {m.name}
                    </button>
                  ))}
                </div>
              )}
            </div>

            <p className="text-[11px] text-neutral-400">More models added regularly — no setup required.</p>
          </div>
        </div>
      )}
    </>
  )
}
