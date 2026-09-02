'use client'

import Link from 'next/link'
import { Suspense, useEffect, useState } from 'react'
import { useRouter, useSearchParams } from 'next/navigation'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { toast } from 'sonner'
import { Eye, EyeOff, UserRound } from 'lucide-react'
import { AuthShell } from '@/components/auth/AuthShell'
import { GoogleSignInButton } from '@/components/auth/GoogleSignInButton'
import { useAuthStore } from '@/stores/auth-store'
import apiClient from '@/lib/api-client'
import { postLoginPath } from '@/lib/post-login-redirect'
import type { AuthTokens, User } from '@/types'

const loginSchema = z.object({
  email:    z.string().email('Enter a valid email'),
  password: z.string().min(1, 'Password is required'),
})

type LoginForm = z.infer<typeof loginSchema>

// Email verification redirects here (see auth-service's EmailVerificationController)
// instead of showing a bare JSON response on the API domain — this surfaces that
// result as a real in-app toast. Split into its own component (rather than calling
// useSearchParams directly in LoginPage) because useSearchParams() bails out of
// static rendering and requires its own <Suspense> boundary — wrapping the whole
// page would be more invasive than isolating just this.
function VerifiedToast() {
  const searchParams = useSearchParams()

  useEffect(() => {
    const verified = searchParams.get('verified')
    if (verified === '1') {
      toast.success('Email verified — you can now sign in.')
    } else if (verified === '0') {
      const reason = searchParams.get('reason')
      toast.error(
        reason === 'token_expired'
          ? 'That verification link has expired. Please request a new one.'
          : 'That verification link is invalid or has already been used.'
      )
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return null
}

export default function LoginPage() {
  const router = useRouter()
  const { setAuth, isAuthenticated, user } = useAuthStore()
  const [serverError, setServerError] = useState<string | null>(null)
  const [showPassword, setShowPassword] = useState(false)

  useEffect(() => {
    // `user` may still be null here even when isAuthenticated is true — zustand-persist
    // only persists tokens across a reload, not the profile (see auth-store.ts's
    // partialize), so this only knows to route an admin straight to /admin when the
    // profile happens to already be in memory (e.g. navigating within the app).
    if (isAuthenticated) router.replace(postLoginPath(user))
  }, [isAuthenticated, user, router])

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<LoginForm>({ resolver: zodResolver(loginSchema) })

  const onSubmit = async (data: LoginForm) => {
    setServerError(null)
    try {
      const { data: tokens } = await apiClient.post<AuthTokens>('/api/v1/auth/login', data)

      // Fetch user profile with new token
      const { data: user } = await apiClient.get<User>('/api/v1/auth/me', {
        headers: { Authorization: `Bearer ${tokens.access_token}` },
      })

      setAuth(user, tokens.access_token, tokens.refresh_token)
      router.push(postLoginPath(user))
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      setServerError(msg ?? 'Login failed. Please check your credentials.')
    }
  }

  return (
    <AuthShell>
      <Suspense fallback={null}>
        <VerifiedToast />
      </Suspense>
      <div className="relative w-full max-w-[420px]">
        <div className="flex flex-col items-center text-center">
          <div className="flex h-11 w-11 items-center justify-center rounded-full bg-primary/10">
            <UserRound className="h-5 w-5 text-primary" />
          </div>
          <h1 className="mt-2.5 text-xl font-semibold text-foreground">Welcome Back</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            Access your account to continue where you left off.
          </p>
        </div>

        <div className="mt-4">
          <GoogleSignInButton label="Google" />
          {/* Apple sign-in removed for now — commented out, not deleted (matching the
              register page); no real Apple auth backend exists yet anyway (Google is
              the only real OAuth provider — see JwtService/GoogleOAuthController), and
              no real Apple brand-logo asset (lucide's "Apple" is the fruit glyph, not
              the logomark). Uncomment alongside restoring the grid-cols-2 wrapper
              above if this comes back.
          <button
            type="button"
            onClick={() => toast('Apple sign-in isn’t available yet.')}
            className="flex w-full items-center justify-center gap-2.5 rounded-full border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50"
          >
            <Apple className="h-4 w-4" />
            Apple
          </button>
          */}
        </div>

        <div className="relative my-4">
          <div className="absolute inset-0 flex items-center">
            <span className="w-full border-t border-border" />
          </div>
          <div className="relative flex justify-center text-xs uppercase">
            <span className="bg-background px-2 text-muted-foreground">or continue with email</span>
          </div>
        </div>

        <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
          <form onSubmit={handleSubmit(onSubmit)} className="space-y-3">
            {serverError && (
              <div className="rounded-md bg-destructive/10 px-4 py-3 text-sm text-destructive">
                {serverError}
              </div>
            )}

            <div className="space-y-1">
              <label htmlFor="email" className="text-sm font-medium text-foreground">Email</label>
              <input
                id="email"
                type="email"
                autoComplete="email"
                placeholder="e.g., jack@gmail.com"
                className="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                {...register('email')}
              />
              {errors.email && <p className="text-xs text-destructive">{errors.email.message}</p>}
            </div>

            <div className="space-y-1">
              <label htmlFor="password" className="text-sm font-medium text-foreground">Password</label>
              <div className="relative">
                <input
                  id="password"
                  type={showPassword ? 'text' : 'password'}
                  autoComplete="current-password"
                  placeholder="e.g., Sij@ck2025"
                  className="w-full rounded-xl border border-input bg-background px-3.5 py-2 pr-10 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                  {...register('password')}
                />
                <button
                  type="button"
                  onClick={() => setShowPassword((v) => !v)}
                  aria-label={showPassword ? 'Hide password' : 'Show password'}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground transition-colors hover:text-foreground"
                >
                  {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                </button>
              </div>
              {errors.password && <p className="text-xs text-destructive">{errors.password.message}</p>}
            </div>

            <div className="flex items-center justify-between text-sm">
              {/* Visual only — every session is persisted indefinitely today regardless
                  (see auth-store.ts's partialize, unconditional), so this checkbox
                  doesn't yet change any real behavior. */}
              <label className="flex items-center gap-2 text-muted-foreground">
                <input type="checkbox" className="h-4 w-4 rounded border-input accent-primary" />
                Remember me
              </label>
              <Link href="/forgot-password" className="text-primary hover:underline">
                Forgot password?
              </Link>
            </div>

            <button
              type="submit"
              disabled={isSubmitting}
              className="w-full rounded-full bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground transition-colors hover:bg-primary/90 disabled:opacity-50"
            >
              {isSubmitting ? 'Signing in...' : 'Sign in'}
            </button>
          </form>
        </div>

        <p className="mt-4 text-center text-sm text-muted-foreground">
          Don&apos;t have an account?{' '}
          <Link href="/register" className="font-medium text-primary hover:underline">
            Sign Up
          </Link>
        </p>
      </div>
    </AuthShell>
  )
}
