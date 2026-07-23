import { create } from 'zustand'
import { persist, createJSONStorage } from 'zustand/middleware'
import type { User } from '@/types'

// Plain, non-httpOnly marker cookie — NOT the real auth mechanism (that's still the
// JWT in localStorage/Authorization headers, unchanged). This exists purely so
// middleware.ts (which runs server-side and can't see localStorage at all) can make a
// fast redirect decision for a definitely-logged-out visitor. It carries no token and
// can't be cryptographically verified — real authorization is still enforced by
// (dashboard)/layout.tsx's client-side check and the backend's own JWT verification.
function setSessionCookie() {
  if (typeof document !== 'undefined') {
    document.cookie = 'has_session=1; path=/; SameSite=Lax'
  }
}
function clearSessionCookie() {
  if (typeof document !== 'undefined') {
    document.cookie = 'has_session=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT'
  }
}

interface AuthState {
  user: User | null
  accessToken: string | null
  refreshToken: string | null
  isAuthenticated: boolean
  // True once zustand-persist has finished reading localStorage. On a normal
  // in-app navigation this happens long before any guard checks isAuthenticated,
  // so it went unnoticed — but a full page reload (e.g. returning from an
  // external redirect like Stripe Checkout) re-runs the app from scratch, and
  // isAuthenticated briefly reads its false default before rehydration
  // completes. Consumers must wait for this before treating isAuthenticated as
  // a real answer, or they'll bounce a genuinely logged-in user to /login.
  hasHydrated: boolean

  // Actions
  setAuth: (user: User, accessToken: string, refreshToken: string) => void
  setTokens: (accessToken: string, refreshToken: string) => void
  setUser: (user: User) => void
  clearAuth: () => void
  setHasHydrated: (value: boolean) => void
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      user: null,
      accessToken: null,
      refreshToken: null,
      isAuthenticated: false,
      hasHydrated: false,

      setAuth: (user, accessToken, refreshToken) => {
        setSessionCookie()
        set({ user, accessToken, refreshToken, isAuthenticated: true })
      },

      setTokens: (accessToken, refreshToken) =>
        set({ accessToken, refreshToken }),

      setUser: (user) => set({ user }),

      clearAuth: () => {
        clearSessionCookie()
        set({ user: null, accessToken: null, refreshToken: null, isAuthenticated: false })
      },

      setHasHydrated: (value) => set({ hasHydrated: value }),
    }),
    {
      name: 'auth-storage',
      storage: createJSONStorage(() => localStorage),
      // Only persist tokens — user profile is re-fetched on load
      partialize: (state) => ({
        accessToken: state.accessToken,
        refreshToken: state.refreshToken,
        isAuthenticated: state.isAuthenticated,
      }),
      onRehydrateStorage: () => (state) => state?.setHasHydrated(true),
    }
  )
)

// zustand-persist writes login/logout to localStorage but each browser tab has
// its own in-memory copy of the store — a tab never notices another tab's
// writes on its own. Without this, logging in on tab A leaves tab B still
// showing the login page (and bouncing to /login if you navigate) until tab B
// is manually reloaded. The `storage` event only fires in *other* tabs (never
// the one that made the write), which is exactly the case that needs covering.
if (typeof window !== 'undefined') {
  window.addEventListener('storage', (event) => {
    if (event.key === 'auth-storage') {
      useAuthStore.persist.rehydrate()
    }
  })
}
