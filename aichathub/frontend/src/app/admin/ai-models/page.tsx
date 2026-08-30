'use client'

import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Label } from '@/components/ui/Label'
import { Badge } from '@/components/ui/Badge'
import { Skeleton } from '@/components/ui/Skeleton'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogTrigger } from '@/components/ui/Dialog'
import apiClient from '@/lib/api-client'
import { describeError } from '@/lib/errors'
import type { AdminAiModel } from '@/types'

const TYPES = ['text', 'image_generation', 'audio_tts', 'audio_stt', 'embedding']
const PRICING_TYPES = ['token_based', 'flat_per_image', 'character_based', 'per_minute']

// capabilities is a free-form jsonb column server-side (no fixed schema) — these are
// just the keys the rest of the app actually reads today (ChatController's server-side
// re-derivation for web_search/reasoning, the customer Models popup for flagship).
const CAPABILITY_KEYS = [
  { key: 'vision', label: 'Vision (image input)' },
  { key: 'file_upload', label: 'File upload / document context' },
  { key: 'function_calling', label: 'Function calling' },
  { key: 'streaming', label: 'Streaming responses' },
  { key: 'web_search', label: 'Web search' },
  { key: 'reasoning', label: 'Deep Think (Anthropic extended reasoning only — see ChatController)' },
  { key: 'flagship', label: 'Flagship (shown in the customer "Models" popup\'s top section)' },
] as const

interface ModelFormState {
  provider: string
  name: string
  model_id: string
  type: string
  description: string
  context_window: string
  max_output_tokens: string
  capabilities: Record<string, boolean>
  pricing_type: string
  provider_input_rate_per_million: string
  provider_output_rate_per_million: string
  provider_flat_rate_per_unit: string
  markup_percentage: string
}

function emptyForm(): ModelFormState {
  return {
    provider: '', name: '', model_id: '', type: 'text', description: '',
    context_window: '', max_output_tokens: '',
    capabilities: { streaming: true },
    pricing_type: 'token_based', provider_input_rate_per_million: '', provider_output_rate_per_million: '',
    provider_flat_rate_per_unit: '', markup_percentage: '30',
  }
}

function formFromModel(m: AdminAiModel): ModelFormState {
  return {
    provider: m.provider, name: m.name, model_id: m.model_id, type: m.type, description: m.description ?? '',
    context_window: m.context_window?.toString() ?? '', max_output_tokens: m.max_output_tokens?.toString() ?? '',
    capabilities: m.capabilities ?? {},
    pricing_type: m.pricing?.pricing_type ?? 'token_based',
    provider_input_rate_per_million: m.pricing?.provider_input_rate_per_million ?? '',
    provider_output_rate_per_million: m.pricing?.provider_output_rate_per_million ?? '',
    provider_flat_rate_per_unit: m.pricing?.provider_flat_rate_per_unit ?? '',
    markup_percentage: m.pricing?.markup_percentage ?? '30',
  }
}

/** Client-side preview only — the real sell rate always comes from the server's own
 * computation on save, this just avoids the admin doing the multiplication by hand. */
function previewSellRate(cost: string, markup: string): string | null {
  const c = parseFloat(cost)
  const m = parseFloat(markup)
  if (isNaN(c) || isNaN(m)) return null
  return (c * (1 + m / 100)).toFixed(c < 1 ? 6 : 4)
}

function ModelFormDialog({ model, trigger }: { model?: AdminAiModel; trigger: React.ReactNode }) {
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<ModelFormState>(model ? formFromModel(model) : emptyForm())
  const [editPricing, setEditPricing] = useState(!model)

  const save = useMutation({
    mutationFn: async () => {
      const body: Record<string, unknown> = {
        name: form.name,
        description: form.description || null,
        context_window: form.context_window ? parseInt(form.context_window, 10) : null,
        max_output_tokens: form.max_output_tokens ? parseInt(form.max_output_tokens, 10) : null,
        capabilities: form.capabilities,
      }
      if (!model) {
        body.provider = form.provider
        body.model_id = form.model_id
        body.type = form.type
      }
      if (!model || editPricing) {
        body.pricing_type = form.pricing_type
        body.markup_percentage = parseFloat(form.markup_percentage)
        if (form.pricing_type === 'token_based') {
          body.provider_input_rate_per_million = parseFloat(form.provider_input_rate_per_million)
          body.provider_output_rate_per_million = parseFloat(form.provider_output_rate_per_million)
        } else {
          body.provider_flat_rate_per_unit = parseFloat(form.provider_flat_rate_per_unit)
        }
      }
      return model
        ? apiClient.patch(`/api/v1/models/admin/${model.id}`, body)
        : apiClient.post('/api/v1/models/admin', body)
    },
    onSuccess: () => {
      toast.success(model ? 'Model updated.' : 'Model created.')
      queryClient.invalidateQueries({ queryKey: ['admin', 'ai-models'] })
      setOpen(false)
    },
    onError: (err: unknown) => toast.error(describeError(err, "We didn't hear back in time — check the models list before trying again.").message),
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (!form.name || (!model && (!form.provider || !form.model_id))) {
      toast.error('Please fill in a provider, model ID, and name before saving.')
      return
    }
    if (!model || editPricing) {
      const tokenBased = form.pricing_type === 'token_based'
      if (tokenBased && (!form.provider_input_rate_per_million || !form.provider_output_rate_per_million)) {
        toast.error('Please set both an input and output provider cost for token-based pricing.')
        return
      }
      if (!tokenBased && !form.provider_flat_rate_per_unit) {
        toast.error('Please set a flat provider cost for this pricing type.')
        return
      }
      if (form.markup_percentage === '') {
        toast.error('Please set a markup percentage.')
        return
      }
    }
    save.mutate()
  }

  return (
    <Dialog open={open} onOpenChange={(next) => { setOpen(next); if (next) { setForm(model ? formFromModel(model) : emptyForm()); setEditPricing(!model) } }}>
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>{model ? 'Edit model' : 'Create model'}</DialogTitle>
        </DialogHeader>
        <form onSubmit={submit} className="max-h-[75vh] space-y-4 overflow-y-auto pr-1">
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="model-provider">Provider</Label>
              <Input id="model-provider" required disabled={!!model} value={form.provider} onChange={(e) => setForm({ ...form, provider: e.target.value })} placeholder="e.g. openai" />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="model-id">Model ID</Label>
              <Input id="model-id" required disabled={!!model} value={form.model_id} onChange={(e) => setForm({ ...form, model_id: e.target.value })} placeholder="e.g. gpt-4o" />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="model-name">Display name</Label>
              <Input id="model-name" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="model-type">Type</Label>
              <Select id="model-type" disabled={!!model} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
                {TYPES.map((t) => <option key={t} value={t}>{t.replace('_', ' ')}</option>)}
              </Select>
            </div>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="model-description">Description</Label>
            <Input id="model-description" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="model-context">Context window</Label>
              <Input id="model-context" type="number" min="1" value={form.context_window} onChange={(e) => setForm({ ...form, context_window: e.target.value })} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="model-max-output">Max output tokens</Label>
              <Input id="model-max-output" type="number" min="1" value={form.max_output_tokens} onChange={(e) => setForm({ ...form, max_output_tokens: e.target.value })} />
            </div>
          </div>

          <div className="space-y-1.5">
            <Label>Capabilities</Label>
            <div className="space-y-2 rounded-md border border-border p-3">
              {CAPABILITY_KEYS.map(({ key, label }) => (
                <label key={key} className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    className="h-4 w-4 rounded border-input accent-primary"
                    checked={form.capabilities[key] ?? false}
                    onChange={(e) => setForm({ ...form, capabilities: { ...form.capabilities, [key]: e.target.checked } })}
                  />
                  {label}
                </label>
              ))}
            </div>
          </div>

          {model && !editPricing ? (
            <Button type="button" variant="outline" onClick={() => setEditPricing(true)}>Update pricing…</Button>
          ) : (
            <div className="space-y-3 rounded-md border border-border p-3">
              <div className="space-y-1.5">
                <Label htmlFor="model-pricing-type">Pricing type</Label>
                <Select id="model-pricing-type" value={form.pricing_type} onChange={(e) => setForm({ ...form, pricing_type: e.target.value })}>
                  {PRICING_TYPES.map((t) => <option key={t} value={t}>{t.replace('_', ' ')}</option>)}
                </Select>
              </div>
              {form.pricing_type === 'token_based' ? (
                <div className="grid grid-cols-2 gap-3">
                  <div className="space-y-1.5">
                    <Label htmlFor="model-input-rate">Provider cost — input $ / 1M tokens</Label>
                    <Input id="model-input-rate" type="number" min="0" step="0.000001" value={form.provider_input_rate_per_million} onChange={(e) => setForm({ ...form, provider_input_rate_per_million: e.target.value })} />
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="model-output-rate">Provider cost — output $ / 1M tokens</Label>
                    <Input id="model-output-rate" type="number" min="0" step="0.000001" value={form.provider_output_rate_per_million} onChange={(e) => setForm({ ...form, provider_output_rate_per_million: e.target.value })} />
                  </div>
                </div>
              ) : (
                <div className="space-y-1.5">
                  <Label htmlFor="model-flat-rate">Provider cost — flat rate per unit ($)</Label>
                  <Input id="model-flat-rate" type="number" min="0" step="0.0001" value={form.provider_flat_rate_per_unit} onChange={(e) => setForm({ ...form, provider_flat_rate_per_unit: e.target.value })} />
                </div>
              )}

              <div className="space-y-1.5">
                <Label htmlFor="model-markup">Markup %</Label>
                <Input id="model-markup" type="number" min="0" step="0.01" value={form.markup_percentage} onChange={(e) => setForm({ ...form, markup_percentage: e.target.value })} />
              </div>

              {form.pricing_type === 'token_based' ? (
                <p className="text-xs text-muted-foreground">
                  Sell rate (preview): {previewSellRate(form.provider_input_rate_per_million, form.markup_percentage) ?? '—'} / {previewSellRate(form.provider_output_rate_per_million, form.markup_percentage) ?? '—'} per 1M in/out
                </p>
              ) : (
                <p className="text-xs text-muted-foreground">
                  Sell rate (preview): {previewSellRate(form.provider_flat_rate_per_unit, form.markup_percentage) ?? '—'} flat
                </p>
              )}
              {model && <p className="text-xs text-muted-foreground">Saving this creates a new pricing record — the old one is kept for historical usage logs, not overwritten.</p>}
            </div>
          )}

          <DialogFooter>
            <Button type="submit" disabled={save.isPending}>{save.isPending ? 'Saving…' : 'Save'}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

export default function AdminAiModelsPage() {
  const queryClient = useQueryClient()

  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'ai-models'],
    queryFn: async () => (await apiClient.get<{ models: AdminAiModel[] }>('/api/v1/models/admin')).data.models,
  })

  const toggleActive = useMutation({
    mutationFn: async ({ id, active }: { id: string; active: boolean }) =>
      apiClient.patch(`/api/v1/models/admin/${id}/${active ? 'activate' : 'deactivate'}`),
    onSuccess: () => {
      toast.success('Model updated.')
      queryClient.invalidateQueries({ queryKey: ['admin', 'ai-models'] })
    },
    onError: (err: unknown) => toast.error(describeError(err, "We didn't hear back in time — check whether the model's status actually changed before retrying.").message),
  })

  const formatRate = (m: AdminAiModel) => {
    if (!m.pricing) return 'No pricing set'
    if (m.pricing.pricing_type === 'token_based') {
      return `$${m.pricing.input_rate_per_million}/$${m.pricing.output_rate_per_million} per 1M in/out`
    }
    return `$${m.pricing.flat_rate_per_unit} flat`
  }

  const formatMarkup = (m: AdminAiModel) =>
    m.pricing?.markup_percentage !== null && m.pricing?.markup_percentage !== undefined
      ? `${m.pricing.markup_percentage}% markup`
      : null

  return (
    <div className="max-w-4xl space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">AI Models</h1>
          <p className="mt-1 text-sm text-muted-foreground">The model catalog packages choose from, and what each model costs to run.</p>
        </div>
        <ModelFormDialog trigger={<Button>Create model</Button>} />
      </div>

      <Card>
        <CardHeader><CardTitle>{data?.length ?? '…'} models</CardTitle></CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="space-y-3">
              {Array.from({ length: 4 }).map((_, i) => (
                <div key={i} className="flex items-center justify-between rounded-md border border-border p-3">
                  <div className="space-y-2">
                    <Skeleton className="h-4 w-48" />
                    <Skeleton className="h-3 w-40" />
                  </div>
                  <Skeleton className="h-7 w-24" />
                </div>
              ))}
            </div>
          ) : !data?.length ? (
            <p className="text-sm text-muted-foreground">No models yet.</p>
          ) : (
            <div className="space-y-3">
              {data.map((m) => (
                <div key={m.id} className="flex items-center justify-between rounded-md border border-border p-3">
                  <div>
                    <p className="font-medium">
                      {m.name} <span className="font-normal text-muted-foreground">({m.provider} · {m.model_id})</span>
                    </p>
                    <p className="text-sm text-muted-foreground">
                      {m.type.replace('_', ' ')} · {formatRate(m)}{formatMarkup(m) && ` · ${formatMarkup(m)}`}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge variant={m.is_active ? 'success' : 'neutral'}>{m.is_active ? 'active' : 'inactive'}</Badge>
                    <ModelFormDialog model={m} trigger={<Button variant="outline" className="px-2.5 py-1.5 text-xs">Edit</Button>} />
                    <Button
                      variant={m.is_active ? 'destructive' : 'outline'}
                      className="px-2.5 py-1.5 text-xs"
                      disabled={toggleActive.isPending}
                      onClick={() => toggleActive.mutate({ id: m.id, active: !m.is_active })}
                    >
                      {m.is_active ? 'Deactivate' : 'Activate'}
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
