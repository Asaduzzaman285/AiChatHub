'use client'

import { type ReactNode, useMemo } from 'react'
import { Check } from 'lucide-react'
import { cn, formatCurrency } from '@/lib/utils'
import { usePublicModels } from '@/hooks/usePublicModels'
import type { Package } from '@/types'

// support_tier is rendered separately below (its own "Priority support" line),
// not as a checkmark item — every package has exactly one tier, it's never "off".
const FEATURE_LABELS: Record<Exclude<keyof Package['features'], 'support_tier'>, string> = {
  file_upload: 'File & document upload',
  api_access: 'API access',
  comparison: 'Compare models side by side',
  image_gen: 'Image generation',
  video_gen: 'Video generation',
  voice_gen: 'Voice generation',
  audio: 'Audio & voice',
  vision: 'Vision / image analysis',
  ide_integration: 'IDE integration support',
  prompt_library: 'Prompt library access',
}

/** Shared by the public landing page's PricingSection (CTA links to /register) and the
 * authenticated Welcome Screen (CTA routes into the Settings modal's real subscribe
 * flow instead) — same card, different call-to-action wired in by the caller.
 *
 * `layout="horizontal"` is for the Pro package specifically — with 4 real packages on
 * the server now (Basic/Standard/Pro/Play), a plain 3-column grid left Pro wrapping
 * onto its own row as a single narrow vertical card, which read as broken/unfinished
 * rather than intentional. Pro instead renders as a wide banner-style card spanning
 * the same total width as the row of 3 verticals above it — see PricingSection.tsx /
 * WelcomePricingSection.tsx for how the split is done. */
export function PricingCard({
  pkg,
  featured,
  popularBadge = featured,
  cta,
  layout = 'vertical',
  currency = 'USD',
}: {
  pkg: Package
  // Controls the highlighted border/shadow treatment — Pro's horizontal banner uses
  // this to stand out visually without necessarily claiming to be "Most Popular" too.
  featured: boolean
  // Defaults to match `featured` (unchanged behavior for the 3-card grid, where
  // "featured" and "popular" were always the same card). Pro's banner call sites pass
  // this explicitly as false — two cards claiming to be "Most Popular" at once on the
  // same page read as meaningless (confirmed live).
  popularBadge?: boolean
  cta: ReactNode
  layout?: 'vertical' | 'horizontal'
  // Informational display only — a BD visitor/user sees the package's own
  // fixed BDT sticker price here, but the currency actually charged at
  // checkout is decided by which payment method they pick (bKash -> BDT,
  // card -> USD), not by this prop. Falls back to USD whenever a package has
  // no BDT sticker price set (monthly_price_bdt is nullable).
  currency?: 'USD' | 'BDT'
}) {
  const features = (Object.keys(FEATURE_LABELS) as Exclude<keyof Package['features'], 'support_tier'>[]).filter((key) => pkg.features[key])
  const displayCurrency = currency === 'BDT' && pkg.price.bdt !== null ? 'BDT' : 'USD'
  const displayPrice = displayCurrency === 'BDT' ? pkg.price.bdt! : pkg.price.usd
  // wallet_credit_bdt is the real, admin-typed BDT entitlement a bKash
  // purchase actually grants (see PackageActivationService::computeWalletCredit())
  // — shown as-is, not derived from any formula, so this card never promises
  // a number the backend won't actually match. Falls back to the USD figure
  // whenever a package has no BDT credit configured (same rule the backend
  // itself uses).
  const displayWalletCredit = displayCurrency === 'BDT' && pkg.wallet_credit_bdt !== null ? pkg.wallet_credit_bdt : pkg.wallet_credit_usd

  // Real model names, not just the feature-flag summary above — a visitor comparing
  // plans previously had no way to see WHICH models a plan actually includes (e.g. does
  // Standard get Claude Sonnet 5 or only Haiku?), only booleans like "vision: true".
  // usePublicModels() is the same unauthenticated endpoint the landing navbar's model
  // showcase already uses, so this works for a visitor who hasn't signed up yet.
  const { models } = usePublicModels()
  const modelNames = useMemo(() => {
    const byId = new Map(models.map((m) => [m.model_id, m.name]))
    return pkg.model_access.map((id) => byId.get(id)).filter((name): name is string => !!name)
  }, [models, pkg.model_access])

  if (layout === 'horizontal') {
    return (
      <div
        className={cn(
          'flex flex-col gap-5 rounded-2xl border p-6 text-left sm:flex-row sm:items-center sm:gap-8',
          featured ? 'border-primary bg-primary/5 shadow-xl shadow-primary/10' : 'border-neutral-200 bg-white'
        )}
      >
        <div className="sm:w-56 sm:shrink-0">
          {popularBadge && (
            <span className="mb-2 inline-block w-fit rounded-full bg-primary px-3 py-1 text-[11px] font-semibold text-primary-foreground">
              Most Popular
            </span>
          )}
          <h3 className="text-lg font-semibold text-neutral-900">{pkg.name}</h3>
          <p className="mt-1 text-sm text-neutral-500">{pkg.description}</p>
          <div className="mt-3">
            <span className="text-3xl font-bold text-neutral-900">{formatCurrency(displayPrice, displayCurrency)}</span>
            <span className="text-sm text-neutral-500">/month</span>
          </div>
          <p className="mt-1 text-xs text-neutral-500">
            Includes {formatCurrency(displayWalletCredit, displayCurrency)} monthly wallet credit
          </p>
        </div>

        <div className="flex-1">
          <ul className="grid grid-cols-1 gap-x-6 gap-y-2.5 text-sm text-neutral-600 sm:grid-cols-2">
            {features.map((key) => (
              <li key={key} className="flex items-start gap-2">
                <Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-primary" />
                {FEATURE_LABELS[key]}
              </li>
            ))}
            {pkg.features.support_tier === 'priority' && (
              <li className="flex items-start gap-2">
                <Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-primary" />
                Priority support
              </li>
            )}
          </ul>
          {modelNames.length > 0 && (
            <div className="mt-4 flex flex-wrap gap-1.5">
              {modelNames.map((name) => (
                <span key={name} className="rounded-full bg-neutral-100 px-2.5 py-1 text-xs text-neutral-600">
                  {name}
                </span>
              ))}
            </div>
          )}
        </div>

        <div className="sm:w-40 sm:shrink-0">{cta}</div>
      </div>
    )
  }

  return (
    <div
      className={cn(
        'flex flex-col rounded-2xl border p-6 text-left',
        featured ? 'border-primary bg-primary/5 shadow-xl shadow-primary/10' : 'border-neutral-200 bg-white'
      )}
    >
      {popularBadge && (
        <span className="mb-3 inline-block w-fit rounded-full bg-primary px-3 py-1 text-[11px] font-semibold text-primary-foreground">
          Most Popular
        </span>
      )}
      <h3 className="text-lg font-semibold text-neutral-900">{pkg.name}</h3>
      <p className="mt-1 text-sm text-neutral-500">{pkg.description}</p>
      <div className="mt-4">
        <span className="text-3xl font-bold text-neutral-900">{formatCurrency(displayPrice, displayCurrency)}</span>
        <span className="text-sm text-neutral-500">/month</span>
      </div>
      <p className="mt-2 text-xs text-neutral-500">
        Includes {formatCurrency(displayWalletCredit, displayCurrency)} monthly wallet credit
      </p>

      <ul className="mt-5 flex-1 space-y-2.5 text-sm text-neutral-600">
        {features.map((key) => (
          <li key={key} className="flex items-start gap-2">
            <Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-primary" />
            {FEATURE_LABELS[key]}
          </li>
        ))}
        {pkg.features.support_tier === 'priority' && (
          <li className="flex items-start gap-2">
            <Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-primary" />
            Priority support
          </li>
        )}
      </ul>
      {modelNames.length > 0 && (
        <div className="mb-5 flex flex-wrap gap-1.5">
          {modelNames.map((name) => (
            <span key={name} className="rounded-full bg-neutral-100 px-2.5 py-1 text-xs text-neutral-600">
              {name}
            </span>
          ))}
        </div>
      )}

      {cta}
    </div>
  )
}
