import { LegalPageShell } from '@/components/legal/LegalPageShell'

export default function PrivacyPage() {
  return (
    <LegalPageShell title="Privacy Policy" updated="August 31, 2026">
      <p>
        This Privacy Policy explains how Alveta.ai (&quot;we&quot;, &quot;us&quot;) collects, uses, and protects
        your information when you use our platform to access, compare, and chat with AI models from
        multiple providers.
      </p>

      <h2>Information We Collect</h2>
      <ul>
        <li>Account details you provide when signing up — name, email address, and password.</li>
        <li>Conversation content — the messages and files you send to a chat session, so it can be shown back to you and used for the AI response.</li>
        <li>Billing information processed by our payment providers (currently Stripe) — we do not store your full card details ourselves.</li>
        <li>Usage data — which models you use, token/usage counts, and wallet activity, used for billing and to improve the service.</li>
      </ul>

      <h2>How We Use Your Information</h2>
      <p>We use the information above to:</p>
      <ul>
        <li>Operate your account, chat sessions, and subscription.</li>
        <li>Route your messages to the AI provider(s) you select, and return their responses to you.</li>
        <li>Process payments and maintain your wallet balance.</li>
        <li>Improve reliability, security, and the overall product.</li>
      </ul>

      <h2>Sharing With Third Parties</h2>
      <p>
        To provide the service, your message content is sent to the underlying AI provider(s) you choose
        (for example Anthropic, OpenAI, Google, or others available on the platform) solely to generate a
        response. Payment details are shared with our payment processor to complete a transaction. We do
        not sell your personal data to third parties.
      </p>

      <h2>Data Security</h2>
      <p>
        Uploaded files are scanned for malware before being stored, and access to stored files is via
        time-limited signed links rather than public URLs. We use industry-standard measures to protect
        your account and data, but no online service can guarantee absolute security.
      </p>

      <h2>Your Rights</h2>
      <p>
        You may request access to, correction of, or deletion of your personal data at any time by
        contacting us. Deleting your account removes your chat history and personal information from our
        active systems, subject to what we are required to retain for billing or legal purposes.
      </p>

      <h2>Changes to This Policy</h2>
      <p>
        We may update this policy from time to time. Material changes will be reflected by updating the
        &quot;Last updated&quot; date above.
      </p>

      <h2>Contact</h2>
      <p>Questions about this policy can be sent to our support team through the app.</p>
    </LegalPageShell>
  )
}
