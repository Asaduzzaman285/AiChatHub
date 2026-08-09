'use client'

import { useEffect, useMemo, useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  Bot, Columns3, Loader2, Paperclip, Send, Sparkles, User, X,
} from 'lucide-react'
import { Button } from '@/components/ui/Button'
import apiClient from '@/lib/api-client'
import { cn } from '@/lib/utils'
import { useAuthStore } from '@/stores/auth-store'
import { useChatSession } from '@/contexts/ChatSessionContext'
import type { AiModel, ChatMessage, FileAttachment } from '@/types'

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
  const { sessions, activeSessionId, setActiveSessionId, createSession } = useChatSession()

  const [showCompare, setShowCompare] = useState(false)
  const [pendingModelId, setPendingModelId] = useState<string>('')
  // The model used for the NEXT message sent in the active session — independent of
  // chat_sessions.model_id (which just reflects the most recently used one, for
  // display). Switching this does not create a new session or clear history.
  const [activeModelId, setActiveModelId] = useState<string>('')
  const [input, setInput] = useState('')
  const [isStreaming, setIsStreaming] = useState(false)
  const [streamingMessages, setStreamingMessages] = useState<StreamingMessage[]>([])
  const [pendingAttachment, setPendingAttachment] = useState<FileAttachment | null>(null)
  const scrollRef = useRef<HTMLDivElement>(null)
  const fileInputRef = useRef<HTMLInputElement>(null)

  // Compare — stateless fan-out (no session_id, nothing persisted to chat-service),
  // shown as a toggle within this same pane rather than a full-screen mode that used
  // to hide the sidebar/session list entirely.
  const [compareModelIds, setCompareModelIds] = useState<string[]>([])
  const [compareInput, setCompareInput] = useState('')
  const [isComparing, setIsComparing] = useState(false)
  const [compareResults, setCompareResults] = useState<Record<string, { text: string; error?: string }>>({})

  const { data: models } = useQuery({
    queryKey: ['models'],
    queryFn: async () => (await apiClient.get<{ models: AiModel[] }>('/api/v1/models')).data.models,
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

  // Switching to a different session resets which model the input box will use back
  // to that session's most-recently-used one — switching model doesn't follow you
  // across sessions.
  useEffect(() => {
    if (activeSession) setActiveModelId(activeSession.model_id)
  }, [activeSession?.id]) // eslint-disable-line react-hooks/exhaustive-deps

  const selectedModel = models?.find((m) => m.id === activeModelId) ?? null

  const uploadAttachment = async (file: File) => {
    const formData = new FormData()
    formData.append('file', file)
    if (activeSession) formData.append('session_id', activeSession.id)
    try {
      // Overriding the instance's default 'application/json' — axios drops this and
      // lets the browser set the correct multipart boundary when the body is FormData.
      const res = await apiClient.post<{ attachment: FileAttachment }>('/api/v1/upload', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      setPendingAttachment(res.data.attachment)
    } catch (err: unknown) {
      const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast.error(message ?? "We couldn't upload that file — please try again.")
    }
  }

  const [uploading, setUploading] = useState(false)
  const handleFileSelect = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    e.target.value = '' // allow selecting the same file again later
    if (!file) return
    setUploading(true)
    await uploadAttachment(file)
    setUploading(false)
  }

  const send = async () => {
    const text = input.trim()
    if (!text || !activeSession || !selectedModel || isStreaming || uploading) return

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
    <div className="flex h-screen flex-col">
      {!activeSession && !showCompare ? (
        <div className="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-sm text-muted-foreground">
          <Sparkles className="h-8 w-8 text-muted-foreground/40" />
          {availableModels.length === 0 ? (
            <p>Your plan doesn&apos;t include any chat models yet — check Plans in Settings.</p>
          ) : (
            <>
              <p>Pick a model and start a new chat.</p>
              <div className="flex items-center gap-2">
                <select
                  value={pendingModelId}
                  onChange={(e) => setPendingModelId(e.target.value)}
                  className="rounded-md border border-input bg-background px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-ring"
                >
                  {availableModels.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                </select>
                <Button disabled={!pendingModelId || createSession.isPending} onClick={() => createSession.mutate(pendingModelId)}>
                  {createSession.isPending ? 'Starting…' : 'New chat'}
                </Button>
                <Button variant="outline" className="gap-1.5" onClick={() => setShowCompare(true)}>
                  <Columns3 className="h-4 w-4" />
                  Compare models
                </Button>
              </div>
            </>
          )}
        </div>
      ) : showCompare ? (
        <div className="flex flex-1 flex-col overflow-hidden">
          <div className="border-b border-border p-3 space-y-2">
            <div className="flex items-center justify-between">
              <p className="text-xs text-muted-foreground">Pick 2–4 models, send one message, see every response side by side.</p>
              <button onClick={() => setShowCompare(false)} className="text-xs text-muted-foreground hover:text-foreground">
                Back to chat
              </button>
            </div>
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
        <>
          <div className="border-b border-border px-4 py-2 flex items-center justify-between gap-3">
            <p className="text-sm font-medium truncate">{activeSession!.title}</p>
            <div className="flex items-center gap-2">
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
                {availableModels.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
              </select>
              <Button variant="outline" className="gap-1.5 px-2.5 py-1.5 text-xs" onClick={() => setShowCompare(true)}>
                <Columns3 className="h-3.5 w-3.5" />
                Compare
              </Button>
            </div>
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
            {uploading && (
              <div className="inline-flex items-center gap-2 rounded-md border border-border bg-accent/50 px-2 py-1 text-xs text-muted-foreground">
                <Loader2 className="h-3.5 w-3.5 animate-spin" />
                Uploading…
              </div>
            )}
            {pendingAttachment && !uploading && (
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
            <form onSubmit={(e) => { e.preventDefault(); send() }} className="flex gap-2">
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
                    disabled={isStreaming || uploading}
                    onClick={() => fileInputRef.current?.click()}
                    aria-label="Attach image"
                  >
                    {uploading ? <Loader2 className="h-4 w-4 animate-spin" /> : <Paperclip className="h-4 w-4" />}
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
              <Button type="submit" disabled={isStreaming || uploading || !input.trim()} className="gap-1.5">
                <Send className="h-4 w-4" />
                {isStreaming ? 'Sending…' : 'Send'}
              </Button>
            </form>
          </div>
        </>
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
