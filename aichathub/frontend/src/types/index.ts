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
// Shapes below match services/subscription-service/app/Http/Controllers/V1/*
// exactly (PackageController + SubscriptionController::formatSubscription()).

export interface PackageFeatures {
  file_upload: boolean
  api_access: boolean
  comparison: boolean
  image_gen: boolean
  audio: boolean
  vision: boolean
}

export interface Package {
  id: string
  name: string
  slug: 'basic' | 'standard' | 'pro'
  description: string
  price: { usd: number; bdt: number | null }
  wallet_credit_usd: number
  features: PackageFeatures
  model_access: string[]
}

/** Package shape as embedded inside a Subscription — a narrower projection than the standalone Package. */
export interface SubscriptionPackage {
  id: string
  name: string
  slug: 'basic' | 'standard' | 'pro'
  monthly_price_usd: number
  model_access: string[]
  features: PackageFeatures
}

export interface Subscription {
  id: string
  status: 'active' | 'past_due' | 'cancelled' | 'expired'
  auto_renew: boolean
  currency: string
  activated_at: string
  renews_at: string
  cancelled_at: string | null
  package: SubscriptionPackage | null
}

// ─── Wallet ────────────────────────────────────────────────────────────────
// GET /wallet and GET /wallet/credit are two separate endpoints — combine
// client-side if a page needs both balance and credit-buffer info at once.

export interface WalletBalance {
  balance: number
  reserved_balance: number
  available_balance: number
  currency: string
}

export interface WalletCreditStatus {
  credit_balance: number
  credit_limit: number
  remaining_credit: number
  in_credit: boolean
}

export interface LedgerEntry {
  id: string
  wallet_id: string
  user_id: string
  type: 'credit' | 'debit' | 'refund' | 'credit_recovered' | 'credit_used'
  amount: string
  balance_before: string
  balance_after: string
  description: string
  reference_type: string | null
  reference_id: string | null
  currency: string
  exchange_rate: string
  created_at: string
}

// ─── Payment ───────────────────────────────────────────────────────────────

export interface Transaction {
  id: string
  user_id: string
  type: 'subscription_purchase' | 'wallet_topup' | 'refund'
  status: 'pending' | 'completed' | 'failed' | 'refunded'
  amount: string
  currency: string
  gateway: string
  gateway_reference: string | null
  description: string | null
  error_message: string | null
  completed_at: string | null
  failed_at: string | null
  created_at: string
}

export interface PaymentMethod {
  id: string
  gateway: string
  type: string
  last_four: string | null
  card_brand: string | null
  expires_at: string | null
  is_default: boolean
  is_active: boolean
  created_at: string
}

// ─── Billing ───────────────────────────────────────────────────────────────

export interface Invoice {
  id: string
  invoice_number: string
  type: string
  amount: string
  currency: string
  total_amount: string
  status: string
  issued_at: string
}

export interface Receipt {
  id: string
  receipt_number: string
  type: string
  amount: string
  currency: string
  issued_at: string
}

// ─── AI Models ─────────────────────────────────────────────────────────────

export interface AiModel {
  id: string
  provider: 'openai' | 'anthropic' | 'gemini' | 'xai' | 'elevenlabs'
  name: string
  model_id: string
  type: 'text' | 'image_generation' | 'audio_tts' | 'audio_stt' | 'embedding'
  description: string | null
  context_window: number | null
  max_output_tokens: number | null
  capabilities: {
    streaming: boolean
    function_calling: boolean
    vision: boolean
    file_upload: boolean
  }
  available: boolean  // Based on the caller's current subscription package
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
  model_id: string | null
  content: string
  prompt_tokens: number
  completion_tokens: number
  total_tokens: number
  cost: number
  created_at: string
}

export interface FileAttachment {
  id: string
  session_id: string | null
  original_name: string
  mime_type: string
  file_size: number
  storage_url: string
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
