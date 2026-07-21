'use client'

import { useEffect, useRef, useState } from 'react'
import { useRouter, useSearchParams } from 'next/navigation'
import { useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, Loader2, XCircle } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import apiClient from '@/lib/api-client'

type Phase = 'checking' | 'success' | 'cancelled' | 'error' | 'still_pending'

const MAX_ATTEMPTS = 5
const POLL_INTERVAL_MS = 1500

/**
 * Where Stripe Checkout redirects back to after payment, for both wallet
 * top-ups and card-funded package purchases. Verification (not this page's
 * own state) is what actually credits/activates — this just asks
 * GET /checkout/{id}/verify and reflects whatever it reports, retrying a
 * few times since the webhook/verify race can take a moment either way.
 */
export default function CheckoutCallbackPage() {
  const router = useRouter()
  const searchParams = useSearchParams()
  const queryClient = useQueryClient()
  const hasRun = useRef(false)

  const type = searchParams.get('type') === 'subscription' ? 'subscription' : 'topup'
  const [phase, setPhase] = useState<Phase>('checking')
  const [message, setMessage] = useState('Confirming your payment…')

  useEffect(() => {
    if (hasRun.current) return
    hasRun.current = true

    const status = searchParams.get('status')
    const sessionId = searchParams.get('session_id')

    if (status === 'cancelled') {
      setPhase('cancelled')
      return
    }

    if (status !== 'success' || !sessionId) {
      setPhase('error')
      setMessage("We couldn't tell whether that payment went through. Check your wallet or plan status before trying again.")
      return
    }

    let attempts = 0

    const poll = async () => {
      attempts += 1

      try {
        const { data } = await apiClient.get<{ status: string }>(`/api/v1/checkout/${sessionId}/verify`)

        if (data.status === 'completed') {
          queryClient.invalidateQueries({ queryKey: ['wallet'] })
          queryClient.invalidateQueries({ queryKey: ['subscription'] })
          setPhase('success')
          return
        }

        if (data.status === 'cancelled' || data.status === 'failed') {
          setPhase('error')
          setMessage(data.status === 'failed' ? 'The payment failed. No changes were made.' : 'The checkout session expired before payment completed.')
          return
        }

        if (attempts >= MAX_ATTEMPTS) {
          setPhase('still_pending')
          return
        }

        setTimeout(poll, POLL_INTERVAL_MS)
      } catch {
        if (attempts >= MAX_ATTEMPTS) {
          setPhase('error')
          setMessage("We couldn't confirm the payment status. Check your wallet or plan status directly.")
          return
        }
        setTimeout(poll, POLL_INTERVAL_MS)
      }
    }

    poll()
  }, [searchParams, queryClient])

  const continueHref = type === 'subscription' ? '/pricing' : '/wallet'
  const continueLabel = type === 'subscription' ? 'Back to Pricing' : 'Back to Wallet'

  return (
    <div className="flex min-h-[60vh] items-center justify-center">
      <div className="max-w-sm text-center space-y-4">
        {(phase === 'checking') && (
          <>
            <Loader2 className="mx-auto h-8 w-8 animate-spin text-primary" />
            <p className="text-sm text-muted-foreground">{message}</p>
          </>
        )}

        {phase === 'still_pending' && (
          <>
            <Loader2 className="mx-auto h-8 w-8 animate-spin text-primary" />
            <p className="text-sm text-muted-foreground">
              Still confirming — this can take a few extra seconds. Check back shortly; your {type === 'subscription' ? 'plan' : 'wallet'} will update automatically once it lands.
            </p>
            <Button onClick={() => router.push(continueHref)}>{continueLabel}</Button>
          </>
        )}

        {phase === 'success' && (
          <>
            <CheckCircle2 className="mx-auto h-8 w-8 text-green-600" />
            <p className="text-sm font-medium">
              {type === 'subscription' ? 'Your plan is now active.' : 'Your wallet has been credited.'}
            </p>
            <Button onClick={() => router.push(continueHref)}>{continueLabel}</Button>
          </>
        )}

        {phase === 'cancelled' && (
          <>
            <XCircle className="mx-auto h-8 w-8 text-muted-foreground" />
            <p className="text-sm font-medium">Checkout cancelled</p>
            <p className="text-sm text-muted-foreground">No charge was made.</p>
            <Button variant="outline" onClick={() => router.push(continueHref)}>{continueLabel}</Button>
          </>
        )}

        {phase === 'error' && (
          <>
            <XCircle className="mx-auto h-8 w-8 text-destructive" />
            <p className="text-sm font-medium">{message}</p>
            <Button variant="outline" onClick={() => router.push(continueHref)}>{continueLabel}</Button>
          </>
        )}
      </div>
    </div>
  )
}
