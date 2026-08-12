import * as Sentry from '@sentry/nextjs'

// Blank DSN is safe — the SDK no-ops with nothing to send to. Real value goes in
// NEXT_PUBLIC_SENTRY_DSN once the separate "aichathub-frontend" Sentry project exists
// (backend uses a different, already-wired-up "aichathub-backend" project).
Sentry.init({
  dsn: process.env.NEXT_PUBLIC_SENTRY_DSN,
  environment: process.env.NODE_ENV,
  tracesSampleRate: 0.1,
})
