'use client'

import Link from 'next/link'
import { useEffect, useState } from 'react'
import { useRouter } from 'next/navigation'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { GoogleSignInButton } from '@/components/auth/GoogleSignInButton'
import { useAuthStore } from '@/stores/auth-store'
import apiClient from '@/lib/api-client'
import { describeError } from '@/lib/errors'

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
  const { isAuthenticated } = useAuthStore()
  const [serverError, setServerError] = useState<string | null>(null)
  const [ambiguous, setAmbiguous] = useState(false)
  const [success, setSuccess] = useState(false)

  useEffect(() => {
    if (isAuthenticated) router.replace('/chat')
  }, [isAuthenticated, router])

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
      <div className="flex min-h-screen items-center justify-center px-4">
        <div className="text-center space-y-3 max-w-sm">
          <div className="text-4xl">📬</div>
          <h2 className="text-xl font-semibold">Check your email</h2>
          <p className="text-sm text-muted-foreground">
            We&apos;ve sent a verification link to your email address. Click it to activate your account.
          </p>
          <Link href="/login" className="text-sm text-primary hover:underline">Back to sign in</Link>
        </div>
      </div>
    )
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-background px-4">
      <div className="w-full max-w-md space-y-6">

        <div className="text-center">
          <h1 className="text-3xl font-bold tracking-tight">Create account</h1>
          <p className="mt-2 text-sm text-muted-foreground">Get started with AI ChatHub</p>
        </div>

        <GoogleSignInButton label="Sign up with Google" mode="signup" />

        <div className="relative">
          <div className="absolute inset-0 flex items-center">
            <span className="w-full border-t" />
          </div>
          <div className="relative flex justify-center text-xs uppercase">
            <span className="bg-background px-2 text-muted-foreground">or register with email</span>
          </div>
        </div>

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
          {serverError && ambiguous && (
            <div className="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800 space-y-1">
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

          {/* Name */}
          <div className="space-y-1">
            <label className="text-sm font-medium">Full name</label>
            <input type="text" placeholder="John Doe" {...register('name')}
              className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring" />
            {errors.name && <p className="text-xs text-destructive">{errors.name.message}</p>}
          </div>

          {/* Email */}
          <div className="space-y-1">
            <label className="text-sm font-medium">Email</label>
            <input type="email" placeholder="you@example.com" {...register('email')}
              className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring" />
            {errors.email && <p className="text-xs text-destructive">{errors.email.message}</p>}
          </div>

          {/* Currency */}
          <div className="space-y-1">
            <label className="text-sm font-medium">Currency</label>
            <select {...register('currency')}
              className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring">
              <option value="USD">USD — US Dollar</option>
              <option value="BDT">BDT — Bangladeshi Taka</option>
            </select>
          </div>

          {/* Password */}
          <div className="space-y-1">
            <label className="text-sm font-medium">Password</label>
            <input type="password" placeholder="Min 8 chars, 1 uppercase, 1 number" {...register('password')}
              className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring" />
            {errors.password && <p className="text-xs text-destructive">{errors.password.message}</p>}
          </div>

          {/* Confirm Password */}
          <div className="space-y-1">
            <label className="text-sm font-medium">Confirm password</label>
            <input type="password" placeholder="••••••••" {...register('password_confirmation')}
              className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring" />
            {errors.password_confirmation && <p className="text-xs text-destructive">{errors.password_confirmation.message}</p>}
          </div>

          <button type="submit" disabled={isSubmitting}
            className="w-full rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
            {isSubmitting ? 'Creating account...' : 'Create account'}
          </button>
        </form>

        <p className="text-center text-sm text-muted-foreground">
          Already have an account?{' '}
          <Link href="/login" className="text-primary hover:underline font-medium">Sign in</Link>
        </p>
      </div>
    </div>
  )
}
