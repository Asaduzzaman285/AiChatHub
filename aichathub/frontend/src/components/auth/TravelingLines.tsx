'use client'

// Two thin vertical lines flanking the auth card, each with a soft highlight segment
// that continuously travels top-to-bottom and loops. Purely decorative — described
// directly by the user, not reverse-engineered from the Figma reference (that line has
// no animation attached; verified before building this, not assumed).
// max-w-[640px] (not the card's own max-w-md) — measured directly against the user's
// reference screenshot: lines at x≈402/1038 in a 1440px frame, card at x=510-930 —
// a ~108px gap from the card edge, not positioned relative to the card itself (a
// page-level margin system, so it stays correct if the card's own width ever changes).
// Hidden below `sm` — a decorative side element has no room on narrow viewports and
// would otherwise sit uncomfortably close to (or under) the card.
export function TravelingLines() {
  return (
    <div
      aria-hidden="true"
      className="pointer-events-none fixed inset-y-0 left-1/2 hidden w-full max-w-[640px] -translate-x-1/2 justify-between sm:flex"
    >
      {/* Slightly different durations (not identical) so the two sides don't move in
          lockstep — a perfectly synchronized pair reads as mechanical/artificial. */}
      <TravelingLine durationMs={5500} />
      <TravelingLine durationMs={6200} delayMs={900} />
    </div>
  )
}

function TravelingLine({ durationMs, delayMs = 0 }: { durationMs: number; delayMs?: number }) {
  return (
    <div className="relative h-full w-px overflow-hidden bg-border/50">
      {/* motion-reduce:hidden — respects prefers-reduced-motion; the static base line
          above still gives the same subtle framing with zero motion.
          Opacity is keyed alongside `top` in the travel-line keyframes (see
          tailwind.config.ts) so the segment fades out before it reaches the clipped
          edge and fades back in at the top, instead of instantly snapping. */}
      <div
        className="absolute left-0 h-1/4 w-full motion-safe:animate-travel-line motion-reduce:hidden"
        style={{
          animationDuration: `${durationMs}ms`,
          animationDelay: `${delayMs}ms`,
          backgroundImage:
            'linear-gradient(to bottom, transparent, hsl(var(--primary)) 50%, transparent)',
        }}
      />
    </div>
  )
}
