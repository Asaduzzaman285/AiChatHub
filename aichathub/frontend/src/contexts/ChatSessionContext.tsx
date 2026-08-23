'use client'

import { createContext, useContext, useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import apiClient from '@/lib/api-client'
import type { ChatSession, Project } from '@/types'

interface ChatSessionContextValue {
  sessions: ChatSession[] | undefined
  sessionsLoading: boolean
  activeSessionId: string | null
  setActiveSessionId: (id: string | null) => void
  createSession: ReturnType<typeof useCreateSession>
  renameSession: ReturnType<typeof useRenameSession>
  deleteSession: ReturnType<typeof useDeleteSession>
  projects: Project[] | undefined
  projectsLoading: boolean
  createProject: ReturnType<typeof useCreateProject>
  renameProject: ReturnType<typeof useRenameProject>
  deleteProject: ReturnType<typeof useDeleteProject>
}

const ChatSessionContext = createContext<ChatSessionContextValue | null>(null)

interface CreateSessionParams {
  modelId: string
  projectId?: string
  isPrivate?: boolean
  privateDurationMinutes?: number
}

function useCreateSession(onCreated: (id: string) => void) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ modelId, projectId, isPrivate, privateDurationMinutes }: CreateSessionParams) =>
      (await apiClient.post<{ session: ChatSession }>('/api/v1/sessions', {
        model_id: modelId,
        project_id: projectId,
        is_private: isPrivate,
        private_duration_minutes: privateDurationMinutes,
      })).data.session,
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

function useCreateProject() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ name, color }: { name: string; color?: string }) =>
      (await apiClient.post<{ project: Project }>('/api/v1/projects', { name, color })).data.project,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['projects'] }),
    onError: () => toast.error("We couldn't create that project — please try again."),
  })
}

function useRenameProject() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, name }: { id: string; name: string }) => apiClient.patch(`/api/v1/projects/${id}`, { name }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['projects'] }),
    onError: () => toast.error("We couldn't rename that project — please try again."),
  })
}

function useDeleteProject() {
  const queryClient = useQueryClient()
  return useMutation({
    // mode: 'orphan' (default, keeps chats, just detaches them) or 'delete_sessions'
    // (explicit opt-in to also delete every chat in the project) — mirrors the two
    // distinct, clearly-labeled destructive actions ProjectController::destroy() exposes,
    // rather than one ambiguous "Delete" that silently decides for the user.
    mutationFn: async ({ id, mode }: { id: string; mode?: 'orphan' | 'delete_sessions' }) =>
      apiClient.delete(`/api/v1/projects/${id}`, { data: mode ? { mode } : undefined }),
    onSuccess: (_res, { mode }) => {
      queryClient.invalidateQueries({ queryKey: ['projects'] })
      queryClient.invalidateQueries({ queryKey: ['chat', 'sessions'] })
      toast.success(mode === 'delete_sessions' ? 'Project and its chats deleted.' : 'Project deleted — chats kept.')
    },
    onError: () => toast.error("We couldn't delete that project — please try again."),
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
    // Private chats' sidebar countdown and scheduler-driven auto-deletion should become
    // visible without the user manually refreshing.
    refetchInterval: 60_000,
  })

  const { data: projects, isLoading: projectsLoading } = useQuery({
    queryKey: ['projects'],
    queryFn: async () => (await apiClient.get<{ projects: Project[] }>('/api/v1/projects')).data.projects,
  })

  const createSession = useCreateSession((id) => setActiveSessionId(id))
  const renameSession = useRenameSession(() => {})
  const deleteSession = useDeleteSession(activeSessionId, () => setActiveSessionId(null))
  const createProject = useCreateProject()
  const renameProject = useRenameProject()
  const deleteProject = useDeleteProject()

  return (
    <ChatSessionContext.Provider value={{
      sessions, sessionsLoading, activeSessionId, setActiveSessionId, createSession, renameSession, deleteSession,
      projects, projectsLoading, createProject, renameProject, deleteProject,
    }}>
      {children}
    </ChatSessionContext.Provider>
  )
}

export function useChatSession() {
  const ctx = useContext(ChatSessionContext)
  if (!ctx) throw new Error('useChatSession must be used within ChatSessionProvider')
  return ctx
}
