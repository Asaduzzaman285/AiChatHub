import { withSentryConfig } from '@sentry/nextjs'

/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  // Required for the Dockerfile's production build — it copies .next/standalone
  // into the final image (a self-contained server bundle, no node_modules needed
  // at runtime). Without this, `next build` never produces that directory at all,
  // and the Docker build fails at the COPY step — never caught before now since
  // this is the first real (non-dev-mode) build this app has ever gone through.
  output: 'standalone',

  async rewrites() {
    return [
      {
        source: '/api/:path*',
        destination: `${process.env.API_GATEWAY_URL || 'http://localhost:8000'}/api/:path*`,
      },
    ]
  },

  images: {
    remotePatterns: [
      { protocol: 'https', hostname: 'lh3.googleusercontent.com' },
      { protocol: 'http',  hostname: 'localhost' },
    ],
  },
}

// No NEXT_PUBLIC_SENTRY_DSN set locally — the SDK just no-ops, this wrapper is safe
// to leave on unconditionally rather than branching dev vs prod config.
export default withSentryConfig(nextConfig, {
  silent: true,
  disableLogger: true,
})
