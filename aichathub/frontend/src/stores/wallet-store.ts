import { create } from 'zustand'
import type { WalletBalance } from '@/types'

interface WalletState {
  wallet: WalletBalance | null
  isLoading: boolean
  setWallet: (wallet: WalletBalance) => void
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
