# AI ChatHub — Development Handoff Document
**Last updated:** 2026-08-07  
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

## 2026-07-20 Session — Fixed real bugs found by actually clicking "Send" in the chat UI

User tried GPT-4o Mini (no real key configured) and reported "nothing appears." Chasing that down
surfaced a chain of real bugs, each hiding the next one:

1. **Misleading error message.** `WalletClientService::reserve()` collapsed two very different
   outcomes into the same `false`: wallet-service genuinely denying the request (real insufficient
   balance) vs. the HTTP call to wallet-service itself timing out. Both produced "Insufficient wallet
   balance. Please top up." — actively wrong when the real problem was a cold container. Now returns
   `?bool` (`true`/`false`/`null`) and `CostTrackingMiddleware` reports each case accurately (402 vs
   503).
2. **Stack trace leakage.** Once reserve() actually succeeded, the real OpenAI call (401, invalid
   placeholder key) threw `Illuminate\Http\Client\RequestException` — a type ChatController's
   `catch (\RuntimeException $e)` never catches, so it fell through to Laravel's default handler and
   returned a raw stack trace (internal file paths, provider request details) as the JSON body.
   **Root architectural cause**, worth understanding for any future work in this controller: the
   actual provider HTTP call runs inside a `StreamedResponse`'s lazily-invoked generator, which
   `Illuminate\Foundation\Application::handleRequest()` only triggers via `$kernel->handle($request)
   ->send()` — *after* the controller method has already returned. No controller-level try/catch can
   ever see an exception thrown there; only a global `$exceptions->render()` handler
   (`bootstrap/app.php`) can. Added one for `RequestException`.
3. **Reservations were leaking money-tracking state on every failed request.** `CostTrackingMiddleware`
   only released a reservation in its success path (`.then()` → `deduct()`) — any provider failure
   left the reserved amount stuck in `reserved_balance` forever, invisible from `GET /wallet` (which
   only surfaces `available_balance`, itself computed from `balance`+credit, never subtracting
   `reserved_balance`). Confirmed live: repeated failed test requests grew a wallet's
   `reserved_balance` from 0 to 0.003+ with zero user-visible symptom.
   - First fix attempt (a try/catch around `$next($prompt)` in the middleware) **did not work** —
     same root cause as #2, that code path is also outside the generator's actual execution window.
   - Second attempt (`$app->terminating()` in bootstrap/app.php) **also did not work** — confirmed via
     direct Laravel source inspection: `Application::handleRequest()` is `$kernel->handle($request)
     ->send(); $kernel->terminate(...)` with zero exception handling of its own. An exception escaping
     `.send()` propagates all the way out as truly uncaught, so `terminate()` on the next line is
     simply never reached.
   - Third attempt (`register_shutdown_function()`, which PHP guarantees runs no matter how the script
     ends) **got further but still didn't work** — confirmed live via logging that the refund HTTP
     call started but never logged completion. PHP-FPM does not reliably give a shutdown function time
     to finish a *new* outbound network call once the response has already been sent to the client.
   - **What actually worked:** dispatch a queued job (`ReleaseWalletReservationJob`) from the shutdown
     function instead of calling `WalletClientService::refund()` directly — a Redis `RPUSH` completes
     fast enough to survive the shutdown window; the actual wallet HTTP call happens later in a
     separate, unhurried queue-worker process. Verified live: `reserved_balance` stayed exactly flat
     across a repeat of the same failing request, instead of growing.
   - **New requirement:** ai-gateway-service now needs its own running queue worker, same as
     auth-service already does: `docker exec -d aichathub-ai-gateway php artisan queue:work redis
     --tries=3`. Not started automatically — start it manually each session (see start-everything
     commands below).
4. **The queue worker itself was silently crashing on startup**, which is *why* the queued-job fix
   initially looked broken too. `services/ai-gateway-service/config/cache.php` didn't exist at all
   (same "missing config file" pattern as several earlier fixes this project) — Laravel's zero-config
   default falls back to the **database** cache store, and `queue:work` checks that cache for a
   `queue:restart` signal before pulling its first job. The `cache` table was never migrated for this
   service, so every worker start-attempt crashed immediately and silently (visible only by running
   it in the foreground — `docker exec -d` swallows the crash). Added `config/cache.php` defaulting to
   redis (matching the `.env`'s `CACHE_DRIVER=redis`, which Laravel 12 doesn't actually read without
   an explicit config file backing it).
5. Cleaned up ~0.0036 in stuck `reserved_balance` left over from repeated test failures during this
   debugging session (`UPDATE wallet_svc.wallets SET reserved_balance = 0` for the affected test user
   — confirmed no other wallet had nonzero `reserved_balance` needing the same cleanup).

**Net result:** GPT-4o Mini (and any other model without a real key) now fails with a single clear
message (`"This model is not configured correctly (invalid provider API key). Please try a different
model."`, HTTP 502) instead of hanging silently, leaking a stack trace, or quietly corrupting wallet
state. Real key still needed for OpenAI/Anthropic/xAI/ElevenLabs — only Gemini works today.

### Same day, later: Grok + DeepSeek keys added — surfaced one more gap, confirmed the fixes above generalize
User supplied real xAI and DeepSeek keys. Verified both directly against their providers before
wiring anything in (same approach as the Gemini key check):
- **xAI (Grok): key is valid but the account has zero credits/billing** — `/v1/models` 403s with
  `permission-denied`. Set `XAI_API_KEY` anyway so it's ready the moment billing is added at
  console.x.ai; `grok-beta` stays in `packages.model_access` (Pro tier) but will fail on every real
  request until then. Also: `grok-beta`'s `/v1/models` couldn't be checked (same 403), so unlike
  Gemini this model_id is **unverified** — flagged with a comment in `ModelSeeder.php`, re-check once
  billing is set up.
- **DeepSeek: key works.** Live model list returned `deepseek-v4-flash` / `deepseek-v4-pro` — newer
  naming than expected from training data, confirming (again) that checking the live API beats
  assuming a remembered model name is still current. Added the `deepseek` provider to
  `config/ai.php` (wasn't listed there — laravel/ai supports it, just needed the config/env
  entries), seeded both models + approximate pricing, added to `packages.model_access` (Flash on
  Basic+, Pro on Standard+).
- Actually generating a completion with the working DeepSeek key still failed —
  **`Laravel\Ai\Exceptions\InsufficientCreditsException`** (the key can list models but the account
  isn't funded for generation). This is a **different exception type** than the `RequestException`
  handled earlier — leaked a stack trace again until handled. Fixed properly this time: all of
  laravel/ai's own exceptions (`InsufficientCreditsException`, `RateLimitedException`,
  `ProviderOverloadedException`, `NoSuchToolException`) share one common base class,
  `Laravel\Ai\Exceptions\AiException` — added a single handler for that base class in
  `bootstrap/app.php` instead of chasing each subclass individually.
- **Confirms the earlier fixes generalize, not just patched for OpenAI specifically:** re-tested with
  a completely different provider/exception type and, on the first try, got a clean error message, no
  stack trace, and `reserved_balance` stayed exactly flat (the queued-job release fired correctly).

---

## 2026-07-20 Session (cont'd) — File/image upload, notification emails, and a critical infra gotcha

### File/image upload into chat — fully working, including real vision
Built from near-zero: chat-service had `FILESYSTEM_DISK=s3` / MinIO credentials already sitting in
`.env` but **no `league/flysystem-aws-s3-v3` package installed, no `config/filesystems.php`, and no
MinIO bucket created** — none of it had ever actually been exercised.
- Installed the S3 driver (`composer require league/flysystem-aws-s3-v3` — the `aws/aws-sdk-php`
  dependency is large enough that it timed out extracting on the first attempt at the default 300s
  Composer process timeout; needed `COMPOSER_PROCESS_TIMEOUT=900` to actually finish, same WSL2
  bind-mount I/O slowness theme as everywhere else in this project).
- Created the `aichathub-files` MinIO bucket (`mc mb` + `mc anonymous set download`) — it never existed.
- Built `FileAttachment` model, `FileAttachmentController` (`upload`/`destroy`, images only —
  JPEG/PNG/WebP/GIF, 10MB cap), `config/filesystems.php`.
- **Two separate browser-facing vs. container-facing URL problem**, worth understanding for any
  future storage work: `AWS_ENDPOINT=http://minio:9000` is only reachable from other containers, not
  the browser; `http://localhost:9000` is only reachable from the host, not from other containers —
  and **neither is reachable from a real AI provider's servers** (no public tunnel in local dev).
  Added `AWS_URL=http://localhost:9000/aichathub-files` for browser-facing image previews, and made
  ai-gateway-service fetch attachment bytes through chat-service's own internal API
  (`POST /internal/attachments/resolve`, returns base64) rather than handing the provider a URL —
  `Image::fromBase64()` instead of `Image::fromUrl()`. This works regardless of network topology.
- **Found and fixed a real API Gateway bug while wiring this up**: `ProxyController::forward()` used
  `$request->all()` to build the outgoing request body, which silently drops any `UploadedFile` — every
  previous endpoint proxied through the gateway was JSON-only, so this never surfaced before. Fixed by
  detecting `$request->allFiles()` and re-attaching via `Http::attach()`. That alone wasn't enough:
  the original client's `Content-Type: multipart/form-data; boundary=OLD` header was also being
  forwarded verbatim, but `attach()` builds a **new** body with its own boundary — header and body
  boundary disagreeing meant the receiving service's multipart parser silently found zero files. Fixed
  by unconditionally dropping `content-type` from the forwarded headers (Laravel's HTTP client sets an
  appropriate one on its own either way).
- Vision wiring: `ChatController::stream()` now accepts `attachment_ids` (max 4), checks the target
  model's `capabilities.vision` before allowing them, resolves them via chat-service, and passes
  `Image::fromBase64()` objects into `$agent->stream()`. Verified live with `gemini-2.5-pro` (the only
  vision-capable model with a working key) — hit a `RateLimitedException` from Gemini's free tier on
  the first attempt (this project has made a *lot* of Gemini calls today) and it resolved on its own
  after a short wait, not a code issue.
- Frontend: paperclip attach button (only shown when the active model supports vision), preview chip
  with remove button, wired into the existing send flow.

### Notification emails (M7) — built from a fully-empty scaffold
`notification-service` had empty `app/Mail`, `app/Models`, `app/Http/Controllers/Internal`, etc. —
directories existed, nothing inside them. Also had one pre-existing listener (`SendWelcomeEmail`)
referencing a `Notification` model and `WelcomeMail` class that **didn't exist anywhere**, and wasn't
wired to any event in the first place — deleted it as dead code rather than trying to resurrect it,
since this project's established pattern everywhere else is direct internal HTTP calls, not events.
- Built `Notification` model, 4 Mailables (`WelcomeMail`, `ReceiptMail`, `LowBalanceMail`,
  `RenewalFailedMail`) with a shared Blade layout component, `NotificationService` (idempotency via
  the existing `idempotency_key` unique constraint — Postgres treats multiple `NULL`s as distinct, so
  omitting a key just means "no idempotency protection," not an error), and one generic
  `POST /internal/notifications/send` endpoint other services call into.
- Wired real triggers: welcome email on email verification (auth-service, non-blocking via
  `afterResponse()`), receipt email on subscription purchase (subscription-service) and on wallet
  top-up — both the synchronous path (`TopupController`) and the async Stripe-webhook path
  (`ProcessStripeWebhookJob`), same idempotency key on both so a retry can't double-send — and low/
  critical balance alerts (wallet-service, at most one email per level per day).
- Found the exact same "missing config file → env var silently ignored" bug pattern **twice more**
  while wiring this: `wallet-service` had no `config/services.php` at all (first outbound call this
  service has ever made) and no `config/wallet.php` (so `LOW_BALANCE_THRESHOLD` in `.env` was never
  actually read — `checkBalanceThresholds()` always used the hardcoded `5.00`/`1.00` fallbacks
  regardless of what `.env` said). Both created.

### 🔴 Critical operational gotcha — force-recreating an app container without its nginx sidecar
Spent a long time chasing what looked like a routing bug (`wallet-service`'s own
`php artisan route:list` showed `POST api/internal/wallet/create` registered correctly, yet every
live HTTP request to it 404'd with Laravel's own "route not found" page) before finding the real
cause: **`docker-compose up -d --force-recreate wallet-service` only recreates the app container, not
its `wallet-nginx` sidecar.** The sidecar had been running for 3 hours (untouched) while the app
container it proxies to was 24 minutes old — the sidecar's upstream connection to the old (dead)
container's IP never got refreshed. Fixed by restarting the nginx sidecar too.
**Rule going forward: whenever you `--force-recreate` (or otherwise replace) an app container, restart
its nginx sidecar in the same breath** (`docker restart <service>-nginx`) — a plain `docker restart` of
the app container does NOT hit this (same IP retained), only recreate/replace does.
This silently broke wallet auto-creation on registration for a while during this session — worth
specifically checking `wallet_svc.wallets` has a row after a fresh registration if this class of bug
is ever suspected again.

### Also fixed while debugging the above
`RegisterController`'s wallet-creation `catch (\Exception $e)` widened to `catch (\Throwable $e)` —
the stale-sidecar failure surfaced in a way the narrower catch didn't reliably log, which is part of
why this took a while to pin down. `\Error`-family throwables don't extend `\Exception` in PHP.

### Queue workers are no longer a manual step
Every session up to this point required manually `docker exec -d`-ing a `queue:work` process into
`aichathub-auth` (and, as of today, `aichathub-ai-gateway`) after every `docker-compose up -d` — the
containers only run `php-fpm` (the Dockerfile's `CMD`), which serves HTTP requests and has no
awareness of Laravel's queue system at all; `queue:work` is a wholly separate long-running process
that has to be started explicitly. This was a real, easy-to-forget gap — the actual mechanism the
user asked to have explained. Fixed properly: added `auth-queue-worker` and
`ai-gateway-queue-worker` as their own services in `docker-compose.yml` (same image/build/`.env` as
their app counterparts, just `command: php artisan queue:work redis ...` instead of the default
`php-fpm`). `restart: unless-stopped` keeps them alive exactly like every other container — `docker-
compose up -d` alone is now sufficient, forever. If a future service gains a real `ShouldQueue` job,
it needs the same treatment (copy one of these two blocks, point it at the new service).

---

## 2026-07-21 Session — Model switching, wallet-vs-card payment, chat management, upload UX

Picked up from a feature-request/bug-report doc the user provided. All items verified live, not just
compiled.

### Chat: model tracking, mid-conversation switching, and a real conversation-history bug
- `chat_messages` gained a nullable `model_id` column (migration `0002_add_model_id_to_chat_messages.php`);
  `ai-gateway-service`'s `ChatController::stream()` now passes it on every `appendMessage()` call (both
  the user and assistant message), and `chat-service`'s `ChatInternalController::appendMessage()`
  syncs `chat_sessions.model_id` to "most recently used" on every message rather than a fixed
  session-creation-time value.
- Frontend `/chat` page rewritten: the model selector in the conversation header is now always visible
  and editable mid-conversation (previously only selectable at "New chat" time) — switching does not
  create a new session or clear history. Assistant messages show a small model-name badge.
- **Found and fixed a real, previously-invisible bug while doing this:** `send()` built the `/chat/stream`
  request body without a `history` field at all — every multi-turn conversation was silently losing all
  prior context on every message, for every user, since the chat page was first built. Not caused by
  the model-switching work; just never noticed because nothing was checking for it. Now sends the last
  30 messages as `{role, content}` pairs (the backend's `TextChatAgent` already fully supported
  `history` — it just never received it).
- Session list gained inline rename (pencil icon) and delete (trash icon, `window.confirm` gate) using
  the already-existing `PATCH`/`DELETE /sessions/{id}` endpoints — no backend changes needed, UI only.
- Image upload now shows a spinner + "Uploading…" chip immediately on file selection and disables Send
  until it completes, instead of giving no feedback during the upload window.

### AI provider error messages — specific instead of generic
Investigated a user-reported "Gemini 2.5 Pro is temporarily unavailable" 502 by grepping ai-gateway's
logs for the exact `session_id`/`attachment_id` in the report — confirmed via log evidence it was a
genuine `Laravel\Ai\Exceptions\RateLimitedException` (Gemini free-tier 429), not a code bug. Per the
user's request for more actionable messaging, `bootstrap/app.php` now has three specific handlers
(`RateLimitedException`, `InsufficientCreditsException`, `ProviderOverloadedException`) registered
before the existing generic `AiException` catch-all — each names the model and suggests switching,
instead of one generic "try a different model" message for every failure type.

### Wallet balance as a payment option for package purchases
User asked to verify why the Basic package showed no file-upload option (confirmed via DB: correct,
by design — Basic's `features.vision = false`) and why there was no upgrade UI (confirmed: genuinely
missing). Built:
- Real upgrade/downgrade/cancel UI on the pricing page (previously subscribe-only).
- `SubscriptionController::subscribe()` now takes `payment_source: wallet|card`. Wallet path reuses
  the reserve+deduct pair Wallet Service already uses for AI cost (new `chargeWallet()` helper) — a
  genuine debit, not the "wallet only ever gets credited, subscribing was free" behavior that existed
  before this session (confirmed via code read: `subscribe()` had a `// Phase 1: no Payment Service
  charge flow wired in yet` comment that was simply stale — the charge endpoint it was describing as
  unbuilt already existed and worked, just was never called from here).
- Frontend shows "Use Wallet Balance ($X Available)" vs "Pay with Card" only when the wallet balance
  actually covers the price.
- This `card` path was later fully superseded by the 2026-07-23 Stripe Checkout Session rewrite below —
  the direct-PaymentIntent-charge version built this session only lived for about two days.

---

## 2026-07-23 Session — Real Stripe Checkout Sessions (replacing the mock/direct-charge flow) + a real auth bug found by using the feature

### Wallet top-up and card-funded package purchases now use real Stripe Checkout Sessions
Both flows previously charged server-side using a hardcoded test `PaymentMethod` id (`pm_card_visa`) —
no hosted payment page, no user-entered card, and for subscriptions, activation happened synchronously
in the same request regardless of whether a real charge occurred. Replaced with genuine Stripe Checkout
(test mode): the user is redirected to a real `checkout.stripe.com` page, enters a Stripe test card, and
nothing is credited/activated until the payment is verified.

**Design**: "verify-on-return" (the frontend's return page asks Stripe directly whether the session was
paid, and completes it immediately) as the primary path, **plus** the `checkout.session.completed`
webhook as an idempotent authoritative backup — both funnel through one `CheckoutCompletionService`,
guarded by the transaction's own status, so double-processing is safe regardless of which lands first.
This means the feature works with zero extra local setup (no `stripe listen` required) while still being
genuinely webhook-capable for production.

**payment-service** — new/changed:
- `StripeGateway::createCheckoutSession()` / `retrieveCheckoutSession()` — `mode: 'payment'` with inline
  `price_data` (no Dashboard-created Products/Prices needed; this project models subscriptions as its
  own periodic one-time charges, not Stripe's native recurring `Subscription` objects).
- `CheckoutCompletionService::complete()`/`cancel()` — the single place a Checkout Session actually
  turns into a wallet credit or an activated subscription. Idempotent (no-ops if already `completed`).
- `TopupController::initiate()` rewritten — creates a `pending` Transaction + Checkout Session, returns
  `checkout_url` instead of confirming a charge synchronously.
- New `CheckoutController::verify()` (`GET /checkout/{sessionId}/verify`) — the frontend's verify-on-return
  endpoint.
- New `PaymentInternalController::createCheckoutSession()` (`POST /internal/payments/checkout`) — same
  create-pending-transaction-then-checkout-session logic, callable by subscription-service for the card
  path (shared with `TopupController` via a new `CreatesCheckoutSessions` trait).
- `ProcessStripeWebhookJob` now handles `checkout.session.completed`/`checkout.session.expired` instead
  of `payment_intent.succeeded`/`payment_intent.payment_failed` — Checkout's own session events are the
  correct signal for a Checkout-based integration per Stripe's own guidance.
- `transactions.status` gained `processing`/`cancelled` (kept `completed`, did not rename to `succeeded`
  — no DB enum, so no migration needed either way).
- Old direct-charge path (`PaymentInternalController::charge()`, `StripeGateway::charge()`) left in place,
  unused by the new flows, harmless — kept for a possible future saved-card one-click flow.

**subscription-service** — new/changed:
- New `PackageActivationService` — extracted the "create+activate a subscription, credit the wallet
  allowance, dispatch an invoice" sequence out of `SubscriptionController` so it can be called from two
  places: the existing synchronous wallet-purchase path, and the new webhook/verify-triggered card path.
- `SubscriptionController::subscribe()`'s `card` branch no longer charges directly — it now asks
  payment-service for a Checkout Session and returns `checkout_url`; the `wallet` branch is unchanged
  (still synchronous, deduct-then-activate immediately).
- New `SubscriptionActivationController::activate()` (`POST /internal/subscriptions/activate`) — called
  by payment-service once a card-funded purchase is verified paid. Defensively checks for an
  already-active subscription first and skips (log + 200) rather than erroring, since by the time a
  webhook fires the user could theoretically have already activated via another path.
- No new subscription-table migration needed — the pending-until-paid design means no `UserSubscription`
  row is created at all until activation, rather than creating one in a "pending" status and transitioning
  it later.

**api-gateway**: added `Route::any('/checkout/{path?}', ...proxyPayment...)`.

**frontend**: new `(dashboard)/billing/checkout-callback/page.tsx` — shared return-landing page for both
flows (`?type=topup|subscription&status=success|cancelled&session_id=...`), polls the verify endpoint a
few times with a spinner before falling back to "still confirming." `wallet/page.tsx` and
`pricing/page.tsx` both redirect to `checkout_url` instead of posting a hardcoded test token.

**Verified live**: Checkout Session creation for both a top-up and a package purchase (confirmed exact
amount/currency/metadata directly against Stripe's API), `verify()` correctly reports `pending` without
crediting early, the internal activation endpoint correctly activates + credits and safely no-ops on a
duplicate call (confirmed via DB), and — critically — a `UserSubscription` row genuinely does not exist
until activation runs, not just "charges then hopes."

### Real bug found from actual user testing: an auth hydration race that pre-dated this feature
User reported a real top-up (paid on Stripe, confirmed via Stripe's API: `payment_status: "paid"`) never
credited the wallet and left no trace in transaction history. Root-caused via server logs: the browser
never called the verify endpoint at all after returning from Stripe — instead it fetched `/chat` shortly
after, both times.

**Root cause**: `useAuthStore` (zustand-persist, JWT in `localStorage`) has no way to signal "I've finished
reading localStorage yet" — `(dashboard)/layout.tsx`'s auth guard checks `isAuthenticated` in a `useEffect`
that can run *before* rehydration completes. On a normal in-app click this window is irrelevant (the store
is long since hydrated). Returning from Stripe is a **full page reload** — the entire app restarts from
scratch — so the guard could see the false default, redirect to `/login`, which then immediately redirects
an (actually logged-in) user to `/chat` once rehydration catches up a moment later. The `session_id` and
Checkout return state were lost in that double-bounce. This bug already existed; nothing in the app had
ever done a real external-domain round-trip before Stripe Checkout, so nothing had exposed it.

**Fixed**: `auth-store.ts` gained a `hasHydrated` flag set via zustand's `onRehydrateStorage` callback;
`(dashboard)/layout.tsx`'s guard now waits for it before making any redirect decision.

**Also fixed for the user directly**: both of their stuck `pending` transactions were confirmed genuinely
paid via Stripe's API and manually completed via a new `checkout:complete {transaction_id}` artisan
command (`services/payment-service/app/Console/Commands/CompleteCheckoutTransaction.php`) — runs the
exact same `CheckoutCompletionService::complete()` path the frontend/webhook would have, so it's safe to
keep as a standing manual-reconciliation tool, not a one-off hack.

### Operational gotcha hit *again* this session (same class as 2026-07-20's, worth re-emphasizing)
`docker-compose up -d --force-recreate` on `payment-service`/`subscription-service`/`api-gateway` (needed
to pick up the `.env`/route changes above) silently broke *every* route on the gateway — not just the new
ones — because `gateway-nginx`/`auth-nginx`/`chat-nginx` (their sidecars, not recreated) still pointed at
the old containers' now-stale IPs. Same root cause and same fix as the 2026-07-20 `wallet-nginx` incident
already documented below, but it recurred because the rule ("restart the nginx sidecar too") isn't
automated anywhere — worth actually scripting if this trips someone up a third time.

---

## 2026-07-23 Session (cont'd) — Wallet idempotency completed + renewal automation built, both found real bugs live

Picked the two Priority-1 items from the work-distribution review. Both were built, tested live
against real failure scenarios (not just the happy path), and both surfaced genuine pre-existing bugs
that code review alone would not have caught.

### Wallet `deduct()`/`refund()` idempotency — same guard `credit()` already had
Added the identical `(type, reference_type, reference_id)` existence check to both methods. Wired real
reference IDs into the two places that actually call them: `CostTrackingMiddleware` now generates one
UUID per request (constructor) and passes it to `deduct()`; `ReleaseWalletReservationJob` generates one
at dispatch time (persisted across its `tries=3`, not regenerated per retry) and passes it to `refund()`.
Verified live: calling `deduct()` and `refund()` twice each with the same reference produced exactly one
ledger entry per pair, not two.

### Subscription renewal automation — `ProcessRenewalJob` built, wired, and scheduled
`ProcessRenewalsCommand` existed but dispatched a `ProcessRenewalJob` class that didn't exist — first
real run would have crashed immediately. Built:
- `PaymentChargeService` (new, subscription-service) — extracted `chargeWallet()` out of
  `SubscriptionController` (now shared, not duplicated) and added `chargeSavedCard()`, which charges a
  user's previously-saved default card directly via the "legacy" `/internal/payments/charge` endpoint
  kept from the Stripe Checkout rewrite — a background job has no browser to send anyone through
  Checkout with, so this is exactly the future use case that endpoint was preserved for.
- New payment-service endpoint `GET /internal/payment-methods/{userId}/default` — looks up a saved
  card's Stripe token for the renewal job to use.
- `SubscriptionService` gained `renewSuccess()`/`markPastDue()`/`cancelForFailedRenewal()` — same
  DB-transaction-plus-history-row pattern as the existing `subscribe()`/`upgrade()`/`downgrade()`.
- `ProcessRenewalJob` — wallet first, then saved card; 3 attempts total, 24h apart, self-rescheduling
  (`static::dispatch($id, $attempt+1)->delay(...)`) rather than a separate `RetryRenewalJob` class;
  cancels the subscription after the 3rd failure. Fixed `ProcessRenewalsCommand`'s dispatch call to
  pass `$subscription->id` (a string), not the Eloquent model itself — the job's constructor takes an
  ID + attempt number, not a model.
- `routes/console.php`: `Schedule::command('renewals:process')->hourly()`.
- `docker-compose.yml`: new `subscription-queue-worker` and `subscription-scheduler` services (same
  pattern as the existing `auth-queue-worker`/`ai-gateway-queue-worker` — `restart: unless-stopped`,
  runs `queue:work`/`schedule:work` instead of `php-fpm`). Also added `services/subscription-service/config/cache.php`
  proactively — the exact same "missing config file → queue:work silently crashes on the database cache
  fallback" bug that broke ai-gateway-service's worker on 2026-07-20 would have hit this one too.

### 🔴 Critical bug found live — every queue worker in this project was sharing one Redis queue
While testing the renewal job, it vanished with zero trace in subscription-service's own logs. Root
cause: **no service sets `REDIS_QUEUE`, so `auth-queue-worker`, `ai-gateway-queue-worker`, and the new
`subscription-queue-worker` were all blindly polling the exact same Redis list, `queues:default`.**
`ai-gateway-queue-worker` won the race, popped `App\Jobs\ProcessRenewalJob` (a class that only exists in
subscription-service's codebase), failed to unserialize it, and then failed to even log the failure
because `failed_jobs` isn't migrated in that service either — total silent loss. This is not a new bug
introduced this session; it's been latent since the very first queue worker was added, invisible only
because no two services had ever both had real jobs in flight before. **Fixed for every service with a
dedicated worker**: added `REDIS_QUEUE=<service>` to `auth-service`, `subscription-service`, and
`ai-gateway-service` (`.env` and `.env.example`), and `--queue=<service>` to each worker's command in
`docker-compose.yml`. **Any future service that gets its own queue worker needs the same treatment** —
a distinct `REDIS_QUEUE` value and a matching `--queue=` flag, or it's back to silent cross-service job
theft.

### Also found live: an ambiguous-timeout-as-failure bug, and a wallet-credit reference collision
Two more real bugs surfaced only by actually running the renewal job against a real (slow) environment,
not by reading the code:
- `chargeWallet()`'s deduct() call timed out client-side after 15s while the deduct had already
  succeeded server-side — the job correctly saw this as "no response" but incorrectly treated that as
  "failed," marking a successfully-charged subscription `past_due`. Fixed by retrying the deduct/charge
  HTTP calls on timeout (`->retry(2, 2000)`) — safe specifically because deduct() is now idempotent, so
  a retry against an already-completed charge just finds the existing ledger entry and no-ops instead of
  double-charging. Applied the same retry to `chargeSavedCard()`'s charge call (idempotent via
  `idempotency_key`) and the default-card lookup (a plain GET, always safe to retry).
- `ProcessRenewalJob::onSuccess()` originally passed `$subscription->id` as the wallet-credit reference —
  but that's the *same* reference the original purchase's credit already used, so `credit()`'s own
  idempotency guard correctly recognized it as "already credited" and silently no-opped every renewal's
  wallet allowance forever. Fixed by using the renewal's own per-cycle `$transactionId` as the reference
  instead.
- Also caught mid-build: `Illuminate\Support\Str::uuid5()` doesn't exist — that's Ramsey's own API
  (`\Ramsey\Uuid\Uuid::uuid5()`), not a Laravel `Str` facade method. Laravel's `Str::uuid()` (v4, no
  namespace) is real; `uuid5()` (deterministic, namespaced) is not exposed the same way.

**Verified live end-to-end**, both paths: a due subscription with sufficient wallet balance renews
correctly (charged, `renews_at` extended 30 days, wallet allowance credited under a distinct reference,
invoice created); a due subscription with insufficient balance and no saved card correctly goes
`past_due` with a real error message and a genuine 24h-delayed retry job sitting in the now-isolated
`queues:subscription:delayed`.

---

## 2026-07-23 Session (cont'd again) — Four real bugs found from actual user testing + password reset/profile built

Picked up right after the renewal-automation work. This pass was almost entirely driven by the user
actually using the app (not curl/DB verification) and reporting what broke — every one of the four
issues below was real and is now fixed and verified live, not theorized.

### 🔴 Money-integrity bug — wallet charged for a subscription that was never activated
Confirmed against the user's real account: wallet ledger showed a genuine `-$10.00 "Subscription:
Basic"` debit, but `user_subscriptions` had no row at all, and the response the user actually saw was
"Insufficient Balance" — i.e. they were told the charge failed while it had actually succeeded
server-side. Root cause: the exact "client times out, server already succeeded" pattern documented
throughout this project, hitting `SubscriptionController::subscribe()`'s wallet path specifically
(`PaymentChargeService::chargeWallet()`'s `deduct()` call). This is the *same* code path the renewal
job's retry fix (earlier today) already covers — this particular incident happened before that fix
landed, so it's already closed going forward. **Reconciled the user's account directly**: activated their
Basic subscription using the transaction reference already on record (`POST
/internal/subscriptions/activate`), confirmed live — subscription now active, wallet back to the
package's $10 allowance.

### 🔴 Duplicate receipts — a real concurrency bug in `CheckoutCompletionService`
Same user's Billing page showed 2 receipts for 1 top-up transaction. Root cause: `complete()` had no
row lock — its `if ($transaction->status === 'completed') return;` guard only protects against
*sequential* re-entry. Two near-simultaneous calls (verify-on-return racing the webhook, or the same
session polled from two browser tabs — see the multi-tab bug below) could both pass that check before
either had updated the row, and both call `createReceipt()`. **Fixed**: `complete()` now claims the row
under `lockForUpdate()` inside a `DB::transaction()` before doing any work — losing the race means
seeing `'processing'` (still claimed) or `'completed'` and returning immediately. A failed charge/
activation now explicitly reverts the claim to `'pending'` (not left at `'processing'`) so a genuine
retry can still claim it later — leaving it at `'processing'` would have permanently blocked all future
retries, since the claim check treats `'processing'` as "someone else already has this."

### Multi-tab session sync — real gap, fixed
Reported: logging in on one tab left a second tab still showing the login page, and navigating to
Wallet from that second tab bounced to `/login` despite being logged in on the other tab. Root cause:
zustand-persist writes to `localStorage`, but each browser tab holds its own separate in-memory copy of
the store and never notices another tab's write. **Fixed**: `auth-store.ts` now listens for the
browser's `storage` event and calls `useAuthStore.persist.rehydrate()` whenever another tab changes
`auth-storage` — a tab now picks up a login/logout from any other tab live, no reload needed.

### Post-payment "logged out, then auto-signed-in a few moments later" — real gap, fixed
Traced with real gateway logs, not guessed: `GET /api/v1/auth/me` returns **499** (client gave up
waiting) roughly **1 in 5 times** in this environment — not rare. `(dashboard)/layout.tsx`'s guard
treated *any* failure of that call, timeout included, as "not logged in": it called `clearAuth()` and
redirected to `/login`, then something else's later success made it look like an "automatic" sign-in a
moment after — actually just the session recovering from an unnecessary logout. **Fixed**: only a real
401 (token actually rejected by the server) clears the session now; anything else (timeout, network
error, 5xx) leaves the session alone and quietly retries up to 3 times (2s apart) instead of logging the
user out. This is the same "ambiguous vs. definite failure" pattern already used elsewhere in this
codebase (`WalletClientService::reserve()`, `describeError()`), just newly applied to the profile fetch.

### Google-only accounts have no way to add a password — confirmed, now buildable (see below)
Verified directly against the reporting user's row: `password IS NULL`, one linked `social_accounts` row
for `google`. This is correct, safe behavior, not data corruption — `users.email` has a real unique
constraint, so registering again with that email is correctly rejected ("An account with this email
already exists"), and logging in with a password against it correctly fails (no hash to check against).
The actual gap: there was no way to *add* a password to a Google-only account. The backend already had
half the plumbing for this (`/auth/me` returns `has_password`/`google_connected` specifically for this,
`SocialAccountController::unlinkGoogle()` already gates on `hasPassword()`) but the piece that lets a
user actually set one — and the Settings/Profile page that would expose it — didn't exist. Built this
session (see below).

### Built: password reset + an authenticated "set/change password" endpoint + a Profile page
- `PasswordReset` model (mirrors `EmailVerification`) — the `password_resets` table already existed,
  migrated but never used.
- `PasswordResetController::forgot()`/`reset()` — implemented the two routes that already existed in
  `routes/api.php` pointing at a `__call() → 501` stub. Same security posture as
  `EmailVerificationController::resend()` (generic response regardless of whether the email exists,
  2-minute throttle), same direct-`Mail::send()` pattern as the verification email (not routed through
  notification-service — this is auth-service's own domain). Token expires in 2 hours.
- `PasswordResetController::setPassword()` (new) — `POST /api/v1/auth/password/set`, authenticated.
  One endpoint covers both cases: a Google-only account just sets a password (no current password to
  check); an account that already has one must confirm the current password first (a real change).
  Added `services.frontend_url` to auth-service's `config/services.php` (env var already existed,
  just wasn't wired into config) so the reset email can link to the frontend's `/reset-password` page.
- Frontend: `(auth)/forgot-password/page.tsx` and `(auth)/reset-password/page.tsx` — both folders
  existed empty (the login page already linked to `/forgot-password`). Verified live end-to-end:
  requested a reset, pulled the real email from Mailpit's API, completed the reset, confirmed the new
  password works and the old one is rejected. Also verified `setPassword()`'s both branches live (wrong
  current password rejected, correct one accepted; no-current-password-required path confirmed by
  nulling a test user's password and calling it with only `new_password`).
- `(dashboard)/profile/page.tsx` (new) — account details, wallet balance overview, subscribed package
  overview, and a "Sign-in & security" section (Google connection status + unlink, set/change password
  form) all on one page. This *is* the "Settings" page from the backlog — built as one page rather than
  two, since a separate empty Settings page would just duplicate it; flagged this interpretation to the
  user rather than assuming silently.
- Header dropdown (`(dashboard)/layout.tsx`) — the plain "Sign out" button is now a
  `@radix-ui/react-dropdown-menu` trigger (already an installed dependency, unused until now) wrapping
  the avatar/email, with two items: **Profile** and **Sign out**. New reusable
  `components/ui/DropdownMenu.tsx` wraps Radix's primitives in this project's existing styling
  conventions (matches `Button.tsx`/`Card.tsx`).

---

## 2026-07-23 Session (cont'd again) — Real bKash Checkout Sessions (theihasan/laravel-bkash)

### bKash added as a third payment_source (wallet top-up + subscription purchase), verified live in bKash's real sandbox
- `composer require theihasan/laravel-bkash` — wraps bKash's tokenized Checkout API (`createPayment`/
  `executePayment`/`queryPayment`/`refundPayment`). Package's own built-in routes/controllers/DB tables
  left unused (`routes.enabled = false` in the published `config/bkash.php`) — this app calls the
  `Bkash` facade directly from its own controllers, same pattern as `StripeGateway` wrapping the Stripe
  SDK, keeping `transactions` as the single source of truth instead of the package's own tables.
- New `App\Services\BkashGateway` (`services/payment-service/app/Services/BkashGateway.php`) — mirrors
  `StripeGateway`'s role, not its shape (bKash's API is fundamentally different: no Session object, no
  signed webhooks). Converts USD→BDT via a fixed rate (`BKASH_USD_TO_BDT_RATE`, bKash only settles in BDT).
- `CreatesCheckoutSessions` trait gained `beginBkashCheckout()` alongside the existing Stripe
  `beginCheckout()` — same "pending Transaction → call gateway → store gateway_reference" shape, kept as
  a parallel method rather than a forced shared abstraction since the two gateways' response shapes
  don't match.
- `CheckoutController::verify()` now branches on `$transaction->gateway`. bKash's `executePayment()` is a
  **one-time, non-idempotent mutating call** (unlike Stripe's read-only `retrieveCheckoutSession`) — once
  a `trx_id` has been recorded, any retry uses the read-only `queryPayment()` instead, never re-executing.
- Used bKash's own **public sandbox demo credentials** (from the package README / bKash's developer
  docs) — a shared test-merchant account, not account-specific like Stripe test keys, so no separate
  signup was needed.
- **Verified live, twice, with real money-shaped sandbox transactions**: a $10 wallet top-up completed
  end-to-end (real bKash Checkout URL → real OTP/PIN entry on bKash's hosted page using their public test
  wallet `01770618575`/OTP `123456`/PIN `12121` → `executePayment` → wallet credited, confirmed via
  `wallet_ledger_entries`), and the reconciliation sweep (see next section) tested against a second,
  deliberately-unpaid transaction.

### Real bugs found and fixed while building this
- **`currency` field silently ignored (and misleading) for bKash requests** — `TopupController`/
  `PaymentInternalController` computed a `currency` value from the request but only ever passed it into
  the Stripe branch; bKash always assumed USD internally regardless of what was sent. Caught by a direct
  question ("isn't it inconsistent?") rather than by testing. Fixed by rejecting (422) any non-USD
  currency when `gateway: bkash` is requested, rather than silently ignoring it.
- Also fixed: **payment-service had no `config/cache.php`** — `CACHE_DRIVER=redis` in `.env` had no
  effect (same bug class already fixed for ai-gateway-service/subscription-service), which meant the
  bKash package's `Cache::` calls (token caching) crashed with `Undefined table: cache` on the very
  first checkout attempt.

## 2026-07-23 Session (cont'd again) — Closed 5 real gaps: rate limiting, CORS, route middleware, bKash reconciliation, Stripe webhook infra

### Rate limiting — `api-gateway`, none existed anywhere in the project before this
- Added `config/cache.php` + `config/database.php` to `api-gateway` (same missing-file bug class as
  above — `CACHE_DRIVER=redis` was set but inert; api-gateway has no real DB connection at all, so
  `database.php` only defines the `redis` block).
- New `app/Providers/AppServiceProvider.php` (+ `bootstrap/providers.php`, neither existed before) — four
  named limiters: `auth-strict` (10/min/IP — login/register/password-forgot/firebase),
  `auth-general` (30/min/IP — rest of `/auth/*`), `webhooks` (60/min/IP), `api` (`RATE_LIMIT_PER_MINUTE`
  env, keyed by `X-User-Id` header since this service has no Auth guard/User model to call `$request->user()` on).
- **Real bug found live while wiring this**: splitting `/auth/{path?}` into explicit per-endpoint routes
  (for tiered throttling) initially 404'd — not a routing failure, `ProxyController::proxyAuth(Request
  $request, string $path = '')` builds the upstream URL from the `path` **route parameter**; the new
  explicit routes had no `{path}` segment at all, so it silently defaulted to `''`, forwarding to a
  truncated upstream URL that itself 404'd, and that 404 got faithfully relayed back. Fixed with
  `->defaults('path', 'login')` etc. on each explicit route.
- Verified live: 12 rapid `/auth/login` calls → 429s kick in after the 10th, with the auth-service itself
  still reachable normally through the `api` tier.

### CORS — `api-gateway` only, deliberately not all 9 services
- No service had `config/cors.php`; Laravel 12's `HandleCors` middleware is in the default stack
  regardless, silently falling back to the framework's own wide-open `allowed_origins: ['*']`.
- Scoped to api-gateway only: CORS is a browser-enforced mechanism, and the browser only ever talks to
  api-gateway — backend-to-backend calls are server-to-server and never subject to it. Locking down the
  other 8 services would be pure busywork.
- **Real bug found live**: `config/cors.php`'s `allowed_origins => [config('services.frontend_url')]`
  came back empty for every request. Laravel loads config files **alphabetically** — `cors.php` loads
  before `services.php` exists in the container, so cross-referencing another config file from within a
  config file silently resolves to `null`. Fixed by reading `env('FRONTEND_URL', ...)` directly instead.
- `supports_credentials` stays `false` — auth is Bearer-token-in-header (localStorage), not cookie-based;
  unaffected by the new marker cookie below (same-origin, never sent cross-origin to the gateway).

### Frontend route-protection middleware — `frontend/src/middleware.ts` (new)
- Confirmed first (via a dedicated explore pass) that this was **not** a drop-in addition: JWTs live only
  in `localStorage` (zustand-persist), no cookie existed anywhere, login never issued `Set-Cookie` — and
  server-side middleware can only read cookies/headers, never localStorage.
- Chosen approach (user's explicit choice over a full httpOnly-cookie migration): a lightweight,
  non-httpOnly `has_session` marker cookie, set/cleared in `auth-store.ts`'s `setAuth`/`clearAuth`. It
  carries no token and isn't cryptographically verified — it only lets `middleware.ts` make a fast
  edge-redirect for the "definitely logged out" case. The actual JWT/localStorage/Bearer-token
  architecture, `(dashboard)/layout.tsx`'s client-side guard, and backend JWT verification are **all
  unchanged** and remain the real authorization boundary.
- Verified live in a running dev server: `/wallet` with no cookie → `307` to `/login` before any page
  renders; `/wallet` with `has_session` cookie present → `200`, page loads normally.

### bKash reconciliation sweep — the gap this gateway inherently has (no webhook, unlike Stripe)
- New `bkash:reconcile` command (`services/payment-service/app/Console/Commands/ReconcileBkashCommand.php`)
  + `ReconcileBkashPaymentJob`, mirroring `ProcessRenewalsCommand`/`ProcessRenewalJob`'s shape. Sweeps
  transactions `gateway=bkash, status=pending, created_at < 15 minutes ago`, resolves each via the
  **read-only** `queryPayment()` (never re-calls the non-idempotent `executePayment()`). No job-level
  self-rescheduling needed — the command's own 15-minute schedule is the retry cadence; the job only
  needs a 24-hour age ceiling so nothing sweeps forever.
- Verified live end-to-end: created a real (deliberately unpaid) bKash transaction, backdated it past 15
  minutes, ran the sweep — job correctly left it `pending` (bKash genuinely hadn't completed it, not a
  false positive). Backdated past 24 hours, re-ran — job correctly `cancelled` it.

### Payment-service queue infrastructure — a real, previously-invisible gap
- **`ProcessStripeWebhookJob` (`ShouldQueue`) had no queue worker at all** — found during the
  production-readiness audit, not something anyone had reported. A dispatched Stripe webhook (or the new
  bKash reconciliation job above) would have sat in Redis forever, never executing — the webhook path was
  code-complete but functionally inert. Added `payment-queue-worker` + `payment-scheduler` containers to
  `docker-compose.yml` (mirroring `subscription-queue-worker`/`subscription-scheduler` exactly) and
  `REDIS_QUEUE=payment` to `.env`/`.env.example` (same per-service queue-isolation fix as auth/
  subscription/ai-gateway earlier this session). Verified live: manually dispatched job ran and completed
  via `docker logs aichathub-payment-queue-worker`.
- **Stripe webhook delivery itself still needs a manual step**: the Stripe CLI isn't installed in this
  environment, and `stripe login` requires interactive browser OAuth against a real Stripe account — that
  has to be done by whoever owns the Stripe account, not automatable. Once done: `stripe listen
  --forward-to http://localhost:8000/api/v1/webhooks/stripe`, paste the printed `whsec_...` into
  `payment-service/.env`, force-recreate `payment-service` + restart its nginx sidecar, do a real top-up,
  confirm `webhook_events` gets a `checkout.session.completed` row with `status=processed`.

### Going live: Stripe & bKash — confirmed by this session's audit, no code changes needed
Both gateways were audited specifically for this. Neither `StripeGateway` nor `BkashGateway` has any
hardcoded test-mode logic — both are pure `env()`/`config()` reads. **Going live is purely a credentials
swap:**
- Stripe: `STRIPE_SECRET_KEY`/`STRIPE_PUBLISHABLE_KEY` → live-mode values (`sk_live_...`/`pk_live_...`,
  live-vs-test is inherent to the key prefix, no other flag), `STRIPE_WEBHOOK_SECRET` → the real signing
  secret from the live webhook endpoint (not the CLI-forwarding one used for local testing).
- bKash: `BKASH_SANDBOX=false` + real production `BKASH_APP_KEY`/`BKASH_APP_SECRET`/`BKASH_USERNAME`/
  `BKASH_PASSWORD` from a real registered bKash merchant account (the sandbox demo credentials obviously
  won't move real money).
- Both: point `FRONTEND_URL` (payment-service *and* the new `api-gateway` CORS config) at the real
  production domain, not `localhost`.

## 2026-07-27 Session — Real upgrade/downgrade proration policy (replaces the old "no proration" placeholder)

### The problem, found by working through real numbers with the user
`SubscriptionController::changePackage()` had a documented "Phase 1 simplification: no proration" —
upgrades never charged anything, and the wallet-credit adjustment was a difference between the two
packages' *nominal* allowance amounts, not anything tied to what the user actually had left. Traced
live: a user with $5 remaining after partial usage who upgrades Basic→Standard ended up with $15 —
not a number anyone could predict or explain. Downgrades reset the billing cycle immediately with zero
compensation.

### Policy landed on (Netflix/Spotify-style, not Stripe-style proration — see session notes for the reasoning)
- **Upgrade**: charge the **full new plan's price** immediately (not a difference) — via a real
  payment gateway (card or bKash) **only**. Credit the wallet with the **full new plan's allowance**,
  added on top of whatever's already there — never clawed back, since the wallet also holds direct
  top-up money indistinguishable from plan allowance at the ledger level. Applied **immediately**: tier
  switches now, cycle resets to a fresh 30 days, and the *next* auto-renewal bills at the new price.
- **Downgrade**: **not applied immediately**. User keeps current tier's access until the already-paid
  period ends. At the *next* renewal, the plan actually switches and future billing is at the lower
  price. No refund, no immediate change.
- **Follow-up correction, same session**: upgrade initially also allowed paying from wallet balance
  (mirroring `subscribe()`'s three-way wallet/card/bkash choice). Removed — wallet balance (whether
  topped up directly or granted as a plan allowance) is meant for AI-usage spending, not for
  self-funding a tier upgrade. Letting it pay for upgrades would let a user "upgrade" using credit that
  was itself a free perk, with no real new payment ever happening. `payment_source` for upgrade is now
  `card|bkash` only — `wallet` remains valid for the *first* `subscribe()`, which is unaffected.

### What shipped
- **`user_subscriptions.scheduled_package_id`** — already existed in the schema, migrated, in the
  model's `$fillable`, completely unused by any code until now. Exactly the field "downgrade takes
  effect at next renewal" needed — no migration required.
- `SubscriptionService::applyUpgrade()` / `scheduleDowngrade()` (replace the old `upgrade()`/
  `downgrade()`) — `services/subscription-service/app/Services/SubscriptionService.php`.
- `renewSuccess()` extended with an optional `$switchToPackage` param — when `ProcessRenewalJob` finds
  a `scheduled_package_id` set, it charges/credits based on that package instead of the current one,
  and `renewSuccess()` performs the actual package_id switch + clears the schedule in the same
  transaction once the charge succeeds.
- `SubscriptionController::doUpgrade()`/`doDowngrade()` (replaces the old shared `changePackage()`
  body) — upgrade now requires `payment_source` (wallet/card/bkash) like `subscribe()` does; downgrade
  takes only `package_slug`, no payment involved at all.
- New internal endpoint `POST /internal/subscriptions/activate-upgrade`
  (`SubscriptionActivationController::activateUpgrade()`) — mirrors the existing `activate()`'s
  deferred-activation shape for the card/bKash upgrade path (Checkout Session → verify/webhook →
  this endpoint), since a card-funded upgrade can't apply synchronously any more than a fresh
  card-funded subscribe can.
- payment-service: `PaymentInternalController::createCheckoutSession()` now accepts a `type`
  (`subscription_purchase` | `subscription_upgrade`) instead of hardcoding it — the
  `CreatesCheckoutSessions` trait already accepted `$type` as a parameter, so this was the only gap.
  `CheckoutCompletionService` gained a `completeUpgrade()` arm in its completion `match()`, calling the
  new activate-upgrade endpoint instead of `activate()`.
- Frontend `pricing/page.tsx` — upgrade reuses the existing wallet/card/bkash payment-source picker UI
  (same one built for first-time subscribe); downgrade is a single confirm button, no picker. A banner
  shows "You're switching to {package} on {date}" when a downgrade is scheduled
  (`subscription.scheduled_package`, new field on the `GET /subscription` response).

### Verified live, including the full renewal-time downgrade application
- Wallet-funded upgrade Basic→Standard: confirmed via ledger — a real $20 debit (`Upgrade: Standard`)
  and a real $20 credit (`Upgrade credit: Standard`) as two independent entries, not a single opaque
  number. (Also incidentally re-confirmed `WalletService::deduct()`'s existing "credit buffer" overdraft
  fallback is working as designed — not a bug, just something to remember exists when reading ledger
  entries that don't match balance arithmetic at first glance.)
- Downgrade Standard→Basic: confirmed `scheduled_package_id` set, `package_id`/`renews_at`/wallet
  completely untouched, user still has Standard's access.
- Forced a renewal (`renewals:process`, backdated `renews_at`) against a subscription with a pending
  scheduled downgrade: confirmed it charged **Basic's** $10 (not Standard's $20), and on success
  `package_id` switched to Basic, `scheduled_package_id` cleared, `renews_at` reset — the full
  scheduled-downgrade-applies-at-renewal path works end-to-end.
- bKash-funded upgrade: confirmed a real sandbox Checkout Session is created with
  `transactions.type = subscription_upgrade` and the right `package_slug` in metadata — the completion
  wiring is structurally identical to the already-verified `subscription_purchase`/`wallet_topup` paths,
  not separately fully replayed end-to-end this pass.
- Gotcha hit again during this pass, already documented but worth re-flagging: the
  `subscription-queue-worker` container needed an explicit restart after editing `ProcessRenewalJob`/
  `SubscriptionService` — the running `queue:work` daemon doesn't pick up code changes on its own.

## 2026-07-28 Session — Admin-auth foundation, per-package credit buffer %, transaction filtering

### Context
Two features the user wanted turned into real plans after earlier analysis-only discussions: the
per-package credit-buffer-percentage idea (replacing the flat $3-for-everyone default), and filtering
on list endpoints for admin-portal use. Both needed the same missing piece first: there was no
admin-auth mechanism anywhere in the codebase — `admin_users` was a real, migrated table (role,
permissions, is_active, FK to users) that nothing read or wrote, and no middleware checked it.

### Admin auth — reuses the existing `X-User-Id` pattern, not a new mechanism
- `AdminUser` model (NEW, `services/auth-service/app/Models/AdminUser.php`) over the pre-existing table.
- `User::getJWTCustomClaims()` gains `'is_admin' => AdminUser::where('user_id', $this->id)->where('is_active', true)->exists()` — computed fresh at every login/refresh, same place `email`/`status`/`name` already live.
- `api-gateway`'s `JwtGatewayMiddleware` forwards a new `X-Is-Admin` header alongside the existing `X-User-*` ones.
- `subscription-service` + `payment-service`: each service's `JwtAuthMiddleware` now captures `X-Is-Admin` into an `auth_is_admin` request attribute; new `AdminGateMiddleware` (alias `admin.gate`) 403s unless it's true; each `Controller.php` gained an `isAdmin(Request $request)` helper mirroring `authUserId()`.
- No api-gateway routing changes needed — existing proxy routes are already generic wildcards per resource, so new admin sub-routes on the same prefix (`/packages/{slug}`, `/transactions/admin`) were already reachable through the gateway; gating happens downstream.
- Known staleness, acceptable for Phase 1: revoking admin status only takes effect on next login/token-refresh (24h TTL) — same class of staleness the `status` claim already has.

### Per-package credit buffer percentage
- New migration `0002_add_credit_buffer_percentage_to_packages.php` — `packages.credit_buffer_percentage` decimal(5,2), default `30.00` (matches the old flat $3 on the $10 Basic package, so existing users see no behavior change).
- `Package::creditBufferAmount()` — `round(monthly_price_usd * (credit_buffer_percentage / 100), 2)`.
- New admin-gated `PATCH /packages/{slug}` (`PackageController::update()`) — any subset of name/description/prices/wallet-credit/buffer%/model_access/features/is_active/sort_order.
- `WalletService::credit()`'s old `bool $activateCreditBuffer` param is now `?float $creditLimit = null`. When given: `credit_limit = max(current, $creditLimit)` — **only ever raises, never lowers**, since shrinking the ceiling while `credit_balance` is already negative under a higher limit could violate the DB `CHECK (credit_balance >= -credit_limit)` constraint. `WalletInternalController`'s credit endpoint accepts `credit_limit` (nullable float) instead of the old `activate_credit_buffer` boolean. `config('wallet.credit_buffer_default')` removed — no longer a runtime fallback.
- All 4 call sites updated to pass `$package->creditBufferAmount()` instead of `true`: `PackageActivationService::activate()`, `SubscriptionController::doUpgrade()`, `SubscriptionActivationController::activateUpgrade()`, `ProcessRenewalJob::onSuccess()`.

### Transaction filtering
- `TransactionController::index()` (user-scoped, existing) gains optional query filters — `status`, `type`, `gateway`, `from`/`to` (date range on `created_at`) — via a shared `applyFilters()` helper. Pagination unchanged.
- New `TransactionController::adminIndex()` — same filters plus optional `user_id` (omit to span everyone). Admin-gated.
- New route `GET /transactions/admin`, registered before `GET /transactions/{id}` so `{id}` doesn't swallow the literal `admin` segment.
- No filtering existed anywhere else in the codebase before this (confirmed by search) — every other list endpoint remains pagination-only for now, scoped deliberately to transactions this pass.

### Verified live
- `is_admin` claim flips correctly: `false` for a normal user, `true` after inserting an `admin_users` row and re-logging-in; `X-Is-Admin` header confirmed reaching both services.
- `PATCH /packages/basic` → 403 as non-admin, 200 as admin, `credit_buffer_percentage` actually changed in the DB.
- `credit_limit` computed correctly via the internal wallet endpoint — Standard's 40% buffer on its price landed as exactly $8, not the old flat $3.
- "Only raise, never lower" confirmed: crediting with a lower `creditLimit` after a higher one had already been set left `credit_limit` unchanged.
- User-scoped `GET /transactions?gateway=bkash`, `?status=completed` narrowed results correctly; unfiltered behavior unchanged.
- `GET /transactions/admin` → 403 as non-admin; 200 as admin, spanning multiple users' transactions and respecting the same filters plus `user_id`.

---

## 2026-07-29 Session — Real RBAC (roles + permissions), audit logging, and per-module admin endpoints

### Context
Phase L's `is_admin` was a binary superuser gate — any admin could do everything. The user supplied a
spec (Platform Administrator / Finance Administrator / Customer Support roles, permission-scoped
dashboards, per-module filtering) and asked whether the app already supported real RBAC before building
anything. Investigation found the schema was already built for this and never wired up:
`admin_users.role` (varchar) + `admin_users.permissions` (jsonb) — migrated, unused; `audit_logs` table
in auth-service — migrated, unused; `spatie/laravel-permission` — a composer dependency, explicitly
disabled (`dont-discover`), never migrated. Decided (with the user) to build RBAC on the existing
`admin_users` columns rather than wire up Spatie — permissions must be forwarded across microservices
via JWT/headers regardless of which package manages them locally in auth-service, so Spatie wouldn't
have simplified the cross-service part, and it would've meant new tables for something that already had
a working, if dormant, representation. Also found two already-built-but-never-exposed pieces reused
here rather than rebuilt: `Internal\UserController@suspend/unsuspend` (auth-service) and
`Internal\PaymentInternalController@refund` (payment-service, real Stripe/bKash branching).

### RBAC design
Permissions (dot-namespaced strings like `payments.refund`, `wallet.adjust`, `users.suspend`) are the
source of truth checked at request time; `role` is only a label + creation-time template
(`services/auth-service/config/admin_roles.php`) used to populate `admin_users.permissions` when an
admin is created or their role changes — never re-consulted at authorization time, so a template change
doesn't silently alter an existing admin's actual grants. `chat_logs.view` is deliberately absent from
every role's default template — Customer Support's chat-history access is "where permission is granted"
per the spec, not a default.

### Claim → header → middleware chain
- `User::getJWTCustomClaims()` (auth-service) now also emits `admin_id`, `admin_role`,
  `admin_permissions` alongside the existing `is_admin`.
- `JwtGatewayMiddleware` (api-gateway) forwards three more headers: `X-Admin-Id`, `X-Admin-Role`,
  `X-Admin-Permissions` (JSON-encoded array).
- Every other service's `JwtAuthMiddleware` captures these into `auth_admin_id`/`auth_admin_role`/
  `auth_admin_permissions` request attributes (wallet-service, ai-gateway-service, and chat-service
  needed this added from scratch — only subscription-service and payment-service had any of this
  infrastructure from Phase L).
- Every `AdminGateMiddleware` gained an optional parameter: `admin.gate:payments.refund` 403s unless the
  admin has `'*'` or that exact string in their permissions; bare `admin.gate` keeps Phase L's
  any-admin behavior unchanged.
- auth-service's own `AdminGateMiddleware` is a different shape than the other 5 — it IS the source of
  truth for admin status, so instead of trusting a forwarded header it queries `admin_users` directly
  against the real `$request->user()` Tymon resolves, matching how `auth.jwt` already works there.

### Audit logging
- New `POST /internal/audit-logs` (auth-service) writes to the existing `audit_logs` table via a new
  `AuditLog` model — auth-service writes directly, every other service posts to this endpoint through a
  new small `AuditLogClient` class (mirrors the existing `AuthServiceClient` pattern) rather than calling
  it inline, so five nearly-identical HTTP client classes replace five nearly-identical inline blocks.
  Best-effort — a logging failure never blocks the admin action itself.
- Wired into every sensitive write this session added: package edits, refunds, wallet adjustments,
  suspend/unsuspend, admin role/permission changes.
- New `GET /auth/admin/audit-logs` — filters on `admin_user_id`, `resource_type`, `action`, date range,
  `ip_address`. No `status` column exists on `audit_logs` (every row is a request that was made, not
  tagged success/fail) — not adding one, per the user's explicit "no new columns for filtering" instruction.

### Admin management + per-module endpoints (all filters use only pre-existing columns)
- **auth-service**: `AdminUserController` (`GET/POST /auth/admin/admins`, `PATCH /auth/admin/admins/{id}`)
  — the "manage administrative roles" responsibility, gated to `admins.manage`. `UserManagementController`
  — `GET /auth/admin/users` (filters: name/email/phone ILIKE, `status`, `email_verified_at`,
  `last_login_at` range, `created_at` range — no `country` filter, that column doesn't exist),
  `POST /auth/admin/users/{id}/suspend`/`unsuspend` (reuses the exact logic already in
  `Internal\UserController`, now genuinely admin-reachable instead of internal-only). `DashboardController`
  — user counts/registrations. `AuditLogController` as above.
- **subscription-service**: `GET /subscription/admin` (filters: `status`, `renews_at` range,
  `auto_renew`, package slug join, `user_id` — no billing-cycle/expiration filters, neither concept
  exists), `GET /subscription/admin/dashboard`. `PATCH /packages/{slug}` tightened from bare
  `admin.gate` to `admin.gate:packages.manage`.
- **payment-service**: new `RefundService` (`app/Services/RefundService.php`) extracted from
  `Internal\PaymentInternalController@refund`'s Stripe/bKash branching, so the new
  `POST /transactions/{id}/refund` (Finance Administrator's "process refunds") and the pre-existing
  internal reversal path share one implementation instead of two. `GET /transactions/admin/dashboard`.
  `GET /transactions/admin` tightened to `admin.gate:payments.view`.
- **wallet-service**: had zero admin infrastructure before this session — built from scratch
  (`JwtAuthMiddleware`, new `AdminGateMiddleware`, `Controller.php` helpers, `bootstrap/app.php` alias).
  `WalletService::credit()`/`deduct()` gained an optional `string $type` param (defaults to the existing
  `'credit'`/`'debit'`) — the idempotency check and the written ledger row both use it, so
  `type=admin_adjustment` (already a documented-but-unused value in `wallet_ledger_entries.type`) tags
  admin-initiated changes distinctly, reusing every bit of existing balance/credit-buffer logic rather
  than adding a parallel code path. `GET /wallet/admin/ledger` (filters: `user_id`, `type`, amount range,
  date range, `reference_type`/`reference_id`), `POST /wallet/admin/{userId}/adjust`,
  `GET /wallet/admin/dashboard`.
- **ai-gateway-service**: also had zero admin infrastructure — same from-scratch build. New `UsageLog`
  and `CircuitBreakerState` Eloquent models over tables that existed but were only ever touched via raw
  `DB::table()`/never queried at all, respectively. `GET /models/admin/usage-logs` (filters: `user_id`,
  provider/model join, `status`, token range, `duration_ms` range, date range).
  `GET /models/admin/dashboard` — its "AI provider status" comes straight from `circuit_breaker_state`
  (closed/open/half_open), a real existing health signal, not anything new.
- **chat-service**: also had zero admin infrastructure — same from-scratch build.
  `GET /sessions/admin/users/{userId}` + `GET /sessions/admin/{sessionId}/messages`, read-only, gated to
  `chat_logs.view` (not in any default template — see RBAC design above).

### Gateway-routing gotcha, found and fixed mid-build
api-gateway's proxy routes are fixed per-resource-prefix wildcards (`/wallet/{path?}` → wallet-service,
`/packages/{path?}` → subscription-service, etc.), never a generic per-service passthrough. Every new
admin route was initially written under a bare `/admin/...` prefix, which matches **no** proxy route at
all — would have 404'd for every client going through the gateway despite working fine in direct
service-to-service testing. Fixed by nesting every admin route under an existing proxied prefix instead
of touching api-gateway at all: `/auth/admin/...`, `/subscription/admin/...`,
`/transactions/admin/dashboard`, `/wallet/admin/...`, `/models/admin/...`, `/sessions/admin/...`.
`/transactions/admin` and `/transactions/{id}/refund` needed no change — already under an existing prefix.

### Verified live
- Bootstrapped a `platform_admin` via direct DB insert (only way to create the first admin — every
  `/admin/admins` route requires `admins.manage`, which nothing grants until one exists), then created a
  `finance_admin` and a `support` admin through the real `POST /auth/admin/admins` API — both landed
  with `permissions` exactly matching their role's template.
- Decoded JWTs for all three confirmed `admin_id`/`admin_role`/`admin_permissions` claims correct.
- Permission checks confirmed both directions: finance_admin → 403 on `packages.manage`
  (`PATCH /packages/basic`), 200 on `payments.view` (`GET /transactions/admin`); support → 200 on
  `users.suspend`, 403 on `payments.refund`; a genuinely non-admin user → 403 on every admin route tried.
- `audit_logs` confirmed capturing real old/new-value diffs for a wallet adjustment and a refund
  (`status: completed→refunded`, `refunded_at` populated), joined to the acting admin's name/email.
- Wallet admin-adjust confirmed both directions (credit and debit), tagged `type=admin_adjustment` in
  the ledger; "only raise, never lower" on `credit_limit` re-confirmed still holds after the `$type`
  param addition to `credit()`.
- The new refund endpoint executed a **real** Stripe test-mode refund against a live sandbox
  PaymentIntent (`pi_3TwH2u9...`) — transaction flipped to `status: refunded` with a real
  `refunded_at`, not a mocked call.
- Every new filtered listing endpoint (`/auth/admin/users?status=active`,
  `/wallet/admin/ledger?type=admin_adjustment`, `/subscription/admin?status=active`,
  `/models/admin/usage-logs`) exercised with real filters, correct narrowing confirmed, pagination
  envelope matches the existing `TransactionController` shape.
- Recurring environment flakiness hit again this pass, always resolved by retry, never a real bug: a
  handful of admin POST requests (create-admin, wallet-adjust, refund) reported a client-side curl
  timeout while the request had actually completed successfully server-side — confirmed each time by
  checking the DB directly. First-request-after-restart connection warm-up is the suspected cause,
  consistent with prior sessions' notes on this environment.

---

## 2026-07-29 Session (cont'd) — Admin panel frontend

### Context
The backend admin API (previous session, same day) had nothing consuming it — the `(admin)` route
group was three empty folders, zero files. Built the full frontend now so the whole platform can be
exercised through a real UI. Investigated the existing frontend first (`(dashboard)/layout.tsx`'s guard
pattern, `lib/api-client.ts`, `lib/errors.ts` + `sonner` toasts, hand-rolled `<table>` convention) and
built in-style rather than introducing new patterns.

### What shipped
- **`services/auth-service/.../LoginController.php::me()`** — now also returns `is_admin`,
  `admin_role`, `admin_permissions` (same `AdminUser` lookup `getJWTCustomClaims()` already does) —
  the frontend trusts `/auth/me` for all user state, so admin status is exposed the same way rather
  than adding a `jwt-decode` dependency to read it out of the JWT client-side.
- **New shared UI primitives** (`frontend/src/components/ui/`): `Badge`, `Dialog` (thin wrapper over
  the already-installed `@radix-ui/react-dialog`, mirrors the existing `DropdownMenu.tsx` wrapper
  style), `Pagination`, `Input`, `Select`, `Label` — none existed before; every admin list page needs
  at least the first three. `lib/query-string.ts`'s `buildQueryString()` — no query-param builder
  existed anywhere in the frontend before this.
- **`frontend/src/types/index.ts`** — `User` gained `is_admin`/`admin_role`/`admin_permissions`; new
  `AdminUser`, `AuditLogEntry`, `AdminUsageLog`, `AdminSubscription`, `AdminMeta`, and one dashboard
  type per service. These do **not** reuse the existing `PaginatedResponse<T>` — real admin endpoints
  return `{ <resource_key>: T[], meta: { current_page, last_page, total } }`, no `per_page` in `meta`,
  a genuine shape mismatch caught before writing any page code.
- **`frontend/src/app/admin/`** (new, a literal folder — not the old empty `(admin)` route group, so
  URLs are genuinely `/admin/...`) — `layout.tsx` mirrors `(dashboard)/layout.tsx`'s hydration-wait +
  `/auth/me` guard, adds a redirect to `/chat` (not `/login`) for non-admins, and renders a sidebar
  filtered through `lib/permissions.ts`'s `hasPermission()` so each role only sees the nav items its
  `admin_permissions` actually cover. Pages: `page.tsx` (dashboard, fans out to all 5 `*/admin/dashboard`
  endpoints), `users/page.tsx` (+ suspend/unsuspend, + `users/[userId]/chat/[sessionId]` drill-down
  gated `chat_logs.view`), `admins/page.tsx` (role/permission management), `subscriptions/page.tsx`,
  `transactions/page.tsx` (+ refund via `Dialog` confirm), `wallet/page.tsx` (+ adjust-balance
  `Dialog`), `ai-usage/page.tsx`, `audit-logs/page.tsx`.
- `(dashboard)/layout.tsx` — sidebar gains a conditional "Admin" link (only when `user?.is_admin`), not
  an automatic redirect on login — an admin logging in still wants the normal chat UI by default.
- `middleware.ts` — added `/admin` to `PROTECTED_PREFIXES`/`matcher` (same `has_session` cookie check
  as every other protected prefix; the real `is_admin` check stays client-side in the new layout).

### Gateway-routing gotcha carried over from the backend session, re-confirmed
Every admin page calls its endpoint through the URL shape the previous session settled on after
discovering api-gateway's proxy routes are fixed per-resource prefixes, not generic passthroughs:
`/api/v1/auth/admin/...`, `/api/v1/subscription/admin...`, `/api/v1/transactions/admin...`,
`/api/v1/wallet/admin/...`, `/api/v1/models/admin/...`, `/api/v1/sessions/admin/...` — never a bare
`/api/v1/admin/...`.

### Verified live
- `tsc --noEmit`: clean, zero errors. `next build`: all admin code compiles and type-checks clean;
  the only build failures are two **pre-existing, untouched** pages (`/reset-password`,
  `/auth/callback`) failing on an unrelated Next 14 static-export requirement
  (`useSearchParams` needs a Suspense boundary) — confirmed via `git status` that neither file was
  touched this session.
- Browser-driven verification (Playwright) against the real running stack, using the three admins
  created and permission-tested last session: platform_admin sees all 8 sidebar items and a real,
  paginated 32-user table with working Suspend buttons; finance_admin correctly has no Admins/Audit
  Logs nav items and no Suspend button; support correctly has no Refund button on Transactions.
  Full suspend → unsuspend round trip exercised through the actual UI (click → toast → mutation →
  invalidate/refetch), confirmed against the database directly (`status` ends back at `active`).
- **Environment gotcha hit hard this pass, worth flagging for next time**: repeated Playwright script
  invocations that didn't reliably reach their `browser.close()` (backgrounded/timed-out runs) left
  30+ orphaned `chrome.exe` processes accumulating on the host. This appears to be what actually
  triggers this project's known "Docker Desktop/WSL2 host-networking failure" mode (documented
  earlier from a different trigger) — host resource exhaustion from orphaned browser processes
  degraded Docker Desktop's port-forwarding enough that even `api-gateway → auth-nginx` container-to-
  container HTTP calls started timing out (`cURL error 28`), while `ping` to the same host still
  worked. Fixed by `taskkill //F //IM chrome.exe` + restarting the affected nginx containers. Next
  time: always ensure Playwright scripts run with an explicit shell-level `timeout`, and check for
  orphaned `chrome.exe` processes before concluding a test failure reflects an app bug.

### Follow-up corrections, same session (found via real user testing, not automated QA)
- **Stale-token bug in `lib/api-client.ts`'s request interceptor** — it unconditionally overwrote
  `config.headers.Authorization` from whatever token was in the auth store, even when a caller (the
  login page, fetching `/auth/me` with the just-issued token immediately after `/auth/login`, before
  `setAuth()` had written it to the store) had already set an explicit, fresher header. A stale/expired
  token left over in the browser's `localStorage` from an earlier session silently clobbered the brand
  new one, causing `/auth/me` to 401 with `token_expired` immediately after a successful login. Fixed
  by only falling back to the store's token when the caller hasn't already set one.
- **Every post-login redirect sent admins to `/chat` like any other user** — login (both the
  already-authenticated guard and the post-submit redirect), register's guard, the OAuth callback page,
  Google sign-in, and the root `/` guard all hardcoded `/chat` with no `is_admin` check, so an admin
  had to manually click the sidebar's "Admin" link after landing in the end-user app. Per explicit user
  feedback ("the admin sidebar is for administration, not using the product — that should be what I
  see"), added one shared `lib/post-login-redirect.ts::postLoginPath(user)` (`is_admin ? '/admin' :
  '/chat'`) and wired it into all 6 call sites. Also found `FirebaseAuthController::authenticate()`'s
  response `user` object never included `is_admin`/`admin_role`/`admin_permissions` at all (unlike
  `LoginController::me()`, fixed earlier this session) — a Google-signed-in admin would always have
  been routed to `/chat` regardless of this fix without this catch. Admins can still reach the normal
  app anytime via the admin sidebar's existing "← Back to app" link.

---

## 2026-07-29 Session (cont'd, again) — Dynamic role management + package creation

### Context
Two more gaps surfaced from actually using the admin panel: "roles" were a fixed 3-entry list in
`config/admin_roles.php`, only ever used as a one-time template — the user wanted real, admin-editable
role objects (create a new role, define its permissions, edit it later with the change applying live to
every admin on it). And packages had no create path at all — `PackageController` could only edit the 3
seeder-created packages, never make a new one, and there was no admin UI for either.

### Roles — now real, database-backed objects
- New `roles` table (auth-service, `0002_create_roles_table.php`): `id`, `name` (unique), `permissions`
  (jsonb), timestamps — seeded in the same migration with the 3 existing roles' exact permission sets.
  `config/admin_roles.php` is deleted; nothing reads it anymore.
- `admin_users.role` stays a plain string column (no data migration needed — existing rows' values
  already matched the seeded names) but gained a real Postgres FK to `roles.name`. `admin_users.permissions`
  (the old jsonb column) is **dropped** — permissions are now resolved live from the assigned role.
- `AdminUser` model gained a `roleRecord()` relation and a `permissions` computed accessor
  (`$this->roleRecord?->permissions ?? []`). **Real bug caught and fixed before it shipped**: naming the
  relation `role()` (matching the existing `role` string column) would have silently broken — Eloquent
  checks real attributes before relation methods, so `$this->role` would always resolve to the raw
  string even from inside the model itself, making `$this->role?->permissions` a fatal "read property on
  string" error. Renamed to `roleRecord()`. Also caught: eager-loading matters here, since the accessor
  lazy-loads `roleRecord` — `AdminUserController::index()` now eager-loads it to avoid an N+1 per row.
- New `RoleController` (`GET/POST /auth/admin/roles`, `PATCH/DELETE /auth/admin/roles/{id}`, gated
  `admins.manage`). **Second real bug caught in design, before implementing**: cascading a role rename
  across every `admin_users` row referencing it, in the same request, is order-dependent under Postgres's
  default (non-deferred) FK checking — renaming either table first transiently violates the constraint.
  Simplified instead: block renaming a role while it's assigned (409, same guard `DELETE` already needs),
  which doesn't limit the feature that actually matters (editing permissions never touches `name`, so
  the live-link works regardless).
- `AdminUserController::store()/update()` simplified — role validated against the real `roles` table
  (`Rule::exists('roles','name')`) instead of a config array; the whole `permissions` request field and
  "re-template on role change" branch are gone, since permissions no longer live on the admin at all.

### Packages — real create path + admin listing
- New `PackageController::store()` (`POST /packages`, gated `packages.manage`) and `adminIndex()`
  (`GET /packages/admin`, same gate, returns every package including inactive ones — the public
  `index()` filters to active-only, which would hide exactly what an admin most needs to see/reactivate).
- **Same routing gotcha as `/transactions/admin` hit again, caught before shipping**: `GET /packages/admin`
  had to be registered *before* the public `GET /packages/{slug}` wildcard, or "admin" would be captured
  as `{slug}` and swallowed by `show()`.

### Frontend
- New `src/app/admin/roles/page.tsx` — list (name, permission count, admin count), create/edit via a
  permission checklist (`lib/permissions.ts`'s new `ALL_PERMISSIONS`, grouped by service) plus a "Full
  access (*)" toggle, delete disabled client-side (and rejected server-side) while a role has admins.
- New `src/app/admin/packages/page.tsx` — list + create/edit: pricing, wallet credit, credit-buffer %,
  a features checklist, and a model-access multi-select sourced from the existing `GET /models` catalog.
- `src/app/admin/admins/page.tsx` — the role picker now fetches real roles from the API instead of a
  hardcoded array.
- `src/app/admin/layout.tsx` — sidebar gained "Packages" and "Roles" nav items.
- New `AdminPackage`/`Role` types (`types/index.ts`) — `AdminPackage` deliberately does NOT reuse the
  public `Package` type, since the admin endpoints return raw Eloquent column names
  (`monthly_price_usd` etc.), not the public endpoints' hand-mapped `price: {usd, bdt}` shape.

### Verified live
- Created role `billing_support` (3 permissions) via the real API, assigned it to a test admin, decoded
  the fresh JWT — `admin_role`/`admin_permissions` matched exactly. Edited the role's permissions, logged
  in again (same admin, no reassignment) — the **fresh** JWT reflected the new permission set,
  confirming the live-link (not a frozen snapshot). Deleting a role with an admin assigned → 409;
  deleting an unused throwaway role → 200.
- Confirmed the pre-existing `finance_admin` test account's permissions are byte-identical after the
  migration — the string-based role FK preserved every existing admin's access with zero backfill.
- Created a new "Enterprise" package via the real API with a custom credit-buffer % and a specific
  model; confirmed it appears in both `GET /packages/admin` (admin, includes inactive) and the public
  `GET /packages` listing (since `is_active` defaulted true).

---

## 2026-07-29 Session (cont'd, x3) — AI Models admin management

### Context
Packages could already choose which AI models to grant, but the model catalog those checkboxes list
was seeder-only — no endpoint could add, edit, or retire a model. User asked for this to round out the
package-creation flow they'd just gotten (pick 4 of 10 models for Basic, 7 for Standard, etc.) with a
way to actually grow the "10" in the first place.

### Real gaps found while reading the schema before building
- `CostTrackingMiddleware::ratesFor()` silently falls back to a flat rate constant when a model has no
  active pricing row — its own comment already flags this as inaccurate. So the new form makes pricing
  required at model-creation time, not an optional afterthought.
- `model_pricing` is versioned (`effective_from`/`effective_until`/`is_active`), not a flat column set
  on the model — editing a rate closes the old row and inserts a new one, never mutates in place, so
  historical `usage_logs` still reflect whatever rate was genuinely active when they were recorded.
- **Neither `AiModel` nor `ModelPricing` declared `$fillable`** — harmless until now since only the
  one-time seeder ever touched these tables (via raw `DB::table()->insert()`, which bypasses Eloquent's
  mass-assignment guard entirely). The new admin controller is the first real `::create()`/`update()`
  caller, and would have thrown `MassAssignmentException` immediately without this fix.
- `usage_logs.model_id` is a real FK with no cascade — a model with usage history can't be hard-deleted.
  "Delete" in the new UI means deactivate, matching how the rest of the app already treats retirement
  (packages, admins) — never a real row delete.
- ai-gateway-service had no `AuditLogClient` yet (its only admin work so far was read-only) and was
  missing `auth_url` from `config/services.php`/`.env` entirely — added both, copied from the identical
  pattern in every other service.

### What shipped
- New `AiModelAdminController` (`services/ai-gateway-service`): `GET/POST /models/admin`,
  `PATCH /models/admin/{id}`, `PATCH /models/admin/{id}/activate|deactivate` — all gated by a new
  `models.manage` permission. Every write audit-logged.
- New `src/app/admin/ai-models/page.tsx` — list with current rate shown per model, create/edit dialog
  (model fields + a pricing-type-aware rate form), activate/deactivate action. Sidebar gained "AI
  Models" next to "AI Usage". `ALL_PERMISSIONS` gained the `models.manage` group.

### Verified live
- Created a real model with token-based pricing → appeared in both `GET /models/admin` and the public
  `GET /models` catalog immediately. Edited its pricing → confirmed via direct DB query that
  `model_pricing` now has **two** rows (the old one closed with `effective_until` set, is_active=false;
  the new one active with no `effective_until`) — not one row mutated in place. Deactivated it →
  confirmed it dropped out of the public catalog. All three actions (`model.created`, `model.updated`,
  `model.deactivated`) produced real rows on the Audit Logs page.

---

## How to Start Everything Tomorrow

```bash
cd "C:\Users\IT News\Downloads\aichathub\aichathub"

# 1. Start everything — queue workers included, no manual step needed anymore.
#    auth-queue-worker and ai-gateway-queue-worker are real docker-compose services
#    (see docker-compose.yml) that just run `queue:work` instead of php-fpm; Docker's
#    `restart: unless-stopped` keeps them alive the same as every other container.
#    (Before 2026-07-20 this required manually `docker exec -d`-ing a worker in after
#    every restart — that's gone now, don't reintroduce it.)
docker-compose up -d

# 2. Start frontend
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

**7. Payment Service — Stripe top-up** — ✅ implemented AND verified live (2026-07-19, rebuilt 2026-07-23)
`POST /api/v1/topup` and card-funded `POST /api/v1/subscription/subscribe` both now use real Stripe
Checkout Sessions (hosted page, real test-card entry) rather than a server-side direct charge — see
"2026-07-23 Session" above. `checkout.session.completed` webhook path is code-complete and exercised
by the design (verify-on-return uses the identical completion code), but genuine Stripe-CLI-forwarded
webhook delivery is still unconfirmed locally (`STRIPE_WEBHOOK_SECRET` is still `whsec_CHANGE_ME`) —
needs `stripe listen --forward-to http://localhost:8004/api/v1/webhooks/stripe` to fully verify that
specific path, though the feature works end-to-end without it.

### 🟢 Medium Priority

**8. Password reset flow** — ✅ done 2026-07-23, verified live end-to-end (forgot → real email via
Mailpit → reset → login with new password). Also added an authenticated set/change-password endpoint
and a Profile page exposing it.
**9. ~~WalletController~~** — ✅ implemented 2026-07-19 (`balance`, `creditStatus`)
**10. ~~LedgerController~~** — ✅ implemented 2026-07-19 (`GET /api/v1/wallet/ledger`, paginated)
**11. Notification email templates** — ✅ done 2026-07-20: all 4 Mailables (`WelcomeMail`, `ReceiptMail`,
`LowBalanceMail`, `RenewalFailedMail`) built and wired to real triggers (email verification, subscription
purchase, wallet top-up, low/critical balance). See "2026-07-20 Session (cont'd)" notes above.
**12. Billing service invoice/receipt generation** — ✅ done 2026-07-19: `InvoiceInternalController@create`,
`ReceiptInternalController@create`, public `InvoiceController`/`ReceiptController` (`index`/`show`)
all implemented and verified live; `download` (PDF) still not implemented

---

## Known Issues / Gotchas

| Issue | Status | Notes |
|---|---|---|
| `php artisan` commands hang | Known | WSL2 volume slowness — use sh scripts instead |
| PowerShell JSON quoting | Known | Always use shell scripts via `docker cp` + `docker exec` |
| Queue workers need manual starting | ✅ Fixed 2026-07-20 | `auth-queue-worker` / `ai-gateway-queue-worker` are now real docker-compose services, start automatically with `docker-compose up -d` |
| Firebase service account not in git | By design | Must copy `firebase-service-account.json` to `services/auth-service/` manually |
| `GOOGLE_CLIENT_ID` warning on docker-compose | Non-issue | Just a warning, not used (we use Firebase instead) |
| Login timeout in test scripts | Known | Login works fine; the test script timeout is too short for the full flow |
| Direct-to-service test scripts on `auth.jwt` routes now 401 | New (2026-07-19) | subscription/wallet/ai-gateway/chat/billing/payment now require `X-User-Id` header set by api-gateway's `JwtGatewayMiddleware` — any test script that curls a service's nginx directly (bypassing `localhost:8000`) on an `auth.jwt`-protected route needs to go through the gateway instead |
| Force-recreating an app container without its nginx sidecar → every route 404s | Known, recurred (2026-07-20, again 2026-07-23) | `docker-compose up -d --force-recreate <service>` gives the container a new internal IP; its `<service>-nginx` sidecar caches the old one at startup and won't notice. Symptom looks like a routing bug (routes are registered correctly, every live request 404s anyway) but is actually the sidecar talking to a dead IP. Fix: `docker restart <service>-nginx` in the same breath as any force-recreate. Has now happened twice — worth scripting if it recurs a third time. |
| Auth guard could bounce a logged-in user to `/login` after a full page reload | ✅ Fixed 2026-07-23 | zustand-persist's rehydration from `localStorage` isn't instant; `(dashboard)/layout.tsx`'s guard could read the false default before it finished. Only ever exposed by a genuine full-page external round-trip (Stripe Checkout was the first feature to do this) — fixed via a `hasHydrated` flag the guard now waits for. |
| Every queue worker shared one Redis queue — any worker could steal and silently lose another service's job | ✅ Fixed 2026-07-23 | No service set `REDIS_QUEUE`, so `auth-queue-worker`/`ai-gateway-queue-worker`/`subscription-queue-worker` all polled the same `queues:default`. Fixed with a distinct `REDIS_QUEUE` per service + matching `--queue=` flag. **Any future dedicated worker must follow the same pattern or it's back to silent job loss.** |
| `queue:work` daemon doesn't reload PHP files | Known | Unlike `php-fpm` (fresh per request), a `queue:work` process boots once and keeps running — editing a job class's code has no effect until the worker container is restarted (`docker restart <service>-queue-worker`). Cost real debugging time 2026-07-23 chasing a "fix" that the running worker hadn't actually picked up yet. |
| `api-gateway`'s `ProxyController` forwarded upstream response headers verbatim → any proxied route whose upstream response comes back `Transfer-Encoding: chunked` (chat-service, confirmed on `/upload`) hangs indefinitely client-side with 0 bytes received, even though the upstream service itself completes and logs a real 2xx | ✅ Fixed 2026-07-23 | `response($body, $status, $response->headers())` re-sent the upstream's `Transfer-Encoding`/`Content-Length`/`Connection` headers alongside a body Symfony re-serializes and computes its own `Content-Length` for — the conflicting framing info left nginx never actually flushing the response, so PHP-FPM's own access log shows 201 while the client just times out. Only surfaced now because file upload was the first proxied route whose upstream response happened to be chunked. Fixed in `ProxyController::forward()` by stripping hop-by-hop headers (`transfer-encoding`, `content-encoding`, `content-length`, `connection`, `keep-alive`) before building the outgoing response — let Symfony/nginx recompute framing for the actual re-serialized body. |
| `/chat/compare` (multi-model fan-out) leaked raw `StreamEvent` JSON into the `chunk` field and crashed mid-stream with `ob_flush(): Failed to flush buffer` | ✅ Fixed 2026-07-23 | `foreach ($agent->stream(...) as $event) { (string) $event }` stringifies whichever `Laravel\Ai\Streaming\Events\StreamEvent` subtype comes through (`stream_start`, etc.), not just text — only `TextDelta` instances carry a real `->delta` string. Separately, `ob_flush()` requires an active user-level output buffer that was never started here, so every chunk crashed after the first. Fixed by filtering for `$event instanceof TextDelta` (echoing `$event->delta`, skipping other event types) and dropping the `ob_flush()` calls, keeping `flush()` alone (which is what actually pushes bytes through PHP-FPM/nginx regardless of `ob_*` state). Verified live: clean per-model text chunks, no crash. |
| `ProcessStripeWebhookJob` (`ShouldQueue`) had no queue worker in payment-service at all | ✅ Fixed 2026-07-23 | Found during a production-readiness audit, not reported by anyone — the Stripe webhook path was code-complete but a dispatched job would sit in Redis forever with nothing to process it. Added `payment-queue-worker`/`payment-scheduler` containers (mirroring subscription-service's) + `REDIS_QUEUE=payment`. This is also what makes the new `bkash:reconcile` sweep's jobs actually run. |
| Config file cross-referencing another config file inside itself silently resolves to `null` | ✅ Found + fixed 2026-07-23 | `config/cors.php`'s `allowed_origins => [config('services.frontend_url')]` came back empty for every request — Laravel loads config files **alphabetically**, so `cors.php` loads before `services.php` exists in the container. Fixed by reading `env('FRONTEND_URL', ...)` directly inside `cors.php` instead of cross-referencing `services.php`. Worth remembering for any future config file that wants a value another config file computes. |
| Splitting a proxy wildcard route (`/auth/{path?}`) into explicit per-endpoint routes silently breaks the proxy unless you also supply the `path` value | ✅ Found + fixed 2026-07-23 | `ProxyController::proxyAuth(Request $request, string $path = '')` builds the upstream URL entirely from the `path` route **parameter** — an explicit route with no `{path}` segment in its URI (e.g. `Route::any('/auth/login', ...)`) leaves `$path` at its default `''`, silently forwarding to a truncated upstream URL that itself 404s, and that 404 gets faithfully relayed back to the client (looks exactly like "the route doesn't exist," but `route:list` shows it registered correctly). Fixed with `->defaults('path', 'login')` (etc.) on each explicit route. |

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
✅ Payment Service: Stripe Checkout Sessions (top-up + card-funded subscribe) — rebuilt 2026-07-23,
  real hosted-page flow replacing the old direct-charge/hardcoded-token version
✅ Wallet credited on subscription purchase AND top-up (idempotency-guarded)
✅ Wallet-vs-card payment choice on package purchase — 2026-07-21
✅ Invoice + receipt generation — verified live
✅ Frontend: pricing page + subscribe/upgrade/downgrade/cancel flow — 2026-07-21
✅ bKash Checkout Sessions (top-up + subscription purchase) — 2026-07-23 (cont'd), real sandbox
  payment verified live end-to-end, plus a `bkash:reconcile` sweep for its (webhook-less) completion gap
✅ Auto-renewal scheduler — `ProcessRenewalJob` built + scheduled, verified live including the
  failure/retry path — 2026-07-23
✅ payment-service queue infrastructure — `payment-queue-worker`/`payment-scheduler` added 2026-07-23;
  `ProcessStripeWebhookJob` (dispatched since 2026-07-19) had literally nothing to run it until now
⬜ Stripe webhook path — code-complete AND now has a worker to actually run it; genuine
  Stripe-CLI-forwarded delivery still needs `stripe listen` (requires the CLI + interactive login,
  someone with the real Stripe account) to confirm end-to-end — not required for the feature to work
✅ Real upgrade/downgrade proration policy — 2026-07-27, verified live including the full
  renewal-time downgrade application
✅ Per-package credit buffer percentage (replaces flat $3 default) — 2026-07-28, verified live (see session notes above)

Week 5-6: AI Chat MVP                      ← DONE (Gemini + DeepSeek; more polish added 2026-07-21)
✅ AI Gateway: chat streaming — verified live with real Gemini 2.5 Flash, Session 2 (2026-07-19)
✅ Balance reserve/deduct cycle — verified, accurate per-model cost (not a flat estimate)
✅ Chat Service: session + message storage — verified live, Session 2
✅ Frontend: chat interface with SSE — built, compiles clean, not yet click-tested in a browser
✅ packages.model_access populated
✅ Mid-conversation model switching + per-message model tracking — 2026-07-21
✅ Conversation history actually sent to the model — 2026-07-21 (was silently missing since /chat's build)
✅ File/image upload + real vision — 2026-07-20 (cont'd session)
✅ Chat session rename/delete UI — 2026-07-21
✅ Upload progress UX (spinner, disabled Send during upload) — 2026-07-21
✅ /chat/compare (multi-model comparison) frontend UI — 2026-07-20
✅ Provider-specific error messages (rate limit / no credits / overloaded) — 2026-07-21
⬜ Real API keys for OpenAI/Anthropic/ElevenLabs — Gemini and DeepSeek both work now (free/cheap);
  xAI has a valid key but zero account credits (grok-beta will 502 until billing is added)
✅ WalletService::deduct()/refund() idempotency guard — 2026-07-23
✅ /chat/compare fixed (raw event JSON leak + ob_flush crash) and vision pipeline verified live — 2026-07-23
✅ Browser click-through QA — ongoing, organically finding real bugs each pass (see Known Issues)

Week 7-8: Billing + Wallet UI              ← DONE
✅ Wallet balance + ledger endpoints — verified live
✅ Transaction history endpoint — verified live
✅ Invoice + receipt generation — verified live
✅ Frontend pages for all of the above (dashboard, pricing, wallet, billing, chat)
✅ Real Stripe Checkout replacing the hardcoded test-token flow — 2026-07-23
⬜ Invoice PDF download (InvoiceController::download() still a 501 stub)
⬜ Settings page (folder exists, no page file — confirmed empty 2026-07-23), saved payment methods UI

Week 9-10: Polish                          ← IN PROGRESS
✅ Notification emails (welcome, receipt, low balance, renewal-failed) — 2026-07-20 (cont'd session)
✅ Password reset + set/change password + Profile page + header dropdown — 2026-07-23, verified live
✅ Rate limiting (api-gateway, 4 tiers) — 2026-07-23, verified live (429s after the 10th rapid login)
✅ CORS hardening (api-gateway, scoped deliberately to just that service) — 2026-07-23
✅ Frontend route-protection middleware (`src/middleware.ts` + marker cookie) — 2026-07-23, verified live
✅ Admin-auth foundation (`is_admin` JWT claim → `X-Is-Admin` header → `admin.gate` middleware) — 2026-07-28, verified live
✅ Real RBAC (roles/permissions, not just binary admin) + audit logging + admin endpoints across all
  6 backend services (users, subscriptions, transactions/refunds, wallet, AI usage, chat logs) — 2026-07-29,
  verified live including a real Stripe refund — see "2026-07-29 Session" notes above
✅ Admin panel frontend — 2026-07-29, verified live: role-gated sidebar, dashboard, users (+ suspend +
  chat-history drill-down), admins, subscriptions, transactions (+ refund), wallet (+ adjust), AI usage,
  audit logs — all under `frontend/src/app/admin/` (a real `/admin/...` URL, not the old empty
  `(admin)/models` etc. route group)
✅ Dynamic role management — real `roles` table (not a config file), live-linked permissions, admin
  UI — 2026-07-29 (see "Dynamic role management + package creation" session notes above)
✅ Package create + admin listing (was edit-only before) — 2026-07-29
✅ AI Models admin management — create/edit/deactivate models with versioned pricing, gap-closed
  ($fillable missing on both AiModel/ModelPricing, caught before it shipped) — 2026-07-29
✅ Admin account management — logout + profile access from the admin panel itself (was missing
  entirely; only had a "back to app" escape hatch) — 2026-07-29
✅ Two real auth bugs found via actual user testing and fixed same day: a stale-token-in-localStorage
  bug in `api-client.ts`'s request interceptor, and every post-login redirect sending admins to `/chat`
  like a regular user instead of `/admin` — see "Follow-up corrections" in the admin-panel-frontend
  session notes above
⬜ End-to-end smoke testing in an actual browser — in progress organically: this whole session's real
  bugs (chat/compare crash, file-upload proxy hang, currency inconsistency, config load-order bug, a
  route-splitting proxy bug) all came from actually exercising features, not from reading code
```

**Overall Phase 1: ~99% complete.** Every core money/chat flow (register → subscribe → upgrade/downgrade
→ wallet → top-up → invoicing → real AI chat) is built AND verified end-to-end against the live stack,
including genuine Stripe **and** bKash Checkout payment experiences, a real (not placeholder)
upgrade/downgrade billing policy, a full password-reset/account-security flow, real rate limiting,
CORS, edge-level route protection, and a complete admin system: RBAC with live-editable roles, audit
logging, package management, AI model catalog management, and a real frontend spanning all of it.
Explicitly **not** in Phase 1 scope right now, by the user's own call: AI token-cost calculation, the
revenue/business model, and sustainable AI-usage-limit strategy — under separate observation/planning,
not blocking anything below.

### Priority order for what's left (see also the shareable work-distribution doc)
1. ~~Wallet `deduct()`/`refund()` idempotency~~ — ✅ done 2026-07-23
2. ~~`ProcessRenewalJob` + scheduling~~ — ✅ done 2026-07-23 — also surfaced and fixed a project-wide queue-collision bug
3. ~~Password reset + Profile page + header dropdown~~ — ✅ done 2026-07-23
4. ~~Real bKash Checkout Sessions~~ — ✅ done 2026-07-23, verified live in bKash's real sandbox
5. ~~Rate limiting, CORS, frontend route middleware, bKash reconciliation, Stripe webhook infra~~ —
   ✅ done 2026-07-23, all verified live
6. ~~Real upgrade/downgrade proration policy~~ — ✅ done 2026-07-27, verified live including the
   renewal-time downgrade application
7. ~~Admin-auth foundation + per-package credit buffer % + transaction filtering~~ — ✅ done 2026-07-28,
   all verified live (see "2026-07-28 Session" notes above)
8. ~~Real RBAC + audit logging + admin endpoints across all 6 services~~ — ✅ done 2026-07-29
9. ~~Admin panel frontend~~ — ✅ done 2026-07-29
10. ~~Dynamic roles, package creation, AI model catalog management, admin account management~~ —
    ✅ done 2026-07-29 (all same-day follow-ups once the admin panel was actually being used)

### 2026-07-30 Session — Admin/end-user separation, real click-through testing begins

- ~~Commit and push everything~~ — ✅ done (`5ad17ed Admin panels basic structure enabled`) — the
  79-file risk item from end of last session is resolved, only trivial diffs sit uncommitted now.
- **Real bug found by the user's own manual testing, not automated QA**: logging into
  `abcd@gmail.com` (used all last session as the "plain non-admin" test account) landed on the admin
  dashboard instead of `/chat`. Root cause: that exact account had been promoted to the `billing_support`
  role during yesterday's dynamic-roles verification and never demoted afterward — a leftover test
  artifact, not a real permission-boundary bug. Fixed by deactivating that `admin_users` row directly
  in the DB. This is the first real finding from the "manually click through the new admin surface"
  item on yesterday's list — confirms that pass is worth continuing.
- **Admin/end-user interface fully separated, per explicit user request**: removed the conditional
  "Admin" link from `(dashboard)/layout.tsx`'s sidebar (no account, admin or not, sees it there
  anymore) and removed the "← Back to app" link from `admin/layout.tsx`'s sidebar. The two surfaces
  are now one-directional by navigation: an admin's only way out of `/admin` is Sign out (the header
  dropdown added earlier this week), not a link back into the consumer app. Note: this removed the
  *links*, not a hard block — an admin account typing `/chat` directly in the address bar can still
  reach it; only URL-level enforcement would close that, not requested (yet).
- **Second real bug found by the user's manual testing — a genuinely significant one**: created a test
  package via the new admin panel, then tried to actually upgrade a real subscriber to it with a card.
  Got a 502 `checkout_failed`. Root cause, confirmed by directly reproducing the internal call: every
  card-funded checkout (`SubscriptionController::createGatewayCheckout()`, used by both `subscribe()`
  and `doUpgrade()`) has been sending `payment_source` (`"card"`/`"bkash"`) straight through as the
  `gateway` field to payment-service's internal checkout endpoint — but that endpoint validates
  `gateway` against `in:stripe,bkash`, not `card`. `"bkash"` matched by pure coincidence; `"card"` was
  never translated to `"stripe"` anywhere in the flow, since this was built. **A card-funded
  subscribe/upgrade had apparently never actually been exercised end-to-end before this exact test** —
  wallet-funded and bKash-funded paths were the ones previously verified live (see the 2026-07-27
  proration session notes: "bKash-funded upgrade... not separately fully replayed end-to-end").
  Fixed by translating `card` → `stripe` inside `createGatewayCheckout()` (the one place that talks to
  payment-service, rather than every call site needing to know the mapping) — verified live: both a
  fresh card-funded `subscribe()` and a card-funded `doUpgrade()` now return real Stripe Checkout URLs.
  Also fixed a related observability gap while in there: a non-2xx response from payment-service on
  this call was previously silently swallowed (no exception thrown, so the `catch` block's logging
  never fired, and the success/failure branch itself didn't log anything either) — confirming this bug
  required manually reproducing the internal HTTP call rather than reading a log line. Now logs the
  actual status/body on any non-success response.
- **Explicit business-rule clarification from the user, then implemented immediately**: wallet balance
  is never a way to pay for the subscription itself — not the first purchase, not an upgrade, not a
  renewal. All three always move real money through Stripe or bKash. Upgrades already worked this way;
  fixed the other two:
  - `SubscriptionController::subscribe()` — `payment_source` no longer accepts `wallet` (now
    `card`/`bkash` only, matching `doUpgrade()`). A `$0` package is the only case that still activates
    with no payment step, since there's genuinely nothing to charge. The now-dead wallet-charging
    branch, and the now-unused `PaymentChargeService` dependency it was the only caller of in this
    controller, were removed rather than left as dead code.
  - `ProcessRenewalJob` — dropped the `chargeWallet() ||` half of the charge chain; renewal now only
    ever tries the saved card. `PaymentChargeService::chargeWallet()` itself is deleted — confirmed via
    search it had zero remaining callers anywhere in the service after both call sites were removed.
  - Frontend `pricing/page.tsx` — removed the "Use Wallet Balance" subscribe button and the wallet-
    balance query that only existed to power it; intro copy updated to state the rule plainly.
  - Verified live: `POST /subscription/subscribe` with `payment_source: wallet` now correctly 422s
    ("The selected payment source is invalid."); a card-funded subscribe and the upgrade fixed earlier
    this session both still return real Stripe Checkout URLs.

### 2026-07-30 Session (cont'd, again) — Concurrency architecture analysis (Octane/FrankenPHP) — analysis only, no code changed

User asked for an honest rating of how the current architecture handles concurrent users, after asking
where Octane/FrankenPHP fit. Findings, grounded in the actual code/config (not assumption):

- **Octane/FrankenPHP is not installed anywhere** — every service (`infrastructure/docker/php/Dockerfile`)
  runs plain `php:8.3-fpm-alpine`, `CMD ["php-fpm"]`, behind an nginx sidecar per service. No
  `laravel/octane` in any `composer.json`.
- **`pm.max_children` is unconfigured** — no `www.conf` override anywhere in `infrastructure/docker/php/`,
  so every one of the 9 services is running Alpine's stock php-fpm pool default: **5 concurrent requests
  per service, full stop.** Free, zero-risk fix available any time (add a `www.conf`), independent of the
  Octane decision.
- **Root project doc `scaling_architecture.md`** (not in `docs/`, separate from
  `AI_ChatHub_Microservices_Architecture.md`) explicitly targets **1,000–10,000 concurrent users for
  Phase 1**, and specifies the Phase 1 stack as **"Laravel 12 + Octane (Swoole/RoadRunner)"** — i.e. Octane
  was planned as part of Phase 1 itself, not deferred to Phase 2. `AI_ChatHub_Microservices_Architecture.md`
  disagrees — its Phase 1 section never mentions Octane and describes plain Docker Compose on a single VPS.
  The two planning docs conflict; `scaling_architecture.md` is the only one that ties a concrete
  concurrency number to a required stack, so treating that as the real target.
- **Why this is a real gap, not just a pool-tuning gap**: confirmed via `CostTrackingMiddleware.php`
  (ai-gateway-service) that chat streaming runs the AI provider call inside a `StreamedResponse`'s lazy
  generator — every active streaming chat response blocks one php-fpm worker process for the entire
  response duration (seconds), not a quick request/reply. That's the exact bottleneck
  `scaling_architecture.md` itself names (§1A) as the reason Octane is required, not optional, once
  streaming is in the picture. Pool tuning alone can raise the ceiling from 5 to maybe a few hundred (real
  OS processes, real RAM each) but can't reach four-digit concurrent streaming sessions on one host —
  that needs an event-loop runtime (Octane's Swoole/RoadRunner, or FrankenPHP's worker mode).
- **Open question, not yet resolved**: whether "1,000–10,000 concurrent" means that many users with an
  open streaming response at the same instant, or that many total active users (mostly idle, a smaller
  fraction actively streaming). Changes urgency significantly; wasn't pinned down this session.

**Decision (confirmed 2026-07-30): next session (2026-07-31) implements Laravel Octane**, piloted on
`ai-gateway-service` and `chat-service` only (the two services with the blocking-stream pattern) — the
other 7 stay on plain php-fpm, no benefit to converting them. Driver choice (Swoole vs RoadRunner) still
to be made at implementation time; Swoole is the more common pairing. **FrankenPHP is explicitly not a
separate roadmap phase** — it's an alternative driver Octane can run on, swappable in later (mainly if we
also want to drop the nginx sidecars) without changing the concurrency ceiling further. Phase 3 in
`scaling_architecture.md` remains what it already says: moving the connection tier off PHP onto Go/Rust
— not a PHP-runtime swap. All three architecture docs (`scaling_architecture.md`,
`AI_ChatHub_Microservices_Architecture.md`, this file) now reflect this consistently as of today.
Also worth an explicit state-leak audit before flipping either service over — a persistent worker keeps
singletons/statics alive across requests, unlike php-fpm's fresh-boot-per-request model, so anything
caching per-request data on a service instance rather than resolving fresh needs a second look first.

### 2026-08-02 Session — Laravel Octane (Swoole) shipped for ai-gateway-service + chat-service, two real bugs found live

Implemented the Octane migration planned last session. Both services now run Swoole instead of
php-fpm; the other 7 services are untouched. Full verification was done through the real stack
(login → streaming chat → wallet ledger), not just a boot check — and that's what caught both bugs
below. Neither would have been found by code review or a health-check-only smoke test.

**Infra:**
- New `infrastructure/docker/php-octane/Dockerfile` (sibling to the existing shared php-fpm one,
  which is untouched and still used by the other 7 services + all queue/scheduler workers, including
  `ai-gateway-queue-worker`). `php:8.3-cli-alpine` base (not `-fpm`), same extensions as before plus
  `sockets` + `swoole` (pecl). Needed `linux-headers` added to `apk` deps — `ext-sockets` doesn't
  compile on musl/Alpine without it (`linux/sock_diag.h` missing), a real build failure caught before
  it went anywhere.
- `docker-compose.yml` — `ai-gateway-service`/`chat-service` build from the new Dockerfile; nginx
  sidecars **kept** (not removed) but rewritten from FastCGI proxies to plain HTTP reverse proxies
  (`proxy_pass http://ai-gateway-service:8000`, `proxy_buffering off`). Deliberate choice: this means
  `api-gateway`'s `AI_GATEWAY_SERVICE_URL`/`CHAT_SERVICE_URL` and ai-gateway-service's own outbound
  `CHAT_SERVICE_URL` call to chat-service needed **zero changes** — nginx stayed the stable internal
  address for both, only what's behind it changed.
- `composer require laravel/octane` in both services; `php artisan octane:install --server=swoole`.

**Code fixes made *before* going live (from the state-leak audit, not found live):**
- `ai-gateway-service/bootstrap/app.php` — `PendingReservationTracker` binding changed from
  `$app->singleton()` to `$app->scoped()`. A true singleton would let one request's wallet-reservation
  state get overwritten by another request on the same persistent Octane worker — `scoped()` is what
  Octane actually flushes between requests.
- `InternalServiceMiddleware.php` in both services — `env('INTERNAL_SERVICE_KEY')` → `config('services.internal_key')`.
  Not Octane-specific, but `env()` outside `config/*.php` silently returns null under `config:cache`,
  which would 401 every internal service call. `chat-service` had no `config/services.php` at all —
  created one.

**Bug #1 (found live) — the single-model chat stream produced zero bytes.** `laravel/ai`'s
`usingVercelDataProtocol()` builds its `StreamedResponse` from a bare `yield`-based closure with no
declared return type. Laravel's `ResponseFactory::stream()` only auto-wraps generator closures in an
echo+flush loop when **not** running under Octane; under Octane it hands the raw closure straight to
`StreamedResponse::setCallback()`, and Symfony's `sendContent()` just invokes it — for a generator
function, that only returns a `Generator` object without executing its body, since nothing iterates
the return value. Confirmed live: `200 OK`, `Content-Length: 0`, no error logged. Checked whether
upgrading `laravel/ai` (v0.9.0 → v0.10.2) fixed it upstream — it didn't (same bare closure, no return
type, in the latest release). Fixed in `ChatController::stream()` without touching vendor code: pull
the callback out via `getCallback()` and re-wrap it in a genuine `echo`+`flush()` loop — this mirrors
the pattern `ChatController::compare()` already used correctly. Verified live: real SSE output,
correct Vercel AI SDK protocol shape, model's actual reply came through.
- Side effect of chasing this down: `composer update laravel/ai --with-all-dependencies` was run to
  test the upgrade theory, which pulled a newer `aws/aws-sdk-php` and got interrupted mid-extraction
  (300s process-timeout against the slow WSL2 bind-mount, extracting a package with tens of thousands
  of files) — left the service in a crash-loop (`Uncaught Error: Failed opening required
  .../aws-sdk-php/src/functions.php`). Fixed by running `composer install` from a one-off
  `docker-compose run --entrypoint sh` container (not the crash-looping service itself, which kept
  interrupting `docker exec` mid-command) with `COMPOSER_PROCESS_TIMEOUT=2400`. Ended up keeping the
  laravel/ai upgrade since it was already done and harmless — the actual fix above doesn't depend on
  the package version either way.

**Bug #2 (found live, the more important one) — a provider failure mid-stream left the wallet
reservation stuck, unreleased.** This is exactly the scenario the plan flagged as highest-risk and
called out for live verification rather than assuming correct. Deliberately triggered a real provider
failure (`gpt-4o-mini` — in the package's `model_access` list but no real OpenAI key configured, so a
genuine `401` from the provider). Result: `reserved_balance` stayed stuck, and
`ai-gateway-queue-worker`'s logs showed no `ReleaseWalletReservationJob` ever dispatched — the
`terminating()` hook (swapped in for `register_shutdown_function()` last session) does **not**
reliably fire for an exception thrown this deep either, same structural gap the original code's own
comment described for php-fpm, just not actually closed by moving to Octane as hoped. Fixed by
wrapping the stream loop in `ChatController::stream()` in its own `try/catch` and dispatching
`ReleaseWalletReservationJob` directly from there — the one point in this flow that's actually
guaranteed to run, rather than a lifecycle hook downstream of it. Bonus fix from the same change: an
uncaught exception here previously leaked a raw PHP stack trace as the HTTP response body (`500`,
`Content-Type: text/event-stream`) — now emits a clean `{"type":"error",...}` SSE event instead.
Kept the `terminating()` hook in `bootstrap/app.php` as a secondary net for other failure shapes, but
it is **not** the mechanism this specific case relies on anymore.

**Verified live, in this order:**
1. Real login → real streaming chat message (`gemini-2.5-flash`) through the full stack
   (`frontend-equivalent curl` → api-gateway → ai-gateway-service/Octane → provider) → correct SSE
   output → correct wallet debit in the ledger (`wallet_ledger_entries`).
2. Deliberate provider failure (`gpt-4o-mini`, unconfigured key) → clean SSE error event → confirmed
   `reserved_balance` still stuck (bug #2, above) → fixed → re-tested → confirmed release job
   dispatched and `reserved_balance` back to 0, `refund` ledger entry recorded.
3. **Concurrency correctness** (the actual point of the `scoped()` fix): fired a successful request and
   a failing request **simultaneously** against the same Octane worker. Both resolved independently and
   correctly — one real `debit`, one `refund`, both timestamped to the same second, `reserved_balance`
   back to exactly `0.000000` afterward. No cross-request contamination.
4. `chat-service` sanity check (`GET /sessions` through api-gateway) — real session data returned,
   confirms Octane conversion didn't just work for ai-gateway-service.
5. All other 7 services + their nginx sidecars confirmed untouched and still healthy throughout
   (`docker ps` — all "Up", none recreated by the compose changes scoped to just these 2 services).

**Unrelated but hit hard during this session — real, pre-existing environment flakiness, not caused by
this work:** intermittent `curl` timeouts (exit 28) specifically on requests routed through
`api-gateway`, even though both hops' own access logs showed the request succeeding internally (200)
— the response just didn't reliably make it back to the client. Confirmed transient (same call
sometimes takes ~200ms, sometimes hangs entirely) rather than deterministic, and unrelated to the
Octane work (reproduced on `POST /auth/login`, a route neither touched service is anywhere near).
Matches this project's previously-documented Docker Desktop/WSL2 network-bridge degradation. Found 23
`chrome.exe` processes running on the host, matching the historical trigger pattern, but couldn't
confirm they're orphaned Playwright instances rather than the user's real browser session (no
automation flags in their command lines) — did **not** kill them without asking. Worth a Docker
Desktop restart if this keeps interfering with work.

### 2026-08-03 Session — pm.max_children fix (found a real nginx-stale-IP bug), admin-panel click-through testing, idempotent wallet reserve() + reconciliation sweep

**`pm.max_children` fix for the 7 remaining php-fpm services** — added `infrastructure/docker/php/www.conf`
(`pm.max_children` 5 → 20, `pm.start_servers` 2 → 4, `pm.min_spare_servers` 1 → 2, `pm.max_spare_servers`
3 → 6, `pm.max_requests` 500) and a `COPY www.conf` line in the shared Dockerfile. Rebuilt and recreated
all 12 services sharing that image. **Found a real bug doing this**: recreating the backend containers
gave them new internal IPs, but their nginx sidecars (long-running, untouched) still had the *old* IPs
cached — nginx resolves upstream hostnames once at worker startup, not per-request — causing `502
connect() failed (111: Connection refused)`. Fixed by restarting the nginx sidecars too. **Worth
remembering going forward: any time a backend container gets recreated (new image, `docker-compose up -d`
without `--no-recreate`), its nginx sidecar needs a restart too**, not just the backend.

**Admin-panel click-through testing** (continuing the standing "keep manually testing" item):
- **Role permission boundaries — 5/5 correct**: `finance_admin` blocked from creating packages and
  suspending users (403 in both cases); `support` can view the wallet ledger (200, real data) but blocked
  from adjusting it or refunding payments (403 in both cases) — exactly matching each role's seeded
  permission list.
- **Package creation → real enforcement**: created a package via the admin API with a deliberately narrow
  `model_access: ["gemini-2.5-flash"]`, pointed a real user's subscription at it, confirmed the included
  model worked and an excluded model was correctly blocked (`403 model_not_in_package`) — not just that
  the create call returned 201.
- **AI model creation → pricing wired correctly**: created a model via the admin API with distinctive
  rates, confirmed it's stored and reachable through the real chat flow.
- Model comparison (`ChatController::compare()`) spot-checked under Octane — works correctly, clean SSE
  output from multiple models at once.
- Found along the way (external, not app bugs): DeepSeek's account is out of credits/quota; a
  newly-supplied xAI key was rejected with `401 Unauthorized` (not the old key's `403` credits issue —
  worth the user re-checking the key was copied in full and is active on console.x.ai).

**Idempotent `WalletService::reserve()` + a stale-reservation reconciliation sweep** — found live while
testing: `reserve()` was the *only* wallet operation (`credit()`/`deduct()`/`refund()` all already have
this) with no idempotency guard. If ai-gateway's HTTP call to `/wallet/reserve` times out on its own side
(this session's recurring network flakiness) while actually succeeding server-side, ai-gateway never finds
out — `CostTrackingMiddleware` throws immediately on a `null` result, *before* `PendingReservationTracker::mark()`
ever runs, so the existing release safety net never even sees it. Confirmed this happened for real, not
just in theory — found a stuck `0.035874` reservation mid-session.

Two-part fix, both mirroring patterns that already work elsewhere in this codebase:
1. **`WalletService::reserve()`** now accepts `?string $referenceId` and guards on it exactly like
   `deduct()`/`refund()` do (`wallet_ledger_entries` existence check before mutating) — a duplicate/retried
   call with the same id is now a safe no-op. Reserve previously wrote nothing to the ledger at all; now
   inserts a `type='reserve'` row, which both this guard and the sweep below key off of. No migration
   needed — `wallet_ledger_entries.type` is a plain `string(20)`, not a DB-level enum.
   `WalletInternalController::reserve()` accepts the new `reference_id` field.
   `CostTrackingMiddleware` reuses its already-existing per-request `$requestId` (previously only used for
   `deduct()`) as this key too — same id, different `reference_type` server-side, no collision.
   `WalletClientService::reserve()` now sends it and added `.retry(2, 500)` (lighter than
   `PaymentChargeService`'s `.retry(2, 2000)` since this blocks a live user-facing streaming request).
2. **New `wallet:reconcile-reservations` command** (`services/wallet-service/app/Console/Commands/
   ReconcileWalletReservationsCommand.php`), mirroring `payment-service`'s existing `ReconcileBkashCommand`
   pattern exactly: finds `type='reserve'` ledger rows older than 15 minutes with no matching `debit`/
   `refund` row for the same `reference_id`, releases each via the existing `WalletService::refund()` (same
   method `ReleaseWalletReservationJob` already uses — no new release logic). Scheduled
   `->everyFifteenMinutes()` in `routes/console.php`; new `wallet-scheduler` container in
   `docker-compose.yml` (copy of the existing `payment-scheduler` shape) runs `schedule:work` continuously.

**Verified live, all three pieces**:
1. Idempotency: called the internal reserve endpoint twice with an identical `reference_id` —
   `reserved_balance` incremented only once, exactly one ledger row exists for that id.
2. Reconciliation: manually backdated a test ledger row past 15 minutes, ran the command — it found and
   released exactly that one, `reserved_balance` dropped back to 0, a `refund` row appeared. Ran again
   immediately after — correctly found nothing (already settled, not reprocessed).
3. **Real-world regression, not just a synthetic test**: while chasing this session's persistent network
   flakiness during a live chat test, the wallet ledger itself became the evidence — one clean
   `reserve`→`debit` pair (server-side succeeded, client just gave up waiting for the response) plus two
   genuinely orphaned `reserve` entries from attempts where the response never made it back at all. Exactly
   the failure mode this fix targets, caught happening for real, not staged.

**Real email delivery wired up — Mailgun (SMTP, sandbox domain)**. Previously Mailpit only (local dev
catcher, no real delivery). User provided real Mailgun SMTP credentials
(`postmaster@sandbox20e4c4d02b3944d59e2c8c0fe3adecbe.mailgun.org`, sandbox domain — only delivers to
recipients added as Authorized Recipients in the Mailgun dashboard until a real domain is verified later,
at which point this is a `.env`-only swap, no code changes).

**Real gap found while wiring this up**: `notification-service` is *not* the single point mail flows
through, despite that being the apparent intent. `auth-service` sends verification and password-reset
emails **directly** via its own `Mail::send()` calls (`SendVerificationEmail` listener,
`PasswordResetController`) — it has its own separate `MAIL_*` config, completely independent of
`notification-service`. Updating only `notification-service/.env` (the first attempt) silently fixed
nothing for registration/password-reset, since those never go through it at all. Grepped every service for
direct `Mail::` usage to confirm the full picture: only `auth-service` and `notification-service` send mail
directly; everything else (payment-service's queued jobs that matched an early broad grep) turned out to be
unrelated `ShouldQueue` usage, not mail. **Fixed both**: `auth-service/.env` and
`notification-service/.env` now carry the same real Mailgun SMTP config.

**Second real gap, easy to hit again**: after editing `.env`, a plain `docker restart` on these containers
did **not** pick up the change — Docker's `env_file:` directive injects `.env` into the container's process
environment once, at *creation* time, not on every restart; a real (already-injected) env var always wins
over Laravel's own `.env` file read, even after the file on disk changes. The symptom was silent and
convincing: the queue job reported `DONE` with zero errors, `Mail::send()` "succeeded" — it just quietly
kept sending through Mailpit the whole time (confirmed by checking Mailpit's own message list, which had
the "successful" verification emails sitting in it). **The fix is `docker-compose up -d --force-recreate
<service>`, not `docker restart`, any time an `.env` value changes** on a container that gets `env_file:`
injection — worth remembering broadly, this isn't specific to mail. (Also re-confirmed today's earlier
lesson: recreating a container gives it a new internal IP, so its nginx sidecar needs restarting
afterward too — did this for `auth-nginx`/`notification-nginx`.)

**Verified live, the real way — not a synthetic test**: raw manual SMTP script first (to isolate
connectivity/auth/send from any Laravel-layer issue) — got a clean `250 Great success` from Mailgun,
confirmed `Delivered`/`Accepted` in Mailgun's own Logs page, and the email genuinely arrived in the real
inbox (spam folder — expected and explained below, not a bug). Then, after finding and fixing the two gaps
above, re-tested through the actual app flow (`POST /auth/verify/resend`) — the real "Verify your AI
ChatHub account" email arrived too, confirming the fix, not just the raw SMTP mechanism.

**Why it lands in spam right now, and why that's fine for today**: the sandbox domain is a
randomly-generated address with no sending history — any receiving mail server treats that with suspicion
regardless of correct SMTP/auth configuration. This resolves naturally once a real custom domain is
verified (SPF/DKIM DNS records) later; nothing else to fix here for the development phase.

### Remaining for Phase 1 (as of 2026-08-03)
1. ~~Host-network flakiness~~ — ✅ meaningfully fixed 2026-08-03, root cause confirmed as genuine host
   resource starvation, not a Docker/app bug. This machine has only **4 CPU cores and 7.9GB RAM total**.
   Diagnosed and fixed in stages, each one verified live with real repeated login timing before moving to
   the next (not just assumed):
   - Closing Chrome (24 processes, 2.4GB) — free RAM 350MB → 1.7GB. Necessary but not sufficient alone.
   - Docker Desktop on this version has **no GUI CPU/memory slider** when using the WSL2 backend — it's
     configured via a `%USERPROFILE%\.wslconfig` file instead (there wasn't one, meaning WSL2 was
     defaulting to using everything available: `docker info` showed all 4 cores, ~3.9GB claimed). Created
     one capping Docker to `processors=2` / `memory=3GB` / `swap=2GB`. Needs a full `wsl --shutdown` +
     Docker Desktop restart to take effect — a plain Docker Desktop "Restart" button is not enough, and
     doesn't even reliably kill/relaunch Docker's own Windows-side processes (confirmed live: their
     StartTime hadn't changed after clicking restart). Also learned: right after this kind of reset,
     `docker info` responding does **not** mean the exposed ports are actually forwarding yet — Windows-side
     port forwarding lags behind the daemon by up to a minute or so; check an actual `curl` to an exposed
     port before assuming things are back, not just the daemon.
   - This alone (capping CPU/memory) **did not fix it** — same ~37% failure rate as before. Real
     insight: reallocating a fixed pie between Windows and Docker doesn't help when the *total* workload
     (27 containers) exceeds what 2-4 cores can comfortably run regardless of the split.
   - What actually worked: **reducing total footprint**, not just reallocating it. Stopped the 3 background
     schedulers (`payment-scheduler`/`subscription-scheduler`/`wallet-scheduler` — not needed for active
     testing) and dialed back this same morning's `pm.max_children` bump (20/4/2/6 → **8/2/1/3** in
     `infrastructure/docker/php/www.conf`) — the earlier, more aggressive tuning assumed more headroom
     than this host actually has; at `start_servers=4` across 7 services that's 28 idle php-fpm workers
     running at all times before a single request even arrives, real overhead this VM can't spare.
   - **Result, verified live**: two batches of repeated real logins through the full stack — 6/6 succeeded
     in the second batch, consistently 4.8-6.5s (down from a mix of 5-15s and frequent outright timeouts
     before). CPU load dropped 79% → 38% over the course of these changes. Not instant/sub-second — that's
     just the real cost of this workload on 2 cores now — but reliable and predictable, which is the
     actual fix: no more random complete failures.
   - Not available on this machine at all: Docker's "mirrored networking mode" (Windows 11+ only, this is
     Windows 10 — confirmed via `wsl --version`). Not worth revisiting.
   - If this regresses later: the pattern that worked was (1) check free RAM / CPU load first, not guess,
     (2) check what's actually running and stop what isn't needed, (3) only then consider `.wslconfig`
     tuning — reducing total footprint mattered more than how the pie was split.
2. `ChatController::compare()` — spot-checked working under Octane today, not yet stress-tested under
   concurrent load the way single-model `stream()` was.
3. Fix the known "No models available" loading-state flash on the chat page — still not done.
4. Everything else unchanged from before: Bitbucket remote, invoice PDF, Settings page, saved payment
   methods UI, real OpenAI/Anthropic keys (still placeholders — Claude specifically needs a real
   console.anthropic.com API key, distinct from a claude.ai subscription), xAI key needs re-verification
   (new key rejected with 401), DeepSeek needs its account credits checked.
5. Octane worker/task-worker counts still at defaults — no load data yet to tune against.
6. ~~Wiring in a real email provider~~ — ✅ done 2026-08-03 (Mailgun SMTP, sandbox domain — see above).
   Follow-ups when ready, not urgent: verify a real custom domain in Mailgun (fixes spam-folder landing,
   removes the Authorized-Recipients restriction) whenever a domain is bought for the product; add any
   other test recipient emails as Authorized Recipients in the Mailgun dashboard as needed meanwhile;
   `notification-service`'s own `NotificationClient`-driven mail (low-balance alerts, renewal-failed,
   receipts) wasn't individually re-exercised today — same config fix applies and the mechanism is proven
   via auth-service's emails, but each specific notification type hasn't been triggered and watched land.
7. A real `stripe listen` session for the webhook path — discussed this session as "for knowledge only,"
   available whenever wanted, not assumed as a next step.

Still explicitly out of scope, by the user's own call: AI token-cost calculation, the revenue/business
model, and sustainable AI-usage-limit strategy.

### 2026-08-04 Session — fixed admin-into-user-routes gap (real bug, found by user's own manual testing)

An admin account typing `/chat`, `/billing`, `/wallet`, or `/pricing` directly into the address bar
could still reach them — the 2026-07-30 admin/user UI separation only removed the nav *links*, it
never added an actual route-level block, and this was explicitly flagged at the time as a known,
not-yet-closed gap. `admin/layout.tsx` already redirects non-admins away (`if (!user.is_admin)
router.replace('/chat')`) — `(dashboard)/layout.tsx` had no mirrored check the other way. Fixed by
adding the same `is_admin` check to `(dashboard)/layout.tsx` (both the already-hydrated-`user` branch
and the `/auth/me` fetch branch, plus the render guard), redirecting admins to `/admin`. `tsc --noEmit`
clean. **Not yet verified in a real browser** — needs the user to confirm live (I can't drive a browser
from here); should be a 2-minute check: log in as admin, type `/chat` in the address bar, confirm it
bounces to `/admin`.

### 2026-08-04 Session (cont'd) — Admin dashboard bar charts, clear-filters, skeleton loading, toast consistency

Four-part frontend polish pass, approved together after a planning-only pass the user reviewed first
(no chart-consolidation endpoint — kept the existing 5 parallel `/admin/dashboard` calls, just render
their already-existing categorical breakdowns as charts instead of text rows).

1. **Dashboard bar charts** — added `recharts`, replaced the plain `flex justify-between` text rows for
   `plan_breakdown` (Subscriptions card), `gateway_breakdown` (Revenue card — this data existed on the
   backend but wasn't rendered anywhere before today), and `provider_breakdown` (AI Usage card) with a
   shared `BreakdownChart` component in `admin/page.tsx`: horizontal `<BarChart>`, colors resolved from
   the app's own HSL CSS variables (`hsl(var(--primary))`, `hsl(var(--border))`, etc.) so it's correct in
   both themes, not hardcoded. Numeric `Stat` tiles untouched — a bar chart of one bar isn't useful.
2. **Clear filters** — new `useListFilters<T>` hook (`src/hooks/useListFilters.ts`) extracting the
   previously copy-pasted draft/applied/page `useState` trio shared identically across 6 admin list
   pages (users, subscriptions, transactions, wallet, ai-usage, audit-logs), adding `clearFilters()` and
   a computed `hasActiveFilters`. Each page now shows a "Clear filters" button next to "Apply filters"
   once any filter is active.
3. **Skeleton loading** — new `src/components/ui/Skeleton.tsx` (`Skeleton`, `SkeletonText`,
   `SkeletonStat`, `SkeletonTableRows`, `SkeletonListItem`), all using Tailwind's built-in `animate-pulse`
   and the `bg-muted` token (correct in both themes). Replaced every plain `<p>Loading…</p>` across
   `admin/**` and `(dashboard)/**` (~20 spots) with a skeleton shaped like the real content underneath —
   table rows for list pages, stat-tile grids for the dashboard, repeated bordered rows for the two
   admin user-chat-history pages, list-item rows for the chat sidebar, card skeletons for the 3
   pricing-plan cards. Deliberately left alone: the handful of full-page auth-guard "checking who you
   are" spinners (`admin/layout.tsx`, `(dashboard)/layout.tsx`, `auth/callback/page.tsx`) — no content
   shape to skeleton against, a spinner is already correct there.
4. **Toast consistency** — `src/lib/errors.ts` gained a wording-convention comment (full sentences,
   sentence case, say what happened + what to do next). Passed over all ~49 `toast.error`/`toast.success`
   call sites app-wide; rewrote the dozen or so terse/robotic outliers (`'Could not update role.'`,
   `'Could not start a new chat.'`, `'Upload failed.'`, `'Name is required.'`, etc.) to match the tone
   already established at the better call sites (`"We didn't hear back in time — check X before trying
   again."`). Most sites already used `describeError()`'s ambiguous/non-ambiguous split correctly — only
   wording needed fixing, not the underlying logic.

`tsc --noEmit` clean throughout (verified after each of the 4 parts, not just once at the end). **Not
yet verified in a real browser** — the dev server responds and the admin dashboard route exists, but a
full authenticated click-through (confirm charts render with real data in both themes, confirm clear-
filters actually resets each of the 6 pages, confirm skeletons appear correctly under real network
latency) needs the user to do live, same as the route-guard fix above — I can't drive a browser from here.

### 2026-08-04 Session (cont'd, again) — Admin/user route-guard fix regression + decoupled pure admin accounts from wallets

**Regression found and fixed**: the earlier same-session admin/user route-guard fix (redirecting
`is_admin` accounts away from every `(dashboard)/**` route) had a side effect nobody had tested —
the admin dropdown's own "Profile" link pointed at `/profile`, which lives under `(dashboard)`, so
clicking it from the admin UI now just bounced straight back to `/admin`. Fixed by extracting the
profile page's content into a shared `ProfileView` component (`frontend/src/components/profile/
ProfileView.tsx`) and giving admins their own `/admin/profile` route that renders it inside the
admin shell; `(dashboard)/profile/page.tsx` is now a thin wrapper around the same component.
Admin dropdown (`admin/layout.tsx`) now links to `/admin/profile` instead of `/profile`.

**Real product question, found via that same page**: the admin's `/admin/profile` showed a genuine
wallet balance. Root cause: `admin_users` is just a bolt-on table (`user_id` FK → `users`) —
admin-ness is computed at JWT-issue time (`User::getJWTCustomClaims()`), never stored on `users`
itself. The only way to create an admin was promoting an *already-existing* consumer user
(`AdminUserController::store()` required an existing `users.id`) — so every admin necessarily went
through consumer registration first, which unconditionally creates a wallet. The $39.27 was
ordinary subscription wallet-credit from before that account was promoted, nothing admin-specific.

**Fix — pure admin accounts, no schema migration needed** (wallet-creation is only ever triggered
from `RegisterController`/`FirebaseAuthController`, never from admin promotion, so a new
admin-creation path that builds a `users` row directly, bypassing registration, simply never
touches wallet-service):
- `AdminUserController::store()` (auth-service) now branches on a `mode` field: `'promote'`
  (existing behavior, unchanged) or `'create'` (new) — builds a `users` row directly with
  `status: 'active'` + `email_verified_at: now()` (no consumer email-verification loop; a trusted
  admin is vouching for the account) and an `admin_users` row, inside one `DB::transaction()`, with
  **no wallet-service call anywhere in that path**. Audit-logged as `admin.account_created`
  (distinct from `admin.created` for promotions).
- **Real gap closed in the same pass**: `ai-gateway-service`'s AI routes (`/chat/stream`,
  `/chat/compare`, `/generate/*`, `/transcribe`) were gated only by `auth.jwt`, with nothing
  blocking an admin JWT — `CostTrackingMiddleware` would unconditionally try to reserve against a
  wallet that a pure admin simply doesn't have, surfacing as a misleading `503 "Could not reach the
  wallet service"` instead of an honest 403. New `BlockAdminMiddleware` (mirrors the existing
  `AdminGateMiddleware`, inverse check) closes this, wired via a new `block.admin` alias onto just
  those 5 routes.
- `admin/admins/page.tsx` — "Add admin" dialog now offers both modes via a simple two-button
  toggle: "Promote existing user" (unchanged) and "Create new admin" (name/email/password/role,
  no search step), with a note in the form that the new account has no wallet/subscription.

**Verified live, not just code-reviewed** (minted a real platform_admin JWT via a bootstrapped
tinker-equivalent script since `laravel/tinker` isn't installed in this project — went through the
actual `JwtService::issueTokens()` code path used by real login, not a fake token):
1. Created a fresh pure admin via `POST /admin/admins {mode:'create',...}` → 201, `status: 'active'`,
   `email_verified_at` set immediately.
2. Logged in as that admin immediately (no verification step) — confirmed `is_admin: true` in the
   JWT claims.
3. `GET /wallet` for that admin → clean `404 wallet_not_found` (confirmed zero rows in
   `wallet_svc.wallets` for that user_id).
4. `POST /chat/stream` for that admin → clean `403 admin_not_allowed`, not the old misleading 503.
5. Registered + promoted a separate fresh consumer via `mode: 'promote'` → unchanged 201 behavior,
   confirmed that user **does** have a wallet row (registration's own wallet-create path untouched).
6. A real consumer JWT hitting `/chat/stream` still reaches normal `422` validation (not blocked by
   the new middleware) — confirms `block.admin` only fires for actual admin JWTs.
7. Audit log shows both `admin.created` (promote) and `admin.account_created` (create) as distinct,
   correctly attributed entries.

Restarted `ai-gateway-service`/`ai-gateway-nginx` after this change — necessary because it runs
under Octane (see earlier session), whose persistent workers boot the app once and don't pick up
`bootstrap/app.php`/`routes/api.php` changes (new middleware alias, new route group) without a
restart. `auth-service` (still plain php-fpm) needed no restart.

**Out of scope, by design**: admins promoted before this change keep their existing wallets — no
backfill/removal, this only changes the creation path going forward. No admin-invite email flow —
the creating admin sets the initial password directly, same trust model as the existing manual
wallet-balance-adjust feature. No broader server-side admin lockout across every other
consumer-facing endpoint (topup, subscribe, billing) — scoped to the one concretely-identified gap,
since the frontend UI guards already block admins from those other flows and there's no
`auth.jwt`-only path into them the way the AI routes had.

### 2026-08-06 Session — CRITICAL money bug found by the user's own real usage: paid upgrades never credited the wallet

Real bug, real money, found by the user actually subscribing and upgrading their own account (not
synthetic testing): upgraded Basic → Standard for real via Stripe ($20, charged and confirmed
`completed`), the plan itself correctly switched, but the wallet balance never moved — sat exactly
where it was before the upgrade.

**Root cause, confirmed against the live DB before touching any code**: `PackageActivationService
::creditWallet()`'s idempotency guard (mirroring `WalletService::credit()`'s own guard) keys on
`(type='credit', reference_type='subscription', reference_id=X)`. The original subscribe already
writes a `credit|subscription|<subscription_id>` ledger row. `applyUpgrade()` never creates a new
`UserSubscription` row — it updates the existing one in place — so the subscription's id never
changes across upgrades. `SubscriptionActivationController::activateUpgrade()` (the real, paid-upgrade
completion path, called by Payment Service once Stripe/bKash confirms payment) was passing
`$subscription->id` as the credit reference — which, being identical to the reference the *original*
subscribe already used, makes every single paid upgrade look like a duplicate of that very first
credit. The guard silently no-ops it: wallet untouched, no error, no log, 201 returned to the
frontend as if everything succeeded. **This is the exact same bug class already found and fixed for
renewals on 2026-07-23** — `ProcessRenewalJob.php` has a comment literally documenting this precise
failure mode and why it switched to using the per-cycle `transactionId` instead — but that fix was
never applied to the upgrade path. `SubscriptionController::doUpgrade()`'s free-package branch had
the identical latent bug (same `$subscription->id` reuse), just never exercised since no free-tier
upgrade had happened yet.

**Blast radius, checked directly against the DB**: only one completed paid `subscription_upgrade`
transaction has ever existed in this environment — the reporting user's own — so no other user was
affected. Structurally, though, this guaranteed failure on *every* paid upgrade, for every user,
forever, until fixed — not an edge case.

**Fix**: both call sites (`SubscriptionActivationController::activateUpgrade()` and
`SubscriptionController::doUpgrade()`'s free branch) now pass the upgrade's own transaction ID as the
credit reference instead of the subscription's permanent ID — mirroring the exact pattern
`ProcessRenewalJob.php` already uses correctly. **Verified live**, not just code-reviewed: registered
a disposable test user, simulated a real paid subscribe via the internal `/subscriptions/activate`
endpoint (wallet: $0 → $10), then a real paid upgrade via `/subscriptions/activate-upgrade` with a
fresh transaction ID (wallet: $10 → $30, two distinct ledger rows, no collision). `php -l` clean on
both files; no restart needed (`subscription-service` is plain php-fpm, `opcache.validate_timestamps=1`
picks up the change on the next request).

**Reporting user's account corrected**: manually credited the missing $20 via the existing admin
wallet-adjust endpoint (`POST /wallet/admin/{userId}/adjust`), balance $19.998106 → $39.998106,
properly attributed in `audit_logs` with a description explaining the correction.

**Separate, smaller finding from the same conversation**: there is no email-change capability
anywhere in the app — checked `auth-service`'s routes directly, only email *verification*/*resend*
exist, nothing for changing an account's email address once set. This affects every account, admin
or consumer, not just the one flagged. Not fixed yet — flagged as a real, complete feature gap, not
attempted this session.

### 2026-08-06 Session (cont'd) — Admin dashboard + sidebar redesign, from a user-provided mockup

User supplied a complete HTML/CSS mockup (`dashboard_redesign.html`, outside the repo) as the target
visual direction and asked for it applied to the real admin dashboard/sidebar. Two scope decisions
confirmed before building: the mockup's topbar search should be **real, functional search**, not
decorative; KPI trend/status indicators should be **derived only from data already returned today**,
nothing fabricated.

- **New design tokens** (`frontend/src/app/globals.css`, `tailwind.config.ts`) — formalized
  `--success`/`--warning`/`--info` (+ `-soft` variants), light/dark-aware, mirroring how `--chart-1..5`
  were added last session. `Badge.tsx` left untouched (still hardcoded Tailwind colors) — only the
  new dashboard components use these.
- **Real free-text user search** — `UserManagementController::index()` (auth-service) gained a
  `search` param (ORs across name/email in one query; the existing `name`/`email` params stay
  independently ANDed for the Users page's own filter form, unchanged). `TransactionController::
  adminIndex()` (payment-service) gained an exact-`id` filter, since `show()` is user-scoped and
  can't do cross-user admin lookups — the new admin search only ever offers a transaction by its
  exact UUID (transactions have no free-text-searchable field; confirmed before building, not
  assumed). Both verified live with real curl calls against real data, not just code-reviewed.
- **New `frontend/src/components/admin/AdminSearch.tsx`** — 300ms-debounced topbar search (no
  debounce utility existed yet, kept local rather than adding a dependency), queries both endpoints
  above, results panel styled to match the existing `DropdownMenuContent` look. Selecting a user
  routes to `/admin/users?email=<exact>`, reusing that page's already-built filter — no new page.
- **Sidebar/topbar redesign** (`admin/layout.tsx`) — gradient brand mark, pill-shaped active nav
  state, a visual divider between operational nav (Dashboard…AI Models) and admin-management nav
  (Admins/Roles/Audit Logs) via a new `group` field on `NAV_ITEMS`, topbar now houses `<AdminSearch />`
  alongside the existing profile dropdown (was `justify-end`-only before, no search existed).
- **Dashboard restructured into 3 rows** (`admin/page.tsx`) — previously 6 cards each mixed headline
  numbers + breakdown charts; now split to match the mockup: **row 1** = 4 new `KpiCard`s (status
  strip + icon chip + big number + trend pill — new `frontend/src/components/admin/DashboardWidgets.tsx`),
  every value traced to a real already-fetched field (e.g. Subscriptions card's `watch` status/trend
  is literally `past_due_subscriptions > 0`, nothing invented); **row 2** = 3 breakdown cards
  (subscriptions-by-tier, transaction status, payment gateway) using new CSS-only `BarRow`/`DotRow`
  primitives — **replaces last session's Recharts-based `BreakdownChart`** for these (simpler, no
  chart-library overhead for 2-3 static rows, matches the mockup's exact visual language; `recharts`
  stays installed/unused here in case something genuinely more complex needs it later); **row 3** =
  AI usage (now also surfaces `total_cost_7d`, wasn't shown before) + provider health, restyled to a
  green pill-row-with-checkmarks when every provider is healthy, falling back to the existing
  per-row `Badge` list when any provider isn't (so a real problem still stands out, doesn't blend
  into a uniform "all green" row). New `SkeletonKpiCard` added to `components/ui/Skeleton.tsx` for
  the hero row's loading state.

`tsc --noEmit` clean, `php -l` clean on both touched backend files. Both new backend endpoints
verified live with real curl calls (search returned a real matching user; the exact-ID lookup
returned the real Standard-upgrade transaction from earlier this session). **Not yet visually
confirmed in a real browser** — colors in both light/dark theme, the search dropdown's actual
interaction feel, and overall layout at real viewport sizes all need the user to check live, same
limitation as every other frontend change this session (no browser-driving capability from here).

### 2026-08-06 Session (cont'd, again) — Clear-filters visibility fix + date-range filtering across all 6 admin list pages

User flagged (via a screenshot of the Subscriptions page) that the "Clear filters" button appeared
missing. It wasn't actually missing — `hasActiveFilters && (...)` conditionally hid it until a
filter was applied, a design choice from an earlier session that turned out to just read as broken.
Fixed by always rendering the button, `disabled={!hasActiveFilters}` instead of hidden — same 6
pages built on `useListFilters` (users, subscriptions, transactions, wallet, ai-usage, audit-logs).

Also asked about date-range filtering, "needed for a specific date range." Checked the actual
backend controllers first rather than assuming: **5 of 6 already had `from`/`to` date-range support
(on `created_at`) that no frontend form ever exposed** — `UserManagementController`,
`TransactionController::adminIndex()`, `WalletAdminController::ledger()`, `UsageLogAdminController`,
`AuditLogController` all already had it, just unused. The 6th, `SubscriptionAdminController`, had
its own already-working `renews_from`/`renews_to` (on `renews_at` — a more useful field for
subscriptions than creation date). Added date-range `<input type="date">` pairs to all 6 pages'
filter forms, wired to each page's correct real param names (no backend changes needed at all —
every filter added was already fully functional server-side, just never surfaced). `tsc --noEmit`
clean; verified live with real curl calls — `from=2026-08-06` on Users correctly returned only that
day's one registration (not all ~15 test users), and a future date on both Users and Subscriptions
correctly returned zero, confirming the filters actually narrow results rather than being silently
ignored.

### 2026-08-07 Session — Email-change capability built (closes the gap flagged 2026-08-06)

Backend (`auth-service`): new `email_verifications.new_email` nullable column (migration
`2026_08_06_000000_add_new_email_to_email_verifications_table`, already run) — a row with it set
means "confirm this new address" rather than the original meaning ("verify your registration
email"). New `EmailChangeController::request()` (`POST /auth/email/change`, authenticated) mirrors
`PasswordResetController::setPassword()`'s `current_password` gate exactly (required only when
`hasPassword()`), validates the new email is unique, and sends a confirmation link **to the new
address** — `users.email` is not touched until that link is clicked, so a hijacked session can't
silently redirect account control, and a typo can't lock anyone out. `EmailVerificationController::
verify()` now branches on `new_email`: null keeps today's exact behavior (activate + welcome email);
set means re-check uniqueness at confirm-time too (race guard), swap `users.email`, no welcome email
(would be wrong for an existing user). Frontend: `ProfileView.tsx` (shared by `/profile` and
`/admin/profile`) gained a "Change email" form mirroring the existing "Change password" form's UX
exactly, same card.

**Real bug caught by live testing, fixed same session**: the confirmation `Mail::send()` call was
synchronous and unwrapped — hitting Mailgun sandbox's "recipient not authorized" limit (already a
known constraint from the 2026-08-03 Mailgun session) crashed the whole request with a raw 500 stack
trace instead of a clean error, and left an orphaned, undeliverable `email_verifications` row behind.
Fixed with the same `catch (\Throwable $e)` + `Log::error` pattern `RegisterController`'s wallet-create
call already uses — now returns a clean `502 send_failed` and deletes the orphaned row. Re-tested
live after the fix to confirm both parts.

**Verified live end-to-end**, not just code-reviewed: wrong current-password → clean 422; the
send-failure path (Mailgun sandbox constraint) → clean 502, no orphaned DB row (confirmed before and
after the fix); manually simulated a real confirmation click (`GET /auth/verify/{token}`) → confirmed
`users.email` actually changed in the DB, the **old** email can no longer log in, the **new** email
logs in successfully with the same password, and replaying the same (now-used) token correctly fails
with `invalid_token`. Restored the shared `test@example.com` test account back to its original email
afterward since it's referenced by name in this repo's own `scripts/test-*.sh` files.

**Not separately re-verified this session** (relied on already-correct, mirrored logic instead of a
fresh live test): a Google-only account (no password) requesting a change with no `current_password`
required — the check is a direct copy of `setPassword()`'s already-proven-live conditional, same
`hasPassword()` gate.

### Tomorrow's plan (as of 2026-08-02)

**Quick wins first — low effort, no decisions needed:**
1. **Restart Docker Desktop** (and possibly the browser) — the host-network flakiness documented above
   measurably slowed down today's verification work, not just a theoretical gotcha anymore.
2. `pm.max_children` on the other 7 php-fpm services is still unconfigured (defaults to 5/service) —
   free fix, independent of Octane, still not done.
3. **Spot-check `ChatController::compare()`** (multi-model comparison) under Octane — wasn't touched by
   either bug fix today and wasn't load-tested live. It already used a genuinely echo-based closure
   (not the vendor's generator-based one that caused bug #1), so risk is low, but hasn't been
   explicitly verified the way single-model `stream()` now has.

**Real product work:**
4. **Keep manually testing the admin surface** — this exact kind of testing is what caught both real
   Octane bugs today. Specifically still worth doing:
   - Create a package, then actually subscribe to it as a test user — confirm the credit buffer % and
     model access chosen actually take effect, not just that the create call returned 201.
   - Create an AI model, then actually send it a real chat message — confirm the pricing set on it
     actually gets deducted correctly.
   - Click through as each of the three roles (platform/finance/support) to feel out the permission
     boundaries directly, not just via curl.
5. **Fix one known small bug**: the chat page shows "No models available on your plan" for the first
   ~15 seconds after opening — `(models ?? []).filter(...)` in `chat/page.tsx` treats "still loading"
   and "genuinely empty" as the same state. Should show a loading indicator instead.

**Lower priority, still open, no urgency:**
6. Move the remote to Bitbucket (discussed a couple times now, still not done — create the empty repo
   on bitbucket.org, add it as a second remote alongside GitHub, push `main`).
7. Invoice PDF download (`InvoiceController::download()` still a 501 stub), the Settings page (folder
   exists, literally empty), saved payment methods UI (backend already supports it, no frontend), real
   API keys for OpenAI/Anthropic/ElevenLabs (only Gemini + DeepSeek work today), xAI has a key but zero
   account credits (grok-beta 502s until funded).
8. Octane worker/task-worker counts are still at Octane's defaults — deliberately not tuned yet, no
   real load data to tune against. Revisit once Prometheus metrics exist (still not set up either).

**Available whenever wanted, not assumed for tomorrow** (2026-08-02's mail/Stripe questions were
explicitly "for knowledge only," not a request to act): wiring in a real email provider (Postmark/
Resend/SendGrid/etc. — currently Mailpit only, config is fully env-driven so this is a quick swap once
a provider is chosen) to replace Mailpit for real user-facing delivery, and a real `stripe listen`
session to confirm the Stripe webhook path end-to-end (needs the user's own Stripe CLI login;
`STRIPE_WEBHOOK_SECRET` is still the `whsec_CHANGE_ME` placeholder).

~~Still explicitly out of scope, by the user's own call: AI token-cost calculation, the
revenue/business model~~ — **now in active planning as of 2026-08-07, see below.** Sustainable
AI-usage-limit strategy remains a separate, still-untouched track.

**Keep doing real click-through testing** generally — every real bug found this entire project has come
from actually exercising a feature, never from reading code.

---

### Tomorrow's plan (as of 2026-08-07) — token markup pricing + auto-debit/saved payment methods

Two features discussed and understanding confirmed against the real code this session, **not yet
implemented** — added here per explicit instruction ("add these things in the plan of tomorrow, we
will do that"). Both need one real product decision made before work starts (see each item).

**1. Token markup / revenue model.** User's description: for each model, take the provider's raw
cost (e.g. $5/1M input tokens, $30/1M output) and apply a markup (e.g. 30%) on top to get the sell
rate charged to users ($6.50/1M in that example) — the markup itself is the revenue. The per-token
metering mechanism this needs **already exists and works** — `model_pricing`
(`ai-gateway-service/database/migrations/0001_create_ai_tables.php:30-44`,
`input_rate_per_million`/`output_rate_per_million`) and `CostTrackingMiddleware::calculateCost()`
already divide a stored per-million rate down to charge each real request. **The real gap**:
`model_pricing` only stores the final sell rate — no column for the provider's raw cost or a markup
%, so today an admin manually pre-computes and types in the marked-up number directly (no system
awareness that it's "$5 + 30%"). **Decision needed before implementing**: do we want the system to
actually compute sell price from a stored provider-cost + markup% (so a markup-policy change updates
pricing automatically, and margin becomes auditable per model) — this needs new columns and a
recompute path — or is manually entering the already-marked-up number, like today, sufficient? These
are meaningfully different amounts of work.

**2. Auto-debit / saved payment methods.** User's description: first payment method used becomes the
default saved account; users can save multiple and choose between them; auto-debit is opt-in; when
wallet balance drops below a threshold (configurable per user), a configurable top-up amount
auto-charges the default/chosen saved method. **Already fully built on the backend**: `PaymentMethodController`
(`services/payment-service/app/Http/Controllers/V1/PaymentMethodController.php`) already does
save/list/delete/set-default with multiple methods per user and "first save becomes default"
behavior — Stripe cards only, no frontend UI yet (same gap flagged earlier as item 7 above).
**Genuinely new, nothing built**: auto-debit trigger logic (no balance-threshold monitor, no
auto-charge job exists anywhere), per-user configurable threshold + top-up amount (no storage for
either yet), and a settings UI for all of it. **Decision needed before implementing**: bKash has no
saved-token/re-charge equivalent in this codebase today — confirm whether auto-debit ships Stripe-only
first, or needs a bKash equivalent before it's usable for bKash-paying users.

---

## Service Implementation Checklist
*(Aligned with PHASE1_DEV_GUIDE.md — updated to reflect actual current state)*

### Auth Service ✅ COMPLETE
- [x] RegisterController — user creation + afterResponse() for email + wallet
- [x] LoginController — email/password + JWT issuance
- [x] EmailVerificationController — verify token + resend
- [x] LogoutController — invalidate JWT + revoke refresh tokens
- [x] TokenRefreshController — rotate refresh token pair
- [x] PasswordResetController — forgot()/reset() ← DONE 2026-07-23 (was a `__call() → 501` stub);
      setPassword() (new, authenticated set/change) added in the same pass
- [x] PasswordReset model ← DONE 2026-07-23 (table existed, migrated, never used)
- [x] SocialAccountController — list + unlink Google (wired, basic impl)
- [x] GoogleOAuthController — Socialite redirect (kept but unused — Firebase used instead)
- [x] FirebaseAuthController — Google Sign-In via Firebase token ← NEW vs Dev Guide
- [x] Internal UserController — show, findByEmail, suspend, unsuspend
- [x] JwtAuthMiddleware — validates JWT on protected routes
- [x] InternalServiceMiddleware — validates X-Internal-Service-Key
- [x] JwtService — issueTokens(), rotateRefreshToken(), revokeAll()
- [x] UserRegistered event + SendVerificationEmail listener
- [ ] Welcome email on first social login

### Subscription Service ✅ CORE + PAYMENT + RENEWAL DONE
- [x] PackageController — index() + show() ← DONE
- [x] PackageSeeder — Basic/Standard/Pro seeded ← DONE
- [x] SubscriptionController — current, subscribe, upgrade, downgrade, cancel, history ← DONE 2026-07-19
- [x] SubscriptionHistory / RenewalAttempt models ← DONE 2026-07-19 (were missing, `subscribe()` would have crashed)
- [x] config/services.php (wallet_url, billing_url, internal_key, payment_url, subscription_url) ← DONE 2026-07-19, extended 2026-07-21/23
- [x] payment_source: wallet|card|bkash on subscribe() and upgrade() ← DONE 2026-07-21, extended 2026-07-27
- [x] Real upgrade/downgrade proration policy — `applyUpgrade()`/`scheduleDowngrade()`,
      `activate-upgrade` internal endpoint, `scheduled_package_id`-aware renewals ← DONE 2026-07-27,
      verified live including the full renewal-time downgrade application (see session notes above)
- [x] PackageActivationService — shared activation logic for both the synchronous wallet path and the
      webhook/verify-triggered card path ← DONE 2026-07-23
- [x] SubscriptionActivationController — POST /internal/subscriptions/activate, called by payment-service
      once a card-funded Checkout Session is verified paid ← DONE 2026-07-23
- [x] PaymentChargeService — chargeWallet() (extracted from SubscriptionController) + chargeSavedCard()
      (new, for renewals) ← DONE 2026-07-23
- [x] ProcessRenewalJob — wallet-then-saved-card charge, 3 attempts 24h apart, self-rescheduling,
      cancels after final failure ← DONE 2026-07-23, verified live both success and failure paths
- [x] Renewal scheduler — `Schedule::command('renewals:process')->hourly()` in routes/console.php,
      `subscription-scheduler` docker-compose service runs `schedule:work` continuously ← DONE 2026-07-23
- [x] subscription-queue-worker docker-compose service ← DONE 2026-07-23 (subscription-service's first
      ever queued job — also see the queue-collision bug fix in session notes above)

### Wallet Service ✅ CORE DONE
- [x] WalletService — createForUser(), credit(), debit(), reserve(), refund() ← already scaffolded
- [x] WalletInternalController — create(), show(), credit(), reserve(), deduct(), refund() ← DONE
- [x] Wallet auto-created on registration via afterResponse() HTTP call ← DONE
- [x] WalletController — GET /wallet, GET /wallet/credit (balance + credit-buffer display) ← DONE 2026-07-19
- [x] LedgerController — GET /wallet/ledger (paginated history) ← DONE 2026-07-19
- [x] credit() idempotency guard (reference_type + reference_id) ← DONE 2026-07-19, fixed a live double-credit
- [x] deduct()/refund() idempotency guard ← DONE 2026-07-23, same pattern as credit(), verified live —
      real reference IDs wired into CostTrackingMiddleware (per-request UUID) and
      ReleaseWalletReservationJob (per-dispatch UUID, stable across its retries)
- [ ] Event listener: subscription.purchased → credit wallet — superseded, subscription/payment services now call wallet-service directly instead (see 2026-07-19 session notes above)

### Payment Service ✅ CORE DONE + real Stripe Checkout Sessions (2026-07-23)
- [x] StripeGateway — charge(), refund(), verifyWebhook() ← already scaffolded; createCheckoutSession()/
      retrieveCheckoutSession() added 2026-07-23
- [x] PaymentInternalController — charge() (legacy, unused by current flows) + refund() (2026-07-19) +
      createCheckoutSession() (2026-07-23, called by subscription-service's card path)
- [x] Transaction/WebhookEvent/PaymentMethod models ← DONE 2026-07-19 (were missing entirely)
- [x] config/services.php ← DONE 2026-07-19; frontend_url + subscription_url added 2026-07-23
- [x] InternalServiceClient — shared wallet-credit/receipt-create HTTP helper ← DONE 2026-07-19
- [x] CheckoutCompletionService — complete()/cancel(), the single idempotent path both verify-on-return
      and the webhook funnel through ← DONE 2026-07-23
- [x] CheckoutController::verify() — GET /checkout/{id}/verify, the frontend's return-page endpoint ← DONE 2026-07-23
- [x] CreatesCheckoutSessions trait — shared "create pending Transaction + Checkout Session" logic
      between TopupController and PaymentInternalController ← DONE 2026-07-23
- [x] ProcessStripeWebhookJob ← rewritten 2026-07-23 to handle checkout.session.completed/expired
      instead of payment_intent.succeeded/payment_failed (Checkout's own events are the correct signal)
- [x] PaymentMethodController — index/store/destroy/setDefault ← DONE 2026-07-19, verified live (Stripe test card 4242)
- [x] TopupController — initiate() rewritten 2026-07-23 for Checkout Sessions (was direct PaymentIntent
      charge with a hardcoded test token); status() unchanged
- [x] TransactionController — index + show ← DONE 2026-07-19, verified live
- [x] StripeWebhookController — validate signature + dispatch job (unchanged, already worked)
- [x] checkout:complete {transaction_id} artisan command — manual reconciliation tool for a transaction
      confirmed paid on Stripe but not yet processed locally ← DONE 2026-07-23, kept as a standing tool
- [x] BkashGateway + bKash Checkout Sessions (wallet top-up + subscription purchase) — DONE 2026-07-23,
      verified live in bKash's real sandbox (see session notes above). `BkashWebhookController` removed
      (bKash's tokenized Checkout has no server-to-server webhook — verify-on-return is the only path)
- [x] bkash:reconcile sweep + payment-queue-worker/payment-scheduler containers ← DONE 2026-07-23,
      verified live (backdated test transaction correctly left pending, then correctly cancelled past
      the 24h ceiling); this is also what makes ProcessStripeWebhookJob actually able to run now
- [ ] Genuine Stripe-CLI-forwarded webhook delivery still needs `stripe listen --forward-to
      http://localhost:8000/api/v1/webhooks/stripe` (through the gateway, not directly to :8004 — CORS/
      rate limiting now live there) to confirm end-to-end (STRIPE_WEBHOOK_SECRET is still
      `whsec_CHANGE_ME`) — requires the Stripe CLI + an interactive `stripe login`, not automatable;
      not required for the feature to work (verify-on-return already covers it), only to exercise this
      specific backup path. See "Going live" notes above for what changes when real money is involved.

### AI Gateway Service ✅ CORE DONE (Session 2, 2026-07-19) — verified live with real Gemini 2.5 Flash
- [x] ModelController — GET /models, cross-referenced against caller's package access
- [x] ChatController — /chat/stream (SSE) and /chat/compare, both fixed from crash-on-every-call state; 2026-07-23: fixed compare()'s raw-event-JSON-leak + ob_flush() crash (see Known Issues), verified live with image attachments through the vision pipeline (gated correctly by model `vision` capability, blocked only by Gemini's known free-tier rate limit, not a code bug)
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
- [ ] Real API keys for OpenAI/Anthropic/ElevenLabs — Gemini and DeepSeek have working keys;
  xAI has a valid key but no account credits (see 2026-07-20 session notes)

### Chat Service ✅ CORE DONE (Session 2, 2026-07-19) — verified live
- [x] ChatSession / ChatMessage Eloquent models — didn't exist before
- [x] SessionController — index/store/show/update/destroy (export() still a 501 stub)
- [x] MessageController — index/store
- [x] ChatInternalController — POST /internal/sessions/{id}/messages, called by ai-gateway-service
      after every /chat/stream call to persist both the user message and assistant reply with
      accurate token/cost data
- [x] FileAttachmentController — upload + delete, verified live 2026-07-23 (images only, MinIO-backed) — was blocked by the api-gateway proxy hang bug above until fixed the same session

### Billing Service ⬜ PARTIAL
- [x] Invoice model ← DONE 2026-07-19
- [x] InvoiceInternalController@create — POST /api/internal/invoices/create, called by subscription-service ← DONE 2026-07-19, verified live
- [x] InvoiceController — index() + show() ← DONE 2026-07-19
- [x] Receipt model ← DONE 2026-07-19
- [x] ReceiptInternalController@create — POST /api/internal/receipts/create, called by payment-service on top-up ← DONE 2026-07-19, verified live
- [x] ReceiptController — index() + show() ← DONE 2026-07-19
- [ ] InvoiceController — download() (PDF generation)

### Notification Service ✅ CORE DONE (2026-07-20 cont'd session)
- [x] WelcomeMail Mailable ← DONE, triggered on email verification
- [x] ReceiptMail Mailable ← DONE, triggered on subscription purchase and wallet top-up (both the
      synchronous and webhook/verify-on-return paths, same idempotency key on both)
- [x] RenewalFailedMail Mailable ← DONE (not yet triggered by anything — renewal automation itself is
      still unbuilt, see Subscription Service below)
- [x] LowBalanceMail Mailable ← DONE, triggered by wallet-service (at most one per level per day)
- [x] Notification model, shared Blade layout component, generic POST /internal/notifications/send
      endpoint, idempotency via the existing idempotency_key unique constraint ← all DONE

### API Gateway ✅ COMPLETE (for current scope) — was actually broken, fixed 2026-07-19
- [x] ProxyController — forwards all routes to downstream services; default proxy timeout bumped 30s→45s (WSL2 bind-mount latency); 2026-07-23: fixed a real hang-on-file-upload bug (see Known Issues) by stripping hop-by-hop headers from forwarded responses
- [x] config/services.php — all downstream URLs mapped
- [x] JwtGatewayMiddleware — validates JWT, passes X-User-Id header — **was completely broken**:
  `firebase/php-jwt` wasn't installed (`composer require`d 2026-07-19) and `config/jwt.php` didn't
  exist (added 2026-07-19). No authenticated gateway request could have succeeded before this fix.
- [x] Rate limiting — DONE 2026-07-23: four tiers (`auth-strict`/`auth-general`/`webhooks`/`api`) via
      `RateLimiter::for()`; required adding `config/cache.php`+`config/database.php` (redis) that never
      existed here either. Verified live — 429s after the 10th rapid `/auth/login` call.
- [x] CORS — DONE 2026-07-23: `config/cors.php` restricts `allowed_origins` to `FRONTEND_URL` instead of
      Laravel's silent wide-open `*` default. Deliberately not replicated to the other 8 services —
      CORS is browser-enforced and the browser only ever talks to api-gateway.

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
- [x] Route protection — client-side guard (`app/(dashboard)/layout.tsx`, since 2026-07-19) **plus** real
  edge middleware (`src/middleware.ts`, added 2026-07-23). JWTs still live only in `localStorage` (never
  readable server-side), so middleware keys off a lightweight non-httpOnly `has_session` marker cookie
  (set/cleared in `auth-store.ts`) purely to redirect the "definitely logged out" case before any page
  renders — it carries no token and isn't the real authorization boundary, which stays the client-side
  guard + backend JWT verification, unchanged. Verified live: no cookie → 307 to `/login`; cookie present
  → 200 through to the page.
- [x] Dashboard layout with sidebar ← DONE 2026-07-19 (`app/(dashboard)/layout.tsx`) — nav: Home/Pricing/Wallet/Billing
- [x] Dashboard home page (`/chat`) ← DONE 2026-07-19, replaced with a real chat interface Session 2
- [x] Pricing/subscribe page ← DONE 2026-07-19, upgrade/downgrade/cancel buttons + wallet-vs-card
  choice added 2026-07-21, real Stripe Checkout redirect (card path) 2026-07-23, bKash added as a third
  payment_source option 2026-07-23 (cont'd)
- [x] Wallet page ← DONE 2026-07-19, top-up now redirects to a real Stripe Checkout Session (2026-07-23)
  or a real bKash Checkout Session (2026-07-23, cont'd) instead of posting a hardcoded test token
- [x] Billing page ← DONE 2026-07-19 (`app/(dashboard)/billing/page.tsx`) — transactions, invoices, receipts tables (read-only, no PDF)
- [x] `billing/checkout-callback` page ← DONE 2026-07-23 — shared return-landing page for both top-up
  and card-funded subscribe, polls `GET /checkout/{id}/verify` with a spinner before falling back to
  "still confirming"
- [x] Chat interface ← DONE Session 2, model switching + per-message model badge + rename/delete +
  upload-progress UX added 2026-07-21. Verified via `tsc --noEmit` + compile/curl smoke test only —
  **not yet click-tested in a browser.**
- [x] Chat compare UI ← DONE 2026-07-20 — "Compare" tab on `/chat`, pick 2-4 models, one
  message fans out to all of them, side-by-side streaming columns. Not yet click-tested in a browser.
- [x] Real image/file upload into chat ← DONE 2026-07-20 (cont'd session) — full vision pipeline working,
  upload-progress UX added 2026-07-21
- [x] `auth-store.ts` hydration flag (`hasHydrated`) + dashboard layout guard fix ← DONE 2026-07-23 —
  fixes a real bug where a full-page external redirect (Stripe Checkout being the first thing to ever
  do this) could bounce a logged-in user through `/login` before zustand-persist finished rehydrating
- [x] Cross-tab session sync ← DONE 2026-07-23 — `auth-store.ts` listens for the browser `storage`
  event and calls `useAuthStore.persist.rehydrate()`; previously a login/logout in one tab was invisible
  to any other open tab until it was manually reloaded
- [x] Ambiguous-vs-real-401 handling on the `/auth/me` profile fetch ← DONE 2026-07-23 — that call times
  out ~1 in 5 times in this environment; only a genuine 401 clears the session now, anything else
  retries quietly (see session notes above)
- [x] Settings/Profile page ← DONE 2026-07-23 (`app/(dashboard)/profile/page.tsx`) — one page covers
  both: account details, wallet balance overview, subscribed package overview, Google connection
  status + unlink, and a set/change-password form. The old empty `(dashboard)/settings/` folder was
  intentionally left unused rather than building a second, duplicate page.
- [x] Header dropdown ← DONE 2026-07-23 — the plain "Sign out" button is now a
  `@radix-ui/react-dropdown-menu` trigger (Profile / Sign out); new
  `components/ui/DropdownMenu.tsx` wraps Radix's primitives in this project's existing style
- [x] Forgot/reset password pages ← DONE 2026-07-23 (`(auth)/forgot-password`, `(auth)/reset-password`)
  — folders existed empty; login page already linked to `/forgot-password`. Verified live end-to-end
  including pulling the real email from Mailpit's API.
- [ ] Saved payment methods list page (backend done, no UI)
- [x] Stripe Elements / hardcoded test token — **superseded 2026-07-23**: both flows now redirect to a
  real Stripe Checkout Session instead of posting `pm_card_visa` server-side; no client-side Elements
  integration needed since Stripe's hosted page collects the card

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

5. ~~Run queue worker after `docker-compose up -d`~~ — no longer needed as of 2026-07-20;
   `auth-queue-worker` and `ai-gateway-queue-worker` are dedicated docker-compose services now
   and start automatically.

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
