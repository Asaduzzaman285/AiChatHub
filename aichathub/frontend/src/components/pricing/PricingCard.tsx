'use client'

import { type ReactNode } from 'react'
import { Check } from 'lucide-react'
import { cn, formatCurrency } from '@/lib/utils'
import type { Package } from '@/types'

const FEATURE_LABELS: Record<keyof Package['features'], string> = {
  file_upload: 'File & document upload',
  api_access: 'API access',
  comparison: 'Compare models side by side',
  image_gen: 'Image generation',
  audio: 'Audio & voice',
  vision: 'Vision / image analysis',
}

/** Shared by the public landing page's PricingSection (CTA links to /register) and the
 * authenticated Welcome Screen (CTA routes into the Settings modal's real subscribe
 * flow instead) — same card, different call-to-action wired in by the caller. */
export function PricingCard({ pkg, featured, cta }: { pkg: Package; featured: boolean; cta: ReactNode }) {
  return (
    <div
      className={cn(
        'flex flex-col rounded-2xl border p-6 text-left',
        featured ? 'border-primary bg-primary/5 shadow-xl shadow-primary/10' : 'border-neutral-200 bg-white'
      )}
    >
      {featured && (
        <span className="mb-3 inline-block w-fit rounded-full bg-primary px-3 py-1 text-[11px] font-semibold text-primary-foreground">
          Most Popular
        </span>
      )}
      <h3 className="text-lg font-semibold text-neutral-900">{pkg.name}</h3>
      <p className="mt-1 text-sm text-neutral-500">{pkg.description}</p>
      <div className="mt-4">
        <span className="text-3xl font-bold text-neutral-900">{formatCurrency(pkg.price.usd)}</span>
        <span className="text-sm text-neutral-500">/month</span>
      </div>
      <p className="mt-2 text-xs text-neutral-500">
        Includes {formatCurrency(pkg.wallet_credit_usd)} monthly wallet credit
      </p>

      <ul className="mt-5 flex-1 space-y-2.5 text-sm text-neutral-600">
        {(Object.keys(FEATURE_LABELS) as (keyof Package['features'])[])
          .filter((key) => pkg.features[key])
          .map((key) => (
            <li key={key} className="flex items-start gap-2">
              <Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-primary" />
              {FEATURE_LABELS[key]}
            </li>
          ))}
      </ul>

      {cta}
    </div>
  )
}
