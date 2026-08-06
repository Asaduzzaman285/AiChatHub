import { cn } from '@/lib/utils'
import type { HTMLAttributes } from 'react'

/** Base shimmer block — bg-muted (not a hardcoded gray) so it's correct in both themes. */
export function Skeleton({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('skeleton rounded-md', className)} {...props} />
}

export function SkeletonText({ className, width = 'w-full' }: { className?: string; width?: string }) {
  return <Skeleton className={cn('h-4', width, className)} />
}

/** Matches admin/page.tsx's <Stat> shape: a big number line + a small label line. */
export function SkeletonStat() {
  return (
    <div className="space-y-2">
      <Skeleton className="h-7 w-16" />
      <Skeleton className="h-3.5 w-20" />
    </div>
  )
}

/**
 * Matches the raw <table> markup repeated across most admin list pages — there's no
 * shared Table component, so each page composes this into its own <tbody>.
 */
export function SkeletonTableRows({ columns, rows = 5 }: { columns: number; rows?: number }) {
  return (
    <>
      {Array.from({ length: rows }).map((_, r) => (
        <tr key={r} className="border-b border-border last:border-0">
          {Array.from({ length: columns }).map((__, c) => (
            <td key={c} className="py-3">
              <Skeleton className="h-4 w-full max-w-32" />
            </td>
          ))}
        </tr>
      ))}
    </>
  )
}

/** Matches the chat-session-sidebar shape: a title line + a smaller metadata line. */
export function SkeletonListItem() {
  return (
    <div className="space-y-2 border-b border-border px-3 py-2.5">
      <Skeleton className="h-4 w-3/4" />
      <Skeleton className="h-3 w-1/2" />
    </div>
  )
}

/** Matches KpiCard's shape: status strip + icon chip + big number + trend pill. */
export function SkeletonKpiCard() {
  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <Skeleton className="h-[3px] w-full rounded-none" />
      <div className="space-y-3 p-4">
        <div className="flex items-center justify-between">
          <Skeleton className="h-3 w-20" />
          <Skeleton className="h-7 w-7 rounded-lg" />
        </div>
        <Skeleton className="h-7 w-16" />
        <div className="flex items-center justify-between">
          <Skeleton className="h-4 w-14 rounded-full" />
          <Skeleton className="h-3 w-12" />
        </div>
      </div>
    </div>
  )
}

/**
 * A "media card" shaped skeleton — icon-circle + label row on top, a large shimmer
 * block standing in for a chart/graph beneath. Used wherever a card is about to
 * reveal a chart, so the loading state already reads as chart-shaped, not just text.
 */
export function SkeletonChartCard({ barRows = 4 }: { barRows?: number }) {
  return (
    <div className="space-y-4">
      <div className="flex items-center gap-2">
        <Skeleton className="h-8 w-8 rounded-full" />
        <Skeleton className="h-3.5 w-24" />
      </div>
      <div className="space-y-2.5">
        {Array.from({ length: barRows }).map((_, i) => (
          <Skeleton key={i} className="h-5 rounded-full" style={{ width: `${85 - i * 14}%` }} />
        ))}
      </div>
    </div>
  )
}
