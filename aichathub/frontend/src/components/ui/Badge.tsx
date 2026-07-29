import { cn } from '@/lib/utils'
import type { HTMLAttributes } from 'react'

interface BadgeProps extends HTMLAttributes<HTMLSpanElement> {
  variant?: 'success' | 'warning' | 'destructive' | 'neutral'
}

const variants: Record<NonNullable<BadgeProps['variant']>, string> = {
  success: 'bg-green-600/10 text-green-600',
  warning: 'bg-yellow-600/10 text-yellow-600',
  destructive: 'bg-destructive/10 text-destructive',
  neutral: 'bg-muted text-muted-foreground',
}

export function Badge({ variant = 'neutral', className, ...props }: BadgeProps) {
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize',
        variants[variant],
        className
      )}
      {...props}
    />
  )
}
