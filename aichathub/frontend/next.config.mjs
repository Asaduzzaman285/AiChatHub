import { withSentryConfig } from '@sentry/nextjs'

/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,

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
