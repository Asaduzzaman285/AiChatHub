'use client'

import Link from 'next/link'

export function CtaBanner() {
  return (
    <section className="px-6 py-10">
      {/* max-w-[1200px] matches the real Figma frame (1200x467) exactly, per the exact
          properties given — was max-w-5xl (1024px), and the gradient was too washed-out
          (low-opacity primary over a light page barely reads as a gradient at all) —
          the reference shows a fully-saturated violet-to-pink card, not a translucent tint. */}
      <div className="mx-auto max-w-[1200px] overflow-hidden rounded-3xl bg-gradient-to-br from-violet-400 via-purple-400 to-pink-300 p-10 sm:p-14">
        <div className="grid items-center gap-10 md:grid-cols-2">
          <div>
            <h2 className="text-balance font-display text-2xl font-bold tracking-tight text-white sm:text-3xl">
              To get the right AI agent, you have to get the right platform.
            </h2>
            <p className="mt-3 max-w-md text-white/65">
              One workspace, transparent usage-based billing, and every leading model kept current —
              so switching between tools stops being the bottleneck.
            </p>
            <div className="mt-6 flex flex-wrap items-center gap-3">
              <a href="#features" className="rounded-full border border-white/30 px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-white/10">
                Learn more
              </a>
              <Link href="/register" className="rounded-full bg-white px-5 py-2.5 text-sm font-semibold text-black transition-opacity hover:opacity-90">
                Try it now
              </Link>
            </div>
          </div>

          {/* eslint-disable-next-line @next/next/no-img-element -- decorative, fixed
              local asset; not worth Next/Image's runtime overhead here. */}
          <img src="/Int.svg" alt="" className="mx-auto w-full max-w-xs" />
        </div>
      </div>
    </section>
  )
}
