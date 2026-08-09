'use client'

import { useEffect } from 'react'
import { useRouter } from 'next/navigation'

/** /billing is no longer its own page — Billing now lives in the Settings modal. */
export default function BillingRedirect() {
  const router = useRouter()
  useEffect(() => { router.replace('/chat?settings=billing') }, [router])
  return null
}
