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
import { describeError } from '@/lib/errors'
import type { AdminCurrency } from '@/types'

interface CurrencyFormState {
  code: string
  name: string
  symbol: string
  decimal_places: string
  exchange_rate: string
  margin_percentage: string
  tax_percentage: string
  vat_percentage: string
  withholding_tax_percentage: string
}

function emptyForm(): CurrencyFormState {
  return {
    code: '', name: '', symbol: '', decimal_places: '2',
    exchange_rate: '', margin_percentage: '0', tax_percentage: '0', vat_percentage: '0', withholding_tax_percentage: '0',
  }
}

function formFromCurrency(c: AdminCurrency): CurrencyFormState {
  return {
    code: c.code, name: c.name, symbol: c.symbol, decimal_places: c.decimal_places.toString(),
    exchange_rate: c.rate?.exchange_rate ?? '',
    margin_percentage: c.rate?.margin_percentage ?? '0',
    tax_percentage: c.rate?.tax_percentage ?? '0',
    vat_percentage: c.rate?.vat_percentage ?? '0',
    withholding_tax_percentage: c.rate?.withholding_tax_percentage ?? '0',
  }
}

/** Client-side preview only — the server always recomputes this from the same
 * cascade on save (CurrencyRate::computeEffectiveRate), this just avoids the
 * admin doing the arithmetic by hand before submitting. Each step compounds
 * on the previous result: margin and VAT/withholding are additive, but tax is
 * a gross-up (divide by 1 - tax%), not additive — matches the admin's own
 * conversion spreadsheet exactly. */
function previewEffectiveRate(form: CurrencyFormState): string | null {
  const rate = parseFloat(form.exchange_rate)
  const margin = parseFloat(form.margin_percentage) || 0
  const tax = parseFloat(form.tax_percentage) || 0
  const vat = parseFloat(form.vat_percentage) || 0
  const withholding = parseFloat(form.withholding_tax_percentage) || 0
  if (isNaN(rate) || tax >= 100) return null
  const afterMargin = rate * (1 + margin / 100)
  const afterTax = afterMargin / (1 - tax / 100)
  const afterVat = afterTax * (1 + vat / 100)
  const afterWithholding = afterVat * (1 + withholding / 100)
  return afterWithholding.toFixed(6)
}

function CurrencyFormDialog({ currency, trigger }: { currency?: AdminCurrency; trigger: React.ReactNode }) {
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<CurrencyFormState>(currency ? formFromCurrency(currency) : emptyForm())
  const [editRate, setEditRate] = useState(!currency)

  const save = useMutation({
    mutationFn: async () => {
      const body: Record<string, unknown> = {}
      if (!currency) {
        body.code = form.code.toUpperCase()
        body.name = form.name
        body.symbol = form.symbol
        body.decimal_places = parseInt(form.decimal_places, 10)
      } else {
        body.name = form.name
        body.symbol = form.symbol
        body.decimal_places = parseInt(form.decimal_places, 10)
      }
      if (!currency || editRate) {
        body.exchange_rate = parseFloat(form.exchange_rate)
        body.margin_percentage = parseFloat(form.margin_percentage) || 0
        body.tax_percentage = parseFloat(form.tax_percentage) || 0
        body.vat_percentage = parseFloat(form.vat_percentage) || 0
        body.withholding_tax_percentage = parseFloat(form.withholding_tax_percentage) || 0
      }
      return currency
        ? apiClient.patch(`/api/v1/subscription/currencies/admin/${currency.code}`, body)
        : apiClient.post('/api/v1/subscription/currencies/admin', body)
    },
    onSuccess: () => {
      toast.success(currency ? 'Currency updated.' : 'Currency created.')
      queryClient.invalidateQueries({ queryKey: ['admin', 'currencies'] })
      setOpen(false)
    },
    onError: (err: unknown) => toast.error(describeError(err, "We didn't hear back in time — check the currency list before trying again.").message),
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (!form.name || !form.symbol || (!currency && form.code.length !== 3)) {
      toast.error('Please fill in a 3-letter code, name, and symbol before saving.')
      return
    }
    if ((!currency || editRate) && !form.exchange_rate) {
      toast.error('Please set an exchange rate.')
      return
    }
    save.mutate()
  }

  return (
    <Dialog open={open} onOpenChange={(next) => { setOpen(next); if (next) { setForm(currency ? formFromCurrency(currency) : emptyForm()); setEditRate(!currency) } }}>
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>{currency ? 'Edit currency' : 'Create currency'}</DialogTitle>
        </DialogHeader>
        <form onSubmit={submit} className="max-h-[75vh] space-y-4 overflow-y-auto pr-1">
          <div className="grid grid-cols-3 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="currency-code">Code</Label>
              <Input id="currency-code" required disabled={!!currency} maxLength={3} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} placeholder="BDT" />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="currency-name">Name</Label>
              <Input id="currency-name" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Bangladeshi Taka" />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="currency-symbol">Symbol</Label>
              <Input id="currency-symbol" required value={form.symbol} onChange={(e) => setForm({ ...form, symbol: e.target.value })} placeholder="৳" />
            </div>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="currency-decimals">Decimal places</Label>
            <Input id="currency-decimals" type="number" min="0" max="4" value={form.decimal_places} onChange={(e) => setForm({ ...form, decimal_places: e.target.value })} />
          </div>

          {currency && !editRate ? (
            <Button type="button" variant="outline" onClick={() => setEditRate(true)}>Update conversion rate…</Button>
          ) : (
            <div className="space-y-3 rounded-md border border-border p-3">
              <div className="space-y-1.5">
                <Label htmlFor="currency-rate">Exchange rate (1 USD =)</Label>
                <Input id="currency-rate" type="number" min="0" step="0.000001" value={form.exchange_rate} onChange={(e) => setForm({ ...form, exchange_rate: e.target.value })} placeholder="124" />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1.5">
                  <Label htmlFor="currency-margin">Margin %</Label>
                  <Input id="currency-margin" type="number" min="0" step="0.01" value={form.margin_percentage} onChange={(e) => setForm({ ...form, margin_percentage: e.target.value })} />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="currency-tax">Tax % (inclusive)</Label>
                  <Input id="currency-tax" type="number" min="0" max="99.99" step="0.01" value={form.tax_percentage} onChange={(e) => setForm({ ...form, tax_percentage: e.target.value })} />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="currency-vat">VAT %</Label>
                  <Input id="currency-vat" type="number" min="0" step="0.01" value={form.vat_percentage} onChange={(e) => setForm({ ...form, vat_percentage: e.target.value })} />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="currency-withholding">Withholding tax %</Label>
                  <Input id="currency-withholding" type="number" min="0" step="0.01" value={form.withholding_tax_percentage} onChange={(e) => setForm({ ...form, withholding_tax_percentage: e.target.value })} />
                </div>
              </div>
              <p className="text-xs text-muted-foreground">
                Each step compounds on the previous one — margin, VAT, and withholding add on top; tax is applied as tax-inclusive (grossed up) instead of added on top.
              </p>
              <p className="text-xs text-muted-foreground">
                Effective rate (preview): 1 USD = {previewEffectiveRate(form) ?? '—'} {form.code || currency?.code}
              </p>
              {currency && <p className="text-xs text-muted-foreground">Saving this creates a new rate record — the old one is kept for historical transactions, not overwritten.</p>}
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

export default function AdminCurrenciesPage() {
  const queryClient = useQueryClient()

  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'currencies'],
    queryFn: async () => (await apiClient.get<{ currencies: AdminCurrency[] }>('/api/v1/subscription/currencies/admin')).data.currencies,
  })

  const toggleActive = useMutation({
    mutationFn: async ({ code, active }: { code: string; active: boolean }) =>
      apiClient.patch(`/api/v1/subscription/currencies/admin/${code}/${active ? 'activate' : 'deactivate'}`),
    onSuccess: () => {
      toast.success('Currency updated.')
      queryClient.invalidateQueries({ queryKey: ['admin', 'currencies'] })
    },
    onError: (err: unknown) => toast.error(describeError(err, "We didn't hear back in time — check whether the currency's status actually changed before retrying.").message),
  })

  const formatBreakdown = (c: AdminCurrency) => {
    if (!c.rate) return 'No rate set'
    const { exchange_rate, margin_percentage, tax_percentage, vat_percentage, withholding_tax_percentage } = c.rate
    const parts = [
      `base ${exchange_rate}`,
      parseFloat(margin_percentage) ? `margin ${margin_percentage}%` : null,
      parseFloat(tax_percentage) ? `tax ${tax_percentage}%` : null,
      parseFloat(vat_percentage) ? `VAT ${vat_percentage}%` : null,
      parseFloat(withholding_tax_percentage) ? `withholding ${withholding_tax_percentage}%` : null,
    ].filter(Boolean)
    return parts.join(' · ')
  }

  return (
    <div className="max-w-4xl space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Currencies</h1>
          <p className="mt-1 text-sm text-muted-foreground">The conversion policy behind non-USD pricing — exchange rate plus margin, tax, VAT, and withholding stacked on top.</p>
        </div>
        <CurrencyFormDialog trigger={<Button>Create currency</Button>} />
      </div>

      <Card>
        <CardHeader><CardTitle>{data?.length ?? '…'} currencies</CardTitle></CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="space-y-3">
              {Array.from({ length: 3 }).map((_, i) => (
                <div key={i} className="flex items-center justify-between rounded-md border border-border p-3">
                  <div className="space-y-2">
                    <Skeleton className="h-4 w-48" />
                    <Skeleton className="h-3 w-64" />
                  </div>
                  <Skeleton className="h-7 w-24" />
                </div>
              ))}
            </div>
          ) : !data?.length ? (
            <p className="text-sm text-muted-foreground">No currencies yet. USD is implicit and never needs a row here — every internal calculation is already in USD.</p>
          ) : (
            <div className="space-y-3">
              {data.map((c) => (
                <div key={c.id} className="flex items-center justify-between rounded-md border border-border p-3">
                  <div>
                    <p className="font-medium">
                      {c.name} <span className="font-normal text-muted-foreground">({c.code} · {c.symbol})</span>
                    </p>
                    <p className="text-sm text-muted-foreground">
                      {formatBreakdown(c)}
                      {c.rate && <span className="font-medium text-foreground"> · effective 1 USD = {c.rate.effective_rate} {c.code}</span>}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge variant={c.is_active ? 'success' : 'neutral'}>{c.is_active ? 'active' : 'inactive'}</Badge>
                    <CurrencyFormDialog currency={c} trigger={<Button variant="outline" className="px-2.5 py-1.5 text-xs">Edit</Button>} />
                    <Button
                      variant={c.is_active ? 'destructive' : 'outline'}
                      className="px-2.5 py-1.5 text-xs"
                      disabled={toggleActive.isPending}
                      onClick={() => toggleActive.mutate({ code: c.code, active: !c.is_active })}
                    >
                      {c.is_active ? 'Deactivate' : 'Activate'}
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
