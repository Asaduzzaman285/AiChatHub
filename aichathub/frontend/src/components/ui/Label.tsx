import * as RadixLabel from '@radix-ui/react-label'
import { cn } from '@/lib/utils'
import type { ComponentPropsWithoutRef, ElementRef } from 'react'
import { forwardRef } from 'react'

export const Label = forwardRef<
  ElementRef<typeof RadixLabel.Root>,
  ComponentPropsWithoutRef<typeof RadixLabel.Root>
>(({ className, ...props }, ref) => (
  <RadixLabel.Root ref={ref} className={cn('text-sm font-medium leading-none', className)} {...props} />
))
Label.displayName = 'Label'
