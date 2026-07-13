# AI ChatHub — Business Requirements Document (BRD)

**Version 2.0 — Subscription + Wallet + Credit Architecture**  
**Status:** Ready for Development  
**Last Updated:** July 7, 2026

---

## 1. Executive Summary

AI ChatHub is a SaaS AI aggregation platform that provides users access to multiple commercial AI providers (OpenAI, Anthropic, Gemini, xAI, ElevenLabs) through a unified interface with a single account and payment relationship.

### Core Business Model

The platform operates on a **subscription-based access + prepaid usage consumption model**:

- **Subscriptions determine access**: Monthly packages (Basic/Standard/Pro) unlock specific AI models and features
- **Wallet funds consumption**: Every AI operation deducts from a prepaid wallet balance
- **Credit buffer**: Allows users to temporarily exceed wallet balance by a configurable amount
- **No usage allowances**: Package price is credited to wallet, not consumed separately

---

## 2. Business Objectives

| Objective | Description |
|-----------|-------------|
| **Unified AI Access** | Single subscription provides access to multiple AI providers without managing separate accounts |
| **Predictable Revenue** | Monthly recurring subscriptions with transparent usage-based billing |
| **Market Flexibility** | Support both local (Bangladesh: BDT/bKash/Nagad) and international (USD/Stripe/PayPal) markets |
| **Scalable Foundation** | Architecture supports growth from MVP to enterprise-scale (B2C Phase 1 → B2B Phase 2) |
| **Trust & Transparency** | Clear billing, real-time balance visibility, immutable audit trails |

---

## 3. Problem Statement

**User Pain Points:**
- Managing multiple AI provider accounts, API keys, and billing relationships is cumbersome
- No unified interface to compare models or switch providers seamlessly
- Unpredictable costs with token-based billing across different providers
- Complex credit card requirements for international services (barrier in Bangladesh market)

**Business Opportunity:**
- Act as aggregator/intermediary absorbing provider complexity
- Offer local payment methods (mobile banking) for emerging markets
- Provide transparent, predictable billing with balance visibility
- Enable cost optimization through provider failover and competitive pricing

---

## 4. Target Market & Users

### Phase 1 (B2C) — Individual Users

| User Segment | Description | Package Fit |
|-------------|-------------|-------------|
| **Students & Learners** | Basic AI assistance for homework, learning | Basic ($10/mo) |
| **Content Creators** | Writing, brainstorming, social media content | Standard ($20/mo) |
| **Professionals** | Research, document analysis, code assistance | Standard ($20/mo) |
| **Power Users** | Multi-modal AI (image/audio/video), heavy usage | Pro ($40/mo) |
| **Developers** | API access for application integration | Pro ($40/mo) + API keys |

### Phase 2 (B2B) — Organizations (Future)

- Teams and departments sharing organization wallets
- Role-based access control for organization members
- Centralized billing and usage reporting
- Department-level cost allocation

---

## 5. Core Business Model: Subscription + Wallet + Credit

### 5.1 Package System (Subscription Tiers)

Packages control **access permissions only**, not usage consumption.

| Package | Monthly Price | Monthly Wallet Credit | Models Included | Key Features |
|---------|--------------|----------------------|-----------------|--------------|
| **Basic** | $10 | $10 | 4 standard models | Basic chat, history, export |
| **Standard** | $20 | $20 | 10 models | + Document analysis, file upload, premium models |
| **Pro** | $40 | $40 | All models | + Image/audio/video generation, API access, priority support |

**Key Principles:**
- Users can only have **one active package** at a time
- Package determines **which models are accessible**, regardless of wallet balance
- A user with $500 wallet balance on Basic package can **only use Basic models**
- Package price is **credited to wallet** upon purchase, not held separately

### 5.2 Wallet System (Prepaid Usage Balance)

The wallet is a **prepaid consumption account** that funds all AI operations.

**Wallet Operations:**
- **Top-ups**: Users add funds anytime via payment gateways
- **Usage Deductions**: Each AI request deducts actual cost (input tokens + output tokens)
- **Refunds**: Failed requests are auto-refunded to wallet
- **Portability**: Wallet balance carries forward on renewals, upgrades, downgrades

**Wallet Balance ≠ Package Tier**
- Wallet amount **never changes** package permissions
- A $0 wallet blocks requests until topped up, but package remains active (grace period policy TBD)

### 5.3 Credit Buffer System (Temporary Overdraft)

Prevents interruption when wallet balance is slightly insufficient.

**How It Works:**

**Scenario:** User has $0.50 in wallet, request costs $2.00

1. System estimates request cost: $2.00
2. Available balance: $0.50 (wallet) + $2.50 (remaining credit buffer) = $3.00
3. Request is **allowed**
4. Actual cost: $2.00
5. Wallet becomes: $0.00
6. Credit balance becomes: **-$1.50**

**Credit Rules:**
- Maximum credit: Configurable by admin (e.g., $2, $3, $5)
- Pre-flight check: Block request if estimated cost > (wallet + remaining credit)
- Auto-settlement: Credit is recovered before any wallet top-up or renewal funding
- No interest/fees: Credit is a UX buffer, not a loan product

**Credit Settlement Examples:**

| Event | Before | Operation | After |
|-------|--------|-----------|-------|
| **Top-up** | Wallet: $5, Credit: -$3 | User tops up $20 | Wallet: $22, Credit: $0 |
| **Renewal** | Wallet: $0, Credit: -$2 | Standard renewal (+$20) | Wallet: $18, Credit: $0 |
| **Upgrade** | Wallet: $10, Credit: -$3, Package: Standard | Upgrade to Pro (+$40) | Wallet: $47, Credit: $0, Package: Pro |

---

## 6. Subscription Lifecycle

### 6.1 Initial Purchase

**User Action:** Purchase Standard package ($20)

**System Processing:**
1. Charge payment method: $20
2. Create subscription record:
   - `package_id`: Standard
   - `activated_at`: 2026-07-08 10:00:00
   - `renews_at`: 2026-08-07 10:00:00 (30 days)
   - `auto_renew`: true
   - `status`: active
3. Save payment method token for renewals
4. Credit wallet: `wallet.balance += $20`
5. Record ledger entry: "Standard Package Purchase"

**Result:** User has Standard access + $20 usable wallet balance

### 6.2 Monthly Auto-Renewal

**Trigger:** 30 days after activation (2026-08-07)

**System Processing:**
1. Charge saved payment method: $20
2. **If payment succeeds:**
   - Settle credit if negative (e.g., -$3 recovered from $20 → $17 to wallet)
   - Credit wallet: `wallet.balance += $17` (after credit recovery)
   - Update subscription: `renews_at += 30 days`
   - Create ledger entry: "Standard Package Renewal"
3. **If payment fails:**
   - Retry after 24 hours
   - Retry after 48 hours (total 2 days)
   - Retry after 72 hours (total 3 days)
   - After 3 failed retries: `status = 'past_due'`
   - Notify user throughout retry period
   - **Wallet balance remains untouched** during grace period
   - Business policy decision: Allow continued usage during retry window or suspend immediately

### 6.3 Package Upgrade

**User Action:** Upgrade from Standard ($20) to Pro ($40)

**System Processing:**
1. Charge **full new package price**: $40 (NOT prorated $20 difference)
2. Update subscription:
   - `package_id`: Pro
   - `previous_package_id`: Standard (audit trail)
   - `renews_at`: Reset to +30 days from upgrade date
   - Next renewal price: $40
3. Settle credit if negative: e.g., -$3 recovered from $40
4. Credit wallet: `wallet.balance += ($40 - credit_owed)`
5. **Access changes immediately**: Pro models unlocked
6. Create ledger entry: "Upgrade to Pro Package"

**Business Rationale for Full Price:**
- Subscription purchase and wallet balance are **independent transactions**
- User gets full $40 of wallet credit **plus** access upgrade
- Simplifies accounting and prevents prorating edge cases

### 6.4 Package Downgrade

**User Action:** Downgrade from Standard ($20) to Basic ($10)

**System Processing:**
1. Charge **full new package price**: $10
2. Update subscription:
   - `package_id`: Basic
   - `previous_package_id`: Standard
   - `renews_at`: Reset to +30 days from downgrade date
   - Next renewal price: $10
3. Settle credit if negative
4. Credit wallet: `wallet.balance += ($10 - credit_owed)`
5. **Access restriction applies immediately**: Standard/Pro models locked
6. **Existing wallet balance preserved**: If user had $15 remaining, now has $25 usable balance
7. Create ledger entry: "Downgrade to Basic Package"

**Policy Option (Business Decision):**
- **Immediate Effect** (default): Model restrictions apply instantly
- **End-of-Cycle Effect** (alternative): Downgrade scheduled for next renewal date
  - Requires `user_subscriptions.scheduled_package_id` field
  - User retains Standard access until renewal date

### 6.5 Cancellation

**User Action:** Cancel subscription

**Options:**
1. **Cancel at End of Cycle** (Recommended):
   - `auto_renew = false`
   - Access continues until `renews_at` date
   - No further charges
   - Wallet balance preserved
2. **Cancel Immediately** (Optional):
   - `status = 'cancelled'`, `cancelled_at = now()`
   - Access revoked immediately
   - Wallet balance preserved (no refund of current cycle)
   - Business policy: Partial refund possible (requires approval workflow)

---

## 7. Payment & Billing Requirements

### 7.1 Payment Methods

| Method | Use Cases | Currency | Region |
|--------|-----------|----------|--------|
| **Stripe** | Subscriptions, Top-ups | USD, BDT | Global |
| **PayPal** | Top-ups | USD | Global |
| **bKash** | Top-ups, Subscriptions | BDT | Bangladesh |
| **Nagad** | Top-ups, Subscriptions | BDT | Bangladesh |
| **SSLCommerz** | Top-ups, Subscriptions | BDT | Bangladesh |

### 7.2 Transaction Types

| Transaction Type | Description | Payment Method | Affects Wallet | Affects Subscription |
|-----------------|-------------|----------------|---------------|---------------------|
| **Subscription Purchase** | Buy/upgrade/downgrade package | All methods | ✅ Credits wallet | ✅ Activates/updates package |
| **Subscription Renewal** | Auto-charge every 30 days | Saved method from purchase | ✅ Credits wallet | ✅ Extends renewal date |
| **Wallet Top-up** | Add funds anytime | All methods | ✅ Credits wallet | ❌ No package change |
| **AI Usage** | Request processing | N/A (wallet deduction) | ❌ Deducts wallet | ❌ No package change |
| **Refund** | Failed AI request | N/A (credit back) | ✅ Credits wallet | ❌ No package change |

### 7.3 Multi-Currency Support

- **Primary Currency:** USD (all pricing, cost calculations)
- **Local Currency:** BDT (Bangladesh market)
- **Exchange Rate:** Stored on every transaction for audit trail
- **Wallet Currency:** Defaults to user's signup currency (USD or BDT)
- **Gateway Fees:** Stored separately for margin reporting

### 7.4 Billing Transparency

**Real-Time Balance Visibility:**
- Wallet balance displayed on: Dashboard, chat interface, billing page
- Updates immediately after: Top-up, usage, renewal, refund
- Credit balance shown when negative: "Balance: $0.00 (Credit: -$2.50)"

**Usage Receipt (Per Request):**
- Model/feature used
- Cost breakdown: input tokens, output tokens, total
- Balance before/after
- Timestamp

**Wallet Ledger (Complete History):**
- All balance-affecting events: top-ups, purchases, usage, refunds, credits, adjustments
- Running balance column for audit
- Filterable by date, type, currency

---

## 8. AI Model Access & Usage

### 8.1 Model Access Control

**Rule:** Package determines eligibility, wallet determines affordability

**Access Validation Flow:**
1. User selects model (e.g., GPT-4o)
2. Check: Is user's current package allowed to access this model?
   - If NO → Block with upgrade prompt
   - If YES → Proceed to step 3
3. Estimate request cost based on input length + expected output
4. Check: Is `wallet.balance + remaining_credit >= estimated_cost`?
   - If NO → Block with top-up prompt
   - If YES → Reserve cost, send request

### 8.2 Usage Cost Calculation

**Per-Model Pricing (Admin Configurable):**
- Input tokens: $X per 1k tokens
- Output tokens: $Y per 1k tokens
- Flat request fee: $Z (for image/audio generation)

**Example (GPT-4o):**
- Input: $0.0025 per 1k tokens
- Output: $0.01 per 1k tokens
- User sends 500 input tokens, receives 2000 output tokens
- Cost: (500/1000 × $0.0025) + (2000/1000 × $0.01) = $0.00125 + $0.02 = $0.02125

**Actual cost deducted after response received** (not estimated cost)

### 8.3 Provider Failover

**Scenario:** Primary model (OpenAI GPT-4o) fails or times out

**System Behavior:**
1. Retry primary once (configurable)
2. If still failing, check fallback rules
3. Fallback to configured alternate (e.g., Anthropic Claude Sonnet)
4. Notify user: "Using Claude Sonnet (OpenAI unavailable)"
5. Charge based on actual model used

---

## 9. Business Rules Summary

### Subscription Rules
- ✅ One active subscription per user at any time
- ✅ Subscription determines accessible models only
- ✅ Subscription purchases/renewals credit wallet with package price
- ✅ Renewals occur every 30 days from activation date
- ✅ Upgrades charge full new price, reset renewal cycle
- ✅ Downgrades charge full new price, reset renewal cycle
- ✅ Wallet balance always carries forward (never lost)

### Wallet Rules
- ✅ Wallet funds all AI usage consumption
- ✅ Top-ups allowed anytime, independent of subscription
- ✅ Wallet balance ≠ package tier (no auto-upgrade based on balance)
- ✅ Wallet cannot go negative (credit buffer provides temporary overdraft)
- ✅ Failed requests auto-refunded to wallet

### Credit Buffer Rules
- ✅ Maximum credit configurable by admin (e.g., $3)
- ✅ Pre-flight cost estimation prevents exceeding max credit
- ✅ Credit auto-settles before any wallet funding (top-up/renewal/upgrade)
- ✅ No interest or fees on credit usage
- ✅ Credit is UX enhancement, not a financial product

### Access Control Rules
- ✅ Model access checked before cost estimation
- ✅ Insufficient balance blocks request with clear top-up CTA
- ✅ Suspended/cancelled subscriptions block all AI requests
- ✅ "Past due" status policy: Business decision (allow grace period or block immediately)

---

## 10. Success Metrics & KPIs

| Metric | Target (Phase 1) | Measurement |
|--------|------------------|-------------|
| **Platform Uptime** | ≥99.5% | Monthly average |
| **Subscription Conversion** | ≥15% of signups | 30-day window |
| **Payment Success Rate** | ≥98% | All transactions |
| **Renewal Retention** | ≥70% | Monthly cohort |
| **AI Request Success** | ≥99% | Including fallback |
| **Support Tickets (Billing)** | <2% of MAU | Monthly |
| **Gross Margin** | ≥30% | (Revenue - AI Provider Costs) / Revenue |

---

## 11. Roadmap

### Phase 1 — MVP (Weeks 1-10)
**Focus:** Validate core value proposition with paying users

- ✅ User registration, authentication
- ✅ Subscription purchase (Basic/Standard/Pro)
- ✅ Wallet top-up (Stripe initially)
- ✅ Credit buffer system
- ✅ Chat with 2-3 providers (OpenAI, Anthropic)
- ✅ Real-time wallet balance display
- ✅ Auto-renewal with retry logic
- ✅ Basic admin panel (users, transactions, pricing)
- ✅ Usage ledger and billing history

**Launch Goal:** 100 paying subscribers

### Phase 2 — Growth (Weeks 11-22)
**Focus:** Full feature parity and market expansion

- All AI providers (Gemini, xAI, ElevenLabs)
- All payment gateways (bKash, Nagad, SSLCommerz, PayPal)
- File uploads + document analysis
- Multi-model comparison UI
- Provider fallback & circuit breakers
- Promo codes & referral system
- Finance admin dashboard (margin reports)
- Support ticketing system
- Mobile-responsive optimization

**Growth Goal:** 1,000 paying subscribers

### Phase 3 — Scale & B2B (Weeks 23+)
**Focus:** Enterprise features and infrastructure scale

- Organization/team accounts
- Shared organization wallets
- API keys + developer portal
- Voice interaction (ElevenLabs)
- Native mobile apps (React Native)
- Advanced analytics & usage forecasting
- Microservices extraction (AI Gateway → Go, Billing service isolation)
- Horizontal scaling (Redis cluster, Postgres sharding, Kafka event bus)
- ClickHouse for analytics/logs

**Scale Goal:** 10,000+ users, <100ms avg. response latency

---

## 12. Risks & Mitigation

| Risk | Impact | Probability | Mitigation |
|------|--------|-------------|------------|
| **AI Provider Price Increase** | Margin erosion | High | Monthly pricing review, pass-through policy, margin monitoring |
| **Payment Gateway Issues (BD)** | Revenue blockage | Medium | Multi-gateway redundancy, customer support escalation |
| **Renewal Payment Failure** | Churn | Medium | Retry logic, proactive notifications, easy payment method update |
| **Credit Buffer Abuse** | Revenue loss | Low | Rate limiting, max credit cap, abuse detection monitoring |
| **Provider Outage** | Service disruption | Medium | Automatic failover, circuit breakers, status page |
| **Scaling Bottlenecks** | Performance degradation | Low (Phase 1) | Phased microservices extraction, infrastructure monitoring |

---

## 13. Open Questions for Stakeholder Decision

| Question | Options | Recommendation |
|----------|---------|----------------|
| **"Past Due" Subscription Access** | (A) Block immediately on failed renewal<br>(B) Allow 3-day grace period during retries | **(B)** — Better UX, reduces churn |
| **Downgrade Timing** | (A) Immediate model restriction<br>(B) Effective next renewal cycle | **(A)** — Simpler logic, immediate cost savings |
| **Partial Refund on Cancellation** | (A) No refunds (wallet balance retained)<br>(B) Prorated refund to wallet | **(A)** — Reduces complexity, incentivizes usage |
| **Credit Buffer Default Limit** | $2 / $3 / $5 | **$3** — Balances UX flexibility with risk |
| **Free Trial** | (A) No free trial<br>(B) 7-day trial with $5 credit | **(B)** — Lowers barrier, validates quality |

---

## 14. Compliance & Legal

### Data Protection
- Chat history encrypted at rest
- PCI DSS compliance for payment handling (via gateway offload)
- GDPR-style data access/deletion for international users
- Bangladesh Bank e-commerce guidelines for local gateways

### Terms of Service
- Subscription renewal terms clearly disclosed
- Wallet balance non-expiring (no escheatment)
- Credit buffer terms (not a lending product)
- AI usage policies (prohibited content, abuse)

### Financial Regulations
- VAT/GST handling per jurisdiction
- Gateway settlement reconciliation
- Anti-money laundering (AML) checks for high-value top-ups

---

## 15. Glossary

| Term | Definition |
|------|------------|
| **Package / Subscription** | Monthly tier (Basic/Standard/Pro) determining which models are accessible |
| **Wallet** | Prepaid balance funding AI usage consumption |
| **Credit Buffer** | Temporary overdraft allowance (configurable, e.g., $3 max) |
| **Wallet Ledger** | Immutable transaction log of all balance changes |
| **Credit Ledger** | Separate log tracking credit buffer usage and settlement |
| **Top-up** | Adding funds to wallet via payment gateway (independent of subscription) |
| **Renewal** | Automatic monthly subscription charge (every 30 days) |
| **Usage Deduction** | Real-time wallet charge for AI request (input/output tokens) |
| **Upgrade** | Moving to higher-tier package (charges full new price) |
| **Downgrade** | Moving to lower-tier package (charges full new price) |
| **Past Due** | Subscription status after failed renewal retries |
| **Auto-Renewal** | System automatically charges saved payment method every 30 days |

---

**Document Prepared By:** AI ChatHub Product Team  
**Approved By:** [Pending Stakeholder Review]  
**Next Review Date:** [TBD]
