# AI ChatHub — Phase 1 Development Guide

**The single document you work from, start to finish.**  
Every step is in order. Complete each one before moving to the next.

---

## Prerequisites

Install these before starting:

| Tool | Version | Check |
|------|---------|-------|
| Docker Desktop | Latest | `docker --version` |
| Node.js | 20+ | `node --version` |
| PHP | 8.3+ | `php --version` |
| Composer | 2.7+ | `composer --version` |
| Git | Any | `git --version` |

---

## Step 1 — Clone & Bootstrap

```bash
# 1. Clone (or open) the monorepo
cd "C:\Users\IT News\Downloads\aichathub\aichathub"

# 2. Copy all .env files — fill them in before docker-compose up
copy services\auth-service\.env.example         services\auth-service\.env
copy services\subscription-service\.env.example services\subscription-service\.env
copy services\wallet-service\.env.example       services\wallet-service\.env
copy services\payment-service\.env.example      services\payment-service\.env
copy services\ai-gateway-service\.env.example   services\ai-gateway-service\.env
copy services\chat-service\.env.example         services\chat-service\.env
copy services\billing-service\.env.example      services\billing-service\.env
copy services\notification-service\.env.example services\notification-service\.env
copy services\api-gateway\.env.example          services\api-gateway\.env
copy frontend\.env.example                      frontend\.env.local

# 3. Generate APP_KEY for every service (run inside each service folder later)
# Done in Step 3 after composer install
```

---

## Step 2 — Fill in .env Files

The minimum values you MUST set before anything works:

### All services — change these two in every .env:
```
APP_KEY=         ← generated in Step 3
INTERNAL_SERVICE_KEY=some-long-random-string-same-across-all-services
```

### auth-service/.env — Google OAuth (get from Google Cloud Console):
```
GOOGLE_CLIENT_ID=xxxxx.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=xxxxx
GOOGLE_REDIRECT_URI=http://localhost:8001/api/v1/auth/google/callback
JWT_SECRET=at-least-32-character-random-string
```

### payment-service/.env — Stripe test keys:
```
STRIPE_SECRET_KEY=sk_test_xxxxx
STRIPE_WEBHOOK_SECRET=whsec_xxxxx   ← from Stripe CLI (Step 7)
STRIPE_PUBLISHABLE_KEY=pk_test_xxxxx
```

### ai-gateway-service/.env — at least one provider key:
```
OPENAI_API_KEY=sk-xxxxx     ← Start with just OpenAI for MVP
```

### frontend/.env.local:
```
NEXT_PUBLIC_API_URL=http://localhost:8000
NEXT_PUBLIC_GOOGLE_CLIENT_ID=xxxxx.apps.googleusercontent.com
```

---

## Step 3 — Install Dependencies for Every Service

Run these in parallel in separate terminals or one after another:

```bash
# Auth Service
cd services\auth-service
composer install
php artisan key:generate

# Subscription Service
cd services\subscription-service
composer install
php artisan key:generate

# Wallet Service
cd services\wallet-service
composer install
php artisan key:generate

# Payment Service
cd services\payment-service
composer install
php artisan key:generate

# AI Gateway Service
cd services\ai-gateway-service
composer install
php artisan key:generate

# Chat Service
cd services\chat-service
composer install
php artisan key:generate

# Billing Service
cd services\billing-service
composer install
php artisan key:generate

# Notification Service
cd services\notification-service
composer install
php artisan key:generate

# API Gateway
cd services\api-gateway
composer install
php artisan key:generate

# Frontend
cd frontend
npm install
```

---

## Step 4 — Start Docker Infrastructure

```bash
# From monorepo root
cd "C:\Users\IT News\Downloads\aichathub\aichathub"

# Start only infrastructure first (Postgres, Redis, Mailpit, MinIO)
docker-compose up -d postgres redis mailpit minio

# Verify they are running
docker-compose ps
```

**Expected output — all 4 should show "Up":**
```
aichathub-postgres   Up   0.0.0.0:5432->5432/tcp
aichathub-redis      Up   0.0.0.0:6379->6379/tcp
aichathub-mailpit    Up   0.0.0.0:1025->1025/tcp, 0.0.0.0:8025->8025/tcp
aichathub-minio      Up   0.0.0.0:9000->9000/tcp, 0.0.0.0:9001->9001/tcp
```

**Access local tools:**
- Mailpit (email UI): http://localhost:8025
- MinIO (file storage UI): http://localhost:9001 (user: minioadmin / pass: minioadmin)

---

## Step 5 — Run Database Migrations (in dependency order)

The schemas were created by `infrastructure/docker/postgres/init.sql` when postgres started.  
Now run Laravel migrations for each service in the correct order:

```bash
# Phase 1 — No FK dependencies
cd services\auth-service         && php artisan migrate
cd ..\..\services\subscription-service && php artisan migrate

# Phase 2 — Depends on auth schema
cd ..\wallet-service       && php artisan migrate
cd ..\payment-service      && php artisan migrate
cd ..\ai-gateway-service   && php artisan migrate
cd ..\billing-service      && php artisan migrate
cd ..\notification-service && php artisan migrate

# Phase 3 — Depends on above
cd ..\chat-service && php artisan migrate
```

**Verify migrations worked:**
```bash
# Connect to postgres and check
docker exec -it aichathub-postgres psql -U postgres -d ai_chathub_db -c "\dt auth_svc.*"
docker exec -it aichathub-postgres psql -U postgres -d ai_chathub_db -c "\dt wallet_svc.*"
```

---

## Step 6 — Seed Initial Data

```bash
# Seed subscription packages (Basic/Standard/Pro)
cd services\subscription-service
php artisan db:seed --class=PackageSeeder

# Seed AI models (12 models across tiers)
cd ..\ai-gateway-service
php artisan db:seed --class=ModelSeeder

# The ModelSeeder prints the model IDs — copy them
# Then update packages.model_access with correct IDs:
cd ..\subscription-service
php artisan tinker
```

Inside tinker, update packages with the model IDs printed by ModelSeeder:
```php
// Replace these UUIDs with the actual ones printed by ModelSeeder
$basicModels    = ['uuid-of-gpt4o-mini', 'uuid-of-claude-haiku', 'uuid-of-gemini-flash', 'uuid-of-grok'];
$standardModels = array_merge($basicModels, ['uuid-of-gpt4o', 'uuid-of-claude-sonnet', 'uuid-of-gemini-pro']);
$proModels      = array_merge($standardModels, ['uuid-of-gpt4-turbo', 'uuid-of-claude-opus', 'uuid-of-dalle3', 'uuid-of-elevenlabs', 'uuid-of-whisper']);

App\Models\Package::where('slug', 'basic')->update(['model_access' => json_encode($basicModels)]);
App\Models\Package::where('slug', 'standard')->update(['model_access' => json_encode($standardModels)]);
App\Models\Package::where('slug', 'pro')->update(['model_access' => json_encode($proModels)]);
exit
```

---

## Step 7 — Set Up Stripe Webhook (Local Testing)

Install Stripe CLI and forward webhooks to your local payment service:

```bash
# Install Stripe CLI (Windows — download from https://stripe.com/docs/stripe-cli)
# Then login
stripe login

# Forward webhooks to local payment service
stripe listen --forward-to http://localhost:8004/api/v1/webhooks/stripe

# Copy the webhook signing secret printed by the CLI
# → Paste it into services\payment-service\.env as STRIPE_WEBHOOK_SECRET
```

---

## Step 8 — Start All Services

```bash
# From monorepo root — start all services + frontend
docker-compose up -d

# Watch logs for any startup errors
docker-compose logs -f auth-service
docker-compose logs -f wallet-service
docker-compose logs -f ai-gateway-service
```

**All service URLs:**

| Service | URL |
|---------|-----|
| **API Gateway** (main entry point) | http://localhost:8000 |
| Auth Service | http://localhost:8001 |
| Subscription Service | http://localhost:8002 |
| Wallet Service | http://localhost:8003 |
| Payment Service | http://localhost:8004 |
| AI Gateway | http://localhost:8005 |
| Chat Service | http://localhost:8006 |
| Billing Service | http://localhost:8007 |
| Notification Service | http://localhost:8008 |
| **Frontend** | http://localhost:3000 |

**Verify health of all services:**
```bash
curl http://localhost:8000/health
curl http://localhost:8001/api/v1/health
curl http://localhost:8002/api/v1/health
curl http://localhost:8003/api/v1/health
curl http://localhost:8004/api/v1/health
curl http://localhost:8005/api/v1/health
curl http://localhost:8006/api/v1/health
curl http://localhost:8007/api/v1/health
```
Every response should be: `{"status":"ok","service":"..."}`.

---

## Step 9 — Start Queue Workers (Event Bus Listeners)

Each service that listens to events needs a queue worker running.  
Open a terminal for each or use a supervisor in production.

```bash
# Wallet Service — listens for subscription.purchased, payment.succeeded
docker exec -it aichathub-wallet php artisan queue:work redis --queue=subscription-events,payment-events --tries=3

# Notification Service — listens for all events that trigger emails
docker exec -it aichathub-notification php artisan queue:work redis --queue=subscription-events,payment-events,wallet-events --tries=3

# Billing Service — listens for subscription.purchased/renewed to generate invoices
docker exec -it aichathub-billing php artisan queue:work redis --queue=subscription-events,payment-events --tries=3

# Subscription Service — runs renewal scheduler (also needs queue worker for jobs)
docker exec -it aichathub-subscription php artisan queue:work redis --tries=3

# Schedule the renewal command (runs hourly)
docker exec -it aichathub-subscription php artisan schedule:work
```

---

## Step 10 — Set Up Google OAuth (Google Cloud Console)

1. Go to https://console.cloud.google.com
2. Create a new project: **AI ChatHub**
3. Go to **APIs & Services → Credentials**
4. Click **Create Credentials → OAuth 2.0 Client ID**
5. Application type: **Web application**
6. Add Authorized JavaScript origins:
   - `http://localhost:3000`
7. Add Authorized redirect URIs:
   - `http://localhost:8001/api/v1/auth/google/callback`
8. Copy **Client ID** and **Client Secret**
9. Paste into:
   - `services/auth-service/.env` → `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`
   - `frontend/.env.local` → `NEXT_PUBLIC_GOOGLE_CLIENT_ID`
10. Restart auth-service: `docker-compose restart auth-service`

**Test the Google OAuth flow:**
1. Open http://localhost:3000/login
2. Click "Continue with Google"
3. Complete Google consent
4. Should redirect to http://localhost:3000/chat
5. Check Mailpit (http://localhost:8025) for welcome email

---

## Step 11 — Verify End-to-End Flow

Run through the entire happy path manually:

### A. Registration (Email)
```
1. Go to http://localhost:3000/register
2. Fill in name, email, password, currency
3. Submit → "Check your email" page shown
4. Open Mailpit → click verification link
5. User status becomes 'active'
```

### B. Registration (Google)
```
1. Go to http://localhost:3000/login
2. Click "Continue with Google"
3. Approve Google consent
4. Redirected to /auth/callback → then /chat
5. Wallet auto-created (check wallet-service logs)
6. Welcome email in Mailpit
```

### C. Subscribe to Package
```bash
# Test via API (or build the subscription UI page)
curl -X POST http://localhost:8000/api/v1/subscription/subscribe \
  -H "Authorization: Bearer YOUR_JWT" \
  -H "Content-Type: application/json" \
  -d '{"package_slug":"basic","payment_method_token":"pm_card_visa","currency":"USD"}'

# Expected:
# - Subscription created (status: active)
# - Wallet credited with $10
# - Invoice generated (check billing-service logs)
# - Receipt email in Mailpit
```

### D. Chat Request
```bash
curl -X POST http://localhost:8000/api/v1/chat/stream \
  -H "Authorization: Bearer YOUR_JWT" \
  -H "Content-Type: application/json" \
  -d '{"message":"Hello! What can you do?","model_id":"gpt-4o-mini"}'

# Expected:
# - SSE stream returns AI response
# - Wallet balance decreases by actual token cost
# - Usage log created in ai-gateway-service DB
# - Message stored in chat-service DB
```

### E. Wallet Top-up
```bash
curl -X POST http://localhost:8000/api/v1/topup \
  -H "Authorization: Bearer YOUR_JWT" \
  -H "Content-Type: application/json" \
  -d '{"amount":20,"currency":"USD","gateway":"stripe","payment_method_token":"pm_card_visa"}'

# Expected:
# - Stripe test charge processed
# - Webhook fires → wallet credited
# - If user had credit_balance < 0 → settled first
# - Receipt email in Mailpit
```

---

## Phase 1 Build Order (Week by Week)

Follow this sequence — each week builds on the previous one.

### Week 1–2: Foundation
- [ ] Docker environment running (all services healthy)
- [ ] All migrations applied
- [ ] Auth Service: register, email verify, login, JWT working
- [ ] Google OAuth working end-to-end
- [ ] Wallet auto-created on user registration
- [ ] Frontend: login + register pages working

### Week 3–4: Subscription Core
- [ ] Subscription Service: package list, subscribe endpoint
- [ ] Payment Service: Stripe charge + webhook handler
- [ ] Saga: purchase → charge → wallet credit → invoice
- [ ] Wallet Service: credit on subscription.purchased event
- [ ] Frontend: pricing page + subscribe flow

### Week 5–6: AI Chat MVP
- [ ] AI Gateway: OpenAI GPT-4o-mini streaming chat
- [ ] Model access check (subscription tier → model list)
- [ ] Balance reserve → deduct → refund flow
- [ ] Chat Service: session + message storage
- [ ] Frontend: basic chat interface with SSE streaming

### Week 7–8: Billing & Wallet UI
- [ ] Wallet balance display (real-time via polling or WebSocket)
- [ ] Wallet ledger history page
- [ ] Top-up flow (Stripe card)
- [ ] Invoice/receipt list page
- [ ] Frontend: wallet page, billing history

### Week 9–10: Auto-Renewal + Admin + Polish
- [ ] Subscription renewal scheduler (ProcessRenewalsCommand)
- [ ] Renewal retry logic (3 attempts over 72 hours)
- [ ] Low/critical balance notifications
- [ ] Basic admin panel (user list, transaction viewer)
- [ ] End-to-end smoke testing
- [ ] Bug fixing + launch preparation

---

## Common Issues & Fixes

### "Connection refused" to a service
```bash
# Check the service is running
docker-compose ps

# Check its logs for startup errors
docker-compose logs auth-service

# Restart it
docker-compose restart auth-service
```

### Migration fails — "schema does not exist"
```bash
# The init.sql creates schemas on first postgres start
# If postgres was started before init.sql existed, recreate volume:
docker-compose down -v  # WARNING: destroys all data
docker-compose up -d postgres
# Wait 5 seconds then re-run migrations
```

### Google OAuth "redirect_uri_mismatch"
- Ensure `GOOGLE_REDIRECT_URI` in auth-service/.env exactly matches the redirect URI in Google Cloud Console
- Must be: `http://localhost:8001/api/v1/auth/google/callback`
- No trailing slash

### JWT "Invalid token" between services
- All services must have the same `JWT_SECRET` value
- The API Gateway validates the JWT — its `JWT_SECRET` must match auth-service
- After changing JWT_SECRET, all existing tokens are invalidated

### Stripe webhook "Invalid signature"
- The `STRIPE_WEBHOOK_SECRET` must come from `stripe listen` output
- It changes every time you restart `stripe listen`
- Format: `whsec_...`

### Wallet balance discrepancy
```bash
# Run the reconciliation query from DB Design Notes
docker exec -it aichathub-postgres psql -U postgres -d ai_chathub_db -c "
SELECT user_id, balance,
    SUM(CASE WHEN type IN ('credit','refund') THEN amount WHEN type='debit' THEN -amount ELSE 0 END) AS ledger_sum
FROM wallet_svc.wallets w
JOIN wallet_svc.wallet_ledger_entries l ON l.wallet_id = w.id
GROUP BY user_id, balance
HAVING ABS(balance - SUM(CASE WHEN type IN ('credit','refund') THEN amount WHEN type='debit' THEN -amount ELSE 0 END)) > 0.000001;"
```
If this returns rows, a wallet operation ran outside a DB transaction — find and fix the service code.

### Queue worker not processing events
```bash
# Check Redis is running
docker exec -it aichathub-redis redis-cli ping  # Should return PONG

# Check what's in the queue
docker exec -it aichathub-redis redis-cli LLEN queues:default

# Check failed jobs
docker exec -it aichathub-wallet php artisan queue:failed
```

---

## File Structure Reference

```
aichathub/
├── services/
│   ├── auth-service/              Laravel 12 — JWT + Google OAuth
│   ├── subscription-service/      Laravel 12 — Packages + Lifecycle
│   ├── wallet-service/            Laravel 12 — Balance + Ledger
│   ├── payment-service/           Laravel 12 — Stripe + Webhooks
│   ├── ai-gateway-service/        Laravel 12 + laravel/ai SDK
│   ├── chat-service/              Laravel 12 — Sessions + Messages
│   ├── billing-service/           Laravel 12 — Invoices + Receipts
│   ├── notification-service/      Laravel 12 — Email + Push
│   └── api-gateway/               Laravel 12 — Proxy + Rate Limit
├── frontend/                      Next.js 14 + TypeScript
├── infrastructure/
│   ├── docker/php/Dockerfile      Base PHP 8.3-FPM image
│   ├── docker/nginx/default.conf  Nginx config with SSE support
│   └── docker/postgres/init.sql   Creates schemas + DB users
├── docker-compose.yml             Full local dev stack
├── PHASE1_DEV_GUIDE.md            This file
└── docs/                          All specification documents
```

---

## Development Workflow (Daily)

```bash
# Start everything
docker-compose up -d

# Watch logs for the service you're working on
docker-compose logs -f auth-service

# Run artisan commands inside a container
docker exec -it aichathub-auth php artisan migrate
docker exec -it aichathub-auth php artisan tinker

# Run tests
docker exec -it aichathub-auth php artisan test

# Restart a specific service after code change
docker-compose restart auth-service

# Stop everything at end of day
docker-compose stop
```

---

## Service Implementation Checklist

Use this to track what's built vs. what's scaffolded (routes exist but controller bodies need completing).

### Auth Service
- [x] Scaffolded — routes, migration, models, GoogleOAuthService, JwtService
- [ ] Complete RegisterController
- [ ] Complete LoginController
- [ ] Complete EmailVerificationController (send + verify)
- [ ] Complete PasswordResetController
- [ ] Complete LogoutController
- [ ] Complete SocialAccountController (link/unlink Google)
- [ ] Complete Internal UserController
- [ ] Wire event publishing (UserRegistered)
- [ ] Add `auth.internal` middleware
- [ ] Add `auth.jwt` middleware

### Subscription Service
- [x] Scaffolded — migration, Package/UserSubscription models, SubscriptionService, PackageSeeder
- [ ] Complete PackageController (index, show)
- [ ] Complete SubscriptionController (current, subscribe, upgrade, downgrade, cancel, history)
- [ ] Complete ProcessRenewalJob
- [ ] Complete RetryRenewalJob
- [ ] Wire event bus listeners (payment.succeeded → subscribe)
- [ ] Wire renewal scheduler

### Wallet Service
- [x] Scaffolded — migration with DB constraints, WalletService (full), WalletInternalController (full)
- [ ] Complete WalletController (balance display)
- [ ] Complete LedgerController (paginated history)
- [ ] Wire event listeners (subscription.purchased, payment.succeeded)
- [ ] Add `auth.internal` middleware

### Payment Service
- [x] Scaffolded — migration, StripeGateway, StripeWebhookController, PaymentInternalController
- [ ] Complete PaymentMethodController (CRUD)
- [ ] Complete TopupController (initiate + status)
- [ ] Complete TransactionController (list + show)
- [ ] Complete ProcessStripeWebhookJob
- [ ] Add BkashWebhookController

### AI Gateway Service
- [x] Scaffolded — migration, TextChatAgent, CostTrackingMiddleware, ChatController, WalletClientService, ModelSeeder
- [ ] Complete ModelController (list models filtered by subscription)
- [ ] Complete ImageController (DALL-E 3)
- [ ] Complete AudioController (TTS)
- [ ] Complete TranscriptionController (Whisper)
- [ ] Complete SubscriptionClientService
- [ ] Complete UsageLoggingMiddleware
- [ ] Wire model pricing lookup into CostTrackingMiddleware

### Chat Service
- [x] Scaffolded — migration (sessions/messages/attachments), routes
- [ ] Complete SessionController (CRUD + export)
- [ ] Complete MessageController (list + store)
- [ ] Complete FileAttachmentController (upload + delete)
- [ ] Wire event listener (ai_request.completed → store messages)

### Billing Service
- [x] Scaffolded — migration (invoices/receipts/promo_codes), routes
- [ ] Complete InvoiceController
- [ ] Complete ReceiptController
- [ ] Wire event listener (subscription.purchased/renewed → create invoice)

### Notification Service
- [x] Scaffolded — migration, SendWelcomeEmail listener
- [ ] Create WelcomeMail Mailable
- [ ] Create SubscriptionReceiptMail
- [ ] Create RenewalFailedMail
- [ ] Create LowBalanceMail
- [ ] Wire all event listeners
- [ ] Add NotificationPreferences controller

### API Gateway
- [x] Scaffolded — ProxyController, full route map
- [ ] Add JwtGatewayMiddleware (validate JWT, extract user, pass headers)
- [ ] Add rate limiting middleware
- [ ] Add CORS middleware

### Frontend
- [x] Scaffolded — package.json, types, stores, api-client, login page, register page, callback page, GoogleSignInButton
- [ ] Middleware for route protection (redirect /chat to /login if not authenticated)
- [ ] Dashboard layout with sidebar
- [ ] Chat interface (session list + message stream + model selector)
- [ ] Wallet page (balance card + ledger table)
- [ ] Billing page (subscription card + invoice list)
- [ ] Settings page (profile + connected accounts)
- [ ] Subscribe / upgrade flow

---

## What to Build First (Suggested Daily Order)

**Day 1:** Get auth-service fully working (register, verify, login, Google OAuth). Test every endpoint with Postman or curl. Don't move on until auth is solid — everything else depends on it.

**Day 2:** Get wallet-service and subscription-service wired. Manually trigger a `subscription.purchased` event and verify wallet gets credited. Check ledger entries are written.

**Day 3:** Get payment-service Stripe flow working. Real test charge using Stripe test cards. Webhook → transaction complete → event published → wallet credited.

**Day 4:** Get ai-gateway-service streaming a chat response. One model (GPT-4o-mini), one user, balance reserve/deduct cycle.

**Day 5:** Wire the frontend login page through to the chat page. Real tokens, real balance, real AI response.

After that, build features in vertical slices — one complete user journey at a time — rather than finishing all backend before touching frontend.

---

## API Keys — Where to Get Them

| Service | Where | Notes |
|---------|-------|-------|
| OpenAI | https://platform.openai.com/api-keys | Start with GPT-4o-mini for lower cost |
| Anthropic | https://console.anthropic.com | Claude Haiku is cheapest |
| Gemini | https://aistudio.google.com/app/apikey | Free tier available |
| xAI | https://console.x.ai | Grok Beta |
| ElevenLabs | https://elevenlabs.io | Only needed for Pro tier TTS |
| Stripe | https://dashboard.stripe.com/test/apikeys | Use test keys initially |
| Google OAuth | https://console.cloud.google.com/apis/credentials | Create OAuth 2.0 Client ID |

---

**You have everything you need to start building. Begin with Day 1 above.**
