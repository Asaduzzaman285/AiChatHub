'use client'

import { useEffect } from 'react'
import { useRouter } from 'next/navigation'

/** /pricing is no longer its own page — Plans now lives in the Settings modal. */
export default function PricingRedirect() {
  const router = useRouter()
  useEffect(() => { router.replace('/chat?settings=plans') }, [router])
  return null
}
