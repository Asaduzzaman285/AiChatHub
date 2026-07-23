import { NextResponse } from 'next/server'
import type { NextRequest } from 'next/server'

const PROTECTED_PREFIXES = ['/chat', '/pricing', '/wallet', '/billing', '/profile']

/**
 * Edge-level redirect for the common "definitely logged out" case — avoids the
 * flash of protected content / wasted round-trip that the client-side guard in
 * (dashboard)/layout.tsx otherwise incurs on a cold page load.
 *
 * This is NOT the real authorization boundary: `has_session` is a plain,
 * non-httpOnly marker cookie (see auth-store.ts) that carries no token and
 * can't be cryptographically verified here. The JWT itself lives only in
 * localStorage, which middleware (server-side) can never read. Actual
 * authorization is unchanged — (dashboard)/layout.tsx's client-side check and
 * the backend's own JWT verification remain the real enforcement; a stale or
 * forged `has_session` cookie with no valid token still gets bounced by those.
 */
export function middleware(request: NextRequest) {
  const isProtected = PROTECTED_PREFIXES.some((prefix) => request.nextUrl.pathname.startsWith(prefix))

  if (isProtected && !request.cookies.has('has_session')) {
    const loginUrl = new URL('/login', request.url)
    return NextResponse.redirect(loginUrl)
  }

  return NextResponse.next()
}

export const config = {
  matcher: ['/chat/:path*', '/pricing/:path*', '/wallet/:path*', '/billing/:path*', '/profile/:path*'],
}
