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

export interface CompareAwareMessage extends BudgetMessage {
  metadata?: { compare_group_id?: string; is_chosen?: boolean; error?: string } | null
}

/**
 * A compare turn persists one message PER model, all sharing the same
 * metadata.compare_group_id — left as-is, every future call to the model would see
 * every model's answer to the same prompt stacked as separate assistant turns. This
 * collapses each group down to exactly one: whichever the user explicitly picked via
 * "Choose the best" (metadata.is_chosen, set via PATCH .../messages/{id}/choose), or
 * the earliest NON-errored message in the group if nothing's been chosen yet —
 * messages arrive already ordered by created_at ascending, and the earliest one in a
 * compare group is normally the primary/first-selected model's response
 * (ChatController::compare()'s foreach persists in model_ids order, and activeModelId
 * is always first — see chat/page.tsx's compareModelIds). "Normally," not always: a
 * model that failed (a provider 503, etc.) also persists now, as a placeholder with
 * metadata.error set (see the same controller's catch block) — that placeholder is
 * never a real answer, so it's skipped in favor of the next real one, and if every
 * model in the group failed, the group contributes nothing at all rather than
 * feeding a blank placeholder to a future turn as if it were content. Re-picking
 * later only affects turns built AFTER the pick; nothing already sent gets rewritten.
 */
export function collapseCompareGroups<T extends CompareAwareMessage>(messages: T[]): T[] {
  const chosenByGroup = new Map<string, T>()
  const firstOkByGroup = new Map<string, T>()
  for (const m of messages) {
    const groupId = m.metadata?.compare_group_id
    if (!groupId) continue
    if (m.metadata?.is_chosen) chosenByGroup.set(groupId, m)
    if (!m.metadata?.error && !firstOkByGroup.has(groupId)) firstOkByGroup.set(groupId, m)
  }

  const seenGroups = new Set<string>()
  const result: T[] = []
  for (const m of messages) {
    const groupId = m.metadata?.compare_group_id
    if (!groupId) {
      result.push(m)
      continue
    }
    if (seenGroups.has(groupId)) continue
    seenGroups.add(groupId)
    const representative = chosenByGroup.get(groupId) ?? firstOkByGroup.get(groupId)
    if (representative) result.push(representative)
  }
  return result
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
