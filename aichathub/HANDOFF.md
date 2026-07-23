# AI ChatHub — Development Handoff Document
**Last updated:** 2026-07-23  
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
⬜ upgrade()/downgrade() have no proration logic (documented simplification)

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
⬜ Admin panel basics — explicitly deferred by the user ("build from scratch later, focus on the rest")
⬜ End-to-end smoke testing in an actual browser — in progress organically: this whole session's real
  bugs (chat/compare crash, file-upload proxy hang, currency inconsistency, config load-order bug, a
  route-splitting proxy bug) all came from actually exercising features, not from reading code
```

**Overall Phase 1: ~92% complete.** Every core money/chat flow (register → subscribe → wallet →
top-up → invoicing → real AI chat) is built AND verified end-to-end against the live stack, including
genuine Stripe **and** bKash Checkout payment experiences, a full password-reset/account-security flow,
and now real rate limiting, CORS, and edge-level route protection. What's left is genuinely the polish
tier: admin panel (deferred), PDF downloads, remaining provider keys, and continuing the real-usage
testing pass — still the single highest-yield way left to find what's broken.

### Priority order for what's left (see also the shareable work-distribution doc)
1. ~~Wallet `deduct()`/`refund()` idempotency~~ — ✅ done 2026-07-23
2. ~~`ProcessRenewalJob` + scheduling~~ — ✅ done 2026-07-23 — also surfaced and fixed a project-wide queue-collision bug
3. ~~Password reset + Profile page + header dropdown~~ — ✅ done 2026-07-23
4. ~~Real bKash Checkout Sessions~~ — ✅ done 2026-07-23, verified live in bKash's real sandbox
5. ~~Rate limiting, CORS, frontend route middleware, bKash reconciliation, Stripe webhook infra~~ —
   ✅ done 2026-07-23, all verified live
6. **Keep doing real click-through testing** — every one of this session's real bugs came from actually
   using features, not from reading code; still the best bug-per-minute ratio of anything left ← NEXT
7. Lower priority: admin panel (deferred), invoice PDF, remaining provider keys, upgrade/downgrade
   proration, an actual `stripe listen` session to confirm the webhook backup path end-to-end.

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
- [x] payment_source: wallet|card on subscribe() ← DONE 2026-07-21
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
