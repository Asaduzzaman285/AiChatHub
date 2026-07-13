# AI ChatHub — Database Design Notes

**Version 2.0 | Microservices Architecture**
**Last Updated:** July 7, 2026

---

## 1. Database Strategy

### 1.1 Shared Database with Service Schemas (Phase 1)

All services share a single PostgreSQL instance, but each service owns an isolated schema. No service queries another service's schema directly — ever. Cross-service data is obtained exclusively via internal REST API calls or event bus messages.

```
PostgreSQL Instance: ai_chathub_db
├── auth_svc          ← Auth Service owns this schema
├── subscription_svc  ← Subscription Service owns this
├── wallet_svc        ← Wallet Service owns this
├── payment_svc       ← Payment Gateway Service owns this
├── billing_svc       ← Billing Service owns this
├── ai_svc            ← AI Gateway Service owns this
├── chat_svc          ← Chat Service owns this
├── notification_svc  ← Notification Service owns this
└── support_svc       ← Support Service owns this (Phase 2)
```

**Why shared DB in Phase 1:**
- Simpler operations (single backup, single connection string)
- No distributed transaction complexity for initial development
- Schema isolation preserves service boundary discipline
- Easy to extract to separate DBs in Phase 3 — schemas become databases

**Phase 3 Migration Path:**
Each schema becomes its own PostgreSQL database, each on its own RDS/Cloud SQL instance. The service code does not change — only connection strings in environment variables change.

### 1.2 Connection Per Service

Each Laravel service connects only to its own schema via `search_path`:

```php
// config/database.php in each service
'pgsql' => [
    'driver'   => 'pgsql',
    'host'     => env('DB_HOST'),
    'database' => env('DB_DATABASE', 'ai_chathub_db'),
    'username' => env('DB_USERNAME'),
    'password' => env('DB_PASSWORD'),
    'schema'   => env('DB_SCHEMA'),  // e.g. 'wallet_svc'
    'options'  => ['search_path' => env('DB_SCHEMA')],
]
```

**Dedicated DB users per service (least privilege):**

| Service | DB User | Permissions |
|---------|---------|-------------|
| auth_svc | `auth_app` | CRUD on `auth_svc.*` only |
| subscription_svc | `sub_app` | CRUD on `subscription_svc.*` only |
| wallet_svc | `wallet_app` | CRUD on `wallet_svc.*` only |
| payment_svc | `payment_app` | CRUD on `payment_svc.*` only |
| billing_svc | `billing_app` | CRUD on `billing_svc.*` only |
| ai_svc | `ai_app` | CRUD on `ai_svc.*` only |
| chat_svc | `chat_app` | CRUD on `chat_svc.*` only |
| notification_svc | `notif_app` | CRUD on `notification_svc.*` only |

---

## 2. Service Data Ownership Map

This defines what data each service exclusively owns and is solely responsible for writing. No other service writes to these tables.

### 2.1 Auth Service → `auth_svc`

| Table | Purpose | Critical Notes |
|-------|---------|----------------|
| `users` | User accounts and status | Source of truth for identity. Other services cache `user_id` only. |
| `email_verifications` | Email verification tokens | Soft-expire only — never delete used tokens |
| `password_resets` | Password reset tokens | Same — keep for audit |
| `refresh_tokens` | JWT refresh tokens | Row `revoked = true` on logout, NOT deleted |
| `login_attempts` | Auth security log | Append-only, used for rate limiting |
| `admin_users` | Admin role assignments | Changes are audit-logged |
| `audit_logs` | All admin actions | **Never update or delete** — append-only forever |
| `system_config` | Platform settings | Admin-managed. Values read by all services via API |

### 2.2 Subscription Service → `subscription_svc`

| Table | Purpose | Critical Notes |
|-------|---------|----------------|
| `packages` | Package definitions | Soft-delete only (`is_active = false`) |
| `user_subscriptions` | Active subscriptions | One active record per user enforced via partial unique index |
| `subscription_history` | Upgrade/downgrade audit | Append-only. Never update entries |
| `renewal_attempts` | Renewal retry log | Append-only per attempt |

### 2.3 Wallet Service → `wallet_svc`

| Table | Purpose | Critical Notes |
|-------|---------|----------------|
| `wallets` | Current balance state | Every write must be inside a transaction with `SELECT FOR UPDATE` |
| `wallet_ledger_entries` | Balance change history | **Append-only forever**. No UPDATE or DELETE permitted |
| `credit_ledger` | Credit buffer history | **Append-only forever**. Separate from wallet ledger for clarity |

### 2.4 Payment Gateway Service → `payment_svc`

| Table | Purpose | Critical Notes |
|-------|---------|----------------|
| `payment_methods` | Saved gateway tokens | Raw card data never stored — PCI DSS |
| `transactions` | All payment records | `idempotency_key` prevents duplicate charges |
| `webhook_events` | Incoming gateway webhooks | `gateway_reference` unique constraint prevents duplicate processing |

### 2.5 Billing Service → `billing_svc`

| Table | Purpose | Critical Notes |
|-------|---------|----------------|
| `invoices` | Subscription invoices | Generated on `subscription.purchased/renewed` events |
| `receipts` | Top-up receipts | Generated on `payment.succeeded` event |
| `promo_codes` | Discount codes | Admin-managed |
| `user_promo_usage` | Promo code redemptions | Unique constraint prevents double-use per user |

### 2.6 AI Gateway Service → `ai_svc`

| Table | Purpose | Critical Notes |
|-------|---------|----------------|
| `ai_models` | Provider model catalog | Soft-delete (`is_active = false`) |
| `model_pricing` | Rates per model | Historical — never delete old rates. Use `effective_until` to expire |
| `usage_logs` | Per-request usage | Append-only. High write volume — consider partitioning by month |
| `provider_fallback_rules` | Failover configuration | Admin-managed |
| `circuit_breaker_state` | Per-model health state | UPSERT pattern — one row per model |

### 2.7 Chat Service → `chat_svc`

| Table | Purpose | Critical Notes |
|-------|---------|----------------|
| `chat_sessions` | Conversation containers | Soft-delete only (`deleted_at`) |
| `chat_messages` | Message history | High write volume — index by session + created_at |
| `file_attachments` | Uploaded files metadata | Actual files in S3. DB stores metadata only |

### 2.8 Notification Service → `notification_svc`

| Table | Purpose | Critical Notes |
|-------|---------|----------------|
| `notifications` | Delivery log | `idempotency_key` prevents duplicate sends |
| `notification_preferences` | User opt-in settings | One row per user, upsert on change |

---

## 3. Critical Locking Strategy

### 3.1 Wallet Operations — Row-Level Locking

Every wallet balance change MUST follow this exact pattern. No exceptions.

```sql
-- ALWAYS wrap wallet operations in a transaction
BEGIN;

-- Step 1: Lock the wallet row exclusively before reading or writing
SELECT id, balance, reserved_balance, credit_balance, credit_limit
FROM wallet_svc.wallets
WHERE user_id = $1
FOR UPDATE;  -- Exclusive row lock — other transactions BLOCK until this commits

-- Step 2: Perform calculations and validate in application code

-- Step 3: Update balance atomically
UPDATE wallet_svc.wallets
SET balance          = $2,
    reserved_balance = $3,
    credit_balance   = $4,
    updated_at       = NOW()
WHERE user_id = $1;

-- Step 4: Write immutable ledger entry
INSERT INTO wallet_svc.wallet_ledger_entries (
    wallet_id, user_id, type, amount,
    balance_before, balance_after, description, reference_type, reference_id
) VALUES ($5, $1, $6, $7, $8, $9, $10, $11, $12);

COMMIT;
```

**Why `FOR UPDATE` and not `FOR SHARE`:**
- Multiple concurrent AI requests can arrive simultaneously for the same user
- `FOR SHARE` allows concurrent reads — this creates a race condition
- `FOR UPDATE` serialises all wallet operations per user

**Expected lock contention:** Low in Phase 1 (<100 concurrent users). If contention rises above 50ms wait time in Phase 3, migrate to Redis Lua scripts for balance operations.

### 3.2 Payment Idempotency — NOWAIT Locking

```sql
BEGIN;

-- Try to lock the idempotency row without waiting
-- If another request is already processing with same key, fail fast
SELECT id, status FROM payment_svc.transactions
WHERE idempotency_key = $1
FOR UPDATE NOWAIT;  -- Fail immediately if locked (don't queue)

-- If row found with status = 'completed': return existing, ROLLBACK
-- If row not found: INSERT new pending transaction, proceed to charge

COMMIT;
```

**`NOWAIT` rationale:** If two requests arrive simultaneously with the same idempotency key (duplicate network request), one should fail fast rather than queue and potentially double-charge.

### 3.3 Subscription Uniqueness — Partial Unique Index

```sql
-- Enforced at DB level — no application logic needed
CREATE UNIQUE INDEX idx_sub_user_active
    ON subscription_svc.user_subscriptions(user_id)
    WHERE status IN ('active', 'past_due');
```

This means PostgreSQL itself rejects a second active subscription INSERT for the same user. The application does not need to query-then-insert — the DB constraint is the enforcement layer.

### 3.4 Webhook Deduplication — Unique Constraint

```sql
-- Unique on (gateway, gateway_reference) prevents duplicate webhook processing
CREATE UNIQUE INDEX idx_webhook_gateway_ref
    ON payment_svc.webhook_events(gateway, gateway_reference);
```

On duplicate webhook arrival: the INSERT fails with a unique constraint violation. The application catches this, returns `200 OK` to the gateway, and skips processing. No manual duplicate check query required.

---

## 4. Indexing Strategy

### 4.1 Index Design Principles

1. **Every FK column is indexed** — prevents sequential scans on joins
2. **Timestamp columns on append-only tables** — always include `created_at DESC` for pagination
3. **Partial indexes** — use `WHERE` clauses to index only relevant rows (active records, pending items)
4. **Covering indexes** — include frequently-selected columns in index to avoid heap fetches
5. **No index on every column** — each index adds write overhead; justify with query patterns

### 4.2 Critical Indexes by Table

**`wallet_svc.wallets`**
```sql
-- Used by every AI request (balance check)
CREATE INDEX idx_wallet_user ON wallet_svc.wallets(user_id);

-- For monitoring low-balance users (admin dashboard, alert job)
CREATE INDEX idx_wallet_balance_low ON wallet_svc.wallets(balance)
    WHERE balance < 5.00;
```

**`wallet_svc.wallet_ledger_entries`**
```sql
-- User ledger history (paginated, most recent first)
CREATE INDEX idx_ledger_user_time
    ON wallet_svc.wallet_ledger_entries(user_id, created_at DESC);

-- Lookup by reference (e.g., find ledger entry for a transaction)
CREATE INDEX idx_ledger_ref
    ON wallet_svc.wallet_ledger_entries(reference_type, reference_id);
```

**`subscription_svc.user_subscriptions`**
```sql
-- Unique active subscription per user (partial — only active/past_due)
CREATE UNIQUE INDEX idx_sub_user_active
    ON subscription_svc.user_subscriptions(user_id)
    WHERE status IN ('active', 'past_due');

-- Renewal job: find subscriptions due for renewal
CREATE INDEX idx_sub_renews
    ON subscription_svc.user_subscriptions(renews_at, auto_renew)
    WHERE status = 'active';
```

**`ai_svc.usage_logs`**
```sql
-- User usage history
CREATE INDEX idx_usage_user ON ai_svc.usage_logs(user_id, created_at DESC);

-- Admin reporting by model
CREATE INDEX idx_usage_model ON ai_svc.usage_logs(model_id, created_at DESC);

-- Lookup for cost reconciliation
CREATE INDEX idx_usage_session ON ai_svc.usage_logs(session_id);
```

**`payment_svc.transactions`**
```sql
-- User transaction history
CREATE INDEX idx_txn_user ON payment_svc.transactions(user_id, created_at DESC);

-- Webhook lookup by gateway reference
CREATE INDEX idx_txn_gateway_ref
    ON payment_svc.transactions(gateway, gateway_reference);

-- Idempotency check (unique index doubles as lookup index)
CREATE UNIQUE INDEX idx_txn_idempotency
    ON payment_svc.transactions(idempotency_key);
```

**`chat_svc.chat_messages`**
```sql
-- Load conversation (session messages in order)
CREATE INDEX idx_msg_session
    ON chat_svc.chat_messages(session_id, created_at ASC);
```

**`notification_svc.notifications`**
```sql
-- Retry job: pending and failed notifications
CREATE INDEX idx_notif_pending
    ON notification_svc.notifications(status, created_at)
    WHERE status IN ('pending', 'failed');
```

### 4.3 Indexes to Avoid

- Do NOT index `wallet_ledger_entries.amount` or `wallet_ledger_entries.type` independently — these are never selective enough
- Do NOT index `chat_messages.content` — use full-text search (tsvector) if search is needed in Phase 3
- Do NOT index every JSONB column — use GIN index only if specific JSON path queries are identified

---

## 5. Append-Only Tables (Financial Integrity)

The following tables are **write-once, never modified**. This is a hard rule enforced in code and ideally at the database level.

| Table | Reason |
|-------|--------|
| `wallet_svc.wallet_ledger_entries` | Financial ledger — the running total must be reconstructable from entries |
| `wallet_svc.credit_ledger` | Credit buffer audit trail |
| `auth_svc.audit_logs` | Admin action log — tamper-proof |
| `auth_svc.login_attempts` | Security log |
| `subscription_svc.subscription_history` | Business event log |
| `subscription_svc.renewal_attempts` | Retry event log |
| `ai_svc.usage_logs` | Usage billing audit trail |

**Enforcement at DB level (optional, recommended for production):**

```sql
-- Revoke UPDATE and DELETE from the application user on ledger tables
REVOKE UPDATE, DELETE ON wallet_svc.wallet_ledger_entries FROM wallet_app;
REVOKE UPDATE, DELETE ON wallet_svc.credit_ledger FROM wallet_app;
REVOKE UPDATE, DELETE ON auth_svc.audit_logs FROM auth_app;
```

**Reconciliation Query (verify ledger integrity):**

```sql
-- The sum of all ledger entries for a wallet should equal current balance
SELECT
    w.user_id,
    w.balance AS current_balance,
    SUM(CASE WHEN l.type IN ('credit','refund') THEN l.amount
             WHEN l.type = 'debit' THEN -l.amount ELSE 0 END) AS ledger_sum,
    w.balance - SUM(CASE WHEN l.type IN ('credit','refund') THEN l.amount
                         WHEN l.type = 'debit' THEN -l.amount ELSE 0 END) AS discrepancy
FROM wallet_svc.wallets w
JOIN wallet_svc.wallet_ledger_entries l ON l.wallet_id = w.id
GROUP BY w.user_id, w.balance
HAVING ABS(w.balance - SUM(CASE WHEN l.type IN ('credit','refund') THEN l.amount
                                 WHEN l.type = 'debit' THEN -l.amount ELSE 0 END)) > 0.000001;
```

Run this reconciliation query daily as a monitoring job. Any result indicates a data integrity bug.

---

## 6. Migration Order & Dependency Graph

Migrations must run in this exact order due to FK dependencies. Each service runs its own migrations via `php artisan migrate` scoped to its schema.

```
Phase 1: No dependencies
├── auth_svc.users                       ← No FK dependencies
├── auth_svc.system_config
├── subscription_svc.packages
└── ai_svc.ai_models

Phase 2: Depends on Phase 1
├── auth_svc.admin_users                 ← needs auth_svc.users
├── auth_svc.email_verifications         ← needs auth_svc.users
├── auth_svc.password_resets             ← needs auth_svc.users
├── auth_svc.refresh_tokens              ← needs auth_svc.users
├── auth_svc.login_attempts              ← needs auth_svc.users
├── auth_svc.audit_logs                  ← needs auth_svc.admin_users
├── wallet_svc.wallets                   ← needs auth_svc.users (user_id reference)
├── payment_svc.payment_methods          ← needs auth_svc.users
├── notification_svc.notification_prefs  ← needs auth_svc.users
├── ai_svc.model_pricing                 ← needs ai_svc.ai_models
└── ai_svc.circuit_breaker_state         ← needs ai_svc.ai_models

Phase 3: Depends on Phase 1 + Phase 2
├── subscription_svc.user_subscriptions  ← needs packages, payment_methods
├── wallet_svc.wallet_ledger_entries     ← needs wallets
├── wallet_svc.credit_ledger             ← needs wallets
├── payment_svc.transactions             ← needs payment_methods
├── ai_svc.provider_fallback_rules       ← needs ai_models (×2)
├── notification_svc.notifications       ← needs auth_svc.users
└── billing_svc.promo_codes

Phase 4: Depends on Phase 3
├── subscription_svc.subscription_history ← needs subscriptions, packages
├── subscription_svc.renewal_attempts     ← needs subscriptions, transactions
├── payment_svc.webhook_events            ← needs transactions
├── billing_svc.invoices                  ← needs user_subscriptions, transactions
├── billing_svc.receipts                  ← needs transactions
├── billing_svc.user_promo_usage          ← needs promo_codes, transactions
└── ai_svc.usage_logs                     ← needs ai_models

Phase 5: Depends on Phase 4
├── chat_svc.chat_sessions               ← needs auth_svc.users, ai_models
├── support_svc.tickets                  ← needs auth_svc.users, admin_users

Phase 6: Depends on Phase 5
├── chat_svc.chat_messages               ← needs chat_sessions
├── support_svc.ticket_messages          ← needs tickets

Phase 7: Depends on Phase 6
└── chat_svc.file_attachments            ← needs chat_sessions, chat_messages
```

---

## 7. Data Types & Precision

### 7.1 Monetary Values

All financial columns use `DECIMAL(12,6)` — not FLOAT, not DOUBLE, not INTEGER cents.

| Type | Used For | Precision |
|------|----------|-----------|
| `DECIMAL(12,6)` | Balance, cost, ledger amounts | Sub-cent precision for token costs (e.g., $0.000125) |
| `DECIMAL(10,2)` | Package prices, transaction amounts | Standard currency precision |
| `DECIMAL(10,6)` | Exchange rates | 6 decimal places for BDT/USD rates |
| `DECIMAL(10,4)` | Flat rates per unit | Image/audio generation rates |

**Never use FLOAT for money.** Floating point arithmetic introduces rounding errors. `DECIMAL` is exact.

**Example — why precision matters:**
- GPT-4o request: 500 input tokens × $0.0025/1k = $0.00125 per request
- At DECIMAL(12,6): stored as `0.001250`
- At DECIMAL(10,2): rounds to `0.00` — user gets free request, platform loses money

### 7.2 UUIDs

All primary keys are `uuid` generated with `uuid_generate_v4()`.

**Why UUID over auto-increment INT:**
- No sequential ID enumeration attacks (`/users/1`, `/users/2` reveals user count)
- Safe to generate IDs client-side before insert (useful for event-sourcing)
- Globally unique across future sharding scenarios
- Slight storage cost (16 bytes vs 4/8 bytes) is acceptable

### 7.3 JSONB Columns

Used for flexible, evolving data structures:

| Column | Contents | Notes |
|--------|----------|-------|
| `packages.model_access` | `["uuid1","uuid2",...]` | Array of allowed model UUIDs |
| `packages.features` | `{"file_upload":true,"api_access":false}` | Feature flags per package |
| `ai_models.capabilities` | `{"streaming":true,"vision":false}` | Model capability flags |
| `ai_svc.usage_logs.metadata` | Request metadata, file IDs, etc. | Semi-structured, varies by operation_type |
| `provider_fallback_rules.trigger_conditions` | `{"timeout_ms":8000,"error_codes":[429,500]}` | Configurable trigger logic |

**JSONB over JSON:** JSONB is stored in decomposed binary format — faster for queries and supports GIN indexes. Always use JSONB over JSON.

---

## 8. Partitioning Strategy (Phase 2+)

High-volume append-only tables will need partitioning as data grows:

### 8.1 `ai_svc.usage_logs` — Range Partition by Month

```sql
-- Convert to partitioned table when row count exceeds ~10M
CREATE TABLE ai_svc.usage_logs (
    ...
    created_at TIMESTAMP NOT NULL
) PARTITION BY RANGE (created_at);

CREATE TABLE ai_svc.usage_logs_2026_07
    PARTITION OF ai_svc.usage_logs
    FOR VALUES FROM ('2026-07-01') TO ('2026-08-01');

CREATE TABLE ai_svc.usage_logs_2026_08
    PARTITION OF ai_svc.usage_logs
    FOR VALUES FROM ('2026-08-01') TO ('2026-09-01');
```

**Benefits:**
- Old partitions can be archived/moved to cheaper storage
- Query planner skips irrelevant partitions
- Maintenance (VACUUM, ANALYZE) runs per-partition

### 8.2 `wallet_svc.wallet_ledger_entries` — Range Partition by Month

Same pattern as usage_logs. Apply when ledger exceeds ~50M rows.

### 8.3 `chat_svc.chat_messages` — Range Partition by Month

Apply when messages exceed ~100M rows. Consider ClickHouse migration in Phase 3.

---

## 9. Connection Pooling

### 9.1 PgBouncer Configuration (Phase 2)

Running database connections are expensive (8MB+ per connection in PostgreSQL). Use PgBouncer in transaction pooling mode.

```ini
; pgbouncer.ini
[databases]
ai_chathub_db = host=postgres port=5432 dbname=ai_chathub_db

[pgbouncer]
pool_mode        = transaction
max_client_conn  = 1000
default_pool_size= 20
server_pool_size = 5
```

**Pool sizes per service (Phase 1 without PgBouncer):**

| Service | Max Connections | Rationale |
|---------|----------------|-----------|
| Auth Service | 10 | Low volume, stateless JWTs |
| Subscription Service | 10 | Moderate volume |
| Wallet Service | 20 | High concurrency (AI requests lock wallet) |
| Payment Service | 10 | Moderate, mostly async |
| AI Gateway Service | 30 | Highest volume — every request touches this |
| Chat Service | 20 | High volume — every message writes here |
| Notification Service | 5 | Low volume, mostly async |
| Billing Service | 5 | Event-driven, low volume |

### 9.2 Laravel Database Configuration

```php
// config/database.php — per service
'pgsql' => [
    'driver'         => 'pgsql',
    'host'           => env('DB_HOST'),
    'port'           => env('DB_PORT', '5432'),
    'database'       => env('DB_DATABASE', 'ai_chathub_db'),
    'username'       => env('DB_USERNAME'),
    'password'       => env('DB_PASSWORD'),
    'options'        => ['search_path' => env('DB_SCHEMA')],
    'pool'           => [
        'min'     => 2,
        'max'     => env('DB_MAX_CONNECTIONS', 10),
    ],
]
```

---

## 10. Backup & Recovery

### 10.1 Backup Strategy

| Backup Type | Frequency | Retention | Tool |
|-------------|-----------|-----------|------|
| Full backup | Daily (02:00 UTC) | 30 days | `pg_dump` or managed service |
| WAL archiving | Continuous | 7 days | `pg_basebackup` + WAL streaming |
| Point-in-time recovery | Enabled | 7 days | Managed service PITR |

**Critical tables for priority restoration:**
1. `wallet_svc.wallets` + `wallet_svc.wallet_ledger_entries` — financial data
2. `payment_svc.transactions` — payment records
3. `auth_svc.users` + `subscription_svc.user_subscriptions` — user access

### 10.2 RTO / RPO Targets

| Metric | Target | Method |
|--------|--------|--------|
| RPO (max data loss) | < 5 minutes | WAL streaming with replica |
| RTO (max downtime) | < 30 minutes | Automated failover to read replica |

---

## 11. Phase 3 — Separate Databases

When traffic demands service isolation, the migration path is:

1. Add read replica for each high-volume schema
2. Route reporting queries to replica (usage_logs, ledger, messages)
3. Export each schema to separate database
4. Update environment variables to point each service to its own DB
5. Set up cross-service synced read tables if absolutely needed (avoid if possible)
6. Migrate `usage_logs` and `chat_messages` to ClickHouse for analytics

No application code changes are required in this migration — only infrastructure and connection strings change, because service code already only touches its own schema.

---

## 12. Sensitive Data Handling

| Field | Table | Handling |
|-------|-------|---------|
| `password` | `users` | bcrypt hash (cost 12). Never store plaintext. |
| `token` | `payment_methods` | Opaque gateway token. Never decrypt. |
| `payload` | `webhook_events` | Log but scrub before display (may contain card partial data) |
| `content` | `chat_messages` | Encrypted at rest (AES-256 via disk/tablespace encryption) |
| `token_hash` | `refresh_tokens` | SHA-256 hash of actual token. Never store raw token. |
| API keys | `.env` / Vault | Never in DB. Use environment variables or Vault. |

---

**END OF DATABASE DESIGN NOTES**
