'use client'

import { useEffect, useMemo, useRef, useState } from 'react'
import { useRouter, useSearchParams } from 'next/navigation'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { formatDistanceToNow } from 'date-fns'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import rehypeHighlight from 'rehype-highlight'
import {
  AlertTriangle, ArrowUp, Bot, ChevronDown, Code2, Compass, Download, FileText, FolderClock,
  GraduationCap, Loader2, Lock, Plus, Sparkles, Upload, User, Wand2, X,
} from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/Dialog'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/DropdownMenu'
import { ModelIcon } from '@/components/chat/ModelIcon'
import { CompareCardGroup, type CompareCardData } from '@/components/chat/CompareCardGroup'
import { PrivateChatPopover } from '@/components/chat/PrivateChatPopover'
import { WalletBalanceChip } from '@/components/wallet/WalletBalanceChip'
import apiClient from '@/lib/api-client'
import { cn, formatUsage } from '@/lib/utils'
import { estimateTokens, buildBoundedHistory, DEFAULT_CONTEXT_WINDOW, DEFAULT_MAX_OUTPUT_TOKENS } from '@/lib/tokenEstimate'
import { useAuthStore } from '@/stores/auth-store'
import { useChatSession } from '@/contexts/ChatSessionContext'
import { useAvailableModels } from '@/hooks/useAvailableModels'
import type { AiModel, ChatMessage, FileAttachment } from '@/types'

// Local-only shape for a message still streaming in — not yet the persisted
// ChatMessage record from the backend (that only exists once chat-service
// has saved it, a beat after the stream finishes).
interface StreamingMessage {
  role: 'user' | 'assistant'
  content: string
}

// Empty-state quick actions — pre-fill the composer with a starting template rather
// than sending immediately, since they're categories, not complete prompts.
const QUICK_ACTIONS = [
  { label: 'Create', icon: Sparkles, prompt: 'Help me create ' },
  { label: 'Explore', icon: Compass, prompt: 'Explain ' },
  { label: 'Code', icon: Code2, prompt: 'Write code that ' },
  { label: 'Learn', icon: GraduationCap, prompt: 'Teach me about ' },
]

// Empty-state suggested questions — complete prompts, so clicking one sends
// immediately instead of just filling the box.
const SUGGESTED_QUESTIONS = [
  'Compare two AI models for a coding task',
  'Summarize a long document in three bullet points',
  'Write a marketing email for a product launch',
  'Explain a complex topic simply',
]

export default function ChatPage() {
  const router = useRouter()
  const searchParams = useSearchParams()
  const queryClient = useQueryClient()
  const { accessToken } = useAuthStore()
  const { sessions, activeSessionId, setActiveSessionId, createSession } = useChatSession()

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

  // Compare mode — a toggle on the same composer rather than a separate view. Off:
  // activeModelId alone answers. On: activeModelId plus whatever's in
  // compareExtraModelIds (up to 3 more, 4 total) all answer the same message,
  // fanned out via /chat/compare (stateless — no session_id, nothing persisted to
  // chat-service, same as before). Results render inline as their own turn.
  const [compareMode, setCompareMode] = useState(false)
  const [compareExtraModelIds, setCompareExtraModelIds] = useState<string[]>([])
  const [isComparing, setIsComparing] = useState(false)
  interface CompareTurn {
    id: string
    prompt: string
    modelIds: string[]
    results: Record<string, {
      text: string
      error?: string
      cost?: number | null
      promptTokens?: number | null
      completionTokens?: number | null
      done?: boolean
    }>
  }
  const [compareTurns, setCompareTurns] = useState<CompareTurn[]>([])
  // "Not preferred" dismissals — keyed by CompareCard's cardKey (message id for
  // persisted cards, `${turnId}:${modelId}` for live-streaming ones). Purely a display
  // filter; the underlying persisted message (if any) is never touched.
  const [dismissedCardKeys, setDismissedCardKeys] = useState<Set<string>>(new Set())

  // Manual "compact conversation" — see handleCompact. Stateless (like sendCompareTurn's
  // unsaved mode): the summary only exists in this panel until the user downloads it or
  // carries it into a new chat, nothing is persisted until then.
  const [compacting, setCompacting] = useState(false)
  const [compactionSummary, setCompactionSummary] = useState<string | null>(null)
  const [showCompactionPanel, setShowCompactionPanel] = useState(false)
  const [startingNewChat, setStartingNewChat] = useState(false)

  const { models, availableModels } = useAvailableModels()

  const { data: messages } = useQuery({
    queryKey: ['chat', 'messages', activeSessionId],
    queryFn: async () =>
      (await apiClient.get<{ messages: ChatMessage[] }>(`/api/v1/sessions/${activeSessionId}/messages`)).data.messages,
    enabled: !!activeSessionId,
  })

  // Recently uploaded files (any session, most recent first) — lets the "+" menu offer a
  // quick re-attach instead of forcing a fresh pick from disk every time, same as ChatGPT.
  const { data: recentFiles } = useQuery({
    queryKey: ['uploads', 'recent'],
    queryFn: async () => (await apiClient.get<{ attachments: FileAttachment[] }>('/api/v1/upload/recent')).data.attachments,
    staleTime: 30_000,
  })

  // Groups consecutive persisted assistant messages that share a metadata.
  // compare_group_id back into one side-by-side card — the persisted counterpart to
  // the locally-rendered `compareTurns` while a comparison is still streaming.
  type CompareEntry = { key: string; modelId: string | null; content: string; cost: string; promptTokens: number; completionTokens: number }
  type RenderItem =
    | { kind: 'message'; message: ChatMessage }
    | { kind: 'compare'; groupId: string; entries: CompareEntry[] }
  const renderItems = useMemo(() => {
    const items: RenderItem[] = []
    for (const m of messages ?? []) {
      const groupId = m.role === 'assistant' ? m.metadata?.compare_group_id : undefined
      const entry: CompareEntry = { key: m.id, modelId: m.model_id, content: m.content, cost: m.cost, promptTokens: m.prompt_tokens, completionTokens: m.completion_tokens }
      const last = items[items.length - 1]
      if (groupId && last?.kind === 'compare' && last.groupId === groupId) {
        last.entries.push(entry)
        continue
      }
      if (groupId) {
        items.push({ kind: 'compare', groupId, entries: [entry] })
        continue
      }
      items.push({ kind: 'message', message: m })
    }
    return items
  }, [messages])

  // activeSessionId is plain component state, not persisted anywhere on its own — a
  // full page reload always starts it back at null. Without the URL round-trip below,
  // that reset used to just show a "pick a model" screen (annoying but harmless); once
  // auto-create was added beneath it, a reload would silently spin up a brand-new
  // session instead of restoring the one you were actually on, which reads as "my chat
  // closed" (confirmed live — this is a real regression from adding auto-create, not
  // pre-existing). Fixed by round-tripping the active session through a `?session=`
  // query param: this effect restores it on a fresh mount if the id in the URL still
  // refers to a real session, and only falls back to auto-creating once there's
  // nothing valid to restore. The matching effect below keeps the URL in sync going
  // forward so a reload always has something to restore from.
  // restoreAttemptedRef makes the URL check a one-shot, tried at most once per real
  // mount — deliberately NOT re-checked on every later transition to a null
  // activeSessionId (e.g. clicking "+ New chat" also passes through activeSessionId
  // === null). Without this guard, a transient render where activeSessionId has
  // already gone null but the router's URL update from that same click hasn't
  // propagated to useSearchParams() yet could read the *previous* session's id and
  // restore it instead of creating a new one — this way "+ New chat" always creates,
  // and only an actual fresh page load ever restores from the URL.
  const restoreAttemptedRef = useRef(false)
  const autoCreatingRef = useRef(false)
  useEffect(() => {
    if (activeSessionId) {
      autoCreatingRef.current = false
      return
    }
    if (autoCreatingRef.current) return

    // Wait for the sessions list before deciding — otherwise a URL-restorable session
    // would lose the race against auto-create on every cold load.
    if (!sessions) return

    if (!restoreAttemptedRef.current) {
      restoreAttemptedRef.current = true
      const sessionParam = searchParams.get('session')
      if (sessionParam && sessions.some((s) => s.id === sessionParam)) {
        setActiveSessionId(sessionParam)
        return
      }
    }

    if (availableModels.length === 0) return
    autoCreatingRef.current = true
    createSession.mutate({ modelId: availableModels[0].id })
  }, [activeSessionId, availableModels, createSession, searchParams, sessions, setActiveSessionId])

  // Keeps `?session=` in the URL matched to the active session — covers session
  // switches that don't already set it themselves (sidebar's session list does this
  // directly; this exists for the auto-create and private-chat-caret paths, which
  // don't know the new session's id until the mutation resolves).
  useEffect(() => {
    if (!activeSessionId) return
    if (searchParams.get('session') !== activeSessionId) {
      router.replace(`/chat?session=${activeSessionId}`, { scroll: false })
    }
  }, [activeSessionId, searchParams, router])

  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' })
  }, [messages, streamingMessages, compareTurns])

  const activeSession = sessions?.find((s) => s.id === activeSessionId) ?? null

  // Switching to a different session resets which model the input box will use back
  // to that session's most-recently-used one — switching model doesn't follow you
  // across sessions.
  useEffect(() => {
    if (activeSession) setActiveModelId(activeSession.model_id)
  }, [activeSession?.id]) // eslint-disable-line react-hooks/exhaustive-deps

  // Was never cleared on session switch — compareTurns is local component state, not
  // keyed by session, so switching chats (or starting a new one) kept showing whatever
  // compare results were left over from whichever session you were just in. Same class
  // of bug for streamingMessages/pendingAttachment if a switch happens mid-send.
  useEffect(() => {
    setCompareTurns([])
    setStreamingMessages([])
    setPendingAttachment(null)
    setDismissedCardKeys(new Set())
  }, [activeSessionId])

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
      queryClient.invalidateQueries({ queryKey: ['uploads', 'recent'] })
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

  // Clipboard image paste (screenshot, copied image) — only image items call
  // preventDefault(); a normal text paste falls through untouched. Chrome/Edge/Firefox
  // all put a pasted image under clipboardData.items with kind 'file' and a MIME type
  // starting with "image/" rather than as plain text, so this never fires for regular
  // copy-pasted text.
  const handlePaste = async (e: React.ClipboardEvent<HTMLInputElement>) => {
    const imageItem = Array.from(e.clipboardData.items).find((item) => item.kind === 'file' && item.type.startsWith('image/'))
    if (!imageItem) return
    e.preventDefault()
    const file = imageItem.getAsFile()
    if (!file) return
    setUploading(true)
    await uploadAttachment(file)
    setUploading(false)
  }

  // Always includes the primary (activeModelId) model — the icon row's first slot —
  // plus whatever's been added via its "+" while compare mode is on.
  const compareModelIds = useMemo(
    () => Array.from(new Set([activeModelId, ...compareExtraModelIds].filter(Boolean))),
    [activeModelId, compareExtraModelIds]
  )

  // Real per-model ceiling, replacing the old blind message-count cap (see
  // buildBoundedHistory). Compare mode uses the MINIMUM context window across every
  // currently selected model — a fair, apples-to-apples comparison, not each model
  // independently truncated to a different length.
  const contextCeiling = useMemo(() => {
    if (compareMode && compareModelIds.length >= 2) {
      const selectedModels = compareModelIds
        .map((id) => availableModels.find((m) => m.id === id))
        .filter((m): m is AiModel => !!m)
      if (selectedModels.length === 0) return null
      return Math.min(...selectedModels.map((m) => m.context_window ?? DEFAULT_CONTEXT_WINDOW))
    }
    return selectedModel ? selectedModel.context_window ?? DEFAULT_CONTEXT_WINDOW : null
  }, [compareMode, compareModelIds, availableModels, selectedModel])

  const estimatedTokensUsed = useMemo(
    () => (messages ?? []).reduce((sum, m) => sum + estimateTokens(m.content), 0) + estimateTokens(input),
    [messages, input]
  )

  const nearingContextLimit = contextCeiling != null && estimatedTokensUsed / contextCeiling >= 0.8

  const toggleCompareExtraModel = (id: string) => {
    setCompareExtraModelIds((prev) =>
      prev.includes(id) ? prev.filter((m) => m !== id) : compareModelIds.length >= 4 ? prev : [...prev, id]
    )
  }

  // "Not preferred" — dismisses one card and drops that model from the ongoing compare
  // selection (not a permanent ban: re-adding it via the existing "+" picker works
  // normally, since compareModelIds is derived fresh from this state every render).
  // modelId must already be the AiModel.id UUID by the time it reaches here — see
  // CompareCardData's own comment for why the two card sources need normalizing first.
  const handleNotPreferred = (modelId: string, cardKey: string) => {
    setDismissedCardKeys((prev) => new Set(prev).add(cardKey))

    if (modelId === activeModelId) {
      const remaining = compareModelIds.filter((id) => id !== modelId)
      if (remaining.length > 1) {
        setActiveModelId(remaining[0])
        setCompareExtraModelIds(remaining.slice(1))
      } else if (remaining.length === 1) {
        // Only one model would be left — compare mode itself stops making sense.
        // Fall back to a normal single-model chat with that model as the active one.
        setActiveModelId(remaining[0])
        setCompareExtraModelIds([])
        setCompareMode(false)
      }
    } else {
      setCompareExtraModelIds((prev) => prev.filter((id) => id !== modelId))
    }
  }

  const send = async () => {
    if (compareMode && compareModelIds.length >= 2) {
      await sendCompareTurn()
    } else {
      await sendSingle()
    }
  }

  // Optional override lets a suggested-question click send immediately instead of
  // just filling the input and waiting for a second click — setInput() is still
  // called by the caller too, so if the send fails the text isn't lost from the box.
  const sendSingle = async (textOverride?: string) => {
    const text = (textOverride ?? input).trim()
    if (!text || !activeSession || !selectedModel || isStreaming || uploading) return

    const attachmentIds = pendingAttachment ? [pendingAttachment.id] : undefined
    // Real per-model budget (see buildBoundedHistory) — replaces a blind last-30-messages
    // cap with one sized to selectedModel's actual context_window, reserving room for its
    // max_output_tokens and this new message itself.
    const ceiling = selectedModel.context_window ?? DEFAULT_CONTEXT_WINDOW
    const reserve = (selectedModel.max_output_tokens ?? DEFAULT_MAX_OUTPUT_TOKENS) + estimateTokens(text)
    const history = buildBoundedHistory(messages ?? [], ceiling, reserve)

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
      // Await the refetch BEFORE clearing the streaming placeholder — clearing it
      // first (the old order) left a real gap where neither the streaming bubble nor
      // the newly-persisted message was on screen yet, since ai-gateway writes the
      // assistant reply a beat after the stream ends, not synchronously with it.
      // That gap is exactly the "answer flashes away and reappears a second later"
      // behavior reported live. sendCompareTurn already awaited this same call for
      // its own local-state clear (see its finally block) — this just matches it.
      await queryClient.invalidateQueries({ queryKey: ['chat', 'messages', activeSessionId] })
      setStreamingMessages([])
      queryClient.invalidateQueries({ queryKey: ['chat', 'sessions'] })
      queryClient.invalidateQueries({ queryKey: ['wallet'] })
    }
  }

  // Stateless fan-out (no session_id, nothing persisted to chat-service) — renders as
  // its own turn inline in the same scroll area as regular messages, rather than a
  // separate full-pane view. Requires an active session only so there's somewhere for
  // it to render; the comparison itself doesn't touch that session's history.
  const sendCompareTurn = async () => {
    const text = input.trim()
    if (!text || !activeSession || isComparing) return

    const models = compareModelIds
      .map((id) => availableModels.find((m) => m.id === id))
      .filter((m): m is AiModel => !!m)
    if (models.length < 2) return

    // Was never sent to /chat/compare at all before this — the attachment sat in
    // state looking "attached" (✓ shown) while every model in the fan-out got no
    // image and correctly reported not seeing one.
    const attachmentIds = pendingAttachment ? [pendingAttachment.id] : undefined
    // Same real-budget history-building as sendSingle, but the ceiling is the MINIMUM
    // context window across every model in the fan-out (fair comparison, not each model
    // independently truncated) and the reserve uses the largest max_output_tokens among
    // them (conservative — leaves enough room for whichever model outputs the most).
    const ceiling = Math.min(...models.map((m) => m.context_window ?? DEFAULT_CONTEXT_WINDOW))
    const reserve = Math.max(...models.map((m) => m.max_output_tokens ?? DEFAULT_MAX_OUTPUT_TOKENS)) + estimateTokens(text)
    const history = buildBoundedHistory(messages ?? [], ceiling, reserve)

    const turnId = crypto.randomUUID()
    setInput('')
    setPendingAttachment(null)
    setIsComparing(true)
    setCompareTurns((prev) => [
      ...prev,
      { id: turnId, prompt: text, modelIds: models.map((m) => m.model_id), results: Object.fromEntries(models.map((m) => [m.model_id, { text: '' }])) },
    ])

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
        // Passing session_id is also what makes this turn actually get persisted
        // (see ai-gateway's ChatController::compare()) — previously it was fully
        // stateless, so leaving a compare session and coming back showed nothing.
        body: JSON.stringify({
          message: text,
          model_ids: models.map((m) => m.model_id),
          attachment_ids: attachmentIds,
          session_id: activeSession.id,
          history,
        }),
        signal: controller.signal,
      })
      clearTimeout(timeoutId)

      if (!res.ok || !res.body) {
        throw new Error('Compare request failed.')
      }

      const reader = res.body.getReader()
      const decoder = new TextDecoder()
      let buffer = ''

      const patchTurn = (modelId: string, patch: Partial<CompareTurn['results'][string]>) => {
        setCompareTurns((prev) =>
          prev.map((t) =>
            t.id !== turnId ? t : {
              ...t,
              results: {
                ...t.results,
                [modelId]: { ...t.results[modelId], ...patch },
              },
            }
          )
        )
      }

      while (true) {
        const { done, value } = await reader.read()
        if (done) break
        buffer += decoder.decode(value, { stream: true })

        const lines = buffer.split('\n\n')
        buffer = lines.pop() ?? ''

        for (const line of lines) {
          const payload = line.replace(/^data:\s*/, '').trim()
          if (!payload) continue

          const event = JSON.parse(payload) as {
            model: string; chunk?: string; error?: string; done?: boolean
            cost?: number | null; prompt_tokens?: number | null; completion_tokens?: number | null
          }
          if (event.error) {
            patchTurn(event.model, { error: event.error })
          } else if (event.done) {
            patchTurn(event.model, {
              done: true,
              cost: event.cost ?? null,
              promptTokens: event.prompt_tokens ?? null,
              completionTokens: event.completion_tokens ?? null,
            })
          } else if (event.chunk) {
            setCompareTurns((prev) =>
              prev.map((t) =>
                t.id !== turnId ? t : {
                  ...t,
                  results: {
                    ...t.results,
                    [event.model]: { ...t.results[event.model], text: (t.results[event.model]?.text ?? '') + event.chunk },
                  },
                }
              )
            )
          }
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
      queryClient.invalidateQueries({ queryKey: ['chat', 'sessions'] })
      // Now persisted (see ai-gateway's compare() persistence) — refetch, then hand
      // off to the grouped rendering sourced from `messages` so this turn doesn't
      // stay duplicated in local-only state (and isn't lost on navigating away and
      // back, unlike before).
      await queryClient.invalidateQueries({ queryKey: ['chat', 'messages', activeSessionId] })
      setCompareTurns((prev) => prev.filter((t) => t.id !== turnId))
    }
  }

  // Manual "compact conversation" — summarizes the FULL history (deliberately
  // unbounded, unlike buildBoundedHistory above; the whole point is compressing
  // everything so far) via the new /chat/compact endpoint. Stateless until the user
  // acts on it: nothing is persisted just from generating a summary.
  const handleCompact = async () => {
    if (!selectedModel || !messages?.length) return
    setCompacting(true)
    setShowCompactionPanel(true)
    setCompactionSummary(null)
    try {
      const history = messages
        .filter((m) => m.role === 'user' || m.role === 'assistant')
        .map((m) => ({ role: m.role, content: m.content }))
      const res = await apiClient.post<{ summary: string }>('/api/v1/chat/compact', {
        model_id: selectedModel.model_id,
        history,
      })
      setCompactionSummary(res.data.summary)
    } catch (err: unknown) {
      const message = (err as { response?: { data?: { error?: string } } })?.response?.data?.error
      toast.error(message ?? "Couldn't generate a summary — please try again.")
      setShowCompactionPanel(false)
    } finally {
      setCompacting(false)
    }
  }

  const handleDownloadSummary = () => {
    if (!compactionSummary) return
    const safeTitle = (activeSession?.title || 'conversation').replace(/[^\w\- ]+/g, '').trim() || 'conversation'
    const blob = new Blob([compactionSummary], { type: 'text/markdown' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `${safeTitle}-summary.md`
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
  }

  // Creates a fresh session and carries the summary forward as its first message —
  // stored with role 'assistant' (not 'system', which laravel/ai's Message value
  // object doesn't accept) so it flows unchanged through the normal history-building
  // logic on future turns; metadata.type flags it for MessageBubble to render as a
  // distinct "continued from a previous conversation" card instead of a normal bubble.
  const handleContinueInNewChat = async () => {
    if (!compactionSummary || !selectedModel) return
    setStartingNewChat(true)
    try {
      const newSession = await createSession.mutateAsync({ modelId: selectedModel.id })
      await apiClient.post(`/api/v1/sessions/${newSession.id}/messages`, {
        role: 'assistant',
        content: compactionSummary,
        metadata: { type: 'compaction_summary' },
      })
      queryClient.invalidateQueries({ queryKey: ['chat', 'messages', newSession.id] })
      setShowCompactionPanel(false)
      setCompactionSummary(null)
    } catch {
      toast.error("Couldn't start the new chat — please try again.")
    } finally {
      setStartingNewChat(false)
    }
  }

  // "Before an actual message is typed" — drives both the top bar (Private Chat +
  // balance) and the rich middle content (heading, quick actions, suggested
  // questions) replacing the old bare "Say hello to get started" placeholder.
  const isEmptyConversation = !messages?.length && !streamingMessages.length && !compareTurns.length

  return (
    // bg-background/text-foreground explicit here on purpose — `.incognito` only
    // redefines CSS custom properties, it doesn't repaint anything by itself. `body`
    // (globals.css) already paints bg-background exactly once, using whatever
    // --background was in scope AT the body level — a descendant redefining the
    // variable can't retroactively change an ancestor's already-resolved background.
    // Without an element inside the .incognito scope that itself applies bg-background,
    // this whole page was silently showing through to body's un-overridden light
    // background regardless of the private theme — text picked up the dark-theme's
    // light foreground colors correctly, background didn't, so text on it was nearly
    // invisible. Confirmed live.
    <div className={cn('flex h-screen flex-col bg-background text-foreground', activeSession?.is_private && 'incognito')}>
      {!activeSession ? (
        <div className="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-sm text-muted-foreground">
          {availableModels.length === 0 ? (
            <>
              <Sparkles className="h-8 w-8 text-muted-foreground/40" />
              <p>Your plan doesn&apos;t include any chat models yet — check Plans in Settings.</p>
            </>
          ) : createSession.isError ? (
            <>
              <Sparkles className="h-8 w-8 text-muted-foreground/40" />
              <p>Couldn&apos;t start a new chat.</p>
              <Button
                onClick={() => {
                  autoCreatingRef.current = false
                  createSession.mutate({ modelId: availableModels[0].id })
                }}
              >
                Try again
              </Button>
            </>
          ) : (
            <Loader2 className="h-6 w-6 animate-spin text-muted-foreground/50" />
          )}
        </div>
      ) : (
        <>
          <div className="flex items-center justify-between gap-3 border-b border-border px-4 py-2">
            <p className="flex min-w-0 items-center gap-1.5 text-sm font-medium truncate">
              {activeSession!.is_private && <Lock className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />}
              {activeSession!.title}
              {activeSession!.is_private && activeSession!.expires_at && (
                <span className="text-[11px] font-normal text-muted-foreground">
                  · deletes {formatDistanceToNow(new Date(activeSession!.expires_at), { addSuffix: true })}
                </span>
              )}
            </p>
            {/* Only before the first message — once a conversation is underway this
                would just be clutter above the message list. */}
            {isEmptyConversation && (
              <div className="flex shrink-0 items-center gap-2">
                <PrivateChatPopover
                  trigger={
                    <button className="flex items-center gap-1.5 rounded-full border border-border px-2.5 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground">
                      <Lock className="h-3 w-3" />
                      Private Chat
                    </button>
                  }
                />
                <WalletBalanceChip />
              </div>
            )}
          </div>

          <div ref={scrollRef} className="flex-1 overflow-y-auto p-4 space-y-4">
            {isEmptyConversation && (
              <div className="mx-auto flex h-full max-w-lg flex-col items-center justify-center gap-6 text-center">
                <div>
                  <h2 className="text-xl font-semibold text-foreground">How can I help you?</h2>
                  <div className="mt-4 flex flex-wrap items-center justify-center gap-2">
                    {QUICK_ACTIONS.map((action) => (
                      <button
                        key={action.label}
                        onClick={() => setInput(action.prompt)}
                        className="flex items-center gap-1.5 rounded-full border border-border bg-accent/50 px-3.5 py-1.5 text-sm font-medium text-foreground transition-colors hover:bg-accent"
                      >
                        <action.icon className="h-3.5 w-3.5 text-primary" />
                        {action.label}
                      </button>
                    ))}
                  </div>
                </div>
                <div className="w-full divide-y divide-border text-left">
                  {SUGGESTED_QUESTIONS.map((q) => (
                    <button
                      key={q}
                      onClick={() => { setInput(q); sendSingle(q) }}
                      className="w-full py-2.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                      {q}
                    </button>
                  ))}
                </div>
              </div>
            )}
            {renderItems.map((item) =>
              item.kind === 'message' ? (
                item.message.metadata?.type === 'compaction_summary' ? (
                  <CompactionSummaryCard key={item.message.id} content={item.message.content} />
                ) : (
                  <MessageBubble
                    key={item.message.id}
                    role={item.message.role}
                    content={item.message.content}
                    modelName={item.message.model_id ? models?.find((mo) => mo.id === item.message.model_id)?.name : undefined}
                    promptTokens={item.message.prompt_tokens}
                    completionTokens={item.message.completion_tokens}
                    cost={item.message.cost}
                    attachments={item.message.attachments}
                  />
                )
              ) : (
                <CompareCardGroup
                  key={item.groupId}
                  cards={item.entries
                    .filter((entry) => !dismissedCardKeys.has(entry.key))
                    .map((entry): CompareCardData => {
                      const model = models?.find((mo) => mo.id === entry.modelId)
                      return {
                        cardKey: entry.key,
                        modelId: entry.modelId,
                        modelName: model?.name ?? 'Unknown model',
                        provider: model?.provider ?? null,
                        content: entry.content,
                        usageText: formatUsage(entry.promptTokens, entry.completionTokens, entry.cost),
                        promptTokens: entry.promptTokens,
                        completionTokens: entry.completionTokens,
                        cost: entry.cost,
                      }
                    })}
                  onDismiss={handleNotPreferred}
                />
              )
            )}
            {streamingMessages.map((m, i) => (
              <MessageBubble
                key={`streaming-${i}`}
                role={m.role}
                content={m.content}
                isLoading={m.role === 'assistant' && !m.content}
                modelName={m.role === 'assistant' ? selectedModel?.name : undefined}
              />
            ))}
            {/* Compare turns while still streaming — the persisted version (grouped by
                compare_group_id, see `renderItems` above) takes over once the turn
                finishes and `messages` is refetched; see sendCompareTurn's finally block. */}
            {compareTurns.map((turn) => (
              <div key={turn.id} className="space-y-2">
                <MessageBubble role="user" content={turn.prompt} />
                <CompareCardGroup
                  cards={turn.modelIds
                    .filter((modelId) => !dismissedCardKeys.has(`${turn.id}:${modelId}`))
                    .map((modelId): CompareCardData => {
                      const model = availableModels.find((m) => m.model_id === modelId)
                      const result = turn.results[modelId]
                      return {
                        cardKey: `${turn.id}:${modelId}`,
                        // Normalized to the AiModel.id UUID here — turn.modelIds itself is the
                        // provider model_id string (what /chat/compare's SSE events key by),
                        // a different id space from activeModelId/compareExtraModelIds.
                        modelId: model?.id ?? null,
                        modelName: model?.name ?? modelId,
                        provider: model?.provider ?? null,
                        content: result?.text || '',
                        error: result?.error,
                        usageText: result?.done && !result.error
                          ? formatUsage(result.promptTokens, result.completionTokens, result.cost)
                          : null,
                        promptTokens: result?.done ? result.promptTokens ?? null : null,
                        completionTokens: result?.done ? result.completionTokens ?? null : null,
                        cost: result?.done ? result.cost ?? null : null,
                      }
                    })}
                  onDismiss={handleNotPreferred}
                />
              </div>
            ))}
          </div>

          <div className="p-3">
            {nearingContextLimit && !isEmptyConversation && (
              <div className="mb-2 flex items-center gap-2 rounded-md border border-warning/30 bg-warning-soft px-3 py-1.5 text-xs text-warning">
                <AlertTriangle className="h-3.5 w-3.5 shrink-0" />
                <span className="flex-1">This conversation is approaching {selectedModel?.name ?? 'the model'}&apos;s context limit.</span>
                <button
                  type="button"
                  onClick={handleCompact}
                  disabled={compacting}
                  className="flex shrink-0 items-center gap-1 rounded-full border border-current px-2 py-0.5 font-medium hover:bg-warning/20 disabled:opacity-50"
                >
                  <Wand2 className="h-3 w-3" />
                  Compact conversation
                </button>
              </div>
            )}
            {uploading && (
              <div className="mb-2 inline-flex items-center gap-2 rounded-md border border-border bg-accent/50 px-2 py-1 text-xs text-muted-foreground">
                <Loader2 className="h-3.5 w-3.5 animate-spin" />
                Uploading…
              </div>
            )}
            {pendingAttachment && !uploading && (
              <div className="mb-2 inline-flex items-center gap-2 rounded-md border border-border bg-accent/50 px-2 py-1 text-xs">
                {pendingAttachment.mime_type.startsWith('image/') ? (
                  <img src={pendingAttachment.storage_url} alt="" className="h-6 w-6 rounded object-cover" />
                ) : (
                  <FileText className="h-6 w-6 rounded bg-background p-1 text-muted-foreground" />
                )}
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

            <input
              ref={fileInputRef}
              type="file"
              accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,text/plain,text/markdown,text/csv,application/json,application/vnd.openxmlformats-officedocument.wordprocessingml.document,.pdf,.txt,.md,.csv,.json,.docx"
              onChange={handleFileSelect}
              className="hidden"
            />

            {/* Gradient-bordered pill composer — the outer div supplies the border via
                its own background, the inner one is the actual opaque surface. */}
            <form
              onSubmit={(e) => { e.preventDefault(); send() }}
              className={cn(
                'rounded-3xl p-[1.5px] shadow-sm',
                // Not CSS-variable-driven on purpose — the incognito palette is deliberately
                // muted/neutral, so the composer border shouldn't still carry the public-chat
                // brand gradient just because it sits inside an `.incognito`-scoped wrapper.
                activeSession?.is_private
                  ? 'bg-neutral-800'
                  : 'bg-gradient-to-r from-violet-500 via-fuchsia-500 to-blue-500'
              )}
            >
              <div className="rounded-3xl bg-card">
                <div className="flex items-center gap-2 px-3 pt-2.5">
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <button
                        type="button"
                        disabled={isStreaming || isComparing}
                        className="flex min-w-0 items-center gap-1.5 rounded-full py-1 pl-1 pr-2 text-sm font-medium hover:bg-accent disabled:opacity-50"
                      >
                        {selectedModel && <ModelIcon provider={selectedModel.provider} className="h-5 w-5 shrink-0" />}
                        <span className="truncate">{selectedModel?.name ?? 'Select a model'}</span>
                        {selectedModel?.pricing && (
                          <span className="shrink-0 rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-normal text-muted-foreground">
                            ${selectedModel.pricing.output_rate_per_million}/1M
                          </span>
                        )}
                        <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                      </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" className="max-h-80 w-64 overflow-y-auto">
                      {availableModels.map((m) => (
                        <DropdownMenuItem key={m.id} onSelect={() => setActiveModelId(m.id)}>
                          <ModelIcon provider={m.provider} className="h-5 w-5 shrink-0" />
                          <span className="truncate">{m.name}</span>
                        </DropdownMenuItem>
                      ))}
                    </DropdownMenuContent>
                  </DropdownMenu>
                </div>

                <div className="px-3 pt-1">
                  <input
                    value={input}
                    onChange={(e) => setInput(e.target.value)}
                    onPaste={handlePaste}
                    disabled={isStreaming || isComparing}
                    placeholder="Ask me anything"
                    className="w-full bg-transparent py-1.5 text-sm text-foreground focus:outline-none disabled:opacity-50"
                  />
                </div>

                <div className="flex items-center gap-1 px-2.5 pb-2.5 pt-1.5">
                  {/* Always visible — was gated behind selectedModel?.capabilities.vision,
                      which hid it entirely for text-only models like DeepSeek. Images go
                      to vision models as image input; documents get their text extracted
                      server-side and injected into the prompt, so they work with any model. */}
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <button
                        type="button"
                        disabled={isStreaming || uploading}
                        aria-label="Attach file"
                        title="Attach file"
                        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-muted-foreground hover:bg-accent hover:text-foreground disabled:opacity-50"
                      >
                        {uploading ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plus className="h-4 w-4" />}
                      </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" className="w-72">
                      <DropdownMenuItem
                        onSelect={(e) => {
                          // Radix closes/unmounts the menu synchronously as part of this same
                          // event — calling fileInputRef.click() immediately can race that
                          // teardown and silently fail to open the native picker. Deferring
                          // one tick lets the menu finish closing first.
                          e.preventDefault()
                          setTimeout(() => fileInputRef.current?.click(), 0)
                        }}
                      >
                        <Upload className="h-4 w-4 text-muted-foreground" />
                        Upload from device
                      </DropdownMenuItem>
                      {recentFiles && recentFiles.length > 0 && (
                        <>
                          <DropdownMenuSeparator />
                          <div className="flex items-center gap-1.5 px-2.5 pb-1 pt-0.5 text-[11px] font-medium text-muted-foreground">
                            <FolderClock className="h-3 w-3" />
                            Recent files
                          </div>
                          <div className="max-h-56 overflow-y-auto">
                            {recentFiles.map((file) => (
                              <DropdownMenuItem
                                key={file.id}
                                onSelect={() => setPendingAttachment(file)}
                              >
                                {file.mime_type.startsWith('image/') ? (
                                  <img src={file.storage_url} alt="" className="h-6 w-6 shrink-0 rounded object-cover" />
                                ) : (
                                  <FileText className="h-6 w-6 shrink-0 rounded bg-accent p-1 text-muted-foreground" />
                                )}
                                <div className="min-w-0 flex-1">
                                  <p className="truncate">{file.original_name}</p>
                                  <p className="text-[11px] text-muted-foreground">
                                    {formatDistanceToNow(new Date(file.created_at), { addSuffix: true })}
                                  </p>
                                </div>
                              </DropdownMenuItem>
                            ))}
                          </div>
                        </>
                      )}
                    </DropdownMenuContent>
                  </DropdownMenu>

                  {/* Model icon row — always shows the primary (top-left dropdown's pick)
                      first. Compare mode adds a "+" to bring in up to 3 more; clicking a
                      non-primary icon removes it. */}
                  <div className="flex items-center gap-1">
                    {compareModelIds.map((id) => {
                      const m = availableModels.find((mo) => mo.id === id)
                      if (!m) return null
                      const isPrimary = id === activeModelId
                      return (
                        <button
                          key={id}
                          type="button"
                          title={m.name}
                          onClick={() => { if (!isPrimary) toggleCompareExtraModel(id) }}
                          className={cn('relative', !isPrimary && 'group')}
                        >
                          <ModelIcon provider={m.provider} className="h-6 w-6 ring-2 ring-card" />
                          {!isPrimary && (
                            <span className="absolute -right-1 -top-1 hidden h-3.5 w-3.5 items-center justify-center rounded-full bg-destructive text-destructive-foreground group-hover:flex">
                              <X className="h-2 w-2" />
                            </span>
                          )}
                        </button>
                      )
                    })}
                    {compareMode && compareModelIds.length < 4 && (
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <button
                            type="button"
                            aria-label="Add a model to compare"
                            className="flex h-6 w-6 items-center justify-center rounded-full border border-dashed border-muted-foreground/40 text-muted-foreground hover:border-primary hover:text-primary"
                          >
                            <Plus className="h-3.5 w-3.5" />
                          </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" className="max-h-72 w-64 overflow-y-auto">
                          {availableModels.filter((m) => !compareModelIds.includes(m.id)).map((m) => (
                            <DropdownMenuItem key={m.id} onSelect={() => toggleCompareExtraModel(m.id)}>
                              <ModelIcon provider={m.provider} className="h-5 w-5 shrink-0" />
                              <span className="truncate">{m.name}</span>
                            </DropdownMenuItem>
                          ))}
                        </DropdownMenuContent>
                      </DropdownMenu>
                    )}
                    {compareMode && (
                      <span className="ml-0.5 text-[11px] text-muted-foreground">{compareModelIds.length}/4</span>
                    )}
                  </div>

                  <div className="flex-1" />

                  {/* Compare mode toggle — off: activeModelId alone answers. On: reveals
                      the "+" above to add up to 3 more models; sending fans out to all of
                      them via /chat/compare, each response priced independently. */}
                  <button
                    type="button"
                    role="switch"
                    aria-checked={compareMode}
                    aria-label="Compare multiple models"
                    title="Compare multiple models"
                    onClick={() => setCompareMode((v) => { const next = !v; if (!next) setCompareExtraModelIds([]); return next })}
                    className={cn(
                      'relative h-5 w-9 shrink-0 rounded-full transition-colors',
                      compareMode ? 'bg-primary' : 'bg-muted'
                    )}
                  >
                    {/* Knob color depends on which track it's sitting on, not just a
                        constant white — bg-primary (the ON track) is a saturated violet
                        in the normal theme (white knob = good contrast) but a
                        deliberately desaturated *light* gray in the incognito palette
                        (white knob on light gray = barely visible, confirmed live).
                        primary-foreground is already defined to contrast correctly
                        against bg-primary in both themes, so it's the right token here
                        — bg-muted (the OFF track) is dark in both light and incognito's
                        actual problem cases, so white stays correct there unchanged. */}
                    <span
                      className={cn(
                        'absolute top-0.5 h-4 w-4 rounded-full shadow transition-transform',
                        compareMode ? 'translate-x-4 bg-primary-foreground' : 'translate-x-0.5 bg-white'
                      )}
                    />
                  </button>

                  <Button
                    type="submit"
                    disabled={
                      compareMode
                        ? isComparing || !input.trim() || compareModelIds.length < 2
                        : isStreaming || uploading || !input.trim()
                    }
                    aria-label="Send"
                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full p-0"
                  >
                    {isStreaming || isComparing ? <Loader2 className="h-4 w-4 animate-spin" /> : <ArrowUp className="h-4 w-4" />}
                  </Button>
                </div>
              </div>
            </form>
          </div>

          <Dialog open={showCompactionPanel} onOpenChange={(open) => { if (!open) { setShowCompactionPanel(false); setCompactionSummary(null) } }}>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Compact conversation</DialogTitle>
                <DialogDescription>
                  A condensed summary of this conversation, preserving the key facts and decisions.
                </DialogDescription>
              </DialogHeader>
              {compacting ? (
                <div className="flex items-center justify-center gap-2 py-8 text-sm text-muted-foreground">
                  <Loader2 className="h-4 w-4 animate-spin" />
                  Generating summary…
                </div>
              ) : (
                <div className="max-h-72 overflow-y-auto whitespace-pre-wrap rounded-md border border-border bg-accent/30 p-3 text-sm">
                  {compactionSummary}
                </div>
              )}
              <DialogFooter>
                <Button variant="outline" onClick={handleDownloadSummary} disabled={!compactionSummary || compacting}>
                  <Download className="mr-1.5 h-4 w-4" />
                  Download
                </Button>
                <Button onClick={handleContinueInNewChat} disabled={!compactionSummary || compacting || startingNewChat}>
                  {startingNewChat ? <Loader2 className="mr-1.5 h-4 w-4 animate-spin" /> : null}
                  Continue in new chat
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        </>
      )}
    </div>
  )
}


function MessageBubble({
  role, content, modelName, promptTokens, completionTokens, cost, isLoading, attachments,
}: {
  role: string
  content: string
  modelName?: string
  promptTokens?: number | null
  completionTokens?: number | null
  cost?: string | number | null
  isLoading?: boolean
  attachments?: FileAttachment[]
}) {
  const isUser = role === 'user'
  const hasTokenCounts = !isUser && promptTokens != null && completionTokens != null
  return (
    <div className={cn('flex items-start gap-2', isUser ? 'justify-end' : 'justify-start')}>
      {!isUser && (
        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-accent">
          <Bot className="h-4 w-4 text-muted-foreground" />
        </div>
      )}
      <div className={cn('max-w-[75%] space-y-1', isUser && 'flex flex-col items-end')}>
        {!isUser && modelName && <p className="text-[11px] text-muted-foreground px-1">{modelName}</p>}
        {/* Was silently lost after send before — the image reached the model fine but
            file_attachments.message_id was never populated by anything, so there was no
            way to show it again once the composer's local pendingAttachment cleared. */}
        {attachments && attachments.length > 0 && (
          <div className={cn('flex flex-wrap gap-1.5', isUser && 'justify-end')}>
            {attachments.map((a) =>
              a.mime_type.startsWith('image/') ? (
                <img key={a.id} src={a.storage_url} alt={a.original_name} className="h-24 w-24 rounded-lg border border-border object-cover" />
              ) : (
                <a
                  key={a.id}
                  href={a.storage_url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="flex items-center gap-1.5 rounded-lg border border-border bg-accent/50 px-2 py-1.5 text-xs hover:bg-accent"
                >
                  <FileText className="h-4 w-4 shrink-0 text-muted-foreground" />
                  <span className="max-w-[140px] truncate">{a.original_name}</span>
                </a>
              )
            )}
          </div>
        )}
        <div
          className={cn(
            'rounded-lg px-3 py-2 text-sm',
            'prose prose-sm max-w-none [&>*:first-child]:mt-0 [&>*:last-child]:mb-0',
            'prose-pre:bg-black/80 prose-pre:text-white prose-code:before:content-none prose-code:after:content-none',
            // Every prose text color inherits from the wrapper's own text-{x}-foreground
            // instead of a fixed prose-invert palette — `prose-invert` used to be pinned
            // to the user bubble only, which happened to look fine against bg-primary in
            // the normal light theme, but broke down the moment bg-card/bg-primary
            // resolved to genuinely dark colors (private chat's incognito palette):
            // the assistant bubble had no invert at all (Tailwind Typography's light-mode
            // default text color rendering as dark-on-near-black), and the user bubble's
            // invert conflicted with incognito's own light-gray bg-primary. Inheriting
            // from the real theme token is correct in every palette, not just the two
            // that happened to get manually checked.
            'prose-headings:text-inherit prose-p:text-inherit prose-strong:text-inherit prose-em:text-inherit prose-a:text-inherit prose-code:text-inherit prose-li:text-inherit prose-blockquote:text-inherit',
            isUser
              ? 'bg-primary text-primary-foreground'
              : 'bg-card border border-border text-card-foreground'
          )}
        >
          {isLoading ? (
            <span className="flex items-center gap-1 py-0.5" aria-label="Thinking">
              <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-current opacity-60 [animation-delay:-0.3s]" />
              <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-current opacity-60 [animation-delay:-0.15s]" />
              <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-current opacity-60" />
            </span>
          ) : (
            <ReactMarkdown remarkPlugins={[remarkGfm]} rehypePlugins={[rehypeHighlight]}>
              {content}
            </ReactMarkdown>
          )}
        </div>
        {/* Cost intentionally not shown right now, per explicit request — code kept
            (not deleted) so it's a one-line re-enable later:
            {cost != null && <span className="px-1 text-[10px] tabular-nums text-muted-foreground">{formatPreciseCurrency(cost)}</span>} */}
        {hasTokenCounts && (
          <p className="px-1 text-[10px] tabular-nums">
            <span className="text-info">{promptTokens!.toLocaleString()} in</span>
            {' · '}
            <span className="text-success">{completionTokens!.toLocaleString()} out</span>
          </p>
        )}
      </div>
      {isUser && (
        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10">
          <User className="h-4 w-4 text-primary" />
        </div>
      )}
    </div>
  )
}

// A summary carried over from a compacted previous conversation (see
// handleContinueInNewChat) — rendered as a distinct labeled card instead of a normal
// chat bubble, even though it's stored as a real role:'assistant' message so it still
// flows through the ordinary history-building logic on future turns.
function CompactionSummaryCard({ content }: { content: string }) {
  return (
    <div className="mx-auto max-w-[85%] rounded-lg border border-dashed border-border bg-accent/30 p-3">
      <p className="mb-1.5 flex items-center gap-1.5 text-[11px] font-medium text-muted-foreground">
        <Wand2 className="h-3 w-3" />
        Continued from a previous conversation
      </p>
      <div className="prose prose-sm max-w-none whitespace-pre-wrap text-sm text-foreground [&>*:first-child]:mt-0 [&>*:last-child]:mb-0">
        {content}
      </div>
    </div>
  )
}
