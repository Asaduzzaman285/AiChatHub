import * as RadixPopover from '@radix-ui/react-popover'
import { cn } from '@/lib/utils'
import type { ComponentPropsWithoutRef, ElementRef } from 'react'
import { forwardRef } from 'react'

export const Popover = RadixPopover.Root
export const PopoverTrigger = RadixPopover.Trigger

export const PopoverContent = forwardRef<
  ElementRef<typeof RadixPopover.Content>,
  ComponentPropsWithoutRef<typeof RadixPopover.Content>
>(({ className, sideOffset = 6, ...props }, ref) => (
  <RadixPopover.Portal>
    <RadixPopover.Content
      ref={ref}
      sideOffset={sideOffset}
      className={cn(
        'z-50 rounded-lg border border-border bg-card p-3 shadow-lg outline-none',
        'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95',
        className
      )}
      {...props}
    />
  </RadixPopover.Portal>
))
PopoverContent.displayName = 'PopoverContent'
