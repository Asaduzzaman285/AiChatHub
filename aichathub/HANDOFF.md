# AI ChatHub — Development Handoff Document
**Last updated:** 2026-07-19  
**Repo:** https://github.com/Asaduzzaman285/AiChatHub  
**Local path:** `C:\Users\IT News\Downloads\aichathub\aichathub`  
**Branch:** main

## 2026-07-19 Session — What Changed

Two passes this session: first built M1/M2/M4/M8(partial) with Docker down (code-only, unverified);
then Docker came up mid-session and the user supplied real Stripe **test/sandbox** keys, so M3 got
built too and **the entire chain — register → verify → login → subscribe → wallet credit → invoice
→ top-up → wallet credit → receipt → payment-method save — was actually run end-to-end through the
live stack via the API Gateway and confirmed working.** Bugs below were found by that live testing,
not by inspection — several would not have been caught by code review alone.

### Critical bug — every non-auth service's `auth.jwt` middleware was completely broken
`JwtAuthMiddleware.php` was byte-identical scaffold copied into all 8 services and called
`JWTAuth::parseToken()->authenticate()`, which needs a local `App\Models\User` + `config/auth.php`
+ tymon/jwt-auth. Only `auth-service` actually has those. `subscription-service` didn't even have
`tymon/jwt-auth` in `composer.json` — any authenticated request would have hard-failed (likely 500)
before reaching the controller. Fixed by having every non-auth service trust the `X-User-Id` /
`X-User-Email` headers that `api-gateway`'s `JwtGatewayMiddleware` already decodes and forwards.
Applied to: subscription, wallet, ai-gateway, chat, billing, payment. Added an `authUserId(Request
$request)` helper to each service's base `Controller.php`.
**Consequence: all `auth.jwt`-gated endpoints on these services now only work when called through
the API Gateway (`localhost:8000`)** — direct-to-service test scripts that hit e.g.
`aichathub-subscription-nginx` directly will get 401 `token_missing`.

### Critical bug — API Gateway's own JWT decoding was broken too (found live)
`api-gateway`'s `JwtGatewayMiddleware` uses `Firebase\JWT\JWT`, but `firebase/php-jwt` was **not in
`composer.json` and not installed** — every authenticated request through the gateway threw `Class
"Firebase\JWT\JWT" not found` (500). Fixed: `composer require firebase/php-jwt:^6.0` inside the
container (persisted to `composer.json`/`composer.lock`, safe — bind-mounted). Then hit a second
issue: `config('jwt.secret')` resolved to `null` because api-gateway had **no `config/jwt.php` at
all** — added a minimal one (`['secret' => env('JWT_SECRET')]`; api-gateway doesn't need the full
tymon/jwt-auth config since it only decodes, never issues, tokens). **This means no one has ever
successfully made an authenticated request through the gateway in this project before today** —
worth knowing if anything else was "verified working" only via direct-to-service curl.

### CRITICAL — wallet credit was not idempotent (caused a real double-credit during testing)
`WalletService::credit()` had no protection against being called twice for the same operation. In
this environment, internal HTTP calls between services routinely exceed a 5s client timeout even
though the server-side operation completes successfully a few seconds later (see "environment is
slow" below) — the caller sees a timeout/exception and, in a naive retry, would credit twice. This
was reproduced live: a manual retry of the exact same wallet credit call took the balance from $10
to $20. **Fixed:** `credit()` now checks for an existing `WalletLedgerEntry` matching
`(type='credit', reference_type, reference_id)` **after** acquiring the row lock (so concurrent
retries serialize correctly, not just sequential ones) and short-circuits if found. Only `credit()`
was fixed — `debit()`/`refund()` have the same gap but aren't exercised by any code yet (AI chat
usage isn't built — M5/M6). Fix that before wiring chat balance deduction.

### M2 — Subscription Service: `SubscriptionController` fully implemented and verified live
`current`, `subscribe`, `upgrade`, `downgrade`, `cancel`, `history`. Was a `501` stub; the service
layer (`SubscriptionService`) was already fully written but unreachable. Also fixed:
- `app/Models/SubscriptionHistory.php` and `app/Models/RenewalAttempt.php` were **missing entirely**
  — `SubscriptionService::subscribe()` referenced `SubscriptionHistory::create()` and would have
  thrown `Class not found` on the very first call.
- `payment_method_id` on `user_subscriptions` is a `uuid` column — the controller was originally
  passing the raw Stripe `payment_method_token` string into it, which threw a Postgres
  `invalid input syntax for type uuid` error (caught live). Fixed by passing `null` — Phase 1 has no
  stored `PaymentMethod` record linkage into subscribe() yet, so there's nothing valid to store there.
- Added `config/services.php` (wallet_url/billing_url/notification_url/internal_key) and
  `BILLING_SERVICE_URL` to `.env`/`.env.example` (was missing).
- Fixed a route double-prefix bug in `routes/internal.php` (`Route::prefix('internal')` inside a
  group already mounted at `api/internal` → effective path was `/api/internal/internal/...`).

Design: payment-service isn't wired into `subscribe()` — Phase 1 treats the request as
pre-authorized rather than actually charging `payment_method_token` through Stripe (that only
happens for top-ups, via M3 below). Wallet credit happens **synchronously** in the request (so the
response balance is accurate); invoice creation fires `afterResponse()` via direct internal HTTP
call to billing-service. No proration on upgrade/downgrade — credits the wallet the difference in
`monthly_wallet_credit_usd` only.

### M3 — Payment Service: Stripe top-up + payment methods, built and verified live with real test keys
The scaffold was much further along than the checklist suggested — `StripeGateway` (charge/refund/
webhook-verify) and `PaymentInternalController::charge()` were already fully implemented — but
**`app/Models/` was completely empty** (`Transaction`, `WebhookEvent` used throughout but didn't
exist — same "would crash on first real call" pattern as subscription-service), **no
`config/services.php`** (so `StripeGateway`'s `config('services.stripe.secret')` was always null),
**no `app/Jobs/`** (webhook controller dispatches `ProcessStripeWebhookJob`, which didn't exist),
and `PaymentInternalController::refund()` was referenced by a route but the method didn't exist.
Built this session:
- `app/Models/Transaction.php`, `WebhookEvent.php`, `PaymentMethod.php`
- `config/services.php` (stripe secret/webhook_secret/publishable_key, wallet_url, billing_url, internal_key)
- `app/Jobs/ProcessStripeWebhookJob.php` — handles `payment_intent.succeeded`/`payment_intent.payment_failed`,
  credits the wallet + creates a receipt, idempotent via the transaction's own status check
- `app/Services/InternalServiceClient.php` — shared wallet-credit/receipt-create HTTP client (used
  by both `TopupController` and the webhook job, since both need the identical calls)
- `TopupController::initiate()` — creates+confirms a Stripe PaymentIntent; if Stripe confirms
  synchronously (normal in test mode) the wallet is credited immediately in the response; if not,
  the transaction stays "pending" and the webhook job credits it later — same-idempotency-key retry
  safe via the wallet credit guard above
- `TopupController::status()`, `PaymentMethodController` (index/store/destroy/setDefault — `store()`
  calls Stripe to retrieve card metadata, never persists raw card data), `TransactionController`
  (index/show)
- `PaymentInternalController::refund()` (was missing)
- Fixed the same `routes/internal.php` double-prefix bug as subscription-service

**Verified live end-to-end** with the user's real Stripe test-mode keys (sandbox account, not live):
registered/verified/logged-in a real user through the gateway, subscribed to a package (wallet
credited $10, invoice generated), topped up $25 via `pm_card_visa` (Stripe PaymentIntent confirmed,
wallet credited to $35, receipt generated), and saved a payment method (Stripe returned Visa •••• 4242,
correctly stored without raw card data). Transaction history correctly shows both a deliberately
forced failure (bad API key, from before the env fix below) and the successful charge.

**⚠️ Stripe webhook is NOT tested** — that requires `stripe listen --forward-to
http://localhost:8004/api/v1/webhooks/stripe` running locally (Stripe CLI, interactive, can't be
automated) to get a real `STRIPE_WEBHOOK_SECRET`; `.env` still has `STRIPE_WEBHOOK_SECRET=whsec_CHANGE_ME`.
Until that's set up, `ProcessStripeWebhookJob` only gets exercised for top-ups where Stripe
requires a redirect/3DS step (rare with test cards) — the common synchronous-confirm path bypasses
it entirely, which is why the live test above still fully succeeded without the webhook secret set.

### M4 — Wallet Service: `WalletController`/`LedgerController` implemented and verified live
`GET /wallet`, `GET /wallet/credit`, `GET /wallet/ledger` (paginated). Were `501` stubs;
`WalletService`/`WalletInternalController` were already complete.

### M8 (partial) — Billing Service: invoice + receipt creation implemented and verified live
Added `app/Models/Invoice.php`, `Receipt.php`, `Internal/InvoiceInternalController@create` (called
by subscription-service), `Internal/ReceiptInternalController@create` (called by payment-service),
public `InvoiceController` (`index`/`show` — `download` still 501, no PDF generation) and
`ReceiptController` (`index`/`show`).

### M1 — AI Models Seeder
`ModelSeeder.php` was already fully written and correct (confirmed: 12 models already seeded in the
DB from a previous session). Added `database/seeders/DatabaseSeeder.php` so `php artisan db:seed`
without `--class=` also works.

### Environment gotchas discovered this session (read before your next session)
- **`docker restart <container>` does NOT reload `.env` changes.** `env_file:` values are baked into
  the container at creation time; restarting only restarts the process with the *old* environment
  still attached. This caused a very confusing "Invalid API Key" error with the *correct* key sitting
  right there in `.env`. Use `docker-compose up -d --force-recreate <service>` after editing a
  service's `.env` (a plain `docker-compose up -d <service>` also works if compose detects the diff).
  `rm bootstrap/cache/config.php` alone is not enough when the stale value is at the OS-env level.
- **This environment (Docker Desktop + WSL2 + Windows bind mounts) is slow, not broken.** A bare
  `/health` check on a freshly-restarted container routinely takes 4–8s; cross-service calls chained
  two or three deep (gateway → subscription-service → wallet-service) can exceed 30s. This is *not*
  representative of production or even a native-Linux dev box — before assuming a timeout means a
  bug, retry directly against the service in question. Bumped internal HTTP call timeouts from 5s
  to 15s (`SubscriptionController`, `InternalServiceClient`, `RegisterController`'s wallet-create
  call) and the gateway's default proxy timeout from Laravel's 30s default to 45s
  (`ProxyController::forward()` — streaming routes keep their existing 120s). Even with these bumps,
  a request can still time out client-side while succeeding server-side (observed live: a "500
  timeout" from the gateway on `/subscribe` had actually created the subscription, credited the
  wallet, and generated the invoice — confirmed by querying Postgres directly). **This is exactly
  why the wallet-credit idempotency fix above matters — don't remove it.**
- Host machine curl testing note (not a project bug, just a local quirk): curl on this Windows/git-bash
  setup sometimes hangs indefinitely resolving `localhost` over IPv6 (`::1`) against Docker Desktop's
  published ports — force IPv4 (`curl -4 http://127.0.0.1:PORT/...`) if a request to a *published*
  port hangs with 0 bytes received. Also: any Laravel API route hit without an explicit
  `Accept: application/json` header gets treated as a browser request and 302-redirects instead of
  returning JSON/401 — always send that header when testing manually.

### Not touched this session (still open, in priority order)
M5 AI Gateway chat streaming (no `AiModel` Eloquent model exists yet either — `ModelSeeder` uses
raw `DB::table()` so it doesn't need one, but a future `ModelController`/`ChatController` will), M6
Chat Service (all 3 controllers still stubs), M7 Notification Mailables (`Mail/` dir empty, no
`EventServiceProvider` in notification-service), M9 Frontend (chat/wallet/billing pages, route guard
middleware), M10 renewal scheduler/admin/polish. Also still open: `debit()`/`refund()` in
`WalletService` need the same idempotency guard as `credit()` before AI chat usage deduction is
wired up; Stripe webhook path is unverified (see M3 note above); `upgrade()`/`downgrade()` have no
proration logic (documented Phase 1 simplification, not a bug).

---

## 2026-07-19 Session 2 — M5 (AI Gateway chat) + M6 (Chat Service) built and proven live with a real model

Picked up right after the above. Goal: wire real AI chat end-to-end using a **free** model (Gemini,
via the user's own free Google AI Studio key) so the flow can be tested without spending money, per
laravel/ai's built-in Gemini gateway. Everything below was verified by directly querying Postgres
after each call, not just by reading a 200 response — same discipline as the M2/M3/M4 pass.

### What was actually broken before this pass (found live, not by code review)
- `ChatController` (`ai-gateway-service`) injected `App\Services\SubscriptionClientService` in its
  constructor — **the class didn't exist anywhere in the codebase.** Every `/chat/stream` call would
  have hard-failed with a container-resolution error before reaching any business logic. Built it
  from scratch calling the (already-correct) `SubscriptionCheckController` internal endpoints on
  subscription-service.
- `WalletClientService` (`ai-gateway-service`) sent the wrong internal-auth header
  (`X-Internal-Key` instead of `X-Internal-Service-Key`) and hit the wrong URL (missing the `/api`
  prefix every other service's internal routes use). Wallet `reserve()` would have always failed
  401 and every chat request would have wrongly 402'd as "insufficient balance" regardless of
  actual balance.
- `ai-gateway-service` had **no `config/services.php` at all** — same class of bug fixed in
  payment-service last session. `config('services.internal_key')` silently returned `null`, so even
  after fixing the header name, the key being sent was empty.
- `packages.model_access` was `[]` for all 3 packages — `PackageSeeder.php` had a literal
  `// Populated after models seeded` TODO comment that was never followed up on. Every
  subscription-gated model-access check (`canAccess()`) would always deny, for every user, forever.
  Filled in a real tiering (Basic: 2 cheap/fast models, Standard: +vision/mid-tier, Pro: everything)
  based on the `features` flags (`comparison`, `vision`, `image_gen`, etc.) that were already seeded
  correctly — those flags were the only real signal for what the tiering was supposed to be.
- `TextChatAgent::middleware()` referenced `App\Ai\Middleware\UsageLoggingMiddleware` — also did not
  exist. Built it to write to the already-existing-but-unused `ai_svc.usage_logs` table.
- Both `CostTrackingMiddleware` and the new `UsageLoggingMiddleware` type-hinted their `handle()`
  return as `Laravel\Ai\Responses\AgentResponse` — but for a **streaming** call, laravel/ai's
  pipeline actually passes a `StreamableAgentResponse` (a different, unrelated class), so every
  streamed request threw a `TypeError` before even reaching Gemini. Loosened both to a union type.
- `CostTrackingMiddleware` hardcoded GPT-4o's rate ($2.50/$10.00 per 1M tokens) for **every** model
  regardless of which one was actually used — meaning a free Gemini call would have debited the
  user's wallet as if it were GPT-4o. `ai_svc.model_pricing` already existed as a table for exactly
  this but only had one seeded row (GPT-4o, "as example" per the seeder's own comment). Built
  `AiModel`/`ModelPricing` Eloquent models (neither existed) and seeded approximate published rates
  for all 9 text models; middleware now looks up the real rate per request.
- The model catalog was seeded with `gemini-1.5-flash` / `gemini-1.5-pro` — **Google has since
  retired the entire 1.5 series** (confirmed live via Gemini's own `ListModels` endpoint with the
  user's real key — the key was valid, the model name was stale). Renamed to `gemini-2.5-flash` /
  `gemini-2.5-pro` in both the live DB and the seeder source (`ModelSeeder.php`,
  `PackageSeeder.php`'s `model_access` arrays) so a future re-seed doesn't reintroduce it. **If chat
  requests start 404ing again in the future, check Gemini's `/v1beta/models` list before assuming
  it's a code bug — Google's lineup moves.**
- `chat-service` had all 3 controllers as literal `__call() { return 501; }` stubs, despite its DB
  migrations (`chat_sessions`, `chat_messages`, `file_attachments`) being fully built already. Built
  `ChatSession`/`ChatMessage` Eloquent models, real `SessionController`/`MessageController` CRUD, and
  a new internal endpoint (`POST /internal/sessions/{id}/messages`) that `ai-gateway-service` calls
  once for the user's message and once for the assistant's reply after each `/chat/stream` call
  completes (via the `StreamableAgentResponse::then()` completion hook — fires after the full
  stream is generated server-side, so cost/token counts are accurate, not estimates).
- Also caught two identical `$request->user()->id` calls in `ChatController` (`stream()` and
  `compare()`) — same class of bug as the JWT middleware fix above; there's no local `User` model in
  ai-gateway-service. Fixed to use the `authUserId()` helper like every other controller.

### Confirmed working live (verified via direct Postgres queries, not just API responses)
Register → subscribe (Basic) → `GET /models` (correctly shows `gemini-2.5-flash`/`gpt-4o-mini` as
`available: true`, everything else `false`) → `POST /sessions` → `POST /chat/stream` with a real
message → real streamed response from Gemini 2.5 Flash → wallet debited the *exact* real per-token
rate for that model (not a flat estimate) → both the user message and assistant reply persisted to
`chat_svc.chat_messages` with correct `role`/`content`/`prompt_tokens`/`completion_tokens`/`cost` →
`chat_sessions.message_count`/`total_tokens`/`total_cost` aggregates update correctly.

### The "credit buffer" business rule got corrected too
Separately (same session): `WalletService::createForUser()` was giving **every** new user a $3
credit buffer (`credit_limit`) regardless of subscription status — contradicts the intended design
("buffer is a perk for package buyers, not a free grant to unsubscribed users"). Fixed: new wallets
now start at `credit_limit = 0`; `WalletService::credit()` gained an `$activateCreditBuffer` param
that subscription-service's `subscribe()` (not `upgrade()`/`downgrade()`) passes as `true`, so the
buffer activates on a user's *first* purchase. Backfilled the ~14 pre-existing test wallets that had
been wrongly given the buffer before this fix (only touched ones with `credit_balance = 0`, to
respect the `chk_credit_within_limit` check constraint).

### Frontend: `/chat` is now a real chat UI, not a dashboard summary
Session list (left) + message thread + streaming input (right), all at the existing `/chat` route
(previously just showed subscription/wallet summary cards — those are one click away via the
Wallet/Pricing nav items already, so this fully replaces that content). Model picker only shows
models the user's current package actually grants (`GET /models`'s `available` flag). Streaming is
hand-rolled (`fetch` + manual `ReadableStream` reader parsing the `data: {...}` SSE frames) rather
than using the already-installed `ai` package's `useChat` hook — the backend's `message` +
`session_id` + `history` request shape doesn't match Vercel's `useChat` default `messages[]` array
format, and reshaping that via `experimental_prepareRequestBody` felt more fragile than just reading
the stream directly, especially since the exact wire format was already confirmed via a live curl
test (`start` → `text-start` → `text-delta`* → `text-end` → `finish` → `[DONE]`).

**Not yet tested interactively in an actual browser** (only `tsc --noEmit` clean + dev-server compile
+ curl smoke test on the route) — streaming render behavior, scroll behavior, and the "no models
available" empty state specifically should get a real click-through before considering this done.

**Not built in this pass:** `/chat/compare` has no frontend UI (endpoint exists, was fixed for the
same bugs as `/chat/stream` while in the file, but nothing calls it), file attachments
(`FileAttachmentController` still a stub, `chat_svc.file_attachments` table unused), session
rename/delete has no UI (backend endpoints exist), Notification Mailables (M7) still untouched.

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
app/Http/Controllers/V1/SubscriptionController.php — current/subscribe/upgrade/downgrade/cancel/history — IMPLEMENTED ✅ (2026-07-19, verified live)
database/seeders/PackageSeeder.php — seeds Basic/Standard/Pro
```

### Payment Service (`services/payment-service/`)
```
config/services.php         — stripe.{secret,webhook_secret,publishable_key}, wallet_url, billing_url, internal_key
app/Services/StripeGateway.php — charge(), refund(), verifyWebhook()
app/Services/InternalServiceClient.php — creditWallet(), createReceipt() — shared by TopupController + the webhook job
app/Jobs/ProcessStripeWebhookJob.php — handles payment_intent.succeeded/payment_failed
app/Http/Controllers/V1/TopupController.php — initiate() + status() — IMPLEMENTED ✅ (verified live with real Stripe test key)
app/Http/Controllers/V1/PaymentMethodController.php — index/store/destroy/setDefault — IMPLEMENTED ✅
app/Http/Controllers/V1/TransactionController.php — index/show — IMPLEMENTED ✅
app/Http/Controllers/Internal/PaymentInternalController.php — charge() (was done) + refund() (added) — for subscription-service
app/Http/Controllers/V1/Webhooks/StripeWebhookController.php — verifies signature, dispatches job — NOT runtime-tested (needs `stripe listen`)
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

### 🔴 Critical — Must Build Before Next Feature Works

**1. AI Models Seeder** (`services/ai-gateway-service/`) — ✅ done, confirmed live
- 12 models seeded in `ai_svc.ai_models`; `gemini-1.5-*` renamed to `gemini-2.5-*` (Google retired
  the 1.5 series — see Session 2 notes above) — confirmed live against Gemini's own `ListModels`.
- `packages.model_access` populated for real (Session 2) — was `[]` for all 3 packages before that.

**2. SubscriptionController** — ✅ implemented AND verified live end-to-end (2026-07-19)
See "2026-07-19 Session" notes above for the two real bugs this live run found and fixed
(missing `SubscriptionHistory`/`RenewalAttempt` models; `payment_method_id` uuid-column mismatch).

**3. ~~Queue Workers for ALL services~~ — superseded, not needed for the subscribe/topup flow**
`SubscriptionService`/`WalletService` publish to Redis pub/sub channels (`subscription-events`,
`wallet-events`) that **no consumer anywhere in the codebase subscribes to** — `queue:work` only
processes Laravel's own queued jobs, not raw `Redis::publish()` channels, so those workers would
sit idle. Wallet crediting and invoice/receipt creation are wired as direct internal HTTP calls
instead. The Redis publishes are left in place for a future real event-bus consumer but nothing
currently depends on them. The auth-service email queue worker is still required and unrelated to
this — see Quick Test Commands below.

### 🟡 High Priority — Next After Subscription

**4. Frontend `/chat` page** — ✅ done (Session 2) — session list, message thread, model picker,
streaming. Not yet click-tested in an actual browser (only compiled/type-checked).
**5. Frontend route protection middleware** (`frontend/src/middleware.ts`) — still not started
(client-side guard exists instead, see Frontend section below)

**6. AI Gateway — chat streaming** (`services/ai-gateway-service/`) — ✅ done (Session 2), verified
live with a real Gemini 2.5 Flash response, accurate per-model wallet debit, and usage logging.
OpenAI/Anthropic/xAI/ElevenLabs models are seeded and access-gated but **have no real API key
configured** (`.env` still has `sk-CHANGE_ME` etc.) — only Gemini actually works right now.
- Still open: `WalletService::debit()`/`refund()` don't have the idempotency guard `credit()` got —
  low risk right now since nothing retries a chat request client-side, but should get the same fix
  before this goes further than manual testing.

**7. Payment Service — Stripe top-up** — ✅ implemented AND verified live with real Stripe test keys (2026-07-19)
`POST /api/v1/topup` works end-to-end (PaymentIntent created+confirmed, wallet credited, receipt
generated). See "2026-07-19 Session" M3 notes above for what was missing and what's still open
(webhook path untested — needs `stripe listen`).

### 🟢 Medium Priority

**8. Password reset flow** — endpoints exist but `PasswordResetController` is a stub
**9. ~~WalletController~~** — ✅ implemented 2026-07-19 (`balance`, `creditStatus`)
**10. ~~LedgerController~~** — ✅ implemented 2026-07-19 (`GET /api/v1/wallet/ledger`, paginated)
**11. Notification email templates** — welcome email, receipt, low balance alert — still stubs, `Mail/` dir empty, no `EventServiceProvider` in notification-service. **This is now the single largest untouched piece of Phase 1.**
**12. Billing service invoice/receipt generation** — ✅ done 2026-07-19: `InvoiceInternalController@create`,
`ReceiptInternalController@create`, public `InvoiceController`/`ReceiptController` (`index`/`show`)
all implemented and verified live; `download` (PDF) still not implemented

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
| Direct-to-service test scripts on `auth.jwt` routes now 401 | New (2026-07-19) | subscription/wallet/ai-gateway/chat/billing/payment now require `X-User-Id` header set by api-gateway's `JwtGatewayMiddleware` — any test script that curls a service's nginx directly (bypassing `localhost:8000`) on an `auth.jwt`-protected route needs to go through the gateway instead |

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

### payment-service `.env` (critical ones)
```
STRIPE_SECRET_KEY=      ← set 2026-07-19, real Stripe TEST/sandbox key (gitignored, not in repo)
STRIPE_PUBLISHABLE_KEY= ← set 2026-07-19, same sandbox account
STRIPE_WEBHOOK_SECRET=whsec_CHANGE_ME   ← still a placeholder, needs `stripe listen` to get a real one
BILLING_SERVICE_URL=http://billing-nginx
```
**Reminder:** after editing this (or any service's) `.env`, `docker restart <container>` is NOT
enough — the old env values are still baked into the container process. Use
`docker-compose up -d --force-recreate <service>` (see 2026-07-19 session notes above; this cost
significant debugging time this session).

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

Week 3-4: Subscription + Payment
✅ SubscriptionController.subscribe() — verified live end-to-end 2026-07-19
✅ Payment Service: Stripe top-up (charge) — verified live with real test keys 2026-07-19
✅ Wallet credited on subscription purchase AND top-up (direct HTTP calls, idempotency-guarded)
✅ Invoice + receipt generation — verified live
⬜ Frontend: pricing page + subscribe flow           ← NEXT
⬜ Stripe webhook path (needs `stripe listen`, untested)
⬜ upgrade()/downgrade() have no proration logic (documented simplification)

Week 5-6: AI Chat MVP                      ← DONE (Gemini only — see below)
✅ AI Gateway: chat streaming — verified live with real Gemini 2.5 Flash, Session 2 (2026-07-19)
✅ Balance reserve/deduct cycle — verified, accurate per-model cost (not a flat estimate)
✅ Chat Service: session + message storage — verified live, Session 2
✅ Frontend: chat interface with SSE — built, compiles clean, not yet click-tested in a browser
✅ packages.model_access populated
⬜ Real API keys for OpenAI/Anthropic/xAI/ElevenLabs — only Gemini works right now (it's free)
⬜ WalletService::debit()/refund() idempotency guard (same class of fix credit() already got)
⬜ /chat/compare (multi-model comparison) has no frontend UI
⬜ File attachments (chat_svc.file_attachments unused, FileAttachmentController still a stub)

Week 7-8: Billing + Wallet UI              ← DONE
✅ Wallet balance + ledger endpoints — verified live
✅ Transaction history endpoint — verified live
✅ Invoice + receipt generation — verified live
✅ Frontend pages for all of the above (dashboard, pricing, wallet, billing, chat)
⬜ Invoice PDF download (InvoiceController::download() still a 501 stub)
⬜ Settings page, saved payment methods UI, Stripe Elements (test PaymentMethod id hardcoded instead)

Week 9-10: Polish                          ← LAST, NOT STARTED
⬜ Notification emails (welcome, receipt, low balance, renewal-failed) — Mail/ dir empty
⬜ Auto-renewal scheduler
⬜ Admin panel basics
⬜ Password reset (routes exist, controller is a stub)
⬜ End-to-end smoke testing in an actual browser (everything so far verified via curl + DB queries)
```

**Overall Phase 1: ~80% complete.** Every core money/chat flow (register → subscribe → wallet →
top-up → invoicing → real AI chat) is built AND verified end-to-end against the live stack, not
just code-complete. What's left is genuinely the polish tier: notification emails, admin panel,
renewal automation, PDF downloads, and settings/payment-method UI — plus giving the frontend an
actual human click-through pass, since everything to date has been verified via curl/DB, not a
browser.

---

## Service Implementation Checklist
*(Aligned with PHASE1_DEV_GUIDE.md — updated to reflect actual current state)*

### Auth Service ✅ COMPLETE
- [x] RegisterController — user creation + afterResponse() for email + wallet
- [x] LoginController — email/password + JWT issuance
- [x] EmailVerificationController — verify token + resend
- [x] LogoutController — invalidate JWT + revoke refresh tokens
- [x] TokenRefreshController — rotate refresh token pair
- [x] PasswordResetController — STUB (routes exist, logic not implemented)
- [x] SocialAccountController — list + unlink Google (wired, basic impl)
- [x] GoogleOAuthController — Socialite redirect (kept but unused — Firebase used instead)
- [x] FirebaseAuthController — Google Sign-In via Firebase token ← NEW vs Dev Guide
- [x] Internal UserController — show, findByEmail, suspend, unsuspend
- [x] JwtAuthMiddleware — validates JWT on protected routes
- [x] InternalServiceMiddleware — validates X-Internal-Service-Key
- [x] JwtService — issueTokens(), rotateRefreshToken(), revokeAll()
- [x] UserRegistered event + SendVerificationEmail listener
- [ ] PasswordResetController full implementation
- [ ] Welcome email on first social login

### Subscription Service ✅ CORE DONE (renewal automation still open)
- [x] PackageController — index() + show() ← DONE
- [x] PackageSeeder — Basic/Standard/Pro seeded ← DONE
- [x] SubscriptionController — current, subscribe, upgrade, downgrade, cancel, history ← DONE 2026-07-19
- [x] SubscriptionHistory / RenewalAttempt models ← DONE 2026-07-19 (were missing, `subscribe()` would have crashed)
- [x] config/services.php (wallet_url, billing_url, internal_key) ← DONE 2026-07-19
- [ ] ProcessRenewalJob
- [ ] RetryRenewalJob
- [ ] Event listener: payment.succeeded → activate subscription (blocked on M3 payment-service)
- [ ] Renewal scheduler

### Wallet Service ✅ CORE DONE (event listener still open, debit/refund idempotency still open)
- [x] WalletService — createForUser(), credit(), debit(), reserve(), refund() ← already scaffolded
- [x] WalletInternalController — create(), show(), credit(), reserve(), deduct(), refund() ← DONE
- [x] Wallet auto-created on registration via afterResponse() HTTP call ← DONE
- [x] WalletController — GET /wallet, GET /wallet/credit (balance + credit-buffer display) ← DONE 2026-07-19
- [x] LedgerController — GET /wallet/ledger (paginated history) ← DONE 2026-07-19
- [x] credit() idempotency guard (reference_type + reference_id) ← DONE 2026-07-19, fixed a live double-credit
- [ ] debit()/refund() need the same idempotency guard before AI chat usage deduction is wired up (M5/M6)
- [ ] Event listener: subscription.purchased → credit wallet — superseded, subscription/payment services now call wallet-service directly instead (see 2026-07-19 session notes above)

### Payment Service ✅ CORE DONE (webhook path untested)
- [x] StripeGateway — charge(), refund(), verifyWebhook() ← already scaffolded
- [x] PaymentInternalController — charge() (already scaffolded) + refund() (added 2026-07-19, was referenced by a route but missing)
- [x] Transaction/WebhookEvent/PaymentMethod models ← DONE 2026-07-19 (were missing entirely)
- [x] config/services.php ← DONE 2026-07-19 (was missing — StripeGateway's config() calls always returned null)
- [x] InternalServiceClient — shared wallet-credit/receipt-create HTTP helper ← DONE 2026-07-19
- [x] ProcessStripeWebhookJob ← DONE 2026-07-19 (was referenced by StripeWebhookController but didn't exist)
- [x] PaymentMethodController — index/store/destroy/setDefault ← DONE 2026-07-19, verified live (Stripe test card 4242)
- [x] TopupController — initiate() + status() ← DONE 2026-07-19, verified live with real Stripe test key
- [x] TransactionController — index + show ← DONE 2026-07-19, verified live
- [x] StripeWebhookController — validate signature + process events (code complete, was already scaffolded)
- [ ] Webhook path itself is UNTESTED — needs `stripe listen --forward-to http://localhost:8004/api/v1/webhooks/stripe` (interactive Stripe CLI) to get a real STRIPE_WEBHOOK_SECRET
- [ ] BkashWebhookController (Bangladesh gateway) — still a stub

### AI Gateway Service ✅ CORE DONE (Session 2, 2026-07-19) — verified live with real Gemini 2.5 Flash
- [x] ModelController — GET /models, cross-referenced against caller's package access
- [x] ChatController — /chat/stream (SSE) and /chat/compare, both fixed from crash-on-every-call state
- [x] SubscriptionClientService — didn't exist before, built from scratch
- [x] WalletClientService — fixed wrong header + wrong URL (was always 401'ing)
- [x] CostTrackingMiddleware — now uses real per-model pricing, not a hardcoded GPT-4o rate
- [x] UsageLoggingMiddleware — didn't exist before, built; writes to ai_svc.usage_logs
- [x] AiModel / ModelPricing Eloquent models — didn't exist before
- [x] config/services.php — didn't exist before (same bug class as payment-service)
- [x] ModelSeeder — 12 models seeded, gemini-1.5-* renamed to gemini-2.5-* (Google retired 1.5)
- [ ] ImageController — DALL-E 3 (Pro tier) — still a stub
- [ ] AudioController — TTS (Pro tier) — still a stub
- [ ] TranscriptionController — Whisper — still a stub
- [ ] Real API keys for OpenAI/Anthropic/xAI/ElevenLabs — only Gemini has a working key

### Chat Service ✅ CORE DONE (Session 2, 2026-07-19) — verified live
- [x] ChatSession / ChatMessage Eloquent models — didn't exist before
- [x] SessionController — index/store/show/update/destroy (export() still a 501 stub)
- [x] MessageController — index/store
- [x] ChatInternalController — POST /internal/sessions/{id}/messages, called by ai-gateway-service
      after every /chat/stream call to persist both the user message and assistant reply with
      accurate token/cost data
- [ ] FileAttachmentController — upload + delete — still a stub, chat_svc.file_attachments unused

### Billing Service ⬜ PARTIAL
- [x] Invoice model ← DONE 2026-07-19
- [x] InvoiceInternalController@create — POST /api/internal/invoices/create, called by subscription-service ← DONE 2026-07-19, verified live
- [x] InvoiceController — index() + show() ← DONE 2026-07-19
- [x] Receipt model ← DONE 2026-07-19
- [x] ReceiptInternalController@create — POST /api/internal/receipts/create, called by payment-service on top-up ← DONE 2026-07-19, verified live
- [x] ReceiptController — index() + show() ← DONE 2026-07-19
- [ ] InvoiceController — download() (PDF generation)

### Notification Service ⬜ NOT STARTED
- [ ] WelcomeMail Mailable
- [ ] SubscriptionReceiptMail Mailable
- [ ] RenewalFailedMail Mailable
- [ ] LowBalanceMail Mailable
- [ ] Event listeners wired for all above

### API Gateway ✅ COMPLETE (for current scope) — was actually broken, fixed 2026-07-19
- [x] ProxyController — forwards all routes to downstream services; default proxy timeout bumped 30s→45s (WSL2 bind-mount latency)
- [x] config/services.php — all downstream URLs mapped
- [x] JwtGatewayMiddleware — validates JWT, passes X-User-Id header — **was completely broken**:
  `firebase/php-jwt` wasn't installed (`composer require`d 2026-07-19) and `config/jwt.php` didn't
  exist (added 2026-07-19). No authenticated gateway request could have succeeded before this fix.
- [ ] Rate limiting middleware (Week 9-10)
- [ ] CORS configuration (currently handled by Laravel defaults)

### Frontend ⬜ PARTIAL
- [x] Login page — email/password form + Google Sign-In button ← DONE
- [x] Register page — name/email/password/currency form ← DONE
- [x] GoogleSignInButton component — Firebase popup flow ← DONE
- [x] useFirebaseAuth hook — sends token to backend, stores JWT ← DONE
- [x] Auth store (Zustand) — persists JWT, isAuthenticated ← DONE
- [x] API client (Axios) — JWT interceptor, token refresh ← DONE
- [x] Firebase SDK initialized ← DONE
- [x] Tailwind CSS working ← DONE
- [x] Auth callback page — handle Google redirect (/auth/callback) ← already existed
- [x] Route protection — ✅ 2026-07-19, but as a **client-side guard**, not real Next.js middleware
  (`app/(dashboard)/layout.tsx`). JWTs live in `localStorage` via zustand persist, which server-side
  middleware can't read — `src/middleware.ts` was never created; if real middleware-based protection
  is wanted later, tokens need to also be set as a cookie on login.
- [x] Dashboard layout with sidebar ← DONE 2026-07-19 (`app/(dashboard)/layout.tsx`) — nav: Home/Pricing/Wallet/Billing
- [x] Dashboard home page (`/chat`) ← DONE 2026-07-19 — subscription + wallet summary cards; **not a chat interface**, chat UI itself is still Week 5-6/M6
- [x] Pricing/subscribe page ← DONE 2026-07-19 (`app/(dashboard)/pricing/page.tsx`) — subscribe works end-to-end; upgrade/downgrade/cancel have no buttons yet (API exists)
- [x] Wallet page ← DONE 2026-07-19 (`app/(dashboard)/wallet/page.tsx`) — balance, ledger table, top-up form (real Stripe test-mode charge)
- [x] Billing page ← DONE 2026-07-19 (`app/(dashboard)/billing/page.tsx`) — transactions, invoices, receipts tables (read-only, no PDF)
- [x] Chat interface ← DONE Session 2 (`app/(dashboard)/chat/page.tsx`) — session list, message
  thread, model picker (limited to what the user's package grants), hand-rolled SSE streaming.
  Verified via `tsc --noEmit` + compile/curl smoke test only — **not yet click-tested in a browser.**
- [ ] Chat compare UI (backend /chat/compare exists, nothing calls it)
- [ ] Settings page (profile + connected accounts)
- [ ] Saved payment methods list page (backend done, no UI)
- [ ] Stripe Elements integration — pricing/wallet pages currently hardcode Stripe's test
  PaymentMethod id (`pm_card_visa`) instead of collecting a real card client-side; fine for Phase 1
  sandbox testing, not something to ship

See `MANUAL_TESTING_GUIDE.md` (repo root) for a step-by-step walkthrough of everything above.

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

---

## Deviations from Original PHASE1_DEV_GUIDE.md

These are intentional changes made during implementation:

| Dev Guide Said | What We Actually Built | Why |
|---|---|---|
| Google OAuth via Socialite redirect | Firebase Auth SDK popup | Firebase handles Google + future providers in one SDK |
| `NEXT_PUBLIC_GOOGLE_CLIENT_ID` in frontend .env | Firebase config vars instead | Firebase SDK replaces direct OAuth handling |
| Wallet service listens to Redis queue events | Wallet created via direct HTTP call from auth-service | Simpler, no cross-service serialization issues |
| Queue workers for all services from Day 1 | Only auth queue worker running | Other services have no active listeners yet |
| `php artisan` commands run locally | All artisan via `docker exec` | Docker-only setup, no local PHP |
| Google OAuth Step 10 of Dev Guide | Not needed — Firebase handles this | Firebase console replaces Google Cloud Console OAuth setup |

---

## Important: What Antigravity Must Know Before Starting

1. **Never use `php artisan` directly** — it hangs on WSL2. Always use:
   ```bash
   docker exec aichathub-{service} php artisan {command}
   ```

2. **Never use PowerShell `curl` with JSON** — quoting breaks. Always write a `.sh` script and `docker cp` it:
   ```bash
   docker cp scripts/your-test.sh aichathub-auth-nginx:/t.sh
   docker exec aichathub-auth-nginx sh /t.sh
   ```

3. **Firebase service account is NOT in git** — must be manually copied to `services/auth-service/firebase-service-account.json` on any new machine.

4. **JWT_SECRET must be the same in ALL service `.env` files** — if you change it in one, change all.

5. **Run queue worker after `docker-compose up -d`**:
   ```bash
   docker exec -d aichathub-auth php artisan queue:work redis --tries=3 --sleep=3
   ```

6. **Seed AI models before implementing subscription subscribe**:
   ```bash
   docker exec aichathub-ai-gateway php artisan db:seed --class=ModelSeeder
   ```

7. **Config cache must be cleared after any .env or config file change**:
   ```bash
   docker exec aichathub-{service} sh -c "rm -f /var/www/bootstrap/cache/config.php"
   ```

8. **Restart api-gateway after config changes**:
   ```bash
   docker-compose restart api-gateway api-gateway-nginx
   ```

---

## Quick Test Commands (Always Use These, Not PowerShell curl)

```bash
# Test registration through API Gateway
docker cp scripts/test-gateway-register.sh aichathub-gateway-nginx:/tgr.sh
docker exec aichathub-gateway-nginx sh /tgr.sh

# Test full auth flow (login, /me, firebase, refresh)
docker cp scripts/test-full-auth.sh aichathub-auth-nginx:/tfa.sh
docker exec aichathub-auth-nginx sh /tfa.sh

# Test wallet internal API
docker cp scripts/test-cross-service.sh aichathub-auth:/tc.sh
docker exec aichathub-auth sh /tc.sh

# Check DB state
docker exec aichathub-postgres psql -U postgres -d ai_chathub_db -c "SELECT COUNT(*) FROM wallet_svc.wallets;"
docker exec aichathub-postgres psql -U postgres -d ai_chathub_db -c "SELECT slug, monthly_price_usd FROM subscription_svc.packages;"
docker exec aichathub-postgres psql -U postgres -d ai_chathub_db -c "\dt auth_svc.*"
```
