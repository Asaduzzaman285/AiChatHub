'use client'

import { useEffect, useRef } from 'react'
import { useRouter, useSearchParams } from 'next/navigation'
import { useAuthStore } from '@/stores/auth-store'
import apiClient from '@/lib/api-client'
import type { User } from '@/types'

/**
 * Google OAuth Callback Handler
 *
 * After Google → Auth Service → redirects here with:
 *   /auth/callback?access_token=xxx&refresh_token=yyy
 *
 * This page:
 *  1. Reads tokens from URL
 *  2. Fetches user profile
 *  3. Stores everything in Zustand
 *  4. Redirects to /chat
 */
export default function AuthCallbackPage() {
  const router       = useRouter()
  const searchParams = useSearchParams()
  const { setAuth, clearAuth } = useAuthStore()
  const hasRun = useRef(false)

  useEffect(() => {
    if (hasRun.current) return
    hasRun.current = true

    const accessToken  = searchParams.get('access_token')
    const refreshToken = searchParams.get('refresh_token')
    const error        = searchParams.get('error')

    if (error || !accessToken || !refreshToken) {
      router.replace('/login?error=google_auth_failed')
      return
    }

    const finalise = async () => {
      try {
        const { data: user } = await apiClient.get<User>('/api/v1/auth/me', {
          headers: { Authorization: `Bearer ${accessToken}` },
        })

        setAuth(user, accessToken, refreshToken)

        // Clean tokens from URL before redirecting
        window.history.replaceState({}, '', '/auth/callback')
        router.replace('/chat')
      } catch {
        clearAuth()
        router.replace('/login?error=profile_fetch_failed')
      }
    }

    finalise()
  }, [searchParams, router, setAuth, clearAuth])

  return (
    <div className="flex min-h-screen items-center justify-center">
      <div className="text-center space-y-3">
        <div className="mx-auto h-8 w-8 animate-spin rounded-full border-4 border-primary border-t-transparent" />
        <p className="text-sm text-muted-foreground">Completing sign-in...</p>
      </div>
    </div>
  )
}
