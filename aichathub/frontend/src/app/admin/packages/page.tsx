'use client'

import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Label } from '@/components/ui/Label'
import { Badge } from '@/components/ui/Badge'
import { Skeleton } from '@/components/ui/Skeleton'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogTrigger } from '@/components/ui/Dialog'
import apiClient from '@/lib/api-client'
import { formatCurrency } from '@/lib/utils'
import { describeError } from '@/lib/errors'
import type { AdminPackage, AiModel, PackageFeatures } from '@/types'

const FEATURE_LABELS: { key: keyof PackageFeatures; label: string }[] = [
  { key: 'file_upload', label: 'File upload' },
  { key: 'api_access', label: 'API access' },
  { key: 'comparison', label: 'Multi-model comparison' },
  { key: 'image_gen', label: 'Image generation' },
  { key: 'audio', label: 'Audio (TTS/STT)' },
  { key: 'vision', label: 'Vision' },
]

interface PackageFormState {
  name: string
  slug: string
  description: string
  monthly_price_usd: string
  monthly_price_bdt: string
  monthly_wallet_credit_usd: string
  credit_buffer_percentage: string
  is_active: boolean
  model_access: string[]
  features: PackageFeatures
}

const EMPTY_FEATURES: PackageFeatures = { file_upload: false, api_access: false, comparison: false, image_gen: false, audio: false, vision: false }

function emptyForm(): PackageFormState {
  return {
    name: '', slug: '', description: '',
    monthly_price_usd: '', monthly_price_bdt: '', monthly_wallet_credit_usd: '',
    credit_buffer_percentage: '30', is_active: true, model_access: [], features: { ...EMPTY_FEATURES },
  }
}

function formFromPackage(pkg: AdminPackage): PackageFormState {
  return {
    name: pkg.name, slug: pkg.slug, description: pkg.description ?? '',
    monthly_price_usd: pkg.monthly_price_usd, monthly_price_bdt: pkg.monthly_price_bdt ?? '',
    monthly_wallet_credit_usd: pkg.monthly_wallet_credit_usd, credit_buffer_percentage: pkg.credit_buffer_percentage,
    is_active: pkg.is_active, model_access: pkg.model_access, features: { ...EMPTY_FEATURES, ...pkg.features },
  }
}

function PackageFormDialog({ pkg, trigger }: { pkg?: AdminPackage; trigger: React.ReactNode }) {
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<PackageFormState>(pkg ? formFromPackage(pkg) : emptyForm())

  const { data: models } = useQuery({
    queryKey: ['admin', 'models-catalog'],
    queryFn: async () => (await apiClient.get<{ models: AiModel[] }>('/api/v1/models')).data.models,
    enabled: open,
  })

  const save = useMutation({
    mutationFn: async () => {
      const body = {
        name: form.name,
        slug: form.slug,
        description: form.description || null,
        monthly_price_usd: parseFloat(form.monthly_price_usd),
        monthly_price_bdt: form.monthly_price_bdt ? parseFloat(form.monthly_price_bdt) : null,
        monthly_wallet_credit_usd: parseFloat(form.monthly_wallet_credit_usd),
        credit_buffer_percentage: parseFloat(form.credit_buffer_percentage),
        is_active: form.is_active,
        model_access: form.model_access,
        features: form.features,
      }
      return pkg
        ? apiClient.patch(`/api/v1/packages/${pkg.slug}`, body)
        : apiClient.post('/api/v1/packages', body)
    },
    onSuccess: () => {
      toast.success(pkg ? 'Package updated.' : 'Package created.')
      queryClient.invalidateQueries({ queryKey: ['admin', 'packages'] })
      setOpen(false)
    },
    onError: (err: unknown) => toast.error(describeError(err, "We didn't hear back in time — check the packages list before trying again.").message),
  })

  const toggleModel = (modelId: string) => {
    setForm((f) => ({
      ...f,
      model_access: f.model_access.includes(modelId) ? f.model_access.filter((m) => m !== modelId) : [...f.model_access, modelId],
    }))
  }

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (!form.name || !form.slug || !form.monthly_price_usd || !form.monthly_wallet_credit_usd) {
      toast.error('Please fill in a name, slug, price, and wallet credit before saving.')
      return
    }
    save.mutate()
  }

  return (
    <Dialog open={open} onOpenChange={(next) => { setOpen(next); if (next) setForm(pkg ? formFromPackage(pkg) : emptyForm()) }}>
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>{pkg ? 'Edit package' : 'Create package'}</DialogTitle>
        </DialogHeader>
        <form onSubmit={submit} className="max-h-[75vh] space-y-4 overflow-y-auto pr-1">
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="pkg-name">Name</Label>
              <Input id="pkg-name" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="pkg-slug">Slug</Label>
              <Input id="pkg-slug" required disabled={!!pkg} value={form.slug} onChange={(e) => setForm({ ...form, slug: e.target.value })} placeholder="e.g. pro" />
            </div>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="pkg-description">Description</Label>
            <Input id="pkg-description" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
          </div>

          <div className="grid grid-cols-3 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="pkg-price-usd">Price (USD)</Label>
              <Input id="pkg-price-usd" type="number" min="0" step="0.01" required value={form.monthly_price_usd} onChange={(e) => setForm({ ...form, monthly_price_usd: e.target.value })} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="pkg-price-bdt">Price (BDT)</Label>
              <Input id="pkg-price-bdt" type="number" min="0" step="0.01" value={form.monthly_price_bdt} onChange={(e) => setForm({ ...form, monthly_price_bdt: e.target.value })} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="pkg-credit">Wallet credit (USD)</Label>
              <Input id="pkg-credit" type="number" min="0" step="0.01" required value={form.monthly_wallet_credit_usd} onChange={(e) => setForm({ ...form, monthly_wallet_credit_usd: e.target.value })} />
            </div>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="pkg-buffer">Credit buffer % <span className="font-normal text-muted-foreground">(overdraft allowance as a % of price)</span></Label>
            <Input id="pkg-buffer" type="number" min="0" max="100" step="1" required value={form.credit_buffer_percentage} onChange={(e) => setForm({ ...form, credit_buffer_percentage: e.target.value })} />
          </div>

          <label className="flex items-center gap-2 text-sm font-medium">
            <input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
            Active (visible on the pricing page)
          </label>

          <div>
            <Label>Features</Label>
            <div className="mt-1.5 grid grid-cols-2 gap-1.5">
              {FEATURE_LABELS.map(({ key, label }) => (
                <label key={key} className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={form.features[key]}
                    onChange={(e) => setForm({ ...form, features: { ...form.features, [key]: e.target.checked } })}
                  />
                  {label}
                </label>
              ))}
            </div>
          </div>

          <div>
            <Label>Models included</Label>
            <div className="mt-1.5 max-h-48 space-y-1 overflow-y-auto rounded-md border border-border p-2">
              {!models ? (
                <div className="space-y-1.5">
                  {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-4 w-40" />)}
                </div>
              ) : (
                models.map((m) => (
                  <label key={m.model_id} className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={form.model_access.includes(m.model_id)} onChange={() => toggleModel(m.model_id)} />
                    <span className="capitalize text-muted-foreground">{m.provider}</span> {m.name}
                  </label>
                ))
              )}
            </div>
          </div>

          <DialogFooter>
            <Button type="submit" disabled={save.isPending}>{save.isPending ? 'Saving…' : 'Save'}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

export default function AdminPackagesPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'packages'],
    queryFn: async () => (await apiClient.get<{ packages: AdminPackage[] }>('/api/v1/packages/admin')).data.packages,
  })

  return (
    <div className="max-w-4xl space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Packages</h1>
          <p className="mt-1 text-sm text-muted-foreground">Subscription plans, pricing, wallet credit, credit buffer, and model access.</p>
        </div>
        <PackageFormDialog trigger={<Button>Create package</Button>} />
      </div>

      <Card>
        <CardHeader><CardTitle>{data?.length ?? '…'} packages</CardTitle></CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="space-y-3">
              {Array.from({ length: 3 }).map((_, i) => (
                <div key={i} className="flex items-center justify-between rounded-md border border-border p-3">
                  <div className="space-y-2">
                    <Skeleton className="h-4 w-40" />
                    <Skeleton className="h-3 w-56" />
                  </div>
                  <Skeleton className="h-7 w-20" />
                </div>
              ))}
            </div>
          ) : !data?.length ? (
            <p className="text-sm text-muted-foreground">No packages yet.</p>
          ) : (
            <div className="space-y-3">
              {data.map((pkg) => (
                <div key={pkg.id} className="flex items-center justify-between rounded-md border border-border p-3">
                  <div>
                    <p className="font-medium">
                      {pkg.name} <span className="font-normal text-muted-foreground">({pkg.slug})</span>
                    </p>
                    <p className="text-sm text-muted-foreground">
                      {formatCurrency(pkg.monthly_price_usd)}/mo · {formatCurrency(pkg.monthly_wallet_credit_usd)} wallet credit ·
                      {' '}{pkg.credit_buffer_percentage}% buffer · {pkg.model_access.length} model{pkg.model_access.length === 1 ? '' : 's'}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge variant={pkg.is_active ? 'success' : 'neutral'}>{pkg.is_active ? 'active' : 'inactive'}</Badge>
                    <PackageFormDialog pkg={pkg} trigger={<Button variant="outline" className="px-2.5 py-1.5 text-xs">Edit</Button>} />
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
