'use client'

import type { ReactNode } from 'react'
import { useRouter } from 'next/navigation'
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/HoverCard'
import { ModelIcon } from '@/components/chat/ModelIcon'
import { useAvailableModels } from '@/hooks/useAvailableModels'
import { useChatSession } from '@/contexts/ChatSessionContext'
import { cn } from '@/lib/utils'
import type { AiModel } from '@/types'

// Real capability tags derived from actual data, not invented marketing copy.
function capabilityTags(m: AiModel): string[] {
  const tags: string[] = []
  if (m.capabilities.vision) tags.push('Vision')
  if (m.capabilities.reasoning) tags.push('Reasoning')
  if (m.capabilities.web_search) tags.push('Search')
  if (m.capabilities.function_calling) tags.push('Tools')
  return tags.length > 0 ? tags : ['Chat']
}

/** Hover-triggered (not click) — wraps whatever `trigger` is passed with a HoverCard,
 * matching the Figma spec: 834px wide, hug height, 16px radius, 1px border, 20px
 * padding, 16px gap, #FCFCFC background. Groups models by capabilities.flagship, a
 * real admin-controlled field (frontend/src/app/admin/ai-models/page.tsx) — not a
 * hardcoded list, so this renders honestly (everything under "More Models") until an
 * admin actually sets one. */
export function ModelsPopup({ trigger }: { trigger: ReactNode }) {
  const router = useRouter()
  const { models } = useAvailableModels()
  const { createSession } = useChatSession()

  const textModels = (models ?? []).filter((m) => m.type === 'text')
  const flagship = textModels.filter((m) => m.capabilities.flagship)
  const more = textModels.filter((m) => !m.capabilities.flagship)

  // Available under the user's plan: start a new chat with it (same createSession
  // mutation "+ New chat" already uses, just pre-selecting a specific model instead of
  // availableModels[0]). Not available: route to the existing Settings > Plans
  // convention rather than silently doing nothing.
  const selectModel = (m: AiModel) => {
    if (!m.available) {
      router.push('/chat?settings=plans')
      return
    }
    createSession.mutate({ modelId: m.id })
    router.push('/chat')
  }

  return (
    <HoverCard openDelay={150} closeDelay={100}>
      <HoverCardTrigger asChild>{trigger}</HoverCardTrigger>
      <HoverCardContent
        side="right"
        align="start"
        style={{ width: 834, backgroundColor: '#FCFCFC', borderColor: '#E4E4E4' }}
        className="max-h-[80vh] overflow-y-auto rounded-2xl p-5"
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
                    onClick={() => selectModel(m)}
                    className={cn(
                      'flex flex-col items-start gap-2 rounded-lg border border-neutral-200 bg-white p-3 text-left transition-colors hover:border-primary',
                      !m.available && 'opacity-60'
                    )}
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
                    onClick={() => selectModel(m)}
                    className={cn(
                      'flex items-center gap-2 rounded-full border border-neutral-200 bg-white px-3 py-1.5 text-xs text-neutral-700 transition-colors hover:border-primary',
                      !m.available && 'opacity-60'
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
      </HoverCardContent>
    </HoverCard>
  )
}
