# AI ChatHub — Software Requirements Specification (SRS)

**Version 2.0 — Microservices Architecture with Laravel AI SDK**  
**Status:** Ready for Implementation  
**Last Updated:** July 7, 2026

---

## 1. Introduction

### 1.1 Purpose

This document specifies the complete functional and non-functional requirements for AI ChatHub, a microservices-based SaaS platform providing unified access to multiple AI providers through a subscription + prepaid wallet model with credit buffer support.

### 1.2 Scope

**In Scope:**
- Microservices architecture from day one (Auth, Subscription, Wallet, Payment, AI Gateway, Notification services)
- Laravel 12 per service with shared event bus communication
- Laravel AI SDK integration for provider abstraction
- Subscription-based model access with 30-day renewal cycles
- Prepaid wallet consumption with configurable credit buffer
- Multi-currency support (USD, BDT)
- Multi-gateway payment integration (Stripe, PayPal, bKash, Nagad, SSLCommerz)
- Real-time chat streaming via WebSockets
- File upload and multi-modal AI operations
- Admin panel for platform management
- Comprehensive audit logging

**Out of Scope (Phase 1):**
- Organization/team accounts (Phase 3)
- API keys for developers (Phase 3)
- Native mobile apps (Phase 3)
- Self-hosted/on-premise deployment

### 1.3 Intended Audience

- Backend developers (Laravel microservices)
- Frontend developers (Next.js)
- DevOps engineers (Docker, Kubernetes, service orchestration)
- QA/Test engineers
- Project managers and business analysts

### 1.4 Definitions & Acronyms

| Term | Definition |
|------|------------|
| **Microservice** | Independently deployable service owning specific business domain |
| **Service Boundary** | Data and logic exclusively owned by one service |
| **Event Bus** | Asynchronous messaging system for inter-service communication (Redis Pub/Sub or RabbitMQ) |
| **API Gateway** | Single entry point routing external requests to internal services |
| **Saga Pattern** | Distributed transaction coordination across services |
| **Circuit Breaker** | Fault tolerance pattern preventing cascade failures |
| **CQRS** | Command Query Responsibility Segregation for read/write optimization |

---

## 2. System Architecture Overview

### 2.1 Microservices Domain Model

```
┌─────────────────────────────────────────────────────────────────┐
│                         API Gateway (Laravel)                    │
│              (Authentication, Rate Limiting, Routing)            │
└────────┬────────────────────────────────────────────────────────┘
         │
         ├─────────────┬──────────────┬──────────────┬─────────────┐
         │             │              │              │             │
    ┌────▼────┐  ┌────▼────┐  ┌─────▼─────┐  ┌─────▼──────┐  ┌──▼──────┐
    │  Auth   │  │Subscrip │  │  Wallet   │  │  Payment   │  │   AI    │
    │ Service │  │  tion   │  │  Service  │  │  Gateway   │  │ Gateway │
    │         │  │ Service │  │           │  │  Service   │  │ Service │
    └────┬────┘  └────┬────┘  └─────┬─────┘  └─────┬──────┘  └──┬──────┘
         │            │              │              │             │
         │       ┌────▼────┐    ┌───▼──────┐  ┌────▼──────┐     │
         │       │  Chat   │    │  Billing │  │Notification│     │
         │       │ Service │    │  Service │  │  Service   │     │
         │       └─────────┘    └──────────┘  └────────────┘     │
         │                                                        │
         └────────────────────────────────────────────────────────┘
                                    │
                              ┌─────▼─────┐
                              │ Event Bus │
                              │  (Redis/  │
                              │ RabbitMQ) │
                              └───────────┘
```

### 2.2 Service Responsibilities

| Service | Responsibilities | Database Ownership |
|---------|------------------|-------------------|
| **Auth Service** | User registration, login, password reset, JWT token issuance, email verification | `users`, `password_resets`, `email_verifications` |
| **Subscription Service** | Package management, subscription lifecycle (purchase/upgrade/downgrade/cancel), renewal scheduling, tier resolution | `packages`, `user_subscriptions`, `subscription_history` |
| **Wallet Service** | Balance management, ledger writes, credit buffer tracking, lock/unlock operations, refunds | `wallets`, `wallet_ledger_entries`, `credit_ledger` |
| **Payment Gateway Service** | Gateway integration (Stripe/PayPal/bKash/etc.), transaction processing, webhook handling, idempotency | `transactions`, `payment_methods`, `webhook_events` |
| **Billing Service** | Top-up orchestration, renewal orchestration, invoice generation, receipt generation | `invoices`, `receipts` |
| **AI Gateway Service** | Provider routing, request/response transformation, streaming, failover, cost calculation, usage logging | `ai_models`, `model_pricing`, `usage_logs`, `provider_fallback_rules` |
| **Chat Service** | Session management, message persistence, conversation history, file attachments | `chat_sessions`, `chat_messages`, `file_attachments` |
| **Notification Service** | Email/SMS/push notifications, notification preferences, delivery tracking | `notifications`, `notification_preferences` |

### 2.3 Inter-Service Communication

**Synchronous (REST API):**
- Auth Service → Subscription Service: Check user's active package
- API Gateway → All Services: Request routing with JWT validation
- AI Gateway → Wallet Service: Reserve/deduct balance
- Subscription Service → Wallet Service: Credit wallet on purchase/renewal

**Asynchronous (Event Bus):**
- `user.registered` → Subscription Service (create default trial), Notification Service (welcome email)
- `subscription.purchased` → Wallet Service (credit wallet), Billing Service (generate invoice)
- `subscription.renewed` → Wallet Service (credit wallet), Notification Service (renewal receipt)
- `payment.succeeded` → Billing Service (complete transaction), Notification Service (payment receipt)
- `wallet.balance_low` → Notification Service (low balance alert)
- `ai_request.completed` → Wallet Service (deduct actual cost), Chat Service (store message)

---

## 3. Functional Requirements by Service

### 3.1 Auth Service

#### FR-AUTH-1: User Registration
**Actor:** Guest User  
**Description:** User creates account with email/password  
**Preconditions:** Email not already registered  
**Steps:**
1. User submits email, password, name
2. System validates email format, password strength (min 8 chars, 1 uppercase, 1 number)
3. System hashes password (bcrypt)
4. System generates UUID user ID
5. System creates user record with `status = 'pending_verification'`
6. System sends verification email with token (expires in 24h)
7. System publishes `user.registered` event

**Postconditions:** User record created, verification email sent  
**Business Rules:**
- Email must be unique
- Password must meet complexity requirements
- Email verification required before full access

#### FR-AUTH-2: Email Verification
**Actor:** Registered User  
**Description:** User verifies email address  
**Steps:**
1. User clicks verification link in email
2. System validates token (not expired, not used)
3. System updates user `status = 'active'`, `email_verified_at = now()`
4. System marks token as used
5. System publishes `user.email_verified` event

#### FR-AUTH-3: Login
**Actor:** Registered User  
**Description:** User authenticates and receives JWT token  
**Steps:**
1. User submits email + password
2. System validates credentials
3. System checks user status (must be 'active')
4. System generates JWT token (expires in 24h)
5. System generates refresh token (expires in 30 days)
6. System returns both tokens + user profile

**Security:**
- Rate limit: 5 failed attempts per email per 15 minutes → temporary lock
- Log all login attempts with IP address

#### FR-AUTH-4: Password Reset
**Actor:** Registered User  
**Steps:**
1. User requests reset with email
2. System sends reset link with token (expires in 1h)
3. User submits new password with token
4. System validates token, updates password
5. System invalidates all existing JWT tokens for that user

#### FR-AUTH-5: Token Refresh
**Actor:** Authenticated User  
**Steps:**
1. Client sends refresh token
2. System validates refresh token (not expired, not revoked)
3. System issues new JWT token
4. System optionally rotates refresh token

---

### 3.2 Subscription Service

#### FR-SUB-1: Package Definition (Admin)
**Actor:** Admin  
**Description:** Admin creates/updates subscription packages  
**Data Fields:**
- `name`: Basic/Standard/Pro
- `monthly_price_usd`: Dollar amount
- `monthly_price_bdt`: Taka amount
- `monthly_wallet_credit_usd`: Amount credited to wallet on purchase/renewal
- `model_access`: JSON array of allowed model IDs
- `features`: JSON object of feature flags (file_upload, api_access, etc.)
- `is_active`: Boolean (controls visibility to users)

#### FR-SUB-2: Subscribe to Package
**Actor:** User  
**Description:** User purchases a subscription package  
**Preconditions:** User has no active subscription OR user is upgrading/downgrading  
**Flow (Purchase - New Subscription):**
1. User selects package (Basic/Standard/Pro)
2. User selects payment method and currency (USD or BDT)
3. Subscription Service calls Payment Gateway Service to charge
4. **If payment succeeds:**
   - Create `user_subscriptions` record:
     - `package_id`, `user_id`, `status = 'active'`
     - `activated_at = now()`, `renews_at = now() + 30 days`
     - `auto_renew = true`, `currency`, `exchange_rate` (at time of purchase)
   - Save payment method token for renewals
   - Publish `subscription.purchased` event
   - **Wallet Service (listener):** Credits wallet with package amount
   - **Billing Service (listener):** Generates invoice
5. **If payment fails:**
   - Return error to user
   - Log failed transaction

**Postconditions:** Active subscription, wallet credited, invoice generated

#### FR-SUB-3: Upgrade Package
**Actor:** User  
**Description:** User upgrades to higher-tier package  
**Preconditions:** User has active subscription  
**Flow:**
1. User selects higher-tier package (Standard → Pro)
2. System charges **full new package price** (NOT prorated)
3. Payment Gateway Service processes payment
4. **If payment succeeds:**
   - Update `user_subscriptions`:
     - `package_id = new_package`, `previous_package_id = old_package`
     - `renews_at = now() + 30 days` (reset renewal cycle)
   - Create `subscription_history` record for audit
   - Publish `subscription.upgraded` event
   - **Wallet Service:** Settles credit if negative, then credits new package amount
   - Access to new models is **immediate**
5. **If payment fails:**
   - Subscription remains unchanged
   - Return error to user

**Business Rule:** Full price, not difference. User gets full wallet credit + access upgrade.

#### FR-SUB-4: Downgrade Package
**Actor:** User  
**Description:** User downgrades to lower-tier package  
**Flow:**
1. User selects lower-tier package (Pro → Basic)
2. System charges **full new package price**
3. **If payment succeeds:**
   - Update `user_subscriptions`:
     - `package_id = new_package`, `previous_package_id = old_package`
     - `renews_at = now() + 30 days` (reset renewal cycle)
   - Create `subscription_history` record
   - Publish `subscription.downgraded` event
   - **Wallet Service:** Settles credit, credits new package amount (existing wallet preserved)
   - **Model access restriction is immediate** (or scheduled per business policy)

**Alternative Flow (Scheduled Downgrade):**
- Set `user_subscriptions.scheduled_package_id = new_package`
- Downgrade takes effect on next renewal date
- Current access continues until then

#### FR-SUB-5: Monthly Auto-Renewal
**Actor:** System (Scheduled Job)  
**Description:** Automatically renews subscription every 30 days  
**Trigger:** Cron job runs hourly, processes all subscriptions where `renews_at <= now()`  
**Flow:**
1. System identifies subscriptions due for renewal
2. For each subscription:
   - Retrieve saved payment method
   - Call Payment Gateway Service to charge package price
3. **If payment succeeds:**
   - Update `renews_at += 30 days`
   - Publish `subscription.renewed` event
   - **Wallet Service:** Settles credit, credits package amount
   - **Billing Service:** Generates renewal receipt
4. **If payment fails:**
   - Create `renewal_attempts` record (attempt 1)
   - Publish `subscription.renewal_failed` event
   - Schedule retry in 24 hours

#### FR-SUB-6: Renewal Retry Logic
**Actor:** System  
**Flow:**
1. After first failure, retry after 24 hours (attempt 2)
2. After second failure, retry after 48 hours total (attempt 3)
3. After third failure (72 hours total):
   - Update `user_subscriptions.status = 'past_due'`
   - Publish `subscription.past_due` event
   - **Notification Service:** Sends urgent payment failure email
   - **Business Policy Decision:**
     - Option A: Block AI access immediately
     - Option B: Allow grace period until `renews_at + 7 days`

**Postconditions:** After 3 failed attempts, subscription enters "past_due" state

#### FR-SUB-7: Cancel Subscription
**Actor:** User  
**Options:**
1. **Cancel at End of Cycle (Recommended):**
   - Set `user_subscriptions.auto_renew = false`
   - Access continues until `renews_at`
   - No further renewals
   - Wallet balance preserved
2. **Cancel Immediately:**
   - Set `status = 'cancelled'`, `cancelled_at = now()`
   - Access revoked immediately
   - Wallet balance preserved (no refund of current cycle pro-rata)

#### FR-SUB-8: Get Current Package Tier (API)
**Actor:** Other Services  
**Description:** API endpoint for other services to check user's current package  
**Input:** `user_id`  
**Output:** Package object (name, model_access, features) OR null if no active subscription  
**Used By:**
- AI Gateway Service (validate model access before request)
- Chat Service (determine feature availability like file upload)

---

### 3.3 Wallet Service

#### FR-WALLET-1: Create Wallet
**Actor:** System  
**Description:** Automatically create wallet on user registration  
**Trigger:** Listen to `user.registered` event  
**Flow:**
1. Receive `user.registered` event
2. Create `wallets` record:
   - `user_id`, `balance = 0.00`, `reserved_balance = 0.00`
   - `currency = user.preferred_currency` (defaults to USD)
   - `credit_balance = 0.00`

#### FR-WALLET-2: Credit Wallet (Top-up)
**Actor:** Payment Gateway Service (via event) OR Subscription Service  
**Description:** Add funds to wallet balance  
**Trigger:** `payment.succeeded` event OR `subscription.purchased` event  
**Flow:**
1. Receive event with amount and transaction ID
2. Lock wallet row (`SELECT ... FOR UPDATE`)
3. **If `credit_balance < 0` (user owes credit):**
   - Calculate credit settlement: `settlement = min(amount, abs(credit_balance))`
   - `credit_balance += settlement`
   - `amount -= settlement`
   - Create `credit_ledger` entry: "Credit Recovered"
4. `balance += amount` (remaining after credit settlement)
5. Create `wallet_ledger_entries` record:
   - `user_id`, `type = 'credit'`, `amount`, `balance_after`
   - `description = 'Top-up via Stripe'` OR `'Standard Package Purchase'`
   - `reference_type = 'transaction'`, `reference_id = transaction.id`
   - `created_at = now()`
6. Unlock wallet row, commit transaction
7. Publish `wallet.balance_updated` event (for real-time UI update)

**Critical:** All wallet operations MUST use database transactions with row-level locking.

#### FR-WALLET-3: Reserve Balance (Pre-flight)
**Actor:** AI Gateway Service  
**Description:** Lock funds before sending AI request to prevent double-spend  
**Input:** `user_id`, `estimated_cost`  
**Flow:**
1. Lock wallet row
2. Calculate available funds: `available = balance + (credit_limit - abs(credit_balance))`
3. **If `available >= estimated_cost`:**
   - `reserved_balance += estimated_cost`
   - Unlock, return success
4. **Else:**
   - Unlock, return error "Insufficient funds"

**Note:** `reserved_balance` prevents race condition where multiple concurrent requests overspend.

#### FR-WALLET-4: Deduct Balance (Post-completion)
**Actor:** AI Gateway Service  
**Description:** Deduct actual cost after AI request completes  
**Input:** `user_id`, `actual_cost`, `reserved_amount`, `usage_log_id`  
**Flow:**
1. Lock wallet row
2. `reserved_balance -= reserved_amount` (release reservation)
3. **If `balance >= actual_cost`:**
   - `balance -= actual_cost`
4. **Else (balance insufficient, use credit):**
   - `shortage = actual_cost - balance`
   - `balance = 0.00`
   - `credit_balance -= shortage` (goes negative)
   - Check: `abs(credit_balance) <= credit_limit`
   - If exceeds limit, this should never happen (pre-flight check failed)
5. Create `wallet_ledger_entries`:
   - `type = 'debit'`, `amount = actual_cost`, `balance_after`
   - `description = 'GPT-4o Request'`
   - `reference_type = 'usage_log'`, `reference_id = usage_log_id`
6. **If balance below threshold ($5):**
   - Publish `wallet.balance_low` event
7. Unlock, commit

#### FR-WALLET-5: Refund Balance
**Actor:** AI Gateway Service  
**Description:** Refund cost if AI request fails  
**Input:** `user_id`, `refund_amount`, `reserved_amount`, `usage_log_id`  
**Flow:**
1. Lock wallet row
2. `reserved_balance -= reserved_amount`
3. Settle credit first if negative, then credit wallet
4. Create `wallet_ledger_entries`:
   - `type = 'credit'`, `amount = refund_amount`
   - `description = 'Refund: Request Failed'`
5. Update `usage_logs.status = 'refunded'`
6. Unlock, commit

#### FR-WALLET-6: Get Wallet Balance (API)
**Actor:** Frontend, Other Services  
**Input:** `user_id`  
**Output:**
```json
{
  "balance": 25.50,
  "reserved_balance": 0.50,
  "credit_balance": -1.20,
  "available_balance": 26.80,
  "credit_limit": 3.00,
  "currency": "USD"
}
```
**Calculation:**
- `available_balance = balance + (credit_limit - abs(credit_balance))`

#### FR-WALLET-7: Wallet Ledger History
**Actor:** User, Admin  
**Description:** View complete wallet transaction history  
**Filters:** Date range, transaction type (credit/debit), currency  
**Output:** Paginated list with:
- Date/time
- Type (credit/debit)
- Amount
- Description
- Balance after transaction
- Reference (transaction ID, usage log ID)

---

### 3.4 Payment Gateway Service

#### FR-PAY-1: Save Payment Method
**Actor:** User  
**Description:** User saves payment method for future renewals  
**Flow:**
1. User enters card/mobile banking details
2. System calls gateway SDK (Stripe for cards, bKash for mobile)
3. Gateway returns opaque token (never store raw card numbers — PCI DSS)
4. Save `payment_methods` record:
   - `user_id`, `gateway = 'stripe'`, `type = 'card'`
   - `token = 'pm_xxxxx'` (gateway token)
   - `last_four`, `card_brand`, `expires_at`
   - `is_default = true`

#### FR-PAY-2: Process Subscription Payment
**Actor:** Subscription Service (internal call)  
**Flow:**
1. Receive: `user_id`, `amount`, `currency`, `payment_method_token`, `idempotency_key`
2. Check `transactions` table for existing transaction with same `idempotency_key`
   - If found: Return existing transaction (prevent double charge)
3. Create pending transaction record
4. Call gateway API with idempotency key
5. **On success:** Update transaction `status = 'completed'`, publish `payment.succeeded`
6. **On failure:** Update `status = 'failed'`, publish `payment.failed`
7. Return transaction object

**Idempotency Key Format:** `{user_id}-{subscription_id}-{attempt_number}-{renewal_date}`

#### FR-PAY-3: Process Top-up Payment
**Actor:** User  
**Flow:**
1. User selects amount and gateway
2. System creates pending transaction with `idempotency_key = UUID`
3. For redirect-based gateways (SSLCommerz, bKash): Return redirect URL
4. For API-based gateways (Stripe): Process directly
5. On webhook confirmation: Complete transaction, publish `payment.succeeded`

#### FR-PAY-4: Webhook Handling
**Actor:** Payment Gateways (Stripe, bKash, etc.)  
**Critical:** Webhooks can deliver multiple times — idempotency is mandatory  
**Flow:**
1. Receive webhook from gateway
2. Verify webhook signature (gateway-specific HMAC check)
3. Extract `gateway_reference` (gateway's transaction ID)
4. Check `webhook_events` table:
   - If `gateway_reference` already processed: Return 200 OK, skip processing
5. Insert into `webhook_events` (marks as processing)
6. Dispatch `ProcessWebhookJob` to queue
7. Job processes event, publishes internal event
8. Mark `webhook_events.status = 'processed'`

#### FR-PAY-5: Refund
**Actor:** Admin  
**Description:** Initiate refund for a transaction  
**Flow:**
1. Admin selects transaction to refund
2. System calls gateway refund API
3. On gateway confirmation: Publish `payment.refunded`
4. **Billing Service/Wallet Service:** Create reversal ledger entries

#### FR-PAY-6: Supported Gateways

| Gateway | Auth Method | Webhook Validation | Currencies |
|---------|-------------|-------------------|------------|
| **Stripe** | API Key (HTTPS) | Stripe-Signature header HMAC | USD, BDT |
| **PayPal** | OAuth2 Bearer | PayPal-Transmission-Sig | USD |
| **bKash** | App Key + Token | Signature validation | BDT |
| **Nagad** | Merchant ID + Key | Signature validation | BDT |
| **SSLCommerz** | Store ID + Password | Hash validation | BDT |

---

### 3.5 Billing Service

#### FR-BILL-1: Generate Invoice
**Actor:** System  
**Trigger:** `subscription.purchased` event or `subscription.renewed` event  
**Flow:**
1. Receive event with subscription and transaction details
2. Generate invoice PDF/HTML record:
   - Invoice number (sequential per user)
   - User details (name, email, billing address)
   - Line items: Package name, monthly price, VAT (if applicable)
   - Payment method used
   - Date/time
3. Store invoice record and PDF
4. Publish `invoice.generated` event
5. **Notification Service:** Email invoice to user

#### FR-BILL-2: Generate Top-up Receipt
**Actor:** System  
**Trigger:** `payment.succeeded` event for top-up transactions  
**Content:** Amount, gateway, currency, exchange rate, timestamp

#### FR-BILL-3: Usage Summary (On Demand)
**Actor:** User  
**Description:** View detailed AI usage per session/period  
**Input:** Date range  
**Output:**
- Total requests
- Total tokens (input + output)
- Total cost
- Breakdown by model
- Breakdown by day

---

### 3.6 AI Gateway Service

#### FR-AI-1: Chat Request
**Actor:** User (via Chat Service)  
**Description:** Send chat message to AI provider  
**Preconditions:**
- Valid JWT token
- Active subscription with access to requested model
- Sufficient wallet balance (wallet + credit allowance)

**Full Request Flow:**
1. **Auth check:** Validate JWT, extract `user_id`
2. **Subscription check:** Call Subscription Service, verify model is in package's `model_access` list
3. **Cost estimation:** Estimate input tokens, calculate estimated cost:
   ```
   estimated_cost = (input_tokens / 1000) * input_rate
                  + (estimated_output_tokens / 1000) * output_rate
   ```
4. **Balance pre-flight:** Call Wallet Service `reserveBalance(user_id, estimated_cost)`
   - If rejected: Return `402 Insufficient Balance`
5. **Send to AI Provider:**
   - Use Laravel AI SDK: `(new ChatAgent($model))->stream($prompt)`
   - Stream response chunks back to client via SSE
6. **On completion:**
   - Calculate actual cost from actual tokens used
   - Call Wallet Service `deductBalance(user_id, actual_cost, reserved=estimated_cost)`
   - Store in `usage_logs`
   - If actual < reserved: Release difference
7. **On failure:**
   - Call Wallet Service `refundBalance(user_id, estimated_cost)`
   - Try fallback provider if configured
   - Return error or fallback response

#### FR-AI-2: Provider Configuration (Laravel AI SDK)
**Description:** How the AI Gateway Service integrates with Laravel AI SDK

**Installation:**
```bash
composer require laravel/ai
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"
```

**Environment Configuration (`config/ai.php`):**
```php
'providers' => [
    'openai'    => ['driver' => 'openai',    'key' => env('OPENAI_API_KEY')],
    'anthropic' => ['driver' => 'anthropic', 'key' => env('ANTHROPIC_API_KEY')],
    'gemini'    => ['driver' => 'gemini',    'key' => env('GEMINI_API_KEY')],
    'xai'       => ['driver' => 'xai',       'key' => env('XAI_API_KEY')],
    'elevenlabs'=> ['driver' => 'elevenlabs','key' => env('ELEVENLABS_API_KEY')],
]
```

**Agent Design Pattern (per model request type):**
```php
// app/Ai/Agents/ChatAgent.php
#[Provider(Lab::OpenAI)]
#[Model('gpt-4o')]
#[MaxTokens(4096)]
class ChatAgent implements Agent, Conversational, HasMiddleware
{
    use Promptable, RemembersConversations;

    public function __construct(
        private User $user,
        private string $model
    ) {}

    public function instructions(): string
    {
        return 'You are a helpful AI assistant.';
    }

    public function messages(): iterable
    {
        return $this->getConversationHistory();
    }

    public function middleware(): array
    {
        return [
            new TokenCountingMiddleware($this->user),
            new CostEstimationMiddleware(),
            new AuditLogMiddleware(),
        ];
    }
}
```

**Streaming in Route:**
```php
Route::post('/chat/stream', function (ChatRequest $request) {
    $agent = ChatAgent::make(user: $request->user(), model: $request->model);
    return $agent->stream($request->message)
        ->then(function (StreamedAgentResponse $response) use ($request) {
            // Actual deduction after streaming complete
            WalletService::deduct($request->user()->id, $response->usage->totalCost());
            UsageLog::create([...]);
        });
});
```

**Failover Configuration:**
```php
// Using built-in Laravel AI SDK failover
$response = (new ChatAgent)->prompt(
    $message,
    provider: [Lab::OpenAI, Lab::Anthropic] // Auto-failover
);
```

#### FR-AI-3: Supported Operations by Package

| Operation | Basic | Standard | Pro | SDK Method |
|-----------|-------|----------|-----|------------|
| Text Chat | ✅ 4 models | ✅ 10 models | ✅ All | `Agent::stream()` |
| Document Analysis | ❌ | ✅ | ✅ | `Agent::prompt(attachments: [Document])` |
| Image Generation | ❌ | ❌ | ✅ | `Image::of()->generate()` |
| TTS (Audio) | ❌ | ❌ | ✅ | `Audio::of()->generate()` |
| STT (Transcription) | ❌ | ❌ | ✅ | `Transcription::fromUpload()->generate()` |
| Vision (Image Analysis) | ❌ | ✅ | ✅ | `Agent::prompt(attachments: [Image])` |
| Embeddings | ❌ | ❌ | ✅ | `Embeddings::for()->generate()` |

#### FR-AI-4: Cost Calculation per Operation

**Text Chat:**
```
cost = (prompt_tokens / 1_000_000) * model.input_rate_per_million
     + (completion_tokens / 1_000_000) * model.output_rate_per_million
```

**Image Generation:**
```
cost = flat_rate_per_image (e.g., $0.040 for DALL-E 3 1024x1024)
```

**Audio TTS:**
```
cost = (character_count / 1_000) * tts_rate_per_k_chars
```

**Transcription (STT):**
```
cost = (audio_duration_seconds / 60) * stt_rate_per_minute
```

All rates stored in `model_pricing` table, manageable via admin panel.

#### FR-AI-5: Circuit Breaker
**Description:** Prevent cascade failures when provider is unreliable  
**Thresholds (configurable):**
- Open circuit: >20% failure rate in last 60 seconds
- Half-open probe: After 30 seconds
- Close circuit: 3 consecutive successful probes

**States:** `closed` (normal), `open` (all requests fail fast), `half-open` (testing)

#### FR-AI-6: Provider Failover
**Configuration stored in `provider_fallback_rules` table:**
```
model_id → fallback_model_id → conditions (timeout_ms, error_codes)
```
**Flow:**
1. Request fails (timeout > 8s or 5xx error)
2. Check fallback rules for current model
3. Notify user: "Your request is being served by [fallback model]"
4. Charge based on actual model used

#### FR-AI-7: Multi-Model Comparison (Standard/Pro)
**Description:** User sends same prompt to multiple models simultaneously  
**Flow:**
1. User selects 2-4 models for comparison
2. System validates all models are accessible in user's package
3. System estimates total cost (sum of all models)
4. Reserve total estimated cost
5. Fan-out requests to all selected providers concurrently
6. Stream all responses to UI side-by-side
7. Deduct actual total cost after all complete

---

### 3.7 Chat Service

#### FR-CHAT-1: Create Chat Session
**Actor:** User  
**Description:** Start new conversation with selected model  
**Flow:**
1. User selects model from available options
2. System verifies user's package allows access to selected model
3. Create `chat_sessions` record:
   - `user_id`, `model_id`, `title = 'New Chat'`
   - `status = 'active'`, `created_at = now()`
4. Return session ID

#### FR-CHAT-2: Send Message
**Actor:** User  
**Description:** User sends message in active session  
**Flow:**
1. User submits message text (+ optional file attachments)
2. System validates session belongs to user
3. Store user message in `chat_messages`:
   - `session_id`, `role = 'user'`, `content = message_text`
   - `tokens = null` (calculated later), `cost = null`
4. Call AI Gateway Service with message + session history
5. AI Gateway streams response
6. On stream completion, store assistant message in `chat_messages`:
   - `session_id`, `role = 'assistant'`, `content = full_response`
   - `prompt_tokens`, `completion_tokens`, `total_tokens`, `cost`
7. Link to `usage_logs` entry for audit
8. Update `chat_sessions.updated_at`

#### FR-CHAT-3: Attach Files to Message
**Actor:** User  
**Description:** User uploads files for document analysis or vision  
**Preconditions:** Package allows file upload (Standard/Pro)  
**Flow:**
1. User uploads file (PDF, DOCX, image)
2. System validates file type and size (max 20MB for Pro, 10MB for Standard)
3. Virus scan (ClamAV or cloud service)
4. Upload to S3-compatible storage
5. Create `file_attachments` record:
   - `user_id`, `session_id`, `message_id`
   - `file_name`, `file_size`, `mime_type`, `storage_path`, `storage_url`
   - `virus_scan_status = 'clean'`
6. Pass file URL to AI Gateway with message

#### FR-CHAT-4: View Session History
**Actor:** User  
**Input:** `session_id`  
**Output:** Paginated list of messages (user + assistant) with timestamps, tokens, cost

#### FR-CHAT-5: List User Sessions
**Actor:** User  
**Output:** All sessions for user, ordered by `updated_at DESC`  
**Fields:** Session ID, title, model, last message preview, total cost, created date

#### FR-CHAT-6: Delete Session
**Actor:** User  
**Flow:**
1. User requests session deletion
2. System soft-deletes `chat_sessions` record (`deleted_at = now()`)
3. Messages and attachments remain for audit purposes (not deleted)
4. Session no longer appears in user's list

#### FR-CHAT-7: Export Session
**Actor:** User  
**Description:** Export conversation to PDF/Markdown/JSON  
**Flow:**
1. User selects session and export format
2. System generates file with all messages
3. Return download link (expires in 1 hour)

---

### 3.8 Notification Service

#### FR-NOTIF-1: Welcome Email
**Trigger:** `user.registered` event  
**Content:**
- Welcome message
- Email verification link
- Getting started guide

#### FR-NOTIF-2: Email Verification
**Trigger:** `user.registered` event  
**Content:**
- Verification link with token
- Link expires in 24 hours

#### FR-NOTIF-3: Subscription Purchase Receipt
**Trigger:** `subscription.purchased` event  
**Content:**
- Package name and price
- Activation date and renewal date
- Invoice PDF attachment

#### FR-NOTIF-4: Renewal Receipt
**Trigger:** `subscription.renewed` event  
**Content:**
- Renewal confirmation
- Next renewal date
- Receipt PDF

#### FR-NOTIF-5: Renewal Failure Alert
**Trigger:** `subscription.renewal_failed` event  
**Content:**
- Payment failure reason
- Update payment method CTA
- Days until retry / suspension

#### FR-NOTIF-6: Low Balance Alert
**Trigger:** `wallet.balance_low` event  
**Threshold:** Balance < $5 (configurable)  
**Content:**
- Current balance
- Top-up link
- Send once per day max (de-duplication)

#### FR-NOTIF-7: Critical Balance Alert
**Trigger:** `wallet.balance_critical` event  
**Threshold:** Balance < $1 and credit < -$2 (configurable)  
**Content:**
- Urgent top-up required
- Service may be interrupted

#### FR-NOTIF-8: Payment Success Receipt
**Trigger:** `payment.succeeded` event (for top-ups)  
**Content:**
- Amount added
- New balance
- Transaction ID

#### FR-NOTIF-9: Notification Preferences
**Actor:** User  
**Description:** User configures notification channels and frequency  
**Preferences:**
- Email (on/off per notification type)
- SMS (on/off, requires phone verification)
- Push (on/off, requires device registration)
- Frequency: immediate, daily digest, weekly digest

#### FR-NOTIF-10: Delivery Tracking
**Description:** Track notification delivery status  
**States:** pending, sent, delivered, failed, bounced, opened, clicked  
**Retry Logic:** Failed emails retried 3 times with exponential backoff

---

### 3.9 Admin Panel Requirements

#### FR-ADMIN-1: User Management
**Actors:** Admin, Super Admin  
**Features:**
- View all users (paginated, searchable, filterable)
- View user details (profile, subscription, wallet, usage)
- Edit user profile
- Suspend/unsuspend user account
- Reset user password (admin-initiated)
- Impersonate user (audit logged)

#### FR-ADMIN-2: Package Management
**Features:**
- Create/edit/archive packages
- Set pricing (USD, BDT)
- Configure model access (JSON array of model IDs)
- Configure feature flags (file_upload, api_access, comparison, etc.)
- View package subscriber count and revenue

#### FR-ADMIN-3: Model & Pricing Management
**Features:**
- Add/edit AI models (name, provider, model_id)
- Set per-model pricing (input rate, output rate per 1M tokens)
- Enable/disable models
- View model usage statistics

#### FR-ADMIN-4: Transaction Management
**Features:**
- View all transactions (subscriptions, top-ups, refunds)
- Filter by: user, date range, gateway, status, currency
- Export to CSV
- Initiate refund (requires approval workflow)
- View transaction details (gateway response, attempts, etc.)

#### FR-ADMIN-5: Wallet Management
**Features:**
- View user wallet balance and credit
- Manual adjustment (credit/debit with reason — audit logged)
- View complete ledger history
- Reconcile wallet balance (compare ledger sum vs current balance)

#### FR-ADMIN-6: Usage Analytics
**Features:**
- Dashboard with key metrics:
  - Total users (active, suspended, past_due)
  - Total revenue (by period)
  - Gross margin (revenue - AI provider costs)
  - Active subscriptions (by package)
  - Top-up volume
  - AI requests per provider
- Export reports by date range

#### FR-ADMIN-7: Webhook Event Log
**Features:**
- View all incoming webhook events
- Filter by gateway, status (processed/failed)
- Retry failed webhook processing
- View raw webhook payload

#### FR-ADMIN-8: Audit Log
**Features:**
- View all admin actions (user edits, manual wallet adjustments, refunds, etc.)
- Log includes: admin user, action, target resource, timestamp, IP address, old/new values

#### FR-ADMIN-9: System Configuration
**Features:**
- Credit buffer limit (global default, per-package override)
- Low balance threshold
- Renewal retry schedule
- Maintenance mode toggle
- Feature flags (enable/disable features globally)

#### FR-ADMIN-10: Support Ticketing
**Features:**
- View user-submitted tickets
- Assign tickets to agents
- Respond to tickets (internal notes + public replies)
- Mark resolved
- Link tickets to user accounts for context

---

## 4. Non-Functional Requirements

### 4.1 Performance

| Metric | Target | Measurement |
|--------|--------|-------------|
| API Response Time | P95 < 200ms (excluding AI gateway) | Application monitoring |
| AI Request Latency | P95 < 3s (streaming start) | From reserve to first token |
| Wallet Lock Contention | < 50ms wait time | Database monitoring |
| Throughput | 1000 req/sec per service | Load testing |
| Database Queries | < 10 queries per request | Query logging |

### 4.2 Scalability

**Horizontal Scaling:**
- All services stateless (JWT auth, no sessions)
- Database connection pooling (PgBouncer)
- Read replicas for reporting queries
- Redis Cluster for event bus (Phase 2)
- Kubernetes auto-scaling based on CPU/memory

**Vertical Scaling:**
- AI Gateway Service: Most resource-intensive (streaming)
- Wallet Service: High lock contention (optimize first)
- Auth Service: Least resource-intensive

### 4.3 Availability

| Component | Target Uptime | Strategy |
|-----------|---------------|----------|
| **Platform Overall** | 99.5% | Multi-AZ deployment, health checks |
| **AI Providers** | 95% (external dependency) | Automatic failover, circuit breakers |
| **Payment Gateways** | 99% (external dependency) | Multi-gateway support, queue retries |
| **Database** | 99.9% | Managed PostgreSQL (AWS RDS/GCP Cloud SQL), automated backups |
| **Event Bus** | 99.9% | Redis Sentinel or managed service |

**Downtime Allowance (99.5%):** 3.65 hours/month

### 4.4 Security

**Authentication:**
- JWT with 24-hour expiration
- Refresh tokens with 30-day expiration, single-use rotation
- Password hashing: bcrypt (cost 12)
- Rate limiting: 100 req/min per user (authenticated), 10 req/min per IP (unauthenticated)

**Data Protection:**
- TLS 1.3 for all API traffic
- Chat history encrypted at rest (AES-256)
- PII fields encrypted (credit card last 4, phone numbers)
- Payment gateway tokens never logged
- Database backups encrypted

**Access Control:**
- RBAC for admin panel (Admin, Super Admin roles)
- Service-to-service auth via API keys (internal network only)
- Audit logging for all admin actions

**Compliance:**
- PCI DSS: Offloaded to payment gateways (Stripe handles card data)
- GDPR: Data export, right to deletion (anonymize instead of hard delete)
- Bangladesh Bank e-commerce guidelines (for bKash, Nagad)

### 4.5 Reliability

**Fault Tolerance:**
- Circuit breakers on all external dependencies (AI providers, gateways)
- Retry logic with exponential backoff (webhook processing, renewal retries)
- Graceful degradation: If AI provider down, show failover options

**Data Integrity:**
- Database transactions for all multi-step operations
- Row-level locking for wallet operations
- Idempotency keys for payment operations
- Append-only ledger (never UPDATE/DELETE ledger entries)

**Monitoring & Alerting:**
- Health check endpoints on all services (`/health`, `/ready`)
- Prometheus metrics + Grafana dashboards
- Alert on: Service down, high error rate, database connection saturation, low disk space
- Logging: Structured JSON logs, centralized (ELK stack or managed service)

### 4.6 Maintainability

**Code Standards:**
- PSR-12 for PHP code
- PHPStan level 8 (strict static analysis)
- 80%+ test coverage (unit + integration tests)
- API documentation (OpenAPI/Swagger)

**Deployment:**
- CI/CD pipeline (GitHub Actions)
- Zero-downtime deployments (rolling updates)
- Database migrations run automatically on deploy (with rollback scripts)
- Feature flags for gradual rollouts

**Observability:**
- Distributed tracing (Jaeger or cloud equivalent)
- Centralized logging with correlation IDs
- Error tracking (Sentry or Bugsnag)

---

## 5. API Contracts (Inter-Service)

### 5.1 Auth Service → Subscription Service

**Endpoint:** `GET /api/internal/subscriptions/user/{user_id}/current`  
**Description:** Get user's active subscription and package details  
**Response:**
```json
{
  "subscription_id": "uuid",
  "package": {
    "id": "uuid",
    "name": "Standard",
    "model_access": ["gpt-4o", "claude-3-sonnet", ...],
    "features": {"file_upload": true, "api_access": false}
  },
  "status": "active",
  "renews_at": "2026-08-07T10:00:00Z"
}
```

### 5.2 AI Gateway → Wallet Service

**Endpoint:** `POST /api/internal/wallet/reserve`  
**Description:** Reserve funds before AI request  
**Request:**
```json
{
  "user_id": "uuid",
  "amount": 0.05,
  "request_id": "uuid"
}
```
**Response:**
```json
{
  "success": true,
  "available_balance": 25.50,
  "reserved_balance": 0.55
}
```

**Endpoint:** `POST /api/internal/wallet/deduct`  
**Description:** Deduct actual cost after AI request completes  
**Request:**
```json
{
  "user_id": "uuid",
  "amount": 0.04,
  "reserved_amount": 0.05,
  "usage_log_id": "uuid",
  "description": "GPT-4o Request"
}
```

**Endpoint:** `POST /api/internal/wallet/refund`  
**Description:** Refund if request fails  
**Request:**
```json
{
  "user_id": "uuid",
  "amount": 0.05,
  "reserved_amount": 0.05,
  "reason": "Request Failed: Timeout"
}
```

### 5.3 Subscription Service → Payment Gateway Service

**Endpoint:** `POST /api/internal/payments/charge`  
**Description:** Process payment for subscription or top-up  
**Request:**
```json
{
  "user_id": "uuid",
  "amount": 20.00,
  "currency": "USD",
  "payment_method_token": "pm_xxxxx",
  "idempotency_key": "user-sub-1-20260807",
  "description": "Standard Package Subscription"
}
```
**Response:**
```json
{
  "transaction_id": "uuid",
  "status": "completed",
  "gateway": "stripe",
  "gateway_reference": "ch_xxxxx"
}
```

---

## 6. Event Catalog

### 6.1 Published Events

| Event Name | Publisher | Payload | Consumers |
|------------|-----------|---------|-----------|
| `user.registered` | Auth Service | `{user_id, email, name}` | Wallet Service (create wallet), Notification Service (welcome email) |
| `user.email_verified` | Auth Service | `{user_id}` | Notification Service |
| `subscription.purchased` | Subscription Service | `{user_id, subscription_id, package_id, amount, currency, transaction_id}` | Wallet Service (credit wallet), Billing Service (generate invoice), Notification Service (receipt) |
| `subscription.upgraded` | Subscription Service | `{user_id, old_package_id, new_package_id, amount}` | Wallet Service, Billing Service, Notification Service |
| `subscription.downgraded` | Subscription Service | `{user_id, old_package_id, new_package_id, amount}` | Wallet Service, Billing Service, Notification Service |
| `subscription.renewed` | Subscription Service | `{user_id, subscription_id, package_id, amount}` | Wallet Service, Billing Service, Notification Service |
| `subscription.renewal_failed` | Subscription Service | `{user_id, subscription_id, attempt_number, error}` | Notification Service (alert) |
| `subscription.past_due` | Subscription Service | `{user_id, subscription_id, days_overdue}` | Notification Service (urgent alert) |
| `subscription.cancelled` | Subscription Service | `{user_id, subscription_id, cancellation_type}` | Notification Service |
| `payment.succeeded` | Payment Gateway Service | `{transaction_id, user_id, amount, currency, gateway}` | Billing Service (complete transaction), Notification Service (receipt) |
| `payment.failed` | Payment Gateway Service | `{transaction_id, user_id, amount, error}` | Subscription Service (handle renewal failure), Notification Service |
| `payment.refunded` | Payment Gateway Service | `{transaction_id, user_id, refund_amount}` | Wallet Service (credit wallet), Billing Service (reversal entry) |
| `wallet.balance_updated` | Wallet Service | `{user_id, balance, credit_balance}` | Frontend (WebSocket real-time update) |
| `wallet.balance_low` | Wallet Service | `{user_id, balance, threshold}` | Notification Service (low balance alert) |
| `wallet.balance_critical` | Wallet Service | `{user_id, balance, credit_balance}` | Notification Service (critical alert) |
| `ai_request.started` | AI Gateway Service | `{user_id, session_id, model_id, estimated_cost}` | (Monitoring/Analytics) |
| `ai_request.completed` | AI Gateway Service | `{user_id, session_id, model_id, tokens, actual_cost, usage_log_id}` | Chat Service (store message), Wallet Service (already deducted) |
| `ai_request.failed` | AI Gateway Service | `{user_id, session_id, model_id, error, refunded_amount}` | Chat Service (error message), Wallet Service (already refunded) |
| `invoice.generated` | Billing Service | `{user_id, invoice_id, pdf_url}` | Notification Service (email invoice) |

### 6.2 Event Bus Implementation

**Technology Options:**
1. **Redis Pub/Sub** (Phase 1 MVP)
   - Pros: Simple, already using Redis for cache
   - Cons: No persistence, no replay, at-most-once delivery
2. **RabbitMQ** (Phase 1 Alternative)
   - Pros: Persistent, at-least-once delivery, topic routing
   - Cons: Additional infrastructure component
3. **Apache Kafka** (Phase 3)
   - Pros: High throughput, event sourcing, replay capability
   - Cons: Operationally complex, overkill for Phase 1

**Recommendation for Phase 1:** Redis Pub/Sub with Laravel Broadcasting + event replay table for critical events (payments, wallet operations)

---

## 7. Data Consistency & Transaction Patterns

### 7.1 Saga Pattern Example: Subscription Purchase

**Saga:** User purchases Standard package

**Steps:**
1. **Subscription Service:** Create pending subscription record
2. **Payment Gateway Service:** Charge payment method
   - If fails → Mark subscription failed, END
3. **Wallet Service:** Credit wallet (settle credit first)
4. **Billing Service:** Generate invoice
5. **Notification Service:** Send receipt email
6. **Subscription Service:** Mark subscription active

**Compensation (if step 3 fails after step 2 succeeded):**
- Refund payment via Payment Gateway Service
- Delete subscription record
- Notify user of failure

### 7.2 Critical Path Row Locking

**Wallet Operations:**
```sql
BEGIN;
SELECT * FROM wallets WHERE user_id = ? FOR UPDATE; -- Lock row
-- Perform balance calculation and update
UPDATE wallets SET balance = ?, reserved_balance = ? WHERE user_id = ?;
INSERT INTO wallet_ledger_entries (...);
COMMIT;
```

**Payment Idempotency:**
```sql
BEGIN;
SELECT * FROM transactions WHERE idempotency_key = ? FOR UPDATE NOWAIT;
-- If exists, return existing
-- Else, create new and process
COMMIT;
```

---

## 8. Testing Requirements

### 8.1 Unit Tests
- Service layer methods (business logic)
- Model methods and relationships
- Helper functions and utilities
- Target: 80%+ coverage

### 8.2 Integration Tests
- API endpoint responses (per service)
- Database transactions (rollback on failure)
- Event publishing and consumption
- External service mocks (AI providers, gateways)

### 8.3 End-to-End Tests
- Complete user flows: signup → purchase → chat → top-up
- Subscription lifecycle: purchase → renewal → upgrade → cancel
- Payment flows with gateway webhooks
- Failover scenarios

### 8.4 Load Tests
- Concurrent AI requests (wallet lock contention)
- Subscription renewal batch processing
- Database connection pool exhaustion
- Event bus throughput

### 8.5 Security Tests
- Authentication bypass attempts
- SQL injection, XSS, CSRF
- Rate limiting effectiveness
- Payment replay attacks (idempotency)

---

## 9. Deployment Requirements

### 9.1 Infrastructure

**Services per Container:**
- API Gateway (Laravel + Nginx)
- Auth Service (Laravel + Nginx)
- Subscription Service (Laravel + Nginx)
- Wallet Service (Laravel + Nginx)
- Payment Gateway Service (Laravel + Nginx)
- Billing Service (Laravel + Nginx)
- AI Gateway Service (Laravel + Nginx)
- Chat Service (Laravel + Nginx)
- Notification Service (Laravel + Nginx)

**Shared Infrastructure:**
- PostgreSQL (managed service, single DB with schemas per service OR separate DBs)
- Redis (cache + event bus)
- S3-compatible storage (file uploads)
- Load balancer (Nginx or cloud LB)

### 9.2 Environment Variables per Service

**Common:**
- `APP_ENV`, `APP_KEY`, `APP_DEBUG`
- `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `REDIS_HOST`, `REDIS_PASSWORD`
- `JWT_SECRET`
- `LOG_LEVEL`

**Service-Specific:**
- **AI Gateway:** `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, etc.
- **Payment Gateway:** `STRIPE_SECRET`, `BKASH_APP_KEY`, etc.
- **Notification:** `MAIL_HOST`, `TWILIO_SID`, etc.

### 9.3 Database Strategy

**Option 1: Shared Database with Schemas**
```
ai_chathub_db
├── auth_service      (users, password_resets)
├── subscription      (packages, user_subscriptions)
├── wallet            (wallets, wallet_ledger_entries, credit_ledger)
├── payment           (transactions, payment_methods, webhook_events)
├── billing           (invoices, receipts)
├── ai_gateway        (ai_models, model_pricing, usage_logs)
├── chat              (chat_sessions, chat_messages, file_attachments)
└── notification      (notifications, notification_preferences)
```

**Option 2: Database per Service (Microservices Purist)**
- Pros: Complete isolation, independent scaling
- Cons: Cross-service joins impossible, more complex

**Recommendation:** Shared database with schemas for Phase 1 (simpler ops), migrate to separate DBs in Phase 3 if needed.

---

## 10. Success Criteria

This project is considered successful when:

✅ All functional requirements (FR-*) are implemented and tested  
✅ All non-functional requirements (performance, security, availability) are met  
✅ End-to-end user flows work without errors  
✅ Payment processing is reliable (98%+ success rate)  
✅ Wallet operations are atomic and accurate (zero balance discrepancies)  
✅ Subscription renewals process successfully (automated)  
✅ AI requests stream responses within 3 seconds  
✅ Admin panel provides complete platform visibility  
✅ System passes security audit (OWASP Top 10 checks)  
✅ Load tests show 1000 req/sec sustained throughput  
✅ Documentation is complete and accurate

---

**END OF SOFTWARE REQUIREMENTS SPECIFICATION**

**Prepared By:** AI ChatHub Development Team  
**Approved By:** [Pending Review]  
**Next Steps:** Review → Schema Design → Implementation
