import { ArrowDown, ArrowUp, type LucideIcon } from 'lucide-react'
import { cn } from '@/lib/utils'

const TONE_CHIP: Record<'primary' | 'success' | 'warning' | 'info', string> = {
  primary: 'bg-primary/10 text-primary',
  success: 'bg-success-soft text-success',
  warning: 'bg-warning-soft text-warning',
  info: 'bg-info-soft text-info',
}

const STATUS_STRIP: Record<'ok' | 'watch', string> = {
  ok: 'bg-success',
  watch: 'bg-warning',
}

const TREND_STYLE: Record<'up' | 'down' | 'flat', string> = {
  up: 'bg-success-soft text-success',
  down: 'bg-destructive/10 text-destructive',
  flat: 'bg-muted text-muted-foreground',
}

/**
 * Top-row hero card — status strip + icon chip + big number + trend pill + a
 * secondary muted line. Every value passed in must trace back to a real field
 * already returned by the dashboard APIs (see admin/page.tsx) — no fabricated
 * trends here, only what the data can actually back.
 */
export function KpiCard({
  label, value, icon: Icon, tone, status, trend, secondary,
}: {
  label: string
  value: string | number
  icon: LucideIcon
  tone: 'primary' | 'success' | 'warning' | 'info'
  status: 'ok' | 'watch'
  trend: { direction: 'up' | 'down' | 'flat'; label: string }
  secondary: string
}) {
  const TrendIcon = trend.direction === 'up' ? ArrowUp : trend.direction === 'down' ? ArrowDown : null

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <div className={cn('h-[3px] w-full', STATUS_STRIP[status])} />
      <div className="space-y-2.5 p-4">
        <div className="flex items-center justify-between">
          <span className="text-xs font-semibold text-muted-foreground">{label}</span>
          <div className={cn('flex h-7 w-7 items-center justify-center rounded-lg', TONE_CHIP[tone])}>
            <Icon className="h-3.5 w-3.5" />
          </div>
        </div>
        <p className="font-display text-[26px] font-bold leading-none tracking-tight">{value}</p>
        <div className="flex items-center justify-between pt-0.5">
          <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold', TREND_STYLE[trend.direction])}>
            {TrendIcon && <TrendIcon className="h-2.5 w-2.5" />}
            {trend.label}
          </span>
          <span className="text-[11px] text-muted-foreground">{secondary}</span>
        </div>
      </div>
    </div>
  )
}

const BAR_COLORS = ['bg-chart-1', 'bg-chart-2', 'bg-chart-3', 'bg-chart-4', 'bg-chart-5']

/** CSS-only rounded track/fill bar — replaces the Recharts version for simple 2-3 row breakdowns. */
export function BarRow({ name, value, max, index = 0 }: { name: string; value: number; max: number; index?: number }) {
  const pct = max > 0 ? Math.round((value / max) * 100) : 0
  return (
    <div>
      <div className="mb-1.5 flex items-center justify-between text-xs">
        <span className="font-medium capitalize text-muted-foreground">{name}</span>
        <span className="font-semibold tabular-nums">{value}</span>
      </div>
      <div className="h-1.5 overflow-hidden rounded-full bg-muted">
        <div className={cn('h-full rounded-full', BAR_COLORS[index % BAR_COLORS.length])} style={{ width: `${pct}%` }} />
      </div>
    </div>
  )
}

const DOT_TONE: Record<'success' | 'warning' | 'destructive' | 'info', string> = {
  success: 'bg-success', warning: 'bg-warning', destructive: 'bg-destructive', info: 'bg-info',
}
const VALUE_TONE: Record<'success' | 'warning' | 'destructive' | 'info', string> = {
  success: 'text-success', warning: 'text-warning', destructive: 'text-destructive', info: 'text-info',
}

/** Colored-dot label + value row — for status breakdowns (completed/pending/failed, etc). */
export function DotRow({ name, value, tone }: { name: string; value: string | number; tone: 'success' | 'warning' | 'destructive' | 'info' }) {
  return (
    <div className="flex items-center justify-between border-b border-border/60 py-2 last:border-0 last:pb-0">
      <div className="flex items-center gap-2">
        <span className={cn('h-2 w-2 shrink-0 rounded-full', DOT_TONE[tone])} />
        <span className="text-sm text-muted-foreground">{name}</span>
      </div>
      <span className={cn('text-sm font-bold tabular-nums', VALUE_TONE[tone])}>{value}</span>
    </div>
  )
}
