'use client'

import { useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  Bot, Columns3, Loader2, MessageSquare, Paperclip, Pencil, Plus, Send, Sparkles, Trash2, User, X,
} from 'lucide-react'
import { Button } from '@/components/ui/Button'
import apiClient from '@/lib/api-client'
import { cn, formatCurrency } from '@/lib/utils'
import { useAuthStore } from '@/stores/auth-store'
import type { AiModel, ChatMessage, ChatSession, FileAttachment } from '@/types'

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

  const [mode, setMode] = useState<'chat' | 'compare'>('chat')
  const [activeSessionId, setActiveSessionId] = useState<string | null>(null)
  const [pendingModelId, setPendingModelId] = useState<string>('')
  // The model used for the NEXT message sent in the active session — independent of
  // chat_sessions.model_id (which just reflects the most recently used one, for
  // display). Switching this does not create a new session or clear history.
  const [activeModelId, setActiveModelId] = useState<string>('')
  const [input, setInput] = useState('')
  const [isStreaming, setIsStreaming] = useState(false)
  const [streamingMessages, setStreamingMessages] = useState<StreamingMessage[]>([])
  const [pendingAttachment, setPendingAttachment] = useState<FileAttachment | null>(null)
  const [renamingId, setRenamingId] = useState<string | null>(null)
  const [renameValue, setRenameValue] = useState('')
  const scrollRef = useRef<HTMLDivElement>(null)
  const fileInputRef = useRef<HTMLInputElement>(null)
  const renameInputRef = useRef<HTMLInputElement>(null)

  // Compare mode — separate from the session-based chat above: /chat/compare is a
  // stateless fan-out (no session_id, nothing persisted to chat-service), so it gets
  // its own small piece of state rather than being bolted onto the session flow.
  const [compareModelIds, setCompareModelIds] = useState<string[]>([])
  const [compareInput, setCompareInput] = useState('')
  const [isComparing, setIsComparing] = useState(false)
  const [compareResults, setCompareResults] = useState<Record<string, { text: string; error?: string }>>({})

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

  useEffect(() => {
    if (renamingId) renameInputRef.current?.focus()
  }, [renamingId])

  const activeSession = sessions?.find((s) => s.id === activeSessionId) ?? null

  // Switching to a different session resets which model the input box will use back
  // to that session's most-recently-used one — switching model doesn't follow you
  // across sessions.
  useEffect(() => {
    if (activeSession) setActiveModelId(activeSession.model_id)
  }, [activeSession?.id]) // eslint-disable-line react-hooks/exhaustive-deps

  const selectedModel = models?.find((m) => m.id === activeModelId) ?? null

  const createSession = useMutation({
    mutationFn: async (modelId: string) =>
      (await apiClient.post<{ session: ChatSession }>('/api/v1/sessions', { model_id: modelId })).data.session,
    onSuccess: (session) => {
      queryClient.invalidateQueries({ queryKey: ['chat', 'sessions'] })
      setActiveSessionId(session.id)
    },
    onError: () => toast.error('Could not start a new chat.'),
  })

  const renameSession = useMutation({
    mutationFn: async ({ id, title }: { id: string; title: string }) =>
      apiClient.patch(`/api/v1/sessions/${id}`, { title }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['chat', 'sessions'] }),
    onError: () => toast.error('Could not rename chat.'),
    onSettled: () => setRenamingId(null),
  })

  const deleteSession = useMutation({
    mutationFn: async (id: string) => apiClient.delete(`/api/v1/sessions/${id}`),
    onSuccess: (_res, id) => {
      queryClient.invalidateQueries({ queryKey: ['chat', 'sessions'] })
      if (activeSessionId === id) setActiveSessionId(null)
      toast.success('Chat deleted.')
    },
    onError: () => toast.error('Could not delete chat.'),
  })

  const startRename = (s: ChatSession) => {
    setRenamingId(s.id)
    setRenameValue(s.title)
  }

  const commitRename = () => {
    const title = renameValue.trim()
    if (!renamingId) return
    if (!title) { setRenamingId(null); return }
    renameSession.mutate({ id: renamingId, title })
  }

  const confirmDelete = (s: ChatSession) => {
    if (window.confirm(`Delete "${s.title}"? This can't be undone.`)) {
      deleteSession.mutate(s.id)
    }
  }

  const uploadAttachment = useMutation({
    mutationFn: async (file: File) => {
      const formData = new FormData()
      formData.append('file', file)
      if (activeSession) formData.append('session_id', activeSession.id)
      // Overriding the instance's default 'application/json' — axios drops this and
      // lets the browser set the correct multipart boundary when the body is FormData.
      const res = await apiClient.post<{ attachment: FileAttachment }>('/api/v1/upload', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return res.data.attachment
    },
    onSuccess: (attachment) => setPendingAttachment(attachment),
    onError: (err: unknown) => {
      const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast.error(message ?? 'Upload failed.')
    },
  })

  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    e.target.value = '' // allow selecting the same file again later
    if (file) uploadAttachment.mutate(file)
  }

  const send = async () => {
    const text = input.trim()
    if (!text || !activeSession || !selectedModel || isStreaming || uploadAttachment.isPending) return

    const attachmentIds = pendingAttachment ? [pendingAttachment.id] : undefined
    // Conversation context — without this every message was being sent with zero
    // awareness of prior turns. Cap it to avoid an unbounded prompt as chats grow;
    // providers will trim further to their own context window regardless.
    const history = (messages ?? [])
      .slice(-30)
      .map((m) => ({ role: m.role, content: m.content }))
      .filter((m) => m.role === 'user' || m.role === 'assistant')

    setInput('')
    setPendingAttachment(null)
    setIsStreaming(true)
    setStreamingMessages([{ role: 'user', content: text }, { role: 'assistant', content: '' }])

    // Without this, a hung backend call (e.g. a cold container timing out
    // talking to another service) leaves the UI looking like it did nothing —
    // no error, no response, just a silently stuck "Sending…" button.
    const controller = new AbortController()
    const timeoutId = setTimeout(() => controller.abort(), 60000)

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
          model_id: selectedModel.model_id,
          session_id: activeSession.id,
          attachment_ids: attachmentIds,
          history,
        }),
        signal: controller.signal,
      })
      clearTimeout(timeoutId)

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
      const message = err instanceof Error && err.name === 'AbortError'
        ? 'The request took too long and timed out. Please try again.'
        : err instanceof Error ? err.message : 'Chat request failed.'
      toast.error(message)
    } finally {
      clearTimeout(timeoutId)
      setIsStreaming(false)
      setStreamingMessages([])
      // The assistant reply is persisted by ai-gateway a beat after the stream
      // ends — refetch shortly after so it lands in the real message list.
      queryClient.invalidateQueries({ queryKey: ['chat', 'messages', activeSessionId] })
      queryClient.invalidateQueries({ queryKey: ['chat', 'sessions'] })
      queryClient.invalidateQueries({ queryKey: ['wallet'] })
    }
  }

  const toggleCompareModel = (id: string) => {
    setCompareModelIds((prev) =>
      prev.includes(id) ? prev.filter((m) => m !== id) : prev.length >= 4 ? prev : [...prev, id]
    )
  }

  const runCompare = async () => {
    const text = compareInput.trim()
    if (!text || compareModelIds.length < 2 || isComparing) return

    const models = compareModelIds
      .map((id) => availableModels.find((m) => m.id === id))
      .filter((m): m is AiModel => !!m)

    setIsComparing(true)
    setCompareResults(Object.fromEntries(models.map((m) => [m.model_id, { text: '' }])))

    const controller = new AbortController()
    const timeoutId = setTimeout(() => controller.abort(), 90000)

    try {
      const res = await fetch(`${process.env.NEXT_PUBLIC_API_URL}/api/v1/chat/compare`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'text/event-stream',
          Authorization: `Bearer ${accessToken}`,
        },
        body: JSON.stringify({ message: text, model_ids: models.map((m) => m.model_id) }),
        signal: controller.signal,
      })
      clearTimeout(timeoutId)

      if (!res.ok || !res.body) {
        throw new Error('Compare request failed.')
      }

      const reader = res.body.getReader()
      const decoder = new TextDecoder()
      let buffer = ''

      while (true) {
        const { done, value } = await reader.read()
        if (done) break
        buffer += decoder.decode(value, { stream: true })

        const lines = buffer.split('\n\n')
        buffer = lines.pop() ?? ''

        for (const line of lines) {
          const payload = line.replace(/^data:\s*/, '').trim()
          if (!payload) continue

          const event = JSON.parse(payload) as { model: string; chunk?: string; error?: string }
          setCompareResults((prev) => ({
            ...prev,
            [event.model]: event.error
              ? { text: prev[event.model]?.text ?? '', error: event.error }
              : { text: (prev[event.model]?.text ?? '') + (event.chunk ?? '') },
          }))
        }
      }
    } catch (err) {
      const message = err instanceof Error && err.name === 'AbortError'
        ? 'The comparison took too long and timed out.'
        : err instanceof Error ? err.message : 'Compare request failed.'
      toast.error(message)
    } finally {
      clearTimeout(timeoutId)
      setIsComparing(false)
      queryClient.invalidateQueries({ queryKey: ['wallet'] })
    }
  }

  return (
    <div className="flex flex-col h-[calc(100vh-4rem)] -m-6">
      {/* Mode tabs */}
      <div className="flex border-b border-border px-2">
        <button
          onClick={() => setMode('chat')}
          className={cn(
            'flex items-center gap-1.5 px-3 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors',
            mode === 'chat' ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'
          )}
        >
          <MessageSquare className="h-4 w-4" />
          Chat
        </button>
        <button
          onClick={() => setMode('compare')}
          className={cn(
            'flex items-center gap-1.5 px-3 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors',
            mode === 'compare' ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'
          )}
        >
          <Columns3 className="h-4 w-4" />
          Compare
        </button>
      </div>

      {mode === 'compare' ? (
        <div className="flex-1 flex flex-col overflow-hidden">
          <div className="border-b border-border p-3 space-y-2">
            <p className="text-xs text-muted-foreground">Pick 2–4 models, send one message, see every response side by side.</p>
            <div className="flex flex-wrap gap-2">
              {availableModels.length === 0 && <p className="text-xs text-muted-foreground">No models available on your plan.</p>}
              {availableModels.map((m) => (
                <label
                  key={m.id}
                  className={cn(
                    'flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs cursor-pointer transition-colors',
                    compareModelIds.includes(m.id) ? 'border-primary bg-primary/10' : 'border-input'
                  )}
                >
                  <input
                    type="checkbox"
                    checked={compareModelIds.includes(m.id)}
                    onChange={() => toggleCompareModel(m.id)}
                    disabled={!compareModelIds.includes(m.id) && compareModelIds.length >= 4}
                    className="sr-only"
                  />
                  {m.name}
                </label>
              ))}
            </div>
            <form onSubmit={(e) => { e.preventDefault(); runCompare() }} className="flex gap-2">
              <input
                value={compareInput}
                onChange={(e) => setCompareInput(e.target.value)}
                disabled={isComparing}
                placeholder="Message all selected models…"
                className="flex-1 rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring disabled:opacity-50"
              />
              <Button type="submit" disabled={isComparing || !compareInput.trim() || compareModelIds.length < 2}>
                {isComparing ? 'Comparing…' : `Compare (${compareModelIds.length})`}
              </Button>
            </form>
          </div>

          <div className="flex-1 overflow-x-auto">
            <div className="flex h-full divide-x divide-border" style={{ minWidth: `${Object.keys(compareResults).length * 320}px` }}>
              {Object.entries(compareResults).map(([modelId, result]) => {
                const model = availableModels.find((m) => m.model_id === modelId)
                return (
                  <div key={modelId} className="flex-1 min-w-[320px] flex flex-col">
                    <div className="border-b border-border px-3 py-2 text-sm font-medium">{model?.name ?? modelId}</div>
                    <div className="flex-1 overflow-y-auto p-3 text-sm whitespace-pre-wrap">
                      {result.error ? <span className="text-destructive">{result.error}</span> : result.text || '…'}
                    </div>
                  </div>
                )
              })}
              {Object.keys(compareResults).length === 0 && (
                <div className="flex-1 flex items-center justify-center text-sm text-muted-foreground">
                  Select models above and send a message to compare responses.
                </div>
              )}
            </div>
          </div>
        </div>
      ) : (
      <div className="flex flex-1 overflow-hidden">
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
            className="w-full gap-1.5"
            disabled={!pendingModelId || createSession.isPending}
            onClick={() => createSession.mutate(pendingModelId)}
          >
            <Plus className="h-4 w-4" />
            {createSession.isPending ? 'Starting…' : 'New chat'}
          </Button>
        </div>

        <div className="flex-1 overflow-y-auto">
          {sessionsLoading ? (
            <p className="p-3 text-xs text-muted-foreground">Loading…</p>
          ) : !sessions?.length ? (
            <div className="p-6 text-center space-y-2">
              <MessageSquare className="h-8 w-8 mx-auto text-muted-foreground/40" />
              <p className="text-xs text-muted-foreground">No chats yet — pick a model and start one.</p>
            </div>
          ) : (
            sessions.map((s) => (
              <div
                key={s.id}
                className={cn(
                  'group w-full flex items-center gap-1 px-3 py-2.5 border-b border-border hover:bg-accent transition-colors',
                  s.id === activeSessionId && 'bg-accent'
                )}
              >
                {renamingId === s.id ? (
                  <input
                    ref={renameInputRef}
                    value={renameValue}
                    onChange={(e) => setRenameValue(e.target.value)}
                    onBlur={commitRename}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') commitRename()
                      if (e.key === 'Escape') setRenamingId(null)
                    }}
                    className="flex-1 min-w-0 rounded border border-input bg-background px-1.5 py-0.5 text-sm"
                  />
                ) : (
                  <button onClick={() => setActiveSessionId(s.id)} className="flex-1 min-w-0 text-left">
                    <p className="truncate text-sm font-medium">{s.title}</p>
                    <p className="text-xs text-muted-foreground">
                      {s.message_count} msgs · {formatCurrency(s.total_cost)}
                    </p>
                  </button>
                )}
                {renamingId !== s.id && (
                  <div className="flex shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button
                      onClick={() => startRename(s)}
                      className="p-1 text-muted-foreground hover:text-foreground"
                      aria-label="Rename chat"
                    >
                      <Pencil className="h-3.5 w-3.5" />
                    </button>
                    <button
                      onClick={() => confirmDelete(s)}
                      className="p-1 text-muted-foreground hover:text-destructive"
                      aria-label="Delete chat"
                    >
                      <Trash2 className="h-3.5 w-3.5" />
                    </button>
                  </div>
                )}
              </div>
            ))
          )}
        </div>
      </div>

      {/* Conversation */}
      <div className="flex-1 flex flex-col">
        {!activeSession ? (
          <div className="flex-1 flex flex-col items-center justify-center gap-2 text-sm text-muted-foreground">
            <Sparkles className="h-8 w-8 text-muted-foreground/40" />
            <p>
              {availableModels.length === 0
                ? "Your plan doesn't include any chat models yet — check Pricing."
                : 'Pick a model on the left and start a new chat.'}
            </p>
          </div>
        ) : (
          <>
            <div className="border-b border-border px-4 py-2 flex items-center justify-between gap-3">
              <p className="text-sm font-medium truncate">{activeSession.title}</p>
              {/* Model stays switchable for the rest of the conversation — this does
                  NOT create a new session or clear history, just changes which model
                  answers the next message. */}
              <select
                value={activeModelId}
                onChange={(e) => setActiveModelId(e.target.value)}
                disabled={isStreaming}
                className="shrink-0 rounded-md border border-input bg-background px-2 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-ring disabled:opacity-50"
              >
                {availableModels.length === 0 && <option value="">No models available</option>}
                {availableModels.map((m) => (
                  <option key={m.id} value={m.id}>{m.name}</option>
                ))}
              </select>
            </div>

            <div ref={scrollRef} className="flex-1 overflow-y-auto p-4 space-y-4">
              {!messages?.length && !streamingMessages.length && (
                <div className="h-full flex flex-col items-center justify-center gap-2 text-muted-foreground">
                  <Bot className="h-8 w-8 text-muted-foreground/40" />
                  <p className="text-sm">Say hello to get started.</p>
                </div>
              )}
              {messages?.map((m) => (
                <MessageBubble
                  key={m.id}
                  role={m.role}
                  content={m.content}
                  modelName={m.model_id ? models?.find((mo) => mo.id === m.model_id)?.name : undefined}
                />
              ))}
              {streamingMessages.map((m, i) => (
                <MessageBubble
                  key={`streaming-${i}`}
                  role={m.role}
                  content={m.content || '…'}
                  modelName={m.role === 'assistant' ? selectedModel?.name : undefined}
                />
              ))}
            </div>

            <div className="border-t border-border p-3 space-y-2">
              {uploadAttachment.isPending && (
                <div className="inline-flex items-center gap-2 rounded-md border border-border bg-accent/50 px-2 py-1 text-xs text-muted-foreground">
                  <Loader2 className="h-3.5 w-3.5 animate-spin" />
                  Uploading…
                </div>
              )}
              {pendingAttachment && !uploadAttachment.isPending && (
                <div className="inline-flex items-center gap-2 rounded-md border border-border bg-accent/50 px-2 py-1 text-xs">
                  <img src={pendingAttachment.storage_url} alt="" className="h-6 w-6 rounded object-cover" />
                  <span className="max-w-[160px] truncate">{pendingAttachment.original_name}</span>
                  <span className="text-green-600">✓</span>
                  <button
                    type="button"
                    onClick={() => setPendingAttachment(null)}
                    className="text-muted-foreground hover:text-foreground"
                    aria-label="Remove attachment"
                  >
                    <X className="h-3.5 w-3.5" />
                  </button>
                </div>
              )}
              <form
                onSubmit={(e) => { e.preventDefault(); send() }}
                className="flex gap-2"
              >
                {selectedModel?.capabilities.vision && (
                  <>
                    <input
                      ref={fileInputRef}
                      type="file"
                      accept="image/jpeg,image/png,image/webp,image/gif"
                      onChange={handleFileSelect}
                      className="hidden"
                    />
                    <Button
                      type="button"
                      variant="outline"
                      disabled={isStreaming || uploadAttachment.isPending}
                      onClick={() => fileInputRef.current?.click()}
                      aria-label="Attach image"
                    >
                      {uploadAttachment.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Paperclip className="h-4 w-4" />}
                    </Button>
                  </>
                )}
                <input
                  value={input}
                  onChange={(e) => setInput(e.target.value)}
                  disabled={isStreaming}
                  placeholder="Message the model…"
                  className="flex-1 rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring disabled:opacity-50"
                />
                <Button type="submit" disabled={isStreaming || uploadAttachment.isPending || !input.trim()} className="gap-1.5">
                  <Send className="h-4 w-4" />
                  {isStreaming ? 'Sending…' : 'Send'}
                </Button>
              </form>
            </div>
          </>
        )}
      </div>
      </div>
      )}
    </div>
  )
}

function MessageBubble({ role, content, modelName }: { role: string; content: string; modelName?: string }) {
  const isUser = role === 'user'
  return (
    <div className={cn('flex items-start gap-2', isUser ? 'justify-end' : 'justify-start')}>
      {!isUser && (
        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-accent">
          <Bot className="h-4 w-4 text-muted-foreground" />
        </div>
      )}
      <div className={cn('max-w-[75%] space-y-1', isUser && 'flex flex-col items-end')}>
        {!isUser && modelName && <p className="text-[11px] text-muted-foreground px-1">{modelName}</p>}
        <div
          className={cn(
            'rounded-lg px-3 py-2 text-sm whitespace-pre-wrap',
            isUser ? 'bg-primary text-primary-foreground' : 'bg-card border border-border'
          )}
        >
          {content}
        </div>
      </div>
      {isUser && (
        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10">
          <User className="h-4 w-4 text-primary" />
        </div>
      )}
    </div>
  )
}
