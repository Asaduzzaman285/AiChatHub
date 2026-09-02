import { LegalPageShell } from '@/components/legal/LegalPageShell'

export default function RefundPolicyPage() {
  return (
    <LegalPageShell title="Refund Policy" updated="August 31, 2026">
      <p>
        This policy explains how refunds work for Alveta.ai subscriptions and wallet top-ups.
      </p>

      <h2>Subscription Plans</h2>
      <p>
        Subscribing or upgrading charges the full plan price immediately. If you believe you were charged
        in error, or you want to cancel shortly after subscribing, contact support within 7 days of the
        charge and we&apos;ll review your request.
      </p>
      <p>
        Cancelling a subscription stops future billing, but you keep access to your current plan until the
        end of the billing cycle you already paid for — we don&apos;t prorate a refund for the unused
        portion of a cancelled cycle. Downgrading takes effect at your next renewal rather than
        immediately, so no refund is owed for the difference.
      </p>

      <h2>Wallet Top-Ups</h2>
      <p>
        Wallet credit is used to pay for AI usage (per-message costs based on the models you use). Because
        credit can be spent as soon as it&apos;s added, top-ups are generally non-refundable once any
        portion of the balance has been used. An unused top-up may be refunded if requested promptly after
        purchase.
      </p>

      <h2>How to Request a Refund</h2>
      <p>
        Contact our support team through the app with your account email and the transaction you&apos;re
        asking about. Include as much detail as you can (date, amount, plan or top-up) so we can look it up
        quickly.
      </p>

      <h2>Processing Time</h2>
      <p>
        Approved refunds are returned to your original payment method and may take several business days to
        appear, depending on your bank or card issuer.
      </p>

      <h2>Contact</h2>
      <p>For any billing question, reach out to our support team through the app.</p>
    </LegalPageShell>
  )
}
