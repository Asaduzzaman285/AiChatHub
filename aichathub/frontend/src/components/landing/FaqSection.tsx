'use client'

import * as Accordion from '@radix-ui/react-accordion'
import { ChevronDown } from 'lucide-react'

const FAQS = [
  {
    q: 'Which AI models can I use with Alveta?',
    a: 'Alveta connects you to leading models from OpenAI, Anthropic, Google, xAI, DeepSeek, and more — the exact lineup depends on your plan, and new models are added as providers release them.',
  },
  {
    q: 'Do I need separate AI subscriptions to use Alveta?',
    a: 'No — one Alveta plan replaces separate subscriptions to each provider. Your plan includes wallet credit that covers usage across every model you have access to.',
  },
  {
    q: 'Can I use multiple AI models at the same time?',
    a: 'Yes. Alveta is built to route to multiple models at once — send one prompt and compare answers from several models side by side in the same conversation.',
  },
  {
    q: 'Is Alveta free to use?',
    a: 'You can create an account and try Alveta with the models included on the free tier. Paid plans unlock the full model lineup, higher usage limits, and extra features like private chats.',
  },
  {
    q: 'Is my data secure with Alveta?',
    a: "Conversations are encrypted in transit, and private chats are never persisted beyond the timer you set for them. Alveta doesn't sell conversation data to third parties.",
  },
  {
    q: 'Can I upload and analyze files?',
    a: 'Yes — drop a PDF, spreadsheet, presentation, image, or document into a chat and ask questions grounded in its contents, no separate tool required.',
  },
]

export function FaqSection() {
  return (
    <section className="px-6 py-20">
      <div className="mx-auto max-w-3xl text-center">
        <h2 className="font-display text-3xl font-bold tracking-tight text-neutral-900 sm:text-4xl">
          Frequently Asked Questions
        </h2>
        <p className="mx-auto mt-4 max-w-xl text-neutral-500">
          Get the details on Alveta&apos;s models, capabilities, pricing, and security before you get
          started.
        </p>

        <Accordion.Root type="single" collapsible className="mt-10 space-y-3 text-left">
          {FAQS.map((item) => (
            <Accordion.Item
              key={item.q}
              value={item.q}
              className="overflow-hidden rounded-xl border border-neutral-200 bg-neutral-50"
            >
              <Accordion.Header>
                <Accordion.Trigger className="group flex w-full items-center justify-between gap-4 px-5 py-4 text-left text-sm font-medium text-neutral-900">
                  {item.q}
                  <ChevronDown className="h-4 w-4 shrink-0 text-neutral-400 transition-transform group-data-[state=open]:rotate-180" />
                </Accordion.Trigger>
              </Accordion.Header>
              <Accordion.Content className="px-5 pb-4 text-sm text-neutral-600 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0">
                {item.a}
              </Accordion.Content>
            </Accordion.Item>
          ))}
        </Accordion.Root>
      </div>
    </section>
  )
}
