'use client'

import { useEffect } from 'react'
import { useRouter } from 'next/navigation'

/** /profile is no longer its own page — Account now lives in the Settings modal. */
export default function ProfileRedirect() {
  const router = useRouter()
  useEffect(() => { router.replace('/chat?settings=account') }, [router])
  return null
}
