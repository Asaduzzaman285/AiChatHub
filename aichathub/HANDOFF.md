# AI ChatHub — Development Handoff Document
**Last updated:** 2026-07-16  
**Repo:** https://github.com/Asaduzzaman285/AiChatHub  
**Local path:** `C:\Users\IT News\Downloads\aichathub\aichathub`  
**Branch:** main

---

## How to Start Everything Tomorrow

```bash
cd "C:\Users\IT News\Downloads\aichathub\aichathub"

# 1. Start all backend services
docker-compose up -d

# 2. Start auth queue worker (sends emails + creates wallets after registration)
docker exec -d aichathub-auth php artisan queue:work redis --tries=3 --sleep=3

# 3. Start frontend
cd frontend
npm run dev
# Frontend: http://localhost:3000
```

---

## What Is Fully Working Right Now

### Infrastructure
| Service | URL | Status |
|---|---|---|
| API Gateway | http://localhost:8000 | ✅ |
| Auth Service | http://localhost:8001 | ✅ |
| Subscription Service | http://localhost:8002 | ✅ |
| Wallet Service | http://localhost:8003 | ✅ |
| Payment Service | http://localhost:8004 | ✅ |
| AI Gateway | http://localhost:8005 | ✅ |
| Chat Service | http://localhost:8006 | ✅ |
| Billing Service | http://localhost:8007 | ✅ |
| Notification Service | http://localhost:8008 | ✅ |
| Frontend | http://localhost:3000 | ✅ |
| Mailpit (email UI) | http://localhost:8025 | ✅ |
| MinIO (file storage) | http://localhost:9001 | ✅ |

### Verified Working Endpoints

```bash
# Registration (via API Gateway)
POST http://localhost:8000/api/v1/auth/register
→ 201: user created, email queued, wallet auto-created after response

# Login
POST http://localhost:8000/api/v1/auth/login
→ 200: access_token + refresh_token (JWT, expires 24h)

# Get current user
GET http://localhost:8000/api/v1/auth/me
Authorization: Bearer {token}
→ 200: user profile

# Refresh token
POST http://localhost:8000/api/v1/auth/refresh
→ 200: new JWT pair

# Firebase Google Sign-In
POST http://localhost:8000/api/v1/auth/firebase
body: {"id_token": "<Firebase ID token from frontend>"}
→ 200: JWT pair + user profile (wallet auto-created for new users)

# Email verification
GET http://localhost:8001/api/v1/auth/verify/{token}
→ 200: account activated (token comes from Mailpit email)

# List packages
GET http://localhost:8000/api/v1/packages
→ 200: Basic ($10), Standard ($20), Pro ($40)

# Wallet balance (internal — not exposed to frontend yet)
GET http://wallet-nginx/api/internal/wallet/{userId}
Header: X-Internal-Service-Key: internal-secret-change-in-production
→ 200: balance, currency, credit info
```

### Database State
- **PostgreSQL schemas:** all 9 created (auth_svc, wallet_svc, subscription_svc, etc.)
- **Migrations:** auth, wallet, subscription all applied
- **Packages seeded:** Basic, Standard, Pro in subscription_svc.packages
- **Users in DB:** multiple test users
- **Wallets in DB:** 9+ wallets, auto-created on registration

### Frontend
- Login page: http://localhost:3000/login — styled with Tailwind ✅
- Register page: http://localhost:3000/register ✅
- Google Sign-In button visible and wired to Firebase SDK ✅
- Tailwind CSS working (postcss.config.js added) ✅
- Auth store (Zustand) persists JWT to localStorage ✅

---

## Architecture Decisions Made

| Decision | What was chosen |
|---|---|
| Social login | Firebase Auth SDK (handles Google, future: Facebook/Apple) |
| Auth tokens | JWT via tymon/jwt-auth (stateless, works across services) |
| Cache | Redis only — no DB cache table anywhere |
| Sessions | None — pure API, no cookies/sessions |
| Spatie Permission | Disabled (dont-discover) — auth service doesn't need roles |
| Sanctum | Disabled (dont-discover) — using JWT not Sanctum tokens |
| Wallet creation | afterResponse() HTTP call from auth to wallet-service |
| Event bus | Synchronous HTTP calls between services (simple, reliable) |

---

## Key Files — What Each Does

### Auth Service (`services/auth-service/`)
```
bootstrap/app.php           — middleware aliases, apiPrefix: 'api/v1', no statefulApi()
config/auth.php             — JWT guard: 'api' using tymon/jwt-auth
config/cache.php            — Redis only (no database store)
config/firebase.php         — Kreait v6 format: projects.app.credentials
config/services.php         — wallet_url, internal_key config keys
config/jwt.php              — JWT_LEEWAY=60 (handles clock drift)
routes/api.php              — all auth routes (no Route::prefix wrapper)
routes/internal.php         — /api/internal/users/* for other services
app/Http/Controllers/V1/Auth/
  RegisterController.php    — creates user + dispatches afterResponse() for email+wallet
  LoginController.php       — validates + issues JWT
  FirebaseAuthController.php — verifies Firebase token → creates/finds user → JWT
  EmailVerificationController.php — verify token + resend
  LogoutController.php      — invalidates JWT + revokes refresh tokens
  TokenRefreshController.php — rotates refresh token pair
app/Services/JwtService.php — issueTokens(), rotateRefreshToken(), revokeAll()
app/Listeners/SendVerificationEmail.php — queued: sends email via Mailpit
app/Events/UserRegistered.php — fired after registration
firebase-service-account.json — NOT in git, must be present on server
```

### API Gateway (`services/api-gateway/`)
```
config/services.php         — ALL downstream service URLs (auth_url, wallet_url, etc.)
routes/api.php              — proxy routes: /auth/* → proxyAuth(), etc.
app/Http/Controllers/Proxy/ProxyController.php — forwards requests to services
app/Http/Middleware/JwtGatewayMiddleware.php — validates JWT, passes X-User-Id header
```

### Wallet Service (`services/wallet-service/`)
```
routes/internal.php         — /api/internal/wallet/create|credit|reserve|deduct|refund|show
app/Http/Controllers/Internal/WalletInternalController.php — create() and balance operations
app/Services/WalletService.php — createForUser(), credit(), debit(), reserve(), refund()
```

### Subscription Service (`services/subscription-service/`)
```
routes/api.php              — GET /packages, GET /packages/{slug}, POST /subscription/subscribe
app/Http/Controllers/V1/PackageController.php — index() + show() — IMPLEMENTED ✅
app/Http/Controllers/V1/SubscriptionController.php — stub (NOT yet implemented)
database/seeders/PackageSeeder.php — seeds Basic/Standard/Pro
```

### Frontend (`frontend/`)
```
postcss.config.js           — required for Tailwind to compile (was missing, now added)
src/lib/firebase.ts         — Firebase SDK init
src/hooks/useFirebaseAuth.ts — signInWithGoogle() → sends token to backend → stores JWT
src/components/auth/GoogleSignInButton.tsx — Google G button component
src/app/(auth)/login/page.tsx — login page with email form + Google button
src/app/(auth)/register/page.tsx — registration form
src/stores/auth-store.ts    — Zustand auth state (persists to localStorage)
src/lib/api-client.ts       — Axios instance with JWT interceptor
.env.local                  — Firebase config + NEXT_PUBLIC_API_URL=http://localhost:8000
```

---

## What Is NOT Yet Built (Phase 1 Remaining)

### High Priority — Build Next

**1. SubscriptionController** (`services/subscription-service/app/Http/Controllers/V1/SubscriptionController.php`)
- `POST /api/v1/subscription/subscribe` — subscribe user to a package
- Needs: validate package slug, check if already subscribed, create subscription record, fire subscription.purchased event, wallet-service credits the user

**2. Frontend `/chat` page** (`frontend/src/app/(dashboard)/chat/page.tsx`)
- Session list sidebar
- Message display area
- Model selector dropdown
- Message input with send button
- SSE streaming support

**3. AI Gateway — OpenAI streaming** (`services/ai-gateway-service/`)
- `POST /api/v1/chat/stream` → streams GPT-4o-mini response via SSE
- Balance check → reserve → stream → deduct flow
- Model list endpoint

**4. Payment Service** — Stripe top-up flow
- `POST /api/v1/topup` → Stripe charge
- Webhook handler → wallet credit

### Medium Priority
- Password reset flow (forgot/reset endpoints are stubbed)
- `GET /api/v1/auth/me` returns full profile but missing `avatar_url` field
- Notification service email templates (welcome email, receipt, low balance)
- Billing service invoice generation

---

## Known Issues / Gotchas

| Issue | Status | Notes |
|---|---|---|
| `php artisan` commands hang | Known | WSL2 volume slowness — use sh scripts instead |
| PowerShell JSON quoting | Known | Always use shell scripts via `docker cp` + `docker exec` |
| Queue worker must be started manually | Known | Run: `docker exec -d aichathub-auth php artisan queue:work redis --tries=3` |
| Firebase service account not in git | By design | Must copy `firebase-service-account.json` to `services/auth-service/` manually |
| `GOOGLE_CLIENT_ID` warning on docker-compose | Non-issue | Just a warning, not used (we use Firebase instead) |
| Login timeout in test scripts | Known | Login works fine; the test script timeout is too short for the full flow |

---

## Environment Variables That Must Be Set

### auth-service `.env` (critical ones)
```
JWT_SECRET=CHANGE_ME_32_CHAR_MIN_SECRET_KEY   ← same across all services
JWT_LEEWAY=60
INTERNAL_SERVICE_KEY=internal-secret-change-in-production   ← same across all services
WALLET_SERVICE_URL=http://wallet-nginx
FIREBASE_CREDENTIALS=/var/www/firebase-service-account.json
FIREBASE_PROJECT_ID=aichathub-ca2c2
```

### api-gateway `.env` (critical ones)
```
JWT_SECRET=     ← must match auth-service
AUTH_SERVICE_URL=http://auth-nginx
SUBSCRIPTION_SERVICE_URL=http://subscription-nginx
WALLET_SERVICE_URL=http://wallet-nginx
PAYMENT_SERVICE_URL=http://payment-nginx
AI_GATEWAY_SERVICE_URL=http://ai-gateway-nginx
CHAT_SERVICE_URL=http://chat-nginx
BILLING_SERVICE_URL=http://billing-nginx
```

### frontend `.env.local`
```
NEXT_PUBLIC_API_URL=http://localhost:8000
NEXT_PUBLIC_FIREBASE_API_KEY=AIzaSyDH-dRLxD99-LbQ6NUjDE4WFwmxn8nrHLo
NEXT_PUBLIC_FIREBASE_AUTH_DOMAIN=aichathub-ca2c2.firebaseapp.com
NEXT_PUBLIC_FIREBASE_PROJECT_ID=aichathub-ca2c2
NEXT_PUBLIC_FIREBASE_APP_ID=1:998118993318:web:4c60835170114e0bd47367
```

---

## Phase 1 Completion Status

```
Week 1-2: Foundation
✅ All 9 Docker services running
✅ All database migrations applied
✅ Auth: register, login, logout, refresh, email verify
✅ Auth: Google Sign-In via Firebase
✅ Wallet auto-created on registration
✅ 3 subscription packages seeded
✅ Frontend: login + register pages styled and functional
✅ API Gateway proxying to auth-service working

Week 3-4: Subscription + Payment           ← START HERE
⬜ SubscriptionController.subscribe()
⬜ Payment Service: Stripe charge
⬜ Wallet credited on subscription.purchased
⬜ Frontend: pricing page + subscribe flow

Week 5-6: AI Chat MVP                      ← AFTER SUBSCRIPTION
⬜ AI Gateway: GPT-4o-mini streaming
⬜ Balance reserve/deduct cycle
⬜ Chat Service: session + message storage
⬜ Frontend: chat interface with SSE

Week 7-8: Billing + Wallet UI              ← AFTER CHAT
⬜ Wallet balance display page
⬜ Transaction history
⬜ Invoice generation

Week 9-10: Polish                          ← LAST
⬜ Auto-renewal scheduler
⬜ Admin panel basics
⬜ End-to-end smoke testing
```

**Overall Phase 1: ~40% complete**

---

## Quick Test Commands (Always Use These, Not PowerShell curl)

```bash
# Test registration through API Gateway
docker cp scripts/test-gateway-register.sh aichathub-gateway-nginx:/tgr.sh
docker exec aichathub-gateway-nginx sh /tgr.sh

# Test full auth flow
docker cp scripts/test-full-auth.sh aichathub-auth-nginx:/tfa.sh
docker exec aichathub-auth-nginx sh /tfa.sh

# Test wallet internal API
docker cp scripts/test-cross-service.sh aichathub-auth:/tc.sh
docker exec aichathub-auth sh /tc.sh

# Check DB state
docker exec aichathub-postgres psql -U postgres -d ai_chathub_db -c "SELECT COUNT(*) FROM wallet_svc.wallets;"
docker exec aichathub-postgres psql -U postgres -d ai_chathub_db -c "SELECT slug, monthly_price_usd FROM subscription_svc.packages;"
```
