'use client'

import { useQuery } from '@tanstack/react-query'
import apiClient from '@/lib/api-client'
import type { PublicAiModel } from '@/types'

// Unauthenticated — the landing page's navbar Models popup has to work for a visitor
// who hasn't signed up yet, so this hits GET /models/public (no JWT required) rather
// than useAvailableModels' /models (which 401s without one, since it cross-references
// a real user's package access).
export function usePublicModels() {
  const { data } = useQuery({
    queryKey: ['models', 'public'],
    queryFn: async () => (await apiClient.get<{ models: PublicAiModel[] }>('/api/v1/models/public')).data.models,
    staleTime: 5 * 60_000,
  })

  return { models: data ?? [] }
}
