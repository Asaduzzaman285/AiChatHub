'use client'

import { useEffect } from 'react'
import { useRouter } from 'next/navigation'

/** /wallet is no longer its own page — Wallet now lives in the Settings modal. */
export default function WalletRedirect() {
  const router = useRouter()
  useEffect(() => { router.replace('/chat?settings=wallet') }, [router])
  return null
}
