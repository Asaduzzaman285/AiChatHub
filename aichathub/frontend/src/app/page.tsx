'use client'

import { useEffect } from 'react'
import { useRouter } from 'next/navigation'
import { useAuthStore } from '@/stores/auth-store'
import { postLoginPath } from '@/lib/post-login-redirect'

export default function RootPage() {
  const router = useRouter()
  const { isAuthenticated, user } = useAuthStore()

  useEffect(() => {
    router.replace(isAuthenticated ? postLoginPath(user) : '/login')
  }, [isAuthenticated, user, router])

  return null
}
