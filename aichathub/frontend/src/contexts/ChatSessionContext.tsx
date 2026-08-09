'use client'

import { createContext, useContext, useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import apiClient from '@/lib/api-client'
import type { ChatSession } from '@/types'

interface ChatSessionContextValue {
  sessions: ChatSession[] | undefined
  sessionsLoading: boolean
  activeSessionId: string | null
  setActiveSessionId: (id: string | null) => void
  createSession: ReturnType<typeof useCreateSession>
  renameSession: ReturnType<typeof useRenameSession>
  deleteSession: ReturnType<typeof useDeleteSession>
}

const ChatSessionContext = createContext<ChatSessionContextValue | null>(null)

function useCreateSession(onCreated: (id: string) => void) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (modelId: string) =>
      (await apiClient.post<{ session: ChatSession }>('/api/v1/sessions', { model_id: modelId })).data.session,
    onSuccess: (session) => {
      queryClient.invalidateQueries({ queryKey: ['chat', 'sessions'] })
      onCreated(session.id)
    },
    onError: () => toast.error("We couldn't start a new chat — please try again."),
  })
}

function useRenameSession(onSettled: () => void) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, title }: { id: string; title: string }) => apiClient.patch(`/api/v1/sessions/${id}`, { title }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['chat', 'sessions'] }),
    onError: () => toast.error("We couldn't rename that chat — please try again."),
    onSettled,
  })
}

function useDeleteSession(activeSessionId: string | null, onDeleted: () => void) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: string) => apiClient.delete(`/api/v1/sessions/${id}`),
    onSuccess: (_res, id) => {
      queryClient.invalidateQueries({ queryKey: ['chat', 'sessions'] })
      if (activeSessionId === id) onDeleted()
      toast.success('Chat deleted.')
    },
    onError: () => toast.error("We couldn't delete that chat — please try again."),
  })
}

/** Shared between (dashboard)/layout.tsx's sidebar (renders the session list) and
 * chat/page.tsx (renders the active conversation) — moved up from being chat/page.tsx-local
 * so the session list is visible in the shared shell, ChatGPT-style, not just on /chat. */
export function ChatSessionProvider({ children }: { children: ReactNode }) {
  const [activeSessionId, setActiveSessionId] = useState<string | null>(null)

  const { data: sessions, isLoading: sessionsLoading } = useQuery({
    queryKey: ['chat', 'sessions'],
    queryFn: async () => (await apiClient.get<{ sessions: ChatSession[] }>('/api/v1/sessions')).data.sessions,
  })

  const createSession = useCreateSession((id) => setActiveSessionId(id))
  const renameSession = useRenameSession(() => {})
  const deleteSession = useDeleteSession(activeSessionId, () => setActiveSessionId(null))

  return (
    <ChatSessionContext.Provider value={{ sessions, sessionsLoading, activeSessionId, setActiveSessionId, createSession, renameSession, deleteSession }}>
      {children}
    </ChatSessionContext.Provider>
  )
}

export function useChatSession() {
  const ctx = useContext(ChatSessionContext)
  if (!ctx) throw new Error('useChatSession must be used within ChatSessionProvider')
  return ctx
}
