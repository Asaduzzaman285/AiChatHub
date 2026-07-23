'use client'

import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { toast } from 'sonner'
import { CheckCircle2, KeyRound, Sparkles } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import apiClient from '@/lib/api-client'
import { formatCurrency, formatDate } from '@/lib/utils'
import { describeError } from '@/lib/errors'
import type { Subscription, User, WalletBalance } from '@/types'

const passwordSchema = z.object({
  current_password: z.string().optional(),
  new_password: z.string()
    .min(8, 'Password must be at least 8 characters')
    .regex(/[A-Z]/, 'Must contain at least one uppercase letter')
    .regex(/[0-9]/, 'Must contain at least one number'),
  new_password_confirmation: z.string(),
}).refine((d) => d.new_password === d.new_password_confirmation, {
  message: 'Passwords do not match',
  path: ['new_password_confirmation'],
})

type PasswordForm = z.infer<typeof passwordSchema>

export default function ProfilePage() {
  const queryClient = useQueryClient()
  const [showPasswordForm, setShowPasswordForm] = useState(false)

  const { data: me } = useQuery({
    queryKey: ['auth', 'me'],
    queryFn: async () => (await apiClient.get<User>('/api/v1/auth/me')).data,
  })

  const { data: wallet } = useQuery({
    queryKey: ['wallet', 'balance'],
    queryFn: async () => (await apiClient.get<WalletBalance>('/api/v1/wallet')).data,
  })

  const { data: subscription } = useQuery({
    queryKey: ['subscription', 'current'],
    queryFn: async () => (await apiClient.get<{ subscription: Subscription | null }>('/api/v1/subscription')).data.subscription,
  })

  const {
    register, handleSubmit, reset, formState: { errors, isSubmitting },
  } = useForm<PasswordForm>({ resolver: zodResolver(passwordSchema) })

  const setPassword = useMutation({
    mutationFn: async (data: PasswordForm) =>
      apiClient.post('/api/v1/auth/password/set', {
        current_password: data.current_password || undefined,
        new_password: data.new_password,
        new_password_confirmation: data.new_password_confirmation,
      }),
    onSuccess: () => {
      toast.success(me?.has_password ? 'Password updated.' : 'Password set — you can now also sign in with email/password.')
      reset()
      setShowPasswordForm(false)
      queryClient.invalidateQueries({ queryKey: ['auth', 'me'] })
    },
    onError: (err: unknown) => toast.error(describeError(err, "We didn't hear back in time — try again in a moment.").message),
  })

  const unlinkGoogle = useMutation({
    mutationFn: async () => apiClient.delete('/api/v1/auth/social/google'),
    onSuccess: () => {
      toast.success('Google account unlinked.')
      queryClient.invalidateQueries({ queryKey: ['auth', 'me'] })
    },
    onError: (err: unknown) => toast.error(describeError(err, "We didn't hear back in time — try again in a moment.").message),
  })

  return (
    <div className="max-w-3xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Profile</h1>
        <p className="mt-1 text-sm text-muted-foreground">Your account, plan, and wallet at a glance.</p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Account</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="flex items-center gap-3">
            <div className="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-lg font-semibold text-primary">
              {me?.email?.[0]?.toUpperCase() ?? '?'}
            </div>
            <div>
              <p className="text-sm font-medium">{me?.name ?? 'Loading…'}</p>
              <p className="text-sm text-muted-foreground">{me?.email}</p>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-4 pt-2 text-sm">
            <div>
              <p className="text-muted-foreground">Preferred currency</p>
              <p className="font-medium">{me?.preferred_currency ?? '—'}</p>
            </div>
            <div>
              <p className="text-muted-foreground">Email verified</p>
              <p className="font-medium flex items-center gap-1">
                {me?.email_verified_at ? (
                  <>
                    <CheckCircle2 className="h-3.5 w-3.5 text-green-600" /> Verified
                  </>
                ) : 'Not verified'}
              </p>
            </div>
          </div>
        </CardContent>
      </Card>

      <div className="grid gap-4 sm:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Wallet</CardTitle>
          </CardHeader>
          <CardContent>
            {wallet ? (
              <div className="space-y-1">
                <p className="text-2xl font-bold">{formatCurrency(wallet.available_balance, wallet.currency)}</p>
                <p className="text-xs text-muted-foreground">available to spend</p>
              </div>
            ) : (
              <p className="text-sm text-muted-foreground">Loading…</p>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Plan</CardTitle>
          </CardHeader>
          <CardContent>
            {subscription ? (
              <div className="space-y-1">
                <p className="text-2xl font-bold flex items-center gap-1.5">
                  <Sparkles className="h-4 w-4 text-primary" />
                  {subscription.package?.name ?? 'Unknown'}
                </p>
                <p className="text-xs text-muted-foreground">
                  {subscription.cancelled_at
                    ? `Ends ${formatDate(subscription.renews_at)}`
                    : `Renews ${formatDate(subscription.renews_at)}`}
                </p>
              </div>
            ) : (
              <p className="text-sm text-muted-foreground">No active plan.</p>
            )}
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Sign-in &amp; security</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex items-center justify-between text-sm">
            <div>
              <p className="font-medium">Google account</p>
              <p className="text-xs text-muted-foreground">
                {me?.google_connected ? 'Connected — you can sign in with Google.' : 'Not connected.'}
              </p>
            </div>
            {me?.google_connected && me?.has_password && (
              <Button variant="outline" disabled={unlinkGoogle.isPending} onClick={() => unlinkGoogle.mutate()}>
                {unlinkGoogle.isPending ? 'Unlinking…' : 'Unlink'}
              </Button>
            )}
          </div>

          <div className="flex items-center justify-between text-sm border-t border-border pt-4">
            <div>
              <p className="font-medium">Password</p>
              <p className="text-xs text-muted-foreground">
                {me?.has_password ? 'Password sign-in is enabled.' : "You signed up with Google — set a password to also sign in with email."}
              </p>
            </div>
            {!showPasswordForm && (
              <Button variant="outline" className="gap-1.5" onClick={() => setShowPasswordForm(true)}>
                <KeyRound className="h-4 w-4" />
                {me?.has_password ? 'Change password' : 'Set password'}
              </Button>
            )}
          </div>

          {showPasswordForm && (
            <form
              onSubmit={handleSubmit((data) => setPassword.mutate(data))}
              className="space-y-3 border-t border-border pt-4"
            >
              {me?.has_password && (
                <div className="space-y-1">
                  <label className="text-sm font-medium">Current password</label>
                  <input type="password" {...register('current_password')}
                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring" />
                  {errors.current_password && <p className="text-xs text-destructive">{errors.current_password.message}</p>}
                </div>
              )}
              <div className="space-y-1">
                <label className="text-sm font-medium">New password</label>
                <input type="password" placeholder="Min 8 chars, 1 uppercase, 1 number" {...register('new_password')}
                  className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring" />
                {errors.new_password && <p className="text-xs text-destructive">{errors.new_password.message}</p>}
              </div>
              <div className="space-y-1">
                <label className="text-sm font-medium">Confirm new password</label>
                <input type="password" {...register('new_password_confirmation')}
                  className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring" />
                {errors.new_password_confirmation && <p className="text-xs text-destructive">{errors.new_password_confirmation.message}</p>}
              </div>
              <div className="flex gap-2">
                <Button type="submit" disabled={isSubmitting || setPassword.isPending}>
                  {setPassword.isPending ? 'Saving…' : 'Save password'}
                </Button>
                <Button type="button" variant="outline" onClick={() => { setShowPasswordForm(false); reset() }}>
                  Cancel
                </Button>
              </div>
            </form>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
