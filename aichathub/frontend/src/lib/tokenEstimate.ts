// Deliberately simple (chars÷4) — good enough for a soft send-time budget and an
// "approaching the limit" indicator, not billing-accuracy. Avoids pulling in a real
// tokenizer just for this.
export function estimateTokens(text: string): number {
  return Math.ceil(text.length / 4)
}

// Fallbacks for when a model's real context_window/max_output_tokens aren't set —
// max_output_tokens in particular is null for every model today (never seeded).
export const DEFAULT_CONTEXT_WINDOW = 32000
export const DEFAULT_MAX_OUTPUT_TOKENS = 4096

export interface BudgetMessage {
  role: string
  content: string
}

/**
 * Walks messages from most recent backward, keeping as many as fit under
 * ceilingTokens - reserveTokens. Replaces a blind "last N messages" cap with one
 * driven by the model's actual context window. Always keeps at least the single
 * most recent qualifying message even if it alone exceeds the budget — sending
 * something truncated is better than sending nothing.
 */
export function buildBoundedHistory(
  messages: BudgetMessage[],
  ceilingTokens: number,
  reserveTokens: number
): { role: 'user' | 'assistant'; content: string }[] {
  const candidates = messages.filter(
    (m): m is BudgetMessage & { role: 'user' | 'assistant' } => m.role === 'user' || m.role === 'assistant'
  )
  const budget = Math.max(ceilingTokens - reserveTokens, 0)

  const selected: { role: 'user' | 'assistant'; content: string }[] = []
  let used = 0
  for (let i = candidates.length - 1; i >= 0; i--) {
    const tokens = estimateTokens(candidates[i].content)
    if (used + tokens > budget && selected.length > 0) break
    used += tokens
    selected.unshift({ role: candidates[i].role, content: candidates[i].content })
  }
  return selected
}
