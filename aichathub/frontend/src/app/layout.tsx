import type { Metadata } from 'next'
import { Inter, Plus_Jakarta_Sans } from 'next/font/google'
import { Toaster } from 'sonner'
import { QueryProvider } from '@/lib/query-provider'
import 'highlight.js/styles/github.css'
import './globals.css'

const inter = Inter({ subsets: ['latin'], variable: '--font-body' })
// Headings/titles use this instead of Inter — a geometric display face gives cards and
// page titles more visual weight without touching every page's body copy.
const jakarta = Plus_Jakarta_Sans({ subsets: ['latin'], weight: ['600', '700', '800'], variable: '--font-display' })

export const metadata: Metadata = {
  metadataBase: new URL('https://app.alveta.ai'),
  title: 'Alveta.ai — All the Best AI Models, One Place',
  description: 'Access, compare, and use the world\'s leading AI models all from one powerful workspace. Stop jumping between AI tools.',
  openGraph: {
    title: 'Alveta.ai — All the Best AI Models, One Place',
    description: 'Access, compare, and use the world\'s leading AI models all from one powerful workspace.',
    siteName: 'Alveta.ai',
    type: 'website',
  },
}

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en" suppressHydrationWarning>
      <body className={`${inter.variable} ${jakarta.variable} font-sans`}>
        <QueryProvider>
          {children}
          <Toaster position="top-right" richColors />
        </QueryProvider>
      </body>
    </html>
  )
}
