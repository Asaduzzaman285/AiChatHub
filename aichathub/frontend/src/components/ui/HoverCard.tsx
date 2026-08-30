import * as RadixHoverCard from '@radix-ui/react-hover-card'
import { cn } from '@/lib/utils'
import type { ComponentPropsWithoutRef, ElementRef } from 'react'
import { forwardRef } from 'react'

export const HoverCard = RadixHoverCard.Root
export const HoverCardTrigger = RadixHoverCard.Trigger

export const HoverCardContent = forwardRef<
  ElementRef<typeof RadixHoverCard.Content>,
  ComponentPropsWithoutRef<typeof RadixHoverCard.Content>
>(({ className, sideOffset = 8, ...props }, ref) => (
  <RadixHoverCard.Portal>
    <RadixHoverCard.Content
      ref={ref}
      sideOffset={sideOffset}
      className={cn(
        'z-50 rounded-lg border border-border bg-card shadow-lg outline-none',
        'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95',
        className
      )}
      {...props}
    />
  </RadixHoverCard.Portal>
))
HoverCardContent.displayName = 'HoverCardContent'
