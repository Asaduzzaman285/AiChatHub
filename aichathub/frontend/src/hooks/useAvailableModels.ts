'use client'

import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import apiClient from '@/lib/api-client'
import type { AiModel } from '@/types'

// Shared by (dashboard)/layout.tsx (the "+ New chat" caret's private-chat popover) and
// chat/page.tsx (the auto-create-on-empty effect and the model switcher) so both read
// the same React Query cache entry — one network fetch, and "what counts as the
// default model" (first available text model — no is_default/last-used concept exists
// on AiModel) is defined in exactly one place.
export function useAvailableModels() {
  const { data: models, isLoading: modelsLoading } = useQuery({
    queryKey: ['models'],
    queryFn: async () => (await apiClient.get<{ models: AiModel[] }>('/api/v1/models')).data.models,
  })

  const availableModels = useMemo(() => (models ?? []).filter((m) => m.type === 'text' && m.available), [models])

  return { models, availableModels, modelsLoading }
}
