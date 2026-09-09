'use client'

import { createContext, useContext, useState, type ReactNode } from 'react'
import * as RadixDialog from '@radix-ui/react-dialog'
import { X } from 'lucide-react'

interface LightboxState {
  src: string
  alt: string
}

interface ImageLightboxContextValue {
  openLightbox: (src: string, alt?: string) => void
}

const ImageLightboxContext = createContext<ImageLightboxContextValue | null>(null)

/**
 * One global viewer for every image in the app, not a per-component modal —
 * mounted once here (see app/layout.tsx) so uploaded and AI-generated images
 * both use the exact same lightbox via the same useImageLightbox() hook,
 * regardless of which chat component rendered the thumbnail that opened it.
 *
 * Built on raw Radix Dialog primitives rather than the shared DialogContent
 * wrapper — that component's card chrome (bg-card, padding, max-w-md, a small
 * plain close icon meant to sit on a white card) is the wrong shape for a
 * full-bleed image viewer over a dark backdrop, and reusing it here would
 * mean fighting its defaults with overrides rather than just building the
 * right thing directly. Radix still supplies everything the spec needs for
 * free: clicking the overlay outside Content closes it (its own dismissable-
 * layer/outside-click handling), Escape closes it, and focus is trapped and
 * restored correctly on open/close — none of that is hand-rolled here.
 */
export function ImageLightboxProvider({ children }: { children: ReactNode }) {
  // Open/closed and "which image" are deliberately separate state. Clearing the
  // image data the instant the dialog closes would unmount the <img> immediately
  // while Radix's own fade/zoom-out exit animation is still playing on Content —
  // the box would animate away empty instead of the image fading out with it.
  // Leaving `image` populated after close is harmless: Content is fully removed
  // from the DOM once the exit animation ends, and the next openLightbox() call
  // overwrites it before anything is shown again anyway.
  const [open, setOpen] = useState(false)
  const [image, setImage] = useState<LightboxState | null>(null)

  const openLightbox = (src: string, alt = 'Image preview') => {
    setImage({ src, alt })
    setOpen(true)
  }

  return (
    <ImageLightboxContext.Provider value={{ openLightbox }}>
      {children}
      <RadixDialog.Root open={open} onOpenChange={setOpen}>
        <RadixDialog.Portal>
          {/* Deliberately darker/more opaque than the shared Dialog's overlay
              (black/50) — an image viewer wants the backdrop to recede more so
              the image itself reads as the clear focus, while still showing
              enough of the chat behind it to stay oriented in context. */}
          <RadixDialog.Overlay className="fixed inset-0 z-50 bg-black/85 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0" />
          {/* No explicit width/height — Content shrink-wraps to the image's own
              rendered size, so it only ever covers the image itself. Radix's
              outside-click dismissal is a global pointerdown check against
              each open layer's DOM node, not click-bubbling through Overlay,
              so this naturally gives "click the image: nothing happens, click
              anywhere else on the backdrop: closes" without any extra
              stopPropagation hack. */}
          <RadixDialog.Content
            className="fixed left-1/2 top-1/2 z-50 -translate-x-1/2 -translate-y-1/2 outline-none data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95 data-[state=closed]:zoom-out-95"
            aria-describedby={undefined}
          >
            <RadixDialog.Title className="sr-only">{image?.alt ?? 'Image preview'}</RadixDialog.Title>
            {image && (
              // object-contain + viewport-bounded max dimensions — the actual
              // requirement (as large as possible, original aspect ratio kept,
              // never stretched/cropped/distorted) rather than a fixed box.
              <img
                src={image.src}
                alt={image.alt}
                className="max-h-[90vh] max-w-[95vw] rounded-md object-contain shadow-2xl"
              />
            )}
            <RadixDialog.Close
              aria-label="Close"
              title="Close"
              className="fixed right-4 top-4 z-[60] flex h-10 w-10 items-center justify-center rounded-full bg-black/60 text-white transition-colors hover:bg-black/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
            >
              <X className="h-5 w-5" />
            </RadixDialog.Close>
          </RadixDialog.Content>
        </RadixDialog.Portal>
      </RadixDialog.Root>
    </ImageLightboxContext.Provider>
  )
}

export function useImageLightbox() {
  const ctx = useContext(ImageLightboxContext)
  if (!ctx) throw new Error('useImageLightbox must be used within an ImageLightboxProvider')
  return ctx
}
