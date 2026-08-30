'use client'

// Overlay-only animation layer for /public/Int.svg — the original artwork (279KB,
// dominated by one embedded base64 PNG texture + a vectorized grain path) is left
// completely untouched, still rendered as a plain <img>. This is a separate, tiny
// <svg> positioned on top using the SAME viewBox (0 0 436 303, read directly from
// Int.svg) so it aligns pixel-for-pixel with the real artwork at any render size,
// without inlining or duplicating any of it.
//
// The 6 `d` strings below are copied verbatim from Int.svg's own dashed connector
// paths (stroke-dasharray="2.7 2.7"). Int.svg has 14 dashed arcs total; these 6 are
// the genuinely long ones (~82-110px endpoint-to-endpoint) that span between distinct
// nodes — the other 8 are short ~59px decorative "orbit ring" arcs that hug a single
// icon, not real inter-node connectors, so a traveling signal on those would just
// look like it circling one icon rather than flowing through the network.
// Each node's real position came from Int.svg's own drop-shadow <filter> bounding
// boxes: 6 outer-icon filters (~65-89px boxes) plus one much larger filter (148x148)
// centered on the viewBox's own middle — that's the central Alveta mark. `reverse`
// was computed from real coordinates (which endpoint sits closer to that central
// filter's center), not guessed — that's each signal's true starting point.
interface Signal {
  d: string
  reverse: boolean
  duration: number
  delay: number
}

const SIGNALS: Signal[] = [
  { d: 'M88.0557 121.838C88.0557 144.895 106.747 163.585 129.802 163.585C152.859 163.585 170.283 146.16 170.283 123.103', reverse: true,  duration: 3.2, delay: 0 },
  { d: 'M227.521 43.4076C227.521 79.4663 198.29 108.699 162.23 108.699',                                                    reverse: true,  duration: 2.8, delay: 0.8 },
  { d: 'M162.232 43.4076C162.232 79.4663 191.463 108.699 227.521 108.699',                                                  reverse: true,  duration: 2.8, delay: 1.6 },
  { d: 'M238.216 186.596C195.989 186.596 180.024 228.342 175.656 268.189',                                                  reverse: false, duration: 3.4, delay: 2.4 },
  { d: 'M357.331 164.152C332.932 199.761 284.286 208.848 248.677 184.448',                                                  reverse: true,  duration: 3.6, delay: 0.4 },
  { d: 'M186.84 209.764C153.056 236.632 104.23 231.458 77.7872 198.207',                                                    reverse: false, duration: 3.0, delay: 1.2 },
]

// motion-reduce:hidden on the whole layer — the static Int.svg underneath is already
// a complete image on its own, so hiding just the signals satisfies "keep the static
// illustration visible, disable the traveling signals."
export function IntNetworkSignals() {
  return (
    <svg
      viewBox="0 0 436 303"
      aria-hidden="true"
      className="pointer-events-none absolute inset-0 h-full w-full motion-reduce:hidden"
    >
      <defs>
        <radialGradient id="int-signal-glow" cx="50%" cy="50%" r="50%">
          <stop offset="0%" stopColor="#fff" stopOpacity="1" />
          <stop offset="100%" stopColor="#fff" stopOpacity="0" />
        </radialGradient>
      </defs>
      {SIGNALS.map((s, i) => (
        <circle key={i} r="1.8" fill="url(#int-signal-glow)">
          <animateMotion
            dur={`${s.duration}s`}
            begin={`${s.delay}s`}
            repeatCount="indefinite"
            calcMode="linear"
            keyPoints={s.reverse ? '1;0' : '0;1'}
            keyTimes="0;1"
            path={s.d}
          />
          {/* Fades in just after the loop restarts and out just before it ends, so the
              signal never visibly appears/disappears mid-travel. */}
          <animate
            attributeName="opacity"
            values="0;1;1;0"
            keyTimes="0;0.12;0.85;1"
            dur={`${s.duration}s`}
            begin={`${s.delay}s`}
            repeatCount="indefinite"
          />
        </circle>
      ))}
    </svg>
  )
}
