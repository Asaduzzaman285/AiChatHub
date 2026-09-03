'use client'

import { useState } from 'react'
import { loadStripe } from '@stripe/stripe-js'
import { Elements, CardElement, useStripe, useElements } from '@stripe/react-stripe-js'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CreditCard, Star, Trash2 } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { Skeleton } from '@/components/ui/Skeleton'
import apiClient from '@/lib/api-client'
import { describeError } from '@/lib/errors'
import type { PaymentMethod } from '@/types'

const stripePromise = loadStripe(process.env.NEXT_PUBLIC_STRIPE_PUBLISHABLE_KEY ?? '')

function AddCardForm({ onAdded }: { onAdded: () => void }) {
  const stripe = useStripe()
  const elements = useElements()
  const queryClient = useQueryClient()
  const [submitting, setSubmitting] = useState(false)

  const save = useMutation({
    mutationFn: async (token: string) => apiClient.post('/api/v1/payment-methods', { payment_method_token: token }),
    onSuccess: () => {
      toast.success('Card added.')
      queryClient.invalidateQueries({ queryKey: ['payment-methods'] })
      onAdded()
    },
    onError: (err: unknown) => toast.error(describeError(err, "We couldn't save that card — please try again.").message),
  })

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!stripe || !elements) return
    const card = elements.getElement(CardElement)
    if (!card) return

    setSubmitting(true)
    // Card details never touch our backend — Stripe.js tokenizes them client-side and
    // hands back an opaque PaymentMethod id, which is all POST /payment-methods gets.
    const { paymentMethod, error } = await stripe.createPaymentMethod({ type: 'card', card })
    setSubmitting(false)

    if (error || !paymentMethod) {
      toast.error(error?.message ?? 'Could not process that card. Please check the details and try again.')
      return
    }

    save.mutate(paymentMethod.id)
  }

  return (
    <form onSubmit={submit} className="space-y-3">
      <div className="rounded-md border border-input bg-background px-3 py-2.5">
        <CardElement options={{ style: { base: { fontSize: '14px' } } }} />
      </div>
      <Button type="submit" disabled={!stripe || submitting || save.isPending} className="w-full">
        {submitting || save.isPending ? 'Saving…' : 'Save card'}
      </Button>
    </form>
  )
}

/** Shared by the Settings modal's Wallet tab. Backend (list/save/delete/set-default) was already
 * fully built before this — this is the first frontend to ever call it. */
export function PaymentMethodsView() {
  const queryClient = useQueryClient()
  const [adding, setAdding] = useState(false)

  const { data: methods, isLoading } = useQuery({
    queryKey: ['payment-methods'],
    queryFn: async () => (await apiClient.get<{ payment_methods: PaymentMethod[] }>('/api/v1/payment-methods')).data.payment_methods,
  })

  const setDefault = useMutation({
    mutationFn: async (id: string) => apiClient.patch(`/api/v1/payment-methods/${id}/default`),
    onSuccess: () => {
      toast.success('Default payment method updated.')
      queryClient.invalidateQueries({ queryKey: ['payment-methods'] })
    },
    onError: (err: unknown) => toast.error(describeError(err, "We didn't hear back in time — please try again.").message),
  })

  const remove = useMutation({
    mutationFn: async (id: string) => apiClient.delete(`/api/v1/payment-methods/${id}`),
    onSuccess: () => {
      toast.success('Payment method removed.')
      queryClient.invalidateQueries({ queryKey: ['payment-methods'] })
    },
    onError: (err: unknown) => toast.error(describeError(err, "We didn't hear back in time — please try again.").message),
  })

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between gap-3 space-y-0">
        <CardTitle>Payment methods</CardTitle>
        {!adding && (
          <Button variant="outline" className="shrink-0 text-xs" onClick={() => setAdding(true)}>Add card</Button>
        )}
      </CardHeader>
      <CardContent className="space-y-3">
        {isLoading ? (
          <div className="space-y-2">
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
          </div>
        ) : !methods?.length && !adding ? (
          <p className="text-sm text-muted-foreground">No saved payment methods yet.</p>
        ) : (
          methods?.map((m) => (
            <div key={m.id} className="flex items-center justify-between rounded-md border border-border p-3 text-sm">
              <div className="flex items-center gap-2.5">
                <CreditCard className="h-4 w-4 text-muted-foreground" />
                <span className="capitalize">{m.card_brand}</span>
                <span className="text-muted-foreground">•••• {m.last_four}</span>
                {m.is_default && <Badge variant="success">default</Badge>}
              </div>
              <div className="flex items-center gap-1">
                {!m.is_default && (
                  <Button
                    variant="outline" className="px-2 py-1.5" title="Set as default"
                    disabled={setDefault.isPending} onClick={() => setDefault.mutate(m.id)}
                  >
                    <Star className="h-3.5 w-3.5" />
                  </Button>
                )}
                <Button
                  variant="outline" className="px-2 py-1.5 text-destructive" title="Remove"
                  disabled={remove.isPending} onClick={() => remove.mutate(m.id)}
                >
                  <Trash2 className="h-3.5 w-3.5" />
                </Button>
              </div>
            </div>
          ))
        )}

        {adding && (
          <div className="space-y-2 rounded-md border border-border p-3">
            <Elements stripe={stripePromise}>
              <AddCardForm onAdded={() => setAdding(false)} />
            </Elements>
            <button type="button" className="text-xs text-muted-foreground hover:text-foreground" onClick={() => setAdding(false)}>
              Cancel
            </button>
          </div>
        )}
      </CardContent>
    </Card>
  )
}
