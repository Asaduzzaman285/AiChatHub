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
  is_admin: boolean
  admin_role: string | null
  admin_permissions: string[]
  welcome_seen_at: string | null
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
  // Set only when a downgrade is scheduled — takes effect at renews_at.
  scheduled_package: { id: string; name: string; slug: string } | null
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

export interface AutoDebitSettings {
  enabled: boolean
  threshold_usd: number
  topup_amount_usd: number
  payment_method_id: string | null
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
  type: 'credit' | 'debit' | 'refund' | 'credit_recovered' | 'credit_used' | 'admin_adjustment'
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

// ─── Usage ─────────────────────────────────────────────────────────────────
// Aggregates come back from Postgres SUM()/decimal columns as strings via the
// pgsql PDO driver, same reason LedgerEntry's amount/balance fields are strings.

export interface UsageSummaryModel {
  model_id: string
  name: string
  provider: string
  prompt_tokens: string
  completion_tokens: string
  total_tokens: string
  cost: string
}

export interface UsageSummary {
  period: '7d' | '30d' | 'all'
  models: UsageSummaryModel[]
  totals: { total_tokens: string; cost: string }
}

export interface UsageLogEntry {
  id: string
  model: { id: string; provider: string; name: string; model_id: string } | null
  operation_type: string
  status: string
  total_tokens: number
  actual_cost: string
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
  provider: 'openai' | 'anthropic' | 'gemini' | 'xai' | 'elevenlabs' | 'deepseek' | 'perplexity' | 'qwen' | 'moonshot'
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
    // Both optional — most models have neither. Composer toggles (Deep Think, Web
    // Search) only render when the active model's capabilities say true; see
    // ChatController::stream()'s own re-derivation of these server-side, which is the
    // real enforcement boundary, not this flag alone.
    web_search?: boolean
    reasoning?: boolean
    // Admin-controlled grouping for the Models popup ("Flagship Models" vs "More
    // Models") — a real, settable field (admin/ai-models form), not a hardcoded
    // frontend list. Everything renders under "More Models" until an admin sets this.
    flagship?: boolean
  }
  available: boolean  // Based on the caller's current subscription package
  pricing: {
    input_rate_per_million: string
    output_rate_per_million: string
    currency: string
  } | null
}

// ─── Chat ──────────────────────────────────────────────────────────────────

export interface ChatSession {
  id: string
  model_id: string
  // Free to move between projects (or out, via null) after creation — unlike
  // is_private below, which is locked at creation and enforced server-side.
  project_id: string | null
  title: string
  status: 'active' | 'archived'
  message_count: number
  total_cost: number
  // Set once at creation, never changed afterward — enforced server-side, not just
  // an unused field on the client. expires_at is null for non-private chats.
  is_private: boolean
  expires_at: string | null
  created_at: string
  updated_at: string
}

export interface Project {
  id: string
  name: string
  color: string | null
  sessions_count: number
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
  // Laravel's `decimal:6` cast serializes to a JSON string ("0.016709"), not a
  // number — confirmed live (a real crash: calling .toFixed() on it throws, since
  // strings don't have that method). Always run this through Number(...) before
  // any arithmetic or formatting.
  cost: string
  created_at: string
  metadata: { compare_group_id?: string; type?: 'compaction_summary' } | null
  // Populated server-side once a message with attachment_ids is persisted — previously
  // always empty/absent since nothing wrote file_attachments.message_id at all, so an
  // uploaded image reached the model fine but never showed up again in the history.
  attachments?: FileAttachment[]
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

// ─── Admin ─────────────────────────────────────────────────────────────────
// Shapes match the real admin endpoints exactly (verified live) — note these
// do NOT use PaginatedResponse<T> below; every admin list endpoint returns
// { <resource_key>: T[], meta: AdminMeta }, no `per_page` in meta.

export interface AdminMeta {
  current_page: number
  last_page: number
  total: number
}

export interface AdminUserSummary {
  id: string
  name: string
  email: string
  phone: string | null
  status: string
  email_verified_at: string | null
  last_login_at: string | null
  created_at: string
}

export interface AdminUser {
  id: string
  user_id: string
  role: string
  permissions: string[]
  is_active: boolean
  created_at: string
  user: { id: string; name: string; email: string }
}

/**
 * Raw Eloquent shape returned by the admin package endpoints (store/update/
 * adminIndex) — deliberately NOT the same shape as the public Package type
 * above (index()/show() hand-map to `price: {usd,bdt}`/`wallet_credit_usd`;
 * the admin endpoints return the model's real column names untouched).
 */
export interface AdminPackage {
  id: string
  name: string
  slug: string
  description: string | null
  monthly_price_usd: string
  monthly_price_bdt: string | null
  monthly_wallet_credit_usd: string
  credit_buffer_percentage: string
  model_access: string[]
  features: PackageFeatures
  is_active: boolean
  sort_order: number
  created_at: string
}

export interface Role {
  id: string
  name: string
  permissions: string[]
  admin_count: number
  created_at: string
}

export interface AdminSubscription extends Subscription {
  user_id: string
}

export interface AuditLogEntry {
  id: string
  admin_user_id: string | null
  actor_type: string
  action: string
  resource_type: string
  resource_id: string | null
  old_values: Record<string, unknown> | null
  new_values: Record<string, unknown> | null
  ip_address: string | null
  user_agent: string | null
  created_at: string
  admin_user: (AdminUser & { user: { id: string; name: string; email: string } }) | null
}

export interface AdminUsageLog {
  id: string
  user_id: string
  session_id: string | null
  model_id: string
  operation_type: string
  status: string
  prompt_tokens: number
  completion_tokens: number
  total_tokens: number
  estimated_cost: string
  actual_cost: string
  currency: string
  duration_ms: number | null
  created_at: string
  model: { id: string; provider: string; name: string; model_id: string } | null
}

export interface AdminModelPricing {
  pricing_type: 'token_based' | 'flat_per_image' | 'character_based' | 'per_minute'
  input_rate_per_million: string | null
  output_rate_per_million: string | null
  flat_rate_per_unit: string | null
  provider_input_rate_per_million: string | null
  provider_output_rate_per_million: string | null
  provider_flat_rate_per_unit: string | null
  markup_percentage: string | null
  currency: string
}

export interface AdminAiModel {
  id: string
  provider: string
  name: string
  model_id: string
  type: 'text' | 'image_generation' | 'audio_tts' | 'audio_stt' | 'embedding'
  description: string | null
  context_window: number | null
  max_output_tokens: number | null
  capabilities: Record<string, boolean>
  is_active: boolean
  created_at: string
  pricing: AdminModelPricing | null
}

export interface AuthAdminDashboard {
  total_users: number
  active_users: number
  suspended_users: number
  pending_verification: number
  new_registrations_7d: number
  new_registrations_30d: number
}

export interface SubscriptionAdminDashboard {
  active_subscriptions: number
  past_due_subscriptions: number
  scheduled_downgrades: number
  plan_breakdown: Record<string, number>
}

export interface PaymentAdminDashboard {
  total_revenue: number
  completed_count: number
  failed_count: number
  pending_count: number
  refunded_count: number
  gateway_breakdown: { gateway: string; count: number; total: string }[]
}

export interface WalletAdminDashboard {
  total_balance: number
  total_credit_owed: number
  deposits_30d: number
  withdrawals_30d: number
  admin_adjustments_30d: number
}

export interface AiAdminDashboard {
  total_tokens_7d: number
  total_cost_7d: number
  failed_requests_7d: number
  provider_breakdown: Record<string, { requests: number; tokens: number; cost: number }>
  provider_health: { model: string | null; provider: string | null; state: string }[]
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
