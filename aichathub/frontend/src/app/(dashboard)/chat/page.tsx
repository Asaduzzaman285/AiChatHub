'use client'

import { useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Button } from '@/components/ui/Button'
import apiClient from '@/lib/api-client'
import { cn, formatCurrency } from '@/lib/utils'
import { useAuthStore } from '@/stores/auth-store'
import type { AiModel, ChatMessage, ChatSession } from '@/types'

// Local-only shape for a message still streaming in — not yet the persisted
// ChatMessage record from the backend (that only exists once chat-service
// has saved it, a beat after the stream finishes).
interface StreamingMessage {
  role: 'user' | 'assistant'
  content: string
}

export default function ChatPage() {
  const queryClient = useQueryClient()
  const { accessToken } = useAuthStore()

  const [activeSessionId, setActiveSessionId] = useState<string | null>(null)
  const [pendingModelId, setPendingModelId] = useState<string>('')
  const [input, setInput] = useState('')
  const [isStreaming, setIsStreaming] = useState(false)
  const [streamingMessages, setStreamingMessages] = useState<StreamingMessage[]>([])
  const scrollRef = useRef<HTMLDivElement>(null)

  const { data: models } = useQuery({
    queryKey: ['models'],
    queryFn: async () => (await apiClient.get<{ models: AiModel[] }>('/api/v1/models')).data.models,
  })

  const { data: sessions, isLoading: sessionsLoading } = useQuery({
    queryKey: ['chat', 'sessions'],
    queryFn: async () => (await apiClient.get<{ sessions: ChatSession[] }>('/api/v1/sessions')).data.sessions,
  })

  const { data: messages } = useQuery({
    queryKey: ['chat', 'messages', activeSessionId],
    queryFn: async () =>
      (await apiClient.get<{ messages: ChatMessage[] }>(`/api/v1/sessions/${activeSessionId}/messages`)).data.messages,
    enabled: !!activeSessionId,
  })

  const availableModels = useMemo(() => (models ?? []).filter((m) => m.type === 'text' && m.available), [models])

  useEffect(() => {
    if (!pendingModelId && availableModels.length > 0) {
      setPendingModelId(availableModels[0].id)
    }
  }, [availableModels, pendingModelId])

  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' })
  }, [messages, streamingMessages])

  const activeSession = sessions?.find((s) => s.id === activeSessionId) ?? null
  const activeModel = models?.find((m) => m.id === activeSession?.model_id) ?? null

  const createSession = useMutation({
    mutationFn: async (modelId: string) =>
      (await apiClient.post<{ session: ChatSession }>('/api/v1/sessions', { model_id: modelId })).data.session,
    onSuccess: (session) => {
      queryClient.invalidateQueries({ queryKey: ['chat', 'sessions'] })
      setActiveSessionId(session.id)
    },
    onError: () => toast.error('Could not start a new chat.'),
  })

  const send = async () => {
    const text = input.trim()
    if (!text || !activeSession || !activeModel || isStreaming) return

    setInput('')
    setIsStreaming(true)
    setStreamingMessages([{ role: 'user', content: text }, { role: 'assistant', content: '' }])

    try {
      const res = await fetch(`${process.env.NEXT_PUBLIC_API_URL}/api/v1/chat/stream`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'text/event-stream',
          Authorization: `Bearer ${accessToken}`,
        },
        body: JSON.stringify({
          message: text,
          model_id: activeModel.model_id,
          session_id: activeSession.id,
        }),
      })

      if (!res.ok || !res.body) {
        const data = await res.json().catch(() => null)
        throw new Error(data?.error ?? 'Chat request failed.')
      }

      const reader = res.body.getReader()
      const decoder = new TextDecoder()
      let buffer = ''
      let assistantText = ''

      while (true) {
        const { done, value } = await reader.read()
        if (done) break
        buffer += decoder.decode(value, { stream: true })

        const lines = buffer.split('\n\n')
        buffer = lines.pop() ?? ''

        for (const line of lines) {
          const payload = line.replace(/^data:\s*/, '').trim()
          if (!payload || payload === '[DONE]') continue

          const event = JSON.parse(payload)
          if (event.type === 'text-delta') {
            assistantText += event.delta
            setStreamingMessages([{ role: 'user', content: text }, { role: 'assistant', content: assistantText }])
          }
        }
      }
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Chat request failed.')
    } finally {
      setIsStreaming(false)
      setStreamingMessages([])
      // The assistant reply is persisted by ai-gateway a beat after the stream
      // ends — refetch shortly after so it lands in the real message list.
      queryClient.invalidateQueries({ queryKey: ['chat', 'messages', activeSessionId] })
      queryClient.invalidateQueries({ queryKey: ['chat', 'sessions'] })
      queryClient.invalidateQueries({ queryKey: ['wallet'] })
    }
  }

  return (
    <div className="flex h-[calc(100vh-4rem)] -m-6">
      {/* Session list */}
      <div className="w-64 shrink-0 border-r border-border flex flex-col">
        <div className="p-3 space-y-2 border-b border-border">
          <select
            value={pendingModelId}
            onChange={(e) => setPendingModelId(e.target.value)}
            className="w-full rounded-md border border-input bg-background px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-ring"
          >
            {availableModels.length === 0 && <option value="">No models available</option>}
            {availableModels.map((m) => (
              <option key={m.id} value={m.id}>{m.name}</option>
            ))}
          </select>
          <Button
            className="w-full"
            disabled={!pendingModelId || createSession.isPending}
            onClick={() => createSession.mutate(pendingModelId)}
          >
            {createSession.isPending ? 'Starting…' : 'New chat'}
          </Button>
        </div>

        <div className="flex-1 overflow-y-auto">
          {sessionsLoading ? (
            <p className="p-3 text-xs text-muted-foreground">Loading…</p>
          ) : !sessions?.length ? (
            <p className="p-3 text-xs text-muted-foreground">No chats yet — pick a model and start one.</p>
          ) : (
            sessions.map((s) => (
              <button
                key={s.id}
                onClick={() => setActiveSessionId(s.id)}
                className={cn(
                  'w-full text-left px-3 py-2 text-sm border-b border-border hover:bg-accent transition-colors',
                  s.id === activeSessionId && 'bg-accent'
                )}
              >
                <p className="truncate font-medium">{s.title}</p>
                <p className="text-xs text-muted-foreground">
                  {s.message_count} msgs · {formatCurrency(s.total_cost)}
                </p>
              </button>
            ))
          )}
        </div>
      </div>

      {/* Conversation */}
      <div className="flex-1 flex flex-col">
        {!activeSession ? (
          <div className="flex-1 flex items-center justify-center text-sm text-muted-foreground">
            {availableModels.length === 0
              ? "Your plan doesn't include any chat models yet — check Pricing."
              : 'Pick a model on the left and start a new chat.'}
          </div>
        ) : (
          <>
            <div className="border-b border-border px-4 py-2">
              <p className="text-sm font-medium">{activeSession.title}</p>
              <p className="text-xs text-muted-foreground">{activeModel?.name ?? 'Unknown model'}</p>
            </div>

            <div ref={scrollRef} className="flex-1 overflow-y-auto p-4 space-y-4">
              {messages?.map((m) => (
                <MessageBubble key={m.id} role={m.role} content={m.content} />
              ))}
              {streamingMessages.map((m, i) => (
                <MessageBubble key={`streaming-${i}`} role={m.role} content={m.content || '…'} />
              ))}
            </div>

            <div className="border-t border-border p-3">
              <form
                onSubmit={(e) => { e.preventDefault(); send() }}
                className="flex gap-2"
              >
                <input
                  value={input}
                  onChange={(e) => setInput(e.target.value)}
                  disabled={isStreaming}
                  placeholder="Message the model…"
                  className="flex-1 rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring disabled:opacity-50"
                />
                <Button type="submit" disabled={isStreaming || !input.trim()}>
                  {isStreaming ? 'Sending…' : 'Send'}
                </Button>
              </form>
            </div>
          </>
        )}
      </div>
    </div>
  )
}

function MessageBubble({ role, content }: { role: string; content: string }) {
  const isUser = role === 'user'
  return (
    <div className={cn('flex', isUser ? 'justify-end' : 'justify-start')}>
      <div
        className={cn(
          'max-w-[75%] rounded-lg px-3 py-2 text-sm whitespace-pre-wrap',
          isUser ? 'bg-primary text-primary-foreground' : 'bg-card border border-border'
        )}
      >
        {content}
      </div>
    </div>
  )
}
