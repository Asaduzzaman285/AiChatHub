import { cn } from '@/lib/utils'
import type { ButtonHTMLAttributes } from 'react'
import { forwardRef } from 'react'

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary' | 'outline' | 'destructive'
}

const variants: Record<NonNullable<ButtonProps['variant']>, string> = {
  primary: 'bg-primary text-primary-foreground hover:bg-primary/90',
  secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
  outline: 'border border-input bg-background hover:bg-accent hover:text-accent-foreground',
  destructive: 'bg-destructive text-destructive-foreground hover:bg-destructive/90',
}

// forwardRef matters beyond convention here — Radix's `asChild` (DropdownMenuTrigger,
// Tooltip, etc.) clones this element and attaches its own ref to it to manage
// popup positioning/focus. Without forwardRef, that ref silently has nowhere to
// go and the trigger just doesn't open (confirmed live: the chat "+" menu).
export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
  ({ variant = 'primary', className, disabled, ...props }, ref) => (
    <button
      ref={ref}
      disabled={disabled}
      className={cn(
        'inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors disabled:opacity-50 disabled:pointer-events-none',
        variants[variant],
        className
      )}
      {...props}
    />
  )
)
Button.displayName = 'Button'
