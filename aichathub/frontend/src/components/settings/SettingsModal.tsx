'use client'

import { useEffect, useState } from 'react'
import { BarChart3, CreditCard, Receipt, Sparkles, User as UserIcon, Wallet as WalletIcon } from 'lucide-react'
import { Dialog, DialogContent } from '@/components/ui/Dialog'
import { ProfileView } from '@/components/profile/ProfileView'
import { BillingView } from '@/components/settings/BillingView'
import { WalletView } from '@/components/settings/WalletView'
import { UsageView } from '@/components/settings/UsageView'
import { PlansView } from '@/components/settings/PlansView'
import { cn } from '@/lib/utils'

type Tab = 'account' | 'billing' | 'wallet' | 'usage' | 'plans'

const TABS: { id: Tab; label: string; icon: typeof UserIcon }[] = [
  { id: 'account', label: 'Account', icon: UserIcon },
  { id: 'billing', label: 'Billing', icon: Receipt },
  { id: 'wallet', label: 'Wallet', icon: WalletIcon },
  { id: 'usage', label: 'Usage', icon: BarChart3 },
  { id: 'plans', label: 'Plans', icon: CreditCard },
]

/**
 * Consolidates what were four separate routed pages (/profile, /billing, /wallet,
 * /pricing) into one modal, ChatGPT-settings-style — internal left mini-nav, content
 * on the right. Each tab's content is unchanged, just relocated (BillingView/WalletView/
 * PlansView were extracted from those pages verbatim, ProfileView already existed as a
 * standalone component from an earlier session).
 */
export function SettingsModal({ open, onOpenChange, defaultTab = 'account' }: {
  open: boolean
  onOpenChange: (open: boolean) => void
  defaultTab?: Tab
}) {
  const [tab, setTab] = useState<Tab>(defaultTab)

  // Radix's onOpenChange only fires for its OWN internally-triggered close/open
  // gestures (Escape, outside click) — never when a parent flips the controlled
  // `open` prop directly, which is the only way this modal ever opens in this app
  // (there's no <DialogTrigger> anywhere; every call site uses openSettings() to set
  // `open`/`defaultTab` state directly). Confirmed by reading @radix-ui/react-use-
  // controllable-state's actual source: onChange only fires from an internal setValue
  // call, not from the controlled prop itself changing. The old `if (next) setTab
  // (defaultTab)` inside onOpenChange below was therefore dead code — `tab` stayed
  // locked to whatever it was at first mount ('account', the initial default) no
  // matter which tab a caller actually requested, unless the user happened to click
  // through tabs manually first. This effect is what actually keeps them in sync.
  useEffect(() => {
    if (open) setTab(defaultTab)
  }, [open, defaultTab])

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="grid max-w-4xl grid-cols-[180px_1fr] gap-0 overflow-hidden p-0 sm:h-[80vh]">
        <div className="flex flex-col gap-1 border-r border-border bg-muted/30 p-3">
          <div className="flex items-center gap-2 px-2 py-2">
            <Sparkles className="h-4 w-4 text-primary" />
            <span className="font-display text-sm font-bold">Settings</span>
          </div>
          {TABS.map((t) => {
            const Icon = t.icon
            return (
              <button
                key={t.id}
                onClick={() => setTab(t.id)}
                className={cn(
                  'flex items-center gap-2.5 rounded-md px-3 py-2 text-left text-sm font-medium transition-colors',
                  tab === t.id ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                )}
              >
                <Icon className="h-4 w-4" />
                {t.label}
              </button>
            )
          })}
        </div>

        <div className="overflow-y-auto p-6">
          {tab === 'account' && <ProfileView />}
          {tab === 'billing' && <BillingView />}
          {tab === 'wallet' && <WalletView />}
          {tab === 'usage' && <UsageView />}
          {tab === 'plans' && <PlansView />}
        </div>
      </DialogContent>
    </Dialog>
  )
}

export type { Tab as SettingsTab }
