import { useState } from 'react'
import { signInWithPopup, signOut } from 'firebase/auth'
import { auth, googleProvider } from '@/lib/firebase'
import { useAuthStore } from '@/stores/auth-store'

interface AuthResult {
  access_token:  string
  refresh_token: string
  token_type:    string
  expires_in:    number
  user: {
    id:                  string
    name:                string
    email:               string
    avatar_url:          string | null
    preferred_currency:  string
    status:              string
  }
  is_new_user: boolean
}

export function useFirebaseAuth() {
  const [loading, setLoading] = useState(false)
  const [error, setError]     = useState<string | null>(null)
  const { setAuth }           = useAuthStore()

  const signInWithGoogle = async (): Promise<AuthResult | null> => {
    setLoading(true)
    setError(null)

    try {
      // 1. Firebase popup
      const result  = await signInWithPopup(auth, googleProvider)
      const idToken = await result.user.getIdToken()

      // 2. Exchange Firebase token for our platform JWT
      const response = await fetch(
        `${process.env.NEXT_PUBLIC_API_URL}/api/v1/auth/firebase`,
        {
          method:  'POST',
          headers: { 'Content-Type': 'application/json' },
          body:    JSON.stringify({ id_token: idToken }),
        }
      )

      if (! response.ok) {
        const data = await response.json()
        throw new Error(data.message || 'Authentication failed')
      }

      const data: AuthResult = await response.json()

      // 3. Persist to Zustand store (handles localStorage via persist middleware)
      setAuth(data.user as never, data.access_token, data.refresh_token)

      // 4. Sign out of Firebase — we use our own JWT from here on
      await signOut(auth)

      return data
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Sign in failed'
      if (message.includes('popup-closed-by-user') || message.includes('cancelled-popup-request')) {
        setError(null)
      } else {
        setError(message)
      }
      return null
    } finally {
      setLoading(false)
    }
  }

  return { signInWithGoogle, loading, error }
}
