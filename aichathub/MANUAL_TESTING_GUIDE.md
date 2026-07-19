# AI ChatHub — Manual Testing Guide (Frontend)

**Covers:** registration → email verify → login → subscribe → wallet → top-up → billing history.
**Does not cover:** AI chat (not built yet — Week 5-6), admin panel, password reset.

Built 2026-07-19 alongside the pricing/wallet/billing pages themselves — this is what those pages
actually do, not a spec for what they should do.

---

## 0. Before you start

**Start the backend:**
```bash
cd "C:\Users\IT News\Downloads\aichathub\aichathub"
docker-compose up -d
docker exec -d aichathub-auth php artisan queue:work redis --tries=3 --sleep=3
```
The queue worker is required — without it, verification emails never get created (see step 2).

**Start the frontend** (separate terminal, not in Docker):
```bash
cd "C:\Users\IT News\Downloads\aichathub\aichathub\frontend"
npm run dev
```
Open **http://localhost:3000** — it redirects to `/login`.

**First load is slow.** Docker Desktop + WSL2 + Windows bind mounts make the first request to any
route noticeably slow (5–15s is normal) — both on the Next.js side (each page compiles on first
visit) and the backend (each container's first request after a restart is slow). Don't assume a
30-second wait on your very first click means something's broken — reload once and it'll be fast
from then on. If a page hangs past ~60s, *then* something's actually wrong — check `docker ps` and
`docker-compose logs -f <service>`.

**Mailpit** (catches all outgoing email, since there's no real SMTP configured): http://localhost:8025

---

## 1. Register

1. Go to http://localhost:3000/register
2. Fill in name, email, password (min 8 chars, 1 uppercase, 1 number), confirm password
3. Submit → you should see "Check your email"

**What this exercises:** `POST /api/v1/auth/register` → user created in `auth_svc.users` (status
`pending_verification`) → wallet auto-created in `wallet_svc.wallets` (balance $0) via a background
HTTP call → verification email queued.

## 2. Verify your email

1. Open http://localhost:8025 (Mailpit)
2. Open the "Verify your AI ChatHub account" email, click the verification link
   (or copy the link and open it directly)
3. You should see "Email verified successfully"

If no email shows up in Mailpit after ~10s, the queue worker from step 0 probably isn't running —
start it and the email will appear once the worker picks up the queued job.

## 3. Log in

1. Go to http://localhost:3000/login
2. Enter the email/password from step 1
3. You land on `/chat` — the dashboard home page

**What this exercises:** `POST /api/v1/auth/login` → JWT issued → `GET /api/v1/auth/me` → both
stored client-side (Zustand, persisted to `localStorage`).

*(Google Sign-In button is also on this page — only works if Firebase is configured, see
`HANDOFF.md` → "Environment Variables That Must Be Set". Skip if you haven't set that up.)*

## 4. Dashboard home

At `/chat` you should see two cards:
- **Subscription** — "No active subscription" with a "View plans" button (expected, you haven't subscribed yet)
- **Wallet balance** — $0.00 (wallet was auto-created on registration, starts empty)

## 5. Subscribe to a plan

1. Click "View plans" (or the **Pricing** link in the sidebar)
2. You'll see three cards: Basic ($10/mo, $10 wallet credit), Standard ($20/mo, $20 credit), Pro ($40/mo, $40 credit)
3. Click **Subscribe** on any plan
4. Wait for the toast — "Subscribed! Your wallet has been credited."

**Note:** there's no real card entry — Phase 1 doesn't have Stripe Elements wired into the
frontend yet, so this uses Stripe's test-mode `pm_card_visa` payment method under the hood (a
built-in Stripe test fixture, not a real charge). Subscribing doesn't cost anything and doesn't
touch a real card.

**What this exercises:** `POST /api/v1/subscription/subscribe` → subscription created → wallet
credited synchronously → invoice generated in the background. This is the flow verified live
against your real Stripe sandbox account in the previous session.

**Verify it worked:**
- Go back to `/chat` — the Subscription card now shows your plan name and renewal date
- The subscribed plan's card on the Pricing page now shows "Current plan" (disabled)
- The other two plan buttons now say "Already subscribed" — upgrade/downgrade aren't wired into
  the UI yet (the API endpoints exist, just no button for them)

## 6. Wallet

1. Click **Wallet** in the sidebar
2. You should see your balance ($10/$20/$40 depending on the plan you picked) and a **Top up** form
3. Enter an amount (e.g. `25`) and click **Top up**
4. Wait for the toast — "Top-up successful — wallet credited."
5. Your balance updates immediately, and a new row appears in the **Transaction history** table below

**What this exercises:** `POST /api/v1/topup` → a real Stripe test-mode PaymentIntent is created
and confirmed against your sandbox account → wallet credited → receipt generated.

If you instead see "Payment succeeded, wallet credit is processing" — that means Stripe needed an
extra confirmation step your test card didn't trigger synchronously; the credit lands within a few
seconds via a background job once you refresh. This is rare with the test card, more common with
cards that trigger 3D Secure.

## 7. Billing

1. Click **Billing** in the sidebar
2. Three sections:
   - **Transactions** — every payment attempt (topups, and eventually subscription charges once
     Payment Service is wired into subscribe — right now subscribe doesn't create a Transaction
     row, only topup does)
   - **Invoices** — one per subscription purchase, status `paid`
   - **Receipts** — one per successful top-up

Nothing to click here yet — no PDF download (`InvoiceController::download()` is a documented `501`
stub), just read-only tables to confirm the records exist.

## 8. Sign out / sign back in

1. Click **Sign out** (top-right)
2. You're returned to `/login`
3. Log back in with the same account — your subscription and wallet balance should still be there
   (this confirms server-side state, not just client cache — reloading refetches everything)

---

## What's intentionally NOT testable yet

| Feature | Status |
|---|---|
| AI Chat | Not built (Week 5-6) — `/chat` is a dashboard summary page, not a chat interface |
| Upgrade / downgrade subscription | API exists, no UI button — use a REST client against `POST /api/v1/subscription/upgrade` \| `/downgrade` with a JWT from `localStorage` |
| Cancel subscription | Same — `POST /api/v1/subscription/cancel`, no UI button |
| Saved payment methods list | Backend done (`GET/POST /api/v1/payment-methods`), no UI page |
| Invoice/receipt PDF download | Not implemented anywhere (backend or frontend) |
| Password reset | Routes exist, controller is a stub |
| Admin panel | Not started |

---

## If something breaks

- **A request hangs for 30–60s then fails:** almost always the WSL2/Docker cold-start issue above —
  retry the exact same action once; if it works the second time, it wasn't a real bug.
- **"Wallet not found" / blank subscription after a fresh `docker-compose up -d`:** the containers
  need a few seconds after startup before the first request succeeds — reload once.
- **Nothing shows up in Mailpit:** the auth-service queue worker isn't running (see step 0).
- **A page 401s immediately after login:** your JWT may have expired (24h) or `docker restart`ing a
  service (not `--force-recreate`) mid-session can leave stale env vars — see `HANDOFF.md` →
  "Environment gotchas" for the full explanation.
- For anything else, `docker-compose logs -f <service>` on the relevant service is the fastest way
  to see the real error — the frontend will usually just show a generic toast.
