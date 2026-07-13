// ─── Auth ──────────────────────────────────────────────────────────────────

export interface User {
  id: string
  name: string
  email: string
  avatar_url: string | null
  preferred_currency: 'USD' | 'BDT'
  email_verified_at: string | null
  has_password: boolean
  google_connected: boolean
}

export interface AuthTokens {
  access_token: string
  refresh_token: string
  token_type: 'bearer'
  expires_in: number
}

// ─── Subscription ──────────────────────────────────────────────────────────

export interface Package {
  id: string
  name: string
  slug: 'basic' | 'standard' | 'pro'
  description: string
  monthly_price_usd: number
  monthly_price_bdt: number
  monthly_wallet_credit_usd: number
  model_access: string[]
  features: {
    file_upload: boolean
    api_access: boolean
    comparison: boolean
    image_gen: boolean
    audio: boolean
    vision: boolean
  }
  is_active: boolean
  sort_order: number
}

export interface Subscription {
  subscription_id: string
  status: 'active' | 'past_due' | 'cancelled' | 'expired'
  renews_at: string
  auto_renew: boolean
  package: Package
}

// ─── Wallet ────────────────────────────────────────────────────────────────

export interface WalletBalance {
  balance: number
  reserved_balance: number
  credit_balance: number
  credit_limit: number
  available_balance: number
  remaining_credit: number
  currency: string
}

export interface LedgerEntry {
  id: string
  type: 'credit' | 'debit' | 'refund' | 'credit_recovery'
  amount: number
  balance_before: number
  balance_after: number
  description: string
  reference_type: string | null
  reference_id: string | null
  currency: string
  created_at: string
}

// ─── AI Models ─────────────────────────────────────────────────────────────

export interface AiModel {
  id: string
  provider: 'openai' | 'anthropic' | 'gemini' | 'xai' | 'elevenlabs'
  name: string
  model_id: string
  type: 'text' | 'image_generation' | 'audio_tts' | 'audio_stt' | 'embedding'
  context_window: number | null
  capabilities: {
    streaming: boolean
    function_calling: boolean
    vision: boolean
    file_upload: boolean
  }
  is_accessible: boolean  // Based on user's subscription
}

// ─── Chat ──────────────────────────────────────────────────────────────────

export interface ChatSession {
  id: string
  model_id: string
  title: string
  status: 'active' | 'archived'
  message_count: number
  total_cost: number
  created_at: string
  updated_at: string
}

export interface ChatMessage {
  id: string
  session_id: string
  role: 'user' | 'assistant' | 'system'
  content: string
  prompt_tokens: number
  completion_tokens: number
  total_tokens: number
  cost: number
  created_at: string
}

// ─── API Responses ─────────────────────────────────────────────────────────

export interface ApiError {
  message: string
  errors?: Record<string, string[]>
  reason?: string
}

export interface PaginatedResponse<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}
