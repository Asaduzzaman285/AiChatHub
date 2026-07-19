import { create } from 'zustand'
import type { WalletBalance, WalletCreditStatus } from '@/types'

// GET /wallet and GET /wallet/credit are separate backend endpoints (see
// types/index.ts) — this store's real-time balance updates need both combined.
type WalletState_ = WalletBalance & WalletCreditStatus

interface WalletState {
  wallet: WalletState_ | null
  isLoading: boolean
  setWallet: (wallet: WalletState_) => void
  setLoading: (loading: boolean) => void
  updateBalance: (balance: number, creditBalance: number) => void
}

export const useWalletStore = create<WalletState>((set, get) => ({
  wallet: null,
  isLoading: false,

  setWallet: (wallet) => set({ wallet }),
  setLoading: (isLoading) => set({ isLoading }),

  updateBalance: (balance, creditBalance) => {
    const current = get().wallet
    if (!current) return
    const creditLimit = current.credit_limit
    const unusedCredit = creditLimit - Math.abs(creditBalance)
    set({
      wallet: {
        ...current,
        balance,
        credit_balance: creditBalance,
        available_balance: balance + Math.max(0, unusedCredit),
        remaining_credit: Math.max(0, unusedCredit),
      },
    })
  },
}))
