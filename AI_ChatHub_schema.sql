-- =============================================================
-- AI ChatHub — Complete PostgreSQL Schema
-- Version 2.0 | Microservices Architecture
-- Organised by service domain / schema
-- =============================================================

-- ─────────────────────────────────────────────────────────────
-- EXTENSIONS
-- ─────────────────────────────────────────────────────────────
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- ─────────────────────────────────────────────────────────────
-- SCHEMAS (one per service domain)
-- ─────────────────────────────────────────────────────────────
CREATE SCHEMA IF NOT EXISTS auth_svc;
CREATE SCHEMA IF NOT EXISTS subscription_svc;
CREATE SCHEMA IF NOT EXISTS wallet_svc;
CREATE SCHEMA IF NOT EXISTS payment_svc;
CREATE SCHEMA IF NOT EXISTS billing_svc;
CREATE SCHEMA IF NOT EXISTS ai_svc;
CREATE SCHEMA IF NOT EXISTS chat_svc;
CREATE SCHEMA IF NOT EXISTS notification_svc;

-- =============================================================
-- AUTH SERVICE SCHEMA
-- =============================================================

CREATE TABLE auth_svc.users (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    email VARCHAR(255) UNIQUE NOT NULL,
    email_verified_at TIMESTAMP NULL,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    phone VARCHAR(50) NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pending_verification',
    preferred_currency VARCHAR(3) DEFAULT 'USD',
    avatar_url TEXT NULL,
    last_login_at TIMESTAMP NULL,
    last_login_ip INET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL
);

CREATE INDEX idx_users_email ON auth_svc.users(email);
CREATE INDEX idx_users_status ON auth_svc.users(status);
CREATE INDEX idx_users_created_at ON auth_svc.users(created_at);

COMMENT ON COLUMN auth_svc.users.status IS 'Enum: pending_verification, active, suspended, banned';

CREATE TABLE auth_svc.email_verifications (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL,
    token VARCHAR(255) UNIQUE NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_email_verif_user ON auth_svc.email_verifications(user_id);
CREATE INDEX idx_email_verif_token ON auth_svc.email_verifications(token);

CREATE TABLE auth_svc.password_resets (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL,
    token VARCHAR(255) UNIQUE NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_password_resets_user ON auth_svc.password_resets(user_id);

CREATE TABLE auth_svc.refresh_tokens (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL,
    token_hash VARCHAR(255) UNIQUE NOT NULL,
    revoked BOOLEAN DEFAULT FALSE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_refresh_tokens_user ON auth_svc.refresh_tokens(user_id);
CREATE INDEX idx_refresh_tokens_hash ON auth_svc.refresh_tokens(token_hash);

CREATE TABLE auth_svc.login_attempts (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    email VARCHAR(255) NOT NULL,
    ip_address INET NOT NULL,
    success BOOLEAN NOT NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_login_attempts_email ON auth_svc.login_attempts(email, created_at);
CREATE INDEX idx_login_attempts_ip ON auth_svc.login_attempts(ip_address, created_at);

-- =============================================================
-- SUBSCRIPTION SERVICE SCHEMA
-- =============================================================

CREATE TABLE subscription_svc.packages (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,
    description TEXT NULL,
    monthly_price_usd DECIMAL(10,2) NOT NULL,
    monthly_price_bdt DECIMAL(10,2) NULL,
    monthly_wallet_credit_usd DECIMAL(10,2) NOT NULL,
    model_access JSONB NOT NULL DEFAULT '[]',
    features JSONB NOT NULL DEFAULT '{}',
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_packages_slug ON subscription_svc.packages(slug);
CREATE INDEX idx_packages_active ON subscription_svc.packages(is_active);

COMMENT ON COLUMN subscription_svc.packages.model_access IS 'Array of model_id UUIDs';
COMMENT ON COLUMN subscription_svc.packages.features IS 'JSON: {file_upload:bool, api_access:bool, comparison:bool}';

CREATE TABLE subscription_svc.user_subscriptions (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL,
    package_id UUID NOT NULL REFERENCES subscription_svc.packages(id),
    previous_package_id UUID NULL REFERENCES subscription_svc.packages(id),
    scheduled_package_id UUID NULL REFERENCES subscription_svc.packages(id),
    status VARCHAR(50) NOT NULL DEFAULT 'active',
    auto_renew BOOLEAN DEFAULT TRUE,
    currency VARCHAR(3) DEFAULT 'USD',
    exchange_rate DECIMAL(10,6) DEFAULT 1.000000,
    payment_method_id UUID NULL,
    activated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    renews_at TIMESTAMP NOT NULL,
    cancelled_at TIMESTAMP NULL,
    cancellation_reason TEXT NULL,
    past_due_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX idx_sub_user_active
    ON subscription_svc.user_subscriptions(user_id)
    WHERE status IN ('active','past_due');

CREATE INDEX idx_sub_user ON subscription_svc.user_subscriptions(user_id);
CREATE INDEX idx_sub_renews ON subscription_svc.user_subscriptions(renews_at, auto_renew)
    WHERE status = 'active';
CREATE INDEX idx_sub_status ON subscription_svc.user_subscriptions(status);

COMMENT ON COLUMN subscription_svc.user_subscriptions.status IS
    'Enum: active, past_due, cancelled, expired';
COMMENT ON COLUMN subscription_svc.user_subscriptions.scheduled_package_id IS
    'Set when downgrade/upgrade is scheduled for next renewal cycle';

CREATE TABLE subscription_svc.subscription_history (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    subscription_id UUID NOT NULL REFERENCES subscription_svc.user_subscriptions(id),
    user_id UUID NOT NULL,
    action VARCHAR(50) NOT NULL,
    old_package_id UUID NULL REFERENCES subscription_svc.packages(id),
    new_package_id UUID NULL REFERENCES subscription_svc.packages(id),
    old_status VARCHAR(50) NULL,
    new_status VARCHAR(50) NULL,
    metadata JSONB NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_sub_history_sub ON subscription_svc.subscription_history(subscription_id);
CREATE INDEX idx_sub_history_user ON subscription_svc.subscription_history(user_id);
CREATE INDEX idx_sub_history_created ON subscription_svc.subscription_history(created_at);

COMMENT ON COLUMN subscription_svc.subscription_history.action IS
    'Enum: purchased, upgraded, downgraded, renewed, cancelled, suspended';

CREATE TABLE subscription_svc.renewal_attempts (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    subscription_id UUID NOT NULL REFERENCES subscription_svc.user_subscriptions(id),
    user_id UUID NOT NULL,
    attempt_number INT NOT NULL,
    scheduled_at TIMESTAMP NOT NULL,
    attempted_at TIMESTAMP NULL,
    success BOOLEAN NULL,
    error_message TEXT NULL,
    transaction_id UUID NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_renewal_attempts_sub ON subscription_svc.renewal_attempts(subscription_id);
CREATE INDEX idx_renewal_attempts_scheduled ON subscription_svc.renewal_attempts(scheduled_at, success)
    WHERE success IS NULL;

COMMENT ON TABLE subscription_svc.renewal_attempts IS
    'Tracks retry attempts for failed renewals (max 3 attempts)';

-- =============================================================
-- WALLET SERVICE SCHEMA
-- =============================================================

CREATE TABLE wallet_svc.wallets (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID UNIQUE NOT NULL,
    balance DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
    reserved_balance DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
    credit_balance DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
    credit_limit DECIMAL(10,2) NOT NULL DEFAULT 3.00,
    currency VARCHAR(3) DEFAULT 'USD',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_balance_non_negative CHECK (balance >= 0),
    CONSTRAINT chk_reserved_non_negative CHECK (reserved_balance >= 0),
    CONSTRAINT chk_credit_within_limit CHECK (credit_balance >= -(credit_limit))
);

CREATE INDEX idx_wallet_user ON wallet_svc.wallets(user_id);
CREATE INDEX idx_wallet_balance_low ON wallet_svc.wallets(balance) WHERE balance < 5.00;

COMMENT ON COLUMN wallet_svc.wallets.balance IS 'Usable wallet balance (always >= 0)';
COMMENT ON COLUMN wallet_svc.wallets.reserved_balance IS 'Locked for in-flight AI requests';
COMMENT ON COLUMN wallet_svc.wallets.credit_balance IS 'Negative when user owes, 0 when settled';
COMMENT ON COLUMN wallet_svc.wallets.credit_limit IS 'Max negative credit_balance allowed';

CREATE TABLE wallet_svc.wallet_ledger_entries (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    wallet_id UUID NOT NULL REFERENCES wallet_svc.wallets(id),
    user_id UUID NOT NULL,
    type VARCHAR(20) NOT NULL,
    amount DECIMAL(12,6) NOT NULL,
    balance_before DECIMAL(12,6) NOT NULL,
    balance_after DECIMAL(12,6) NOT NULL,
    description VARCHAR(255) NOT NULL,
    reference_type VARCHAR(50) NULL,
    reference_id UUID NULL,
    currency VARCHAR(3) DEFAULT 'USD',
    exchange_rate DECIMAL(10,6) DEFAULT 1.000000,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_wallet_ledger_wallet ON wallet_svc.wallet_ledger_entries(wallet_id, created_at DESC);
CREATE INDEX idx_wallet_ledger_user ON wallet_svc.wallet_ledger_entries(user_id, created_at DESC);
CREATE INDEX idx_wallet_ledger_ref ON wallet_svc.wallet_ledger_entries(reference_type, reference_id);

COMMENT ON COLUMN wallet_svc.wallet_ledger_entries.type IS
    'Enum: credit, debit, refund, credit_recovery, admin_adjustment';
COMMENT ON TABLE wallet_svc.wallet_ledger_entries IS
    'Append-only ledger. NEVER UPDATE OR DELETE rows. Financial audit trail.';

CREATE TABLE wallet_svc.credit_ledger (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    wallet_id UUID NOT NULL REFERENCES wallet_svc.wallets(id),
    user_id UUID NOT NULL,
    type VARCHAR(30) NOT NULL,
    amount DECIMAL(12,6) NOT NULL,
    credit_balance_before DECIMAL(12,6) NOT NULL,
    credit_balance_after DECIMAL(12,6) NOT NULL,
    description VARCHAR(255) NOT NULL,
    reference_id UUID NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_credit_ledger_wallet ON wallet_svc.credit_ledger(wallet_id, created_at DESC);
CREATE INDEX idx_credit_ledger_user ON wallet_svc.credit_ledger(user_id, created_at DESC);

COMMENT ON COLUMN wallet_svc.credit_ledger.type IS
    'Enum: credit_used (went negative), credit_recovered (settled via top-up/renewal)';
COMMENT ON TABLE wallet_svc.credit_ledger IS
    'Separate append-only ledger tracking credit buffer usage. Never update or delete.';

-- =============================================================
-- PAYMENT GATEWAY SERVICE SCHEMA
-- =============================================================

CREATE TABLE payment_svc.payment_methods (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL,
    gateway VARCHAR(50) NOT NULL,
    type VARCHAR(50) NOT NULL,
    token TEXT NOT NULL,
    last_four VARCHAR(4) NULL,
    card_brand VARCHAR(30) NULL,
    bank_name VARCHAR(100) NULL,
    mobile_number VARCHAR(20) NULL,
    expires_at DATE NULL,
    is_default BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_payment_methods_user ON payment_svc.payment_methods(user_id);
CREATE INDEX idx_payment_methods_default ON payment_svc.payment_methods(user_id, is_default)
    WHERE is_default = TRUE AND is_active = TRUE;

COMMENT ON COLUMN payment_svc.payment_methods.gateway IS
    'Enum: stripe, paypal, bkash, nagad, sslcommerz';
COMMENT ON COLUMN payment_svc.payment_methods.type IS
    'Enum: card, mobile_banking, bank_transfer';
COMMENT ON COLUMN payment_svc.payment_methods.token IS
    'Opaque gateway token — never store raw card numbers (PCI DSS)';

CREATE TABLE payment_svc.transactions (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL,
    type VARCHAR(50) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'USD',
    exchange_rate DECIMAL(10,6) DEFAULT 1.000000,
    gateway VARCHAR(50) NOT NULL,
    gateway_reference VARCHAR(255) NULL,
    payment_method_id UUID NULL REFERENCES payment_svc.payment_methods(id),
    idempotency_key VARCHAR(255) UNIQUE NOT NULL,
    description TEXT NULL,
    metadata JSONB NULL,
    error_message TEXT NULL,
    gateway_fee DECIMAL(10,2) NULL,
    net_amount DECIMAL(10,2) NULL,
    completed_at TIMESTAMP NULL,
    failed_at TIMESTAMP NULL,
    refunded_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX idx_transactions_idempotency ON payment_svc.transactions(idempotency_key);
CREATE INDEX idx_transactions_user ON payment_svc.transactions(user_id, created_at DESC);
CREATE INDEX idx_transactions_gateway_ref ON payment_svc.transactions(gateway, gateway_reference);
CREATE INDEX idx_transactions_status ON payment_svc.transactions(status);

COMMENT ON COLUMN payment_svc.transactions.type IS
    'Enum: subscription_purchase, subscription_renewal, wallet_topup, refund';
COMMENT ON COLUMN payment_svc.transactions.status IS
    'Enum: pending, completed, failed, refunded';
COMMENT ON COLUMN payment_svc.transactions.gateway_reference IS
    'Gateway unique transaction ID (e.g., Stripe charge ID)';
COMMENT ON COLUMN payment_svc.transactions.net_amount IS
    'Amount after gateway fees deducted';

CREATE TABLE payment_svc.webhook_events (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    gateway VARCHAR(50) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    gateway_reference VARCHAR(255) UNIQUE NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    payload JSONB NOT NULL,
    processed_at TIMESTAMP NULL,
    error_message TEXT NULL,
    transaction_id UUID NULL REFERENCES payment_svc.transactions(id),
    retry_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX idx_webhook_gateway_ref ON payment_svc.webhook_events(gateway, gateway_reference);
CREATE INDEX idx_webhook_status ON payment_svc.webhook_events(status, created_at);
CREATE INDEX idx_webhook_transaction ON payment_svc.webhook_events(transaction_id);

COMMENT ON COLUMN payment_svc.webhook_events.status IS
    'Enum: pending, processing, processed, failed';
COMMENT ON TABLE payment_svc.webhook_events IS
    'Prevents duplicate webhook processing (idempotency)';

-- =============================================================
-- BILLING SERVICE SCHEMA
-- =============================================================

CREATE TABLE billing_svc.invoices (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL,
    subscription_id UUID NULL,
    invoice_number VARCHAR(50) UNIQUE NOT NULL,
    type VARCHAR(50) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'USD',
    tax_amount DECIMAL(10,2) DEFAULT 0.00,
    total_amount DECIMAL(10,2) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'generated',
    transaction_id UUID NULL,
    pdf_url TEXT NULL,
    issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    due_at TIMESTAMP NULL,
    paid_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX idx_invoices_number ON billing_svc.invoices(invoice_number);
CREATE INDEX idx_invoices_user ON billing_svc.invoices(user_id, created_at DESC);
CREATE INDEX idx_invoices_status ON billing_svc.invoices(status);

COMMENT ON COLUMN billing_svc.invoices.type IS
    'Enum: subscription_purchase, subscription_renewal, subscription_upgrade';

CREATE TABLE billing_svc.receipts (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL,
    receipt_number VARCHAR(50) UNIQUE NOT NULL,
    type VARCHAR(50) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'USD',
    transaction_id UUID NULL,
    pdf_url TEXT NULL,
    issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX idx_receipts_number ON billing_svc.receipts(receipt_number);
CREATE INDEX idx_receipts_user ON billing_svc.receipts(user_id, created_at DESC);

COMMENT ON COLUMN billing_svc.receipts.type IS
    'Enum: wallet_topup, refund';

-- =============================================================
-- AI GATEWAY SERVICE SCHEMA
-- =============================================================

CREATE TABLE ai_svc.ai_models (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    provider VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    model_id VARCHAR(100) NOT NULL,
    type VARCHAR(50) NOT NULL,
    description TEXT NULL,
    context_window INT NULL,
    max_output_tokens INT NULL,
    capabilities JSONB DEFAULT '{}',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(provider, model_id)
);

CREATE INDEX idx_models_provider ON ai_svc.ai_models(provider, is_active);
CREATE INDEX idx_models_type ON ai_svc.ai_models(type);

COMMENT ON COLUMN ai_svc.ai_models.provider IS
    'Enum: openai, anthropic, gemini, xai, elevenlabs, mistral, ollama';
COMMENT ON COLUMN ai_svc.ai_models.type IS
    'Enum: text, vision, image_generation, audio_tts, audio_stt, embedding';
COMMENT ON COLUMN ai_svc.ai_models.capabilities IS
    'JSON: {streaming:bool, function_calling:bool, vision:bool, file_upload:bool}';

CREATE TABLE ai_svc.model_pricing (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    model_id UUID NOT NULL REFERENCES ai_svc.ai_models(id),
    pricing_type VARCHAR(30) NOT NULL,
    input_rate_per_million DECIMAL(10,6) NULL,
    output_rate_per_million DECIMAL(10,6) NULL,
    flat_rate_per_unit DECIMAL(10,4) NULL,
    currency VARCHAR(3) DEFAULT 'USD',
    effective_from TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    effective_until TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(model_id, pricing_type, effective_from)
);

CREATE INDEX idx_pricing_model ON ai_svc.model_pricing(model_id, is_active);
CREATE INDEX idx_pricing_effective ON ai_svc.model_pricing(effective_from, effective_until);

COMMENT ON COLUMN ai_svc.model_pricing.pricing_type IS
    'Enum: token_based, flat_per_request, flat_per_image, flat_per_minute, character_based';
COMMENT ON TABLE ai_svc.model_pricing IS
    'Separate pricing history for AI provider rate changes over time';

CREATE TABLE ai_svc.usage_logs (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL,
    session_id UUID NULL,
    message_id UUID NULL,
    model_id UUID NOT NULL REFERENCES ai_svc.ai_models(id),
    operation_type VARCHAR(50) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'completed',
    prompt_tokens INT DEFAULT 0,
    completion_tokens INT DEFAULT 0,
    total_tokens INT DEFAULT 0,
    estimated_cost DECIMAL(12,6) DEFAULT 0.000000,
    actual_cost DECIMAL(12,6) DEFAULT 0.000000,
    currency VARCHAR(3) DEFAULT 'USD',
    duration_ms INT NULL,
    provider_request_id VARCHAR(255) NULL,
    error_message TEXT NULL,
    metadata JSONB NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_usage_user ON ai_svc.usage_logs(user_id, created_at DESC);
CREATE INDEX idx_usage_session ON ai_svc.usage_logs(session_id);
CREATE INDEX idx_usage_model ON ai_svc.usage_logs(model_id, created_at DESC);
CREATE INDEX idx_usage_status ON ai_svc.usage_logs(status);

COMMENT ON COLUMN ai_svc.usage_logs.operation_type IS
    'Enum: text_chat, document_analysis, image_generation, audio_tts, audio_stt, vision, embedding';
COMMENT ON COLUMN ai_svc.usage_logs.status IS
    'Enum: completed, failed, refunded';

CREATE TABLE ai_svc.provider_fallback_rules (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    primary_model_id UUID NOT NULL REFERENCES ai_svc.ai_models(id),
    fallback_model_id UUID NOT NULL REFERENCES ai_svc.ai_models(id),
    trigger_conditions JSONB DEFAULT '{}',
    priority INT DEFAULT 1,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(primary_model_id, fallback_model_id)
);

CREATE INDEX idx_fallback_primary ON ai_svc.provider_fallback_rules(primary_model_id, is_active);

COMMENT ON COLUMN ai_svc.provider_fallback_rules.trigger_conditions IS
    'JSON: {timeout_ms:8000, error_codes:[429,500,503], max_retries:1}';

CREATE TABLE ai_svc.circuit_breaker_state (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    model_id UUID NOT NULL REFERENCES ai_svc.ai_models(id) UNIQUE,
    state VARCHAR(20) NOT NULL DEFAULT 'closed',
    failure_count INT DEFAULT 0,
    success_count INT DEFAULT 0,
    opened_at TIMESTAMP NULL,
    next_probe_at TIMESTAMP NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_circuit_breaker_model ON ai_svc.circuit_breaker_state(model_id);

COMMENT ON COLUMN ai_svc.circuit_breaker_state.state IS
    'Enum: closed (normal), open (blocking), half_open (probing)';

-- =============================================================
-- CHAT SERVICE SCHEMA
-- =============================================================

CREATE TABLE chat_svc.chat_sessions (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL,
    model_id UUID NOT NULL,
    title VARCHAR(255) DEFAULT 'New Chat',
    status VARCHAR(30) DEFAULT 'active',
    message_count INT DEFAULT 0,
    total_tokens INT DEFAULT 0,
    total_cost DECIMAL(12,6) DEFAULT 0.000000,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL
);

CREATE INDEX idx_sessions_user ON chat_svc.chat_sessions(user_id, updated_at DESC)
    WHERE deleted_at IS NULL;
CREATE INDEX idx_sessions_model ON chat_svc.chat_sessions(model_id);

CREATE TABLE chat_svc.chat_messages (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    session_id UUID NOT NULL REFERENCES chat_svc.chat_sessions(id),
    user_id UUID NOT NULL,
    role VARCHAR(20) NOT NULL,
    content TEXT NOT NULL,
    prompt_tokens INT DEFAULT 0,
    completion_tokens INT DEFAULT 0,
    total_tokens INT DEFAULT 0,
    cost DECIMAL(12,6) DEFAULT 0.000000,
    usage_log_id UUID NULL,
    provider_message_id VARCHAR(255) NULL,
    is_streaming BOOLEAN DEFAULT FALSE,
    metadata JSONB NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_messages_session ON chat_svc.chat_messages(session_id, created_at ASC);
CREATE INDEX idx_messages_user ON chat_svc.chat_messages(user_id, created_at DESC);

COMMENT ON COLUMN chat_svc.chat_messages.role IS 'Enum: user, assistant, system, tool';

CREATE TABLE chat_svc.file_attachments (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL,
    session_id UUID NULL REFERENCES chat_svc.chat_sessions(id),
    message_id UUID NULL REFERENCES chat_svc.chat_messages(id),
    file_name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_size BIGINT NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    storage_disk VARCHAR(50) DEFAULT 's3',
    storage_path TEXT NOT NULL,
    storage_url TEXT NULL,
    virus_scan_status VARCHAR(30) DEFAULT 'pending',
    virus_scan_at TIMESTAMP NULL,
    provider_file_id VARCHAR(255) NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_attachments_user ON chat_svc.file_attachments(user_id);
CREATE INDEX idx_attachments_session ON chat_svc.file_attachments(session_id);
CREATE INDEX idx_attachments_message ON chat_svc.file_attachments(message_id);

COMMENT ON COLUMN chat_svc.file_attachments.virus_scan_status IS
    'Enum: pending, clean, infected, skipped';
COMMENT ON COLUMN chat_svc.file_attachments.provider_file_id IS
    'ID stored with AI provider (from Laravel AI SDK Files API)';

-- =============================================================
-- NOTIFICATION SERVICE SCHEMA
-- =============================================================

CREATE TABLE notification_svc.notifications (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL,
    type VARCHAR(100) NOT NULL,
    channel VARCHAR(30) NOT NULL,
    subject VARCHAR(255) NULL,
    content TEXT NOT NULL,
    metadata JSONB NULL,
    status VARCHAR(30) DEFAULT 'pending',
    sent_at TIMESTAMP NULL,
    delivered_at TIMESTAMP NULL,
    failed_at TIMESTAMP NULL,
    opened_at TIMESTAMP NULL,
    error_message TEXT NULL,
    retry_count INT DEFAULT 0,
    idempotency_key VARCHAR(255) UNIQUE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_notif_user ON notification_svc.notifications(user_id, created_at DESC);
CREATE INDEX idx_notif_status ON notification_svc.notifications(status, created_at)
    WHERE status IN ('pending', 'failed');
CREATE INDEX idx_notif_type ON notification_svc.notifications(type);
CREATE INDEX idx_notif_idem ON notification_svc.notifications(idempotency_key);

COMMENT ON COLUMN notification_svc.notifications.channel IS
    'Enum: email, sms, push, in_app';
COMMENT ON COLUMN notification_svc.notifications.type IS
    'Enum: welcome, email_verification, subscription_receipt, renewal_receipt, etc.';

CREATE TABLE notification_svc.notification_preferences (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID UNIQUE NOT NULL,
    email_subscription_events BOOLEAN DEFAULT TRUE,
    email_payment_events BOOLEAN DEFAULT TRUE,
    email_low_balance BOOLEAN DEFAULT TRUE,
    email_marketing BOOLEAN DEFAULT FALSE,
    sms_critical_alerts BOOLEAN DEFAULT FALSE,
    push_enabled BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================================
-- ADMIN & AUDIT (shared across services — can live in auth schema)
-- =============================================================

CREATE TABLE auth_svc.admin_users (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL REFERENCES auth_svc.users(id),
    role VARCHAR(50) NOT NULL DEFAULT 'admin',
    permissions JSONB DEFAULT '{}',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_admin_user ON auth_svc.admin_users(user_id);
COMMENT ON COLUMN auth_svc.admin_users.role IS 'Enum: admin, super_admin, support_agent';

CREATE TABLE auth_svc.audit_logs (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    admin_user_id UUID NULL REFERENCES auth_svc.admin_users(id),
    actor_type VARCHAR(30) NOT NULL DEFAULT 'admin',
    action VARCHAR(100) NOT NULL,
    resource_type VARCHAR(50) NOT NULL,
    resource_id UUID NULL,
    old_values JSONB NULL,
    new_values JSONB NULL,
    ip_address INET NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_audit_admin ON auth_svc.audit_logs(admin_user_id, created_at DESC);
CREATE INDEX idx_audit_resource ON auth_svc.audit_logs(resource_type, resource_id);
CREATE INDEX idx_audit_action ON auth_svc.audit_logs(action, created_at DESC);

COMMENT ON TABLE auth_svc.audit_logs IS
    'Immutable log. Never update or delete rows. All admin actions stored here.';

-- =============================================================
-- SUPPORT TICKETING (Phase 2 — can live in a support schema)
-- =============================================================

CREATE SCHEMA IF NOT EXISTS support_svc;

CREATE TABLE support_svc.tickets (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL,
    subject VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    status VARCHAR(30) DEFAULT 'open',
    priority VARCHAR(20) DEFAULT 'normal',
    category VARCHAR(50) NULL,
    assigned_to UUID NULL REFERENCES auth_svc.admin_users(id),
    resolved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_tickets_user ON support_svc.tickets(user_id);
CREATE INDEX idx_tickets_status ON support_svc.tickets(status, created_at DESC);
CREATE INDEX idx_tickets_assigned ON support_svc.tickets(assigned_to)
    WHERE status NOT IN ('resolved', 'closed');

COMMENT ON COLUMN support_svc.tickets.status IS
    'Enum: open, in_progress, waiting_on_user, resolved, closed';
COMMENT ON COLUMN support_svc.tickets.priority IS 'Enum: low, normal, high, urgent';

CREATE TABLE support_svc.ticket_messages (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    ticket_id UUID NOT NULL REFERENCES support_svc.tickets(id),
    sender_id UUID NOT NULL,
    sender_type VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    is_internal BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_ticket_msg_ticket ON support_svc.ticket_messages(ticket_id, created_at ASC);

COMMENT ON COLUMN support_svc.ticket_messages.sender_type IS 'Enum: user, admin';
COMMENT ON COLUMN support_svc.ticket_messages.is_internal IS
    'True = internal note (not visible to user)';

-- =============================================================
-- PROMO CODES (Billing Service)
-- =============================================================

CREATE TABLE billing_svc.promo_codes (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    code VARCHAR(50) UNIQUE NOT NULL,
    type VARCHAR(30) NOT NULL,
    discount_amount DECIMAL(10,2) NULL,
    discount_percent DECIMAL(5,2) NULL,
    max_uses INT NULL,
    times_used INT DEFAULT 0,
    valid_from TIMESTAMP NOT NULL,
    valid_until TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX idx_promo_code ON billing_svc.promo_codes(code) WHERE is_active = TRUE;

COMMENT ON COLUMN billing_svc.promo_codes.type IS
    'Enum: flat_discount, percent_discount, free_wallet_credit';

CREATE TABLE billing_svc.user_promo_usage (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    promo_code_id UUID NOT NULL REFERENCES billing_svc.promo_codes(id),
    user_id UUID NOT NULL,
    transaction_id UUID NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(promo_code_id, user_id)
);

CREATE INDEX idx_promo_usage_user ON billing_svc.user_promo_usage(user_id);

-- =============================================================
-- SYSTEM CONFIGURATION (Admin managed settings)
-- =============================================================

CREATE TABLE auth_svc.system_config (
    key VARCHAR(100) PRIMARY KEY,
    value TEXT NOT NULL,
    description TEXT NULL,
    updated_by UUID NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO auth_svc.system_config (key, value, description) VALUES
    ('credit_buffer_default', '3.00',   'Default max credit buffer amount in USD'),
    ('low_balance_threshold', '5.00',   'Wallet balance below which low-balance alert fires'),
    ('critical_balance_threshold','1.00','Wallet balance for critical alert'),
    ('renewal_retry_1_hours',  '24',    'Hours after first renewal failure to retry'),
    ('renewal_retry_2_hours',  '48',    'Hours after first renewal failure to retry (attempt 2)'),
    ('renewal_retry_3_hours',  '72',    'Hours after first renewal failure to retry (attempt 3)'),
    ('max_file_size_standard', '10',    'Max file upload MB for Standard package'),
    ('max_file_size_pro',      '20',    'Max file upload MB for Pro package');

-- =============================================================
-- TRIGGERS & FUNCTIONS (Examples for auto-updates)
-- =============================================================

-- Auto-update updated_at timestamp on row changes
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Apply to all tables with updated_at column
CREATE TRIGGER trg_users_updated_at
    BEFORE UPDATE ON auth_svc.users
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER trg_wallets_updated_at
    BEFORE UPDATE ON wallet_svc.wallets
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER trg_subscriptions_updated_at
    BEFORE UPDATE ON subscription_svc.user_subscriptions
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER trg_packages_updated_at
    BEFORE UPDATE ON subscription_svc.packages
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER trg_transactions_updated_at
    BEFORE UPDATE ON payment_svc.transactions
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER trg_payment_methods_updated_at
    BEFORE UPDATE ON payment_svc.payment_methods
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER trg_models_updated_at
    BEFORE UPDATE ON ai_svc.ai_models
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER trg_sessions_updated_at
    BEFORE UPDATE ON chat_svc.chat_sessions
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- =============================================================
-- SEED DATA (Initial packages and models)
-- =============================================================

-- Packages
INSERT INTO subscription_svc.packages (id, name, slug, monthly_price_usd, monthly_price_bdt, monthly_wallet_credit_usd, sort_order) VALUES
    (uuid_generate_v4(), 'Basic',    'basic',    10.00, 1000.00, 10.00, 1),
    (uuid_generate_v4(), 'Standard', 'standard', 20.00, 2000.00, 20.00, 2),
    (uuid_generate_v4(), 'Pro',      'pro',      40.00, 4000.00, 40.00, 3);

-- AI Models (examples — IDs generated, populate model_access in packages after)
INSERT INTO ai_svc.ai_models (provider, name, model_id, type, context_window) VALUES
    ('openai',    'GPT-4o',               'gpt-4o',                       'text',   128000),
    ('openai',    'GPT-4o Mini',          'gpt-4o-mini',                  'text',   128000),
    ('anthropic', 'Claude 3.5 Sonnet',    'claude-3-5-sonnet-20241022',   'text',   200000),
    ('anthropic', 'Claude 3 Haiku',       'claude-3-haiku-20240307',      'text',   200000),
    ('gemini',    'Gemini 1.5 Pro',       'gemini-1.5-pro',               'text',   2000000),
    ('gemini',    'Gemini 1.5 Flash',     'gemini-1.5-flash',             'text',   1000000),
    ('xai',       'Grok Beta',            'grok-beta',                    'text',   131072),
    ('openai',    'DALL-E 3',             'dall-e-3',                     'image_generation', NULL),
    ('elevenlabs','ElevenLabs Turbo v2.5','eleven_turbo_v2_5',            'audio_tts',        NULL),
    ('openai',    'Whisper',              'whisper-1',                    'audio_stt',        NULL);

-- =============================================================
-- COMMENTS & DOCUMENTATION
-- =============================================================

COMMENT ON SCHEMA auth_svc IS 'Auth Service: Users, authentication, admin roles, audit logs';
COMMENT ON SCHEMA subscription_svc IS 'Subscription Service: Packages, user subscriptions, renewals';
COMMENT ON SCHEMA wallet_svc IS 'Wallet Service: Balance management, ledgers, credit buffer';
COMMENT ON SCHEMA payment_svc IS 'Payment Gateway Service: Transactions, payment methods, webhooks';
COMMENT ON SCHEMA billing_svc IS 'Billing Service: Invoices, receipts, promo codes';
COMMENT ON SCHEMA ai_svc IS 'AI Gateway Service: Models, pricing, usage logs, failover rules';
COMMENT ON SCHEMA chat_svc IS 'Chat Service: Sessions, messages, file attachments';
COMMENT ON SCHEMA notification_svc IS 'Notification Service: Emails, SMS, push, preferences';
COMMENT ON SCHEMA support_svc IS 'Support Service: Tickets, ticket messages (Phase 2)';

-- =============================================================
-- END OF SCHEMA
-- =============================================================
