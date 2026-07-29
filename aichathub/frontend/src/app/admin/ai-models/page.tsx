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
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogTrigger } from '@/components/ui/Dialog'
import apiClient from '@/lib/api-client'
import { describeError } from '@/lib/errors'
import type { AdminAiModel } from '@/types'

const TYPES = ['text', 'image_generation', 'audio_tts', 'audio_stt', 'embedding']
const PRICING_TYPES = ['token_based', 'flat_per_image', 'character_based', 'per_minute']

interface ModelFormState {
  provider: string
  name: string
  model_id: string
  type: string
  description: string
  context_window: string
  max_output_tokens: string
  pricing_type: string
  input_rate_per_million: string
  output_rate_per_million: string
  flat_rate_per_unit: string
}

function emptyForm(): ModelFormState {
  return {
    provider: '', name: '', model_id: '', type: 'text', description: '',
    context_window: '', max_output_tokens: '',
    pricing_type: 'token_based', input_rate_per_million: '', output_rate_per_million: '', flat_rate_per_unit: '',
  }
}

function formFromModel(m: AdminAiModel): ModelFormState {
  return {
    provider: m.provider, name: m.name, model_id: m.model_id, type: m.type, description: m.description ?? '',
    context_window: m.context_window?.toString() ?? '', max_output_tokens: m.max_output_tokens?.toString() ?? '',
    pricing_type: m.pricing?.pricing_type ?? 'token_based',
    input_rate_per_million: m.pricing?.input_rate_per_million ?? '',
    output_rate_per_million: m.pricing?.output_rate_per_million ?? '',
    flat_rate_per_unit: m.pricing?.flat_rate_per_unit ?? '',
  }
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
      }
      if (!model) {
        body.provider = form.provider
        body.model_id = form.model_id
        body.type = form.type
      }
      if (!model || editPricing) {
        body.pricing_type = form.pricing_type
        if (form.pricing_type === 'token_based') {
          body.input_rate_per_million = parseFloat(form.input_rate_per_million)
          body.output_rate_per_million = parseFloat(form.output_rate_per_million)
        } else {
          body.flat_rate_per_unit = parseFloat(form.flat_rate_per_unit)
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
    onError: (err: unknown) => toast.error(describeError(err, 'Could not save this model.').message),
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (!form.name || (!model && (!form.provider || !form.model_id))) {
      toast.error('Provider, model ID, and name are required.')
      return
    }
    if (!model || editPricing) {
      const tokenBased = form.pricing_type === 'token_based'
      if (tokenBased && (!form.input_rate_per_million || !form.output_rate_per_million)) {
        toast.error('Input and output rates are required for token-based pricing.')
        return
      }
      if (!tokenBased && !form.flat_rate_per_unit) {
        toast.error('A flat rate is required for this pricing type.')
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
                    <Label htmlFor="model-input-rate">Input $ / 1M tokens</Label>
                    <Input id="model-input-rate" type="number" min="0" step="0.000001" value={form.input_rate_per_million} onChange={(e) => setForm({ ...form, input_rate_per_million: e.target.value })} />
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="model-output-rate">Output $ / 1M tokens</Label>
                    <Input id="model-output-rate" type="number" min="0" step="0.000001" value={form.output_rate_per_million} onChange={(e) => setForm({ ...form, output_rate_per_million: e.target.value })} />
                  </div>
                </div>
              ) : (
                <div className="space-y-1.5">
                  <Label htmlFor="model-flat-rate">Flat rate per unit ($)</Label>
                  <Input id="model-flat-rate" type="number" min="0" step="0.0001" value={form.flat_rate_per_unit} onChange={(e) => setForm({ ...form, flat_rate_per_unit: e.target.value })} />
                </div>
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
    onError: (err: unknown) => toast.error(describeError(err, 'Could not update this model.').message),
  })

  const formatRate = (m: AdminAiModel) => {
    if (!m.pricing) return 'No pricing set'
    if (m.pricing.pricing_type === 'token_based') {
      return `$${m.pricing.input_rate_per_million}/$${m.pricing.output_rate_per_million} per 1M in/out`
    }
    return `$${m.pricing.flat_rate_per_unit} flat`
  }

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
            <p className="text-sm text-muted-foreground">Loading…</p>
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
                    <p className="text-sm text-muted-foreground">{m.type.replace('_', ' ')} · {formatRate(m)}</p>
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
