'use client'

import { type ReactNode, useState } from 'react'
import { useRouter } from 'next/navigation'
import { Lock } from 'lucide-react'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/Popover'
import { Button } from '@/components/ui/Button'
import { useChatSession } from '@/contexts/ChatSessionContext'
import { useAvailableModels } from '@/hooks/useAvailableModels'

/** Extracted so both the sidebar's caret button and the Welcome Screen's "Private Chat"
 * pill open the identical create-flow instead of two copies of the same logic. Privacy
 * is fixed at creation (enforced server-side, see SessionController), so this is the one
 * place a private chat can ever be started — never a bare toggle on an existing session. */
export function PrivateChatPopover({ trigger }: { trigger: ReactNode }) {
  const router = useRouter()
  const { createSession } = useChatSession()
  const { availableModels } = useAvailableModels()
  const [open, setOpen] = useState(false)
  const [duration, setDuration] = useState(60)

  const start = () => {
    if (availableModels.length === 0) return
    createSession.mutate({ modelId: availableModels[0].id, isPrivate: true, privateDurationMinutes: duration })
    setOpen(false)
    router.push('/chat')
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>{trigger}</PopoverTrigger>
      <PopoverContent align="end" className="w-56">
        <div className="flex items-center gap-2 text-sm font-medium">
          <Lock className="h-3.5 w-3.5 text-muted-foreground" />
          Private chat
        </div>
        <p className="mt-1 text-xs text-muted-foreground">
          Incognito-style chat that deletes itself automatically. Can&apos;t be made private later.
        </p>
        <select
          value={duration}
          onChange={(e) => setDuration(Number(e.target.value))}
          className="mt-3 w-full rounded-md border border-input bg-background px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-ring"
        >
          <option value={60}>Deletes after 1 hour</option>
          <option value={180}>Deletes after 3 hours</option>
          <option value={360}>Deletes after 6 hours</option>
          <option value={1440}>Deletes after 24 hours</option>
        </select>
        <Button
          className="mt-3 w-full"
          disabled={availableModels.length === 0 || createSession.isPending}
          onClick={start}
        >
          Start private chat
        </Button>
      </PopoverContent>
    </Popover>
  )
}
