'use client'

import Link from 'next/link'
import { useEffect, useState } from 'react'
import { useRouter } from 'next/navigation'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Eye, EyeOff, UserRound } from 'lucide-react'
import { AuthShell } from '@/components/auth/AuthShell'
import { GoogleSignInButton } from '@/components/auth/GoogleSignInButton'
import { useAuthStore } from '@/stores/auth-store'
import apiClient from '@/lib/api-client'
import { describeError } from '@/lib/errors'
import { postLoginPath } from '@/lib/post-login-redirect'

const registerSchema = z.object({
  name:             z.string().min(2, 'Name must be at least 2 characters'),
  email:            z.string().email('Enter a valid email'),
  password:         z.string()
    .min(8,  'Password must be at least 8 characters')
    .regex(/[A-Z]/, 'Must contain at least one uppercase letter')
    .regex(/[0-9]/, 'Must contain at least one number'),
  password_confirmation: z.string(),
  currency:         z.enum(['USD', 'BDT']).default('USD'),
}).refine((d) => d.password === d.password_confirmation, {
  message: 'Passwords do not match',
  path: ['password_confirmation'],
})

type RegisterForm = z.infer<typeof registerSchema>

export default function RegisterPage() {
  const router = useRouter()
  const { isAuthenticated, user } = useAuthStore()
  const [serverError, setServerError] = useState<string | null>(null)
  const [ambiguous, setAmbiguous] = useState(false)
  const [success, setSuccess] = useState(false)
  const [showPassword, setShowPassword] = useState(false)
  const [showPasswordConfirmation, setShowPasswordConfirmation] = useState(false)

  useEffect(() => {
    if (isAuthenticated) router.replace(postLoginPath(user))
  }, [isAuthenticated, user, router])

  const { register, handleSubmit, formState: { errors, isSubmitting } } =
    useForm<RegisterForm>({ resolver: zodResolver(registerSchema) })

  const onSubmit = async (data: RegisterForm) => {
    setServerError(null)
    setAmbiguous(false)
    try {
      await apiClient.post('/api/v1/auth/register', data)
      setSuccess(true)
    } catch (err: unknown) {
      const { ambiguous, message } = describeError(
        err,
        "We didn't hear back in time, but your account may have already been created."
      )
      setAmbiguous(ambiguous)
      setServerError(message)
    }
  }

  if (success) {
    return (
      <AuthShell>
        <div className="relative max-w-sm space-y-3 text-center">
          <div className="text-4xl">📬</div>
          <h2 className="text-xl font-semibold text-foreground">Check your email</h2>
          <p className="text-sm text-muted-foreground">
            We&apos;ve sent a verification link to your email address. Click it to activate your account.
          </p>
          <Link href="/login" className="text-sm text-primary hover:underline">Back to sign in</Link>
        </div>
      </AuthShell>
    )
  }

  return (
    <AuthShell>
      <div className="relative w-full max-w-[420px]">
        <div className="flex flex-col items-center text-center">
          <div className="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10">
            <UserRound className="h-5 w-5 text-primary" />
          </div>
          <h1 className="mt-2 text-xl font-semibold text-foreground">Create a new account</h1>
          <p className="mt-1 text-sm text-muted-foreground">Start your journey with us</p>
        </div>

        <div className="mt-3.5">
          <GoogleSignInButton label="Google" mode="signup" />
          {/* Apple sign-in removed for now — commented out, not deleted; no real Apple
              auth backend exists yet anyway (see JwtService/GoogleOAuthController —
              Google is the only real OAuth provider), and no real Apple brand-logo
              asset (lucide's "Apple" is the fruit glyph, not the logomark). Uncomment
              alongside restoring the grid-cols-2 wrapper above if this comes back.
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

        <div className="relative my-3.5">
          <div className="absolute inset-0 flex items-center">
            <span className="w-full border-t border-border" />
          </div>
          <div className="relative flex justify-center text-xs uppercase">
            <span className="bg-background px-2 text-muted-foreground">or register with email</span>
          </div>
        </div>

        <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
          <form onSubmit={handleSubmit(onSubmit)} className="space-y-2.5">
            {serverError && ambiguous && (
              <div className="space-y-1 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <p>{serverError}</p>
                <p>
                  Check your email for a verification link, or{' '}
                  <Link href="/login" className="font-medium underline">try logging in</Link>{' '}
                  before submitting again — resubmitting with the same email may show &quot;already exists&quot;
                  if it did go through.
                </p>
              </div>
            )}
            {serverError && !ambiguous && (
              <div className="rounded-md bg-destructive/10 px-4 py-3 text-sm text-destructive">{serverError}</div>
            )}

            <div className="space-y-1">
              <label className="text-sm font-medium text-foreground">Full Name</label>
              <input
                type="text"
                placeholder="e.g., Jack Smith"
                {...register('name')}
                className="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
              />
              {errors.name && <p className="text-xs text-destructive">{errors.name.message}</p>}
            </div>

            <div className="space-y-1">
              <label className="text-sm font-medium text-foreground">Email</label>
              <input
                type="email"
                placeholder="e.g., jack@gmail.com"
                {...register('email')}
                className="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
              />
              {errors.email && <p className="text-xs text-destructive">{errors.email.message}</p>}
            </div>

            {/* Real field, required by the backend — not in the Figma mockup (which
                only shows Name/Email/Password), but the account can't be created
                without it, so it stays, styled to match. */}
            <div className="space-y-1">
              <label className="text-sm font-medium text-foreground">Currency</label>
              <select
                {...register('currency')}
                className="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
              >
                <option value="USD">USD — US Dollar</option>
                <option value="BDT">BDT — Bangladeshi Taka</option>
              </select>
            </div>

            <div className="space-y-1">
              <label className="text-sm font-medium text-foreground">Password</label>
              <div className="relative">
                <input
                  type={showPassword ? 'text' : 'password'}
                  placeholder="e.g., Sij@ck2025"
                  {...register('password')}
                  className="w-full rounded-xl border border-input bg-background px-3.5 py-2 pr-10 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
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
              <p className="text-xs text-muted-foreground">8+ characters, 1 uppercase, 1 number</p>
              {errors.password && <p className="text-xs text-destructive">{errors.password.message}</p>}
            </div>

            {/* Real field, required by the backend (password confirmation match) — not
                in the Figma mockup, kept for the same reason Currency is. */}
            <div className="space-y-1">
              <label className="text-sm font-medium text-foreground">Confirm password</label>
              <div className="relative">
                <input
                  type={showPasswordConfirmation ? 'text' : 'password'}
                  placeholder="••••••••"
                  {...register('password_confirmation')}
                  className="w-full rounded-xl border border-input bg-background px-3.5 py-2 pr-10 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                />
                <button
                  type="button"
                  onClick={() => setShowPasswordConfirmation((v) => !v)}
                  aria-label={showPasswordConfirmation ? 'Hide password' : 'Show password'}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground transition-colors hover:text-foreground"
                >
                  {showPasswordConfirmation ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                </button>
              </div>
              {errors.password_confirmation && (
                <p className="text-xs text-destructive">{errors.password_confirmation.message}</p>
              )}
            </div>

            <button
              type="submit"
              disabled={isSubmitting}
              className="w-full rounded-full bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground transition-colors hover:bg-primary/90 disabled:opacity-50"
            >
              {isSubmitting ? 'Signing Up...' : 'Sign Up'}
            </button>
          </form>
        </div>

        <p className="mt-3.5 text-center text-sm text-muted-foreground">
          Already have an account?{' '}
          <Link href="/login" className="font-medium text-primary hover:underline">
            Sign In
          </Link>
        </p>
      </div>
    </AuthShell>
  )
}
