# AI ChatHub — Development Handoff Document
**Last updated:** 2026-07-15  
**Purpose:** Context for continuing development in a new AI session (Antigravity)

---

## Project Overview

AI ChatHub is a multi-tenant AI chat platform with a **Laravel 12 microservices backend** and **Next.js 14 frontend**, running in Docker on Windows with WSL2.

- **Repo:** https://github.com/Asaduzzaman285/AiChatHub
- **Local path:** `C:\Users\IT News\Downloads\aichathub\aichathub`
- **Architecture:** 9 Laravel microservices + 1 Next.js frontend, shared PostgreSQL with schemas, Redis

---

## Current Infrastructure Status

### ✅ Running and Working
```
docker-compose up -d    # starts everything
```

| Container | Status | Port |
|---|---|---|
| aichathub-postgres | ✅ Running | 5432 |
| aichathub-redis | ✅ Running | 6379 |
| aichathub-mailpit | ✅ Running | 8025 (UI) |
| aichathub-minio | ✅ Running | 9001 (UI) |
| aichathub-auth + auth-nginx | ✅ Running | 8001 |
| aichathub-subscription + nginx | ✅ Running | 8002 |
| aichathub-wallet + nginx | ✅ Running | 8003 |
| aichathub-payment + nginx | ✅ Running | 8004 |
| aichathub-ai-gateway + nginx | ✅ Running | 8005 |
| aichathub-chat + nginx | ✅ Running | 8006 |
| aichathub-billing + nginx | ✅ Running | 8007 |
| aichathub-notification + nginx | ✅ Running | 8008 |
| aichathub-api-gateway + nginx | ✅ Running | 8000 |

### ✅ All Health Endpoints Return 200
```
curl http://localhost:8001/api/v1/health  → {"status":"ok","service":"auth"}
curl http://localhost:8002/api/v1/health  → {"status":"ok","service":"subscription"}
... all 8 services return 200
```

### ✅ Database
- PostgreSQL schemas created: `auth_svc`, `subscription_svc`, `wallet_svc`, `payment_svc`, `billing_svc`, `ai_svc`, `chat_svc`, `notification_svc`
- Auth service migrations: **applied** (users, social_accounts, email_verifications, etc.)
- Other services: migrations are pending (need to run when implementing each service)

---

## Current Blocker — The ONE Remaining Issue

**The Firebase auth endpoint returns HTTP 302 instead of 401.**

```
POST http://localhost:8001/api/v1/auth/firebase
→ 302 redirect to http://aichathub-auth-nginx  (WRONG — should be 401 JSON)
```

### Root Cause Analysis (fully investigated)
The redirect comes from nginx passing incorrect HOST headers to PHP-FPM, causing Laravel to build redirect URLs using the internal Docker container name `aichathub-auth-nginx`.

### Fixes Already Applied (in codebase, need container restart)
1. `infrastructure/docker/nginx/auth.conf` — added `HTTP_HOST`, `SERVER_NAME`, `SERVER_PORT` fastcgi params
2. `services/auth-service/bootstrap/app.php` — added `URL::forceRootUrl(config('app.url'))`
3. Removed `$middleware->statefulApi()` from all services (was enabling Sanctum session redirects)

### To Apply the Fix
```bash
cd "C:\Users\IT News\Downloads\aichathub\aichathub"
docker-compose restart auth-nginx
docker exec aichathub-auth php artisan config:clear

# Test — should return 401 not 302
curl -s -w "\nHTTP:%{http_code}" -X POST http://localhost:8001/api/v1/auth/firebase \
  -H "Content-Type: application/json" \
  -d '{"id_token":"bad-token"}'
# Expected: {"message":"Invalid or expired Firebase token."} HTTP:401
```

---

## What's Been Built

### Auth Service (`services/auth-service/`)
- ✅ Migrations applied (all tables exist)
- ✅ Firebase Admin SDK installed (`kreait/laravel-firebase:^6.0`)
- ✅ `FirebaseAuthController` — POST `/api/v1/auth/firebase` (verifies Firebase token → returns JWT)
- ✅ `JwtAuthMiddleware` — validates JWT on protected routes
- ✅ `InternalServiceMiddleware` — validates X-Internal-Service-Key header
- ✅ `UserController` (internal) — GET `/api/internal/users/{id}`, find by email, suspend/unsuspend
- ✅ Routes defined in `routes/api.php` — all auth endpoints scaffolded
- ⬜ `RegisterController` — stub only (returns 501)
- ⬜ `LoginController` — stub only
- ⬜ `EmailVerificationController` — stub only
- ⬜ All other controllers — stub only

### Frontend (`frontend/`)
- ✅ Firebase JS SDK installed (`firebase@12.16.0`)
- ✅ `src/lib/firebase.ts` — Firebase SDK initialization
- ✅ `src/hooks/useFirebaseAuth.ts` — `signInWithGoogle()` hook
- ✅ `src/components/auth/GoogleSignInButton.tsx` — Google sign-in button component
- ✅ Login page (`app/(auth)/login/page.tsx`) — already uses GoogleSignInButton
- ✅ Auth store (Zustand) — `useAuthStore` with persist middleware
- ⬜ Frontend not running yet (needs `npm run dev`)

### All Other Services
- ✅ Scaffolded — routes, controllers (stubs), middleware
- ✅ Health endpoints working
- ⬜ Actual business logic not implemented

---

## Phase 1 Progress vs PHASE1_DEV_GUIDE.md

```
Week 1–2: Foundation
✅ Docker environment running (all services healthy)
✅ All migrations applied (auth-service)
⚠️  Firebase endpoint: 302→401 fix applied, needs container restart to verify
⬜ Auth Service: register, email verify, login working  ← NEXT TO BUILD
⬜ Google OAuth working end-to-end
⬜ Wallet auto-created on user registration
⬜ Frontend: login + register pages working

Week 3–4: Subscription Core         ← NOT STARTED
Week 5–6: AI Chat MVP               ← NOT STARTED
Week 7–8: Billing & Wallet UI       ← NOT STARTED
Week 9–10: Auto-Renewal + Polish    ← NOT STARTED
```

---

## Immediate Next Steps (Priority Order)

### Step 1: Verify Firebase fix
```bash
docker-compose restart auth-nginx
curl -X POST http://localhost:8001/api/v1/auth/firebase \
  -H "Content-Type: application/json" -d '{"id_token":"bad"}'
# Must return 401 JSON
```

### Step 2: Implement RegisterController
File: `services/auth-service/app/Http/Controllers/V1/Auth/RegisterController.php`

Needs to:
- Validate name, email, password, currency
- Create user with `status = pending_verification`
- Generate email verification token
- Send verification email via Mailpit (SMTP on port 1025)
- Fire `UserRegistered` event (wallet-service listens to create wallet)
- Return 201 with user data

### Step 3: Implement LoginController
File: `services/auth-service/app/Http/Controllers/V1/Auth/LoginController.php`

Needs to:
- Validate email + password
- Check user status is `active`
- Issue JWT using `tymon/jwt-auth`
- Return access_token + refresh_token + user data

### Step 4: Test end-to-end Google Sign-In
1. Start frontend: `cd frontend && npm run dev`
2. Open http://localhost:3000/login
3. Click "Continue with Google"
4. Should redirect to `/chat` after successful sign-in

### Step 5: Implement wallet auto-creation
When user registers/signs in via Firebase, the wallet-service needs a wallet created.
This happens via Redis event bus: auth-service fires `UserRegistered` event → wallet-service creates wallet.

---

## Key Technical Decisions Made

| Decision | What was chosen | Why |
|---|---|---|
| Social login | Firebase Auth SDK (not Socialite redirect) | Handles Google/Facebook/Apple all at once |
| Auth tokens | JWT (tymon/jwt-auth) | Stateless, works across microservices |
| Cache | Redis only (no DB cache) | DB cache table was causing migration deadlocks |
| Session | None — pure API, no sessions | Microservices don't use sessions |
| Spatie Permission | Disabled (dont-discover) | Auth service doesn't need role/permission system |
| Sanctum | Disabled (dont-discover) | Using JWT not Sanctum tokens |

---

## Important File Locations

```
services/auth-service/
├── .env                          # DB, Redis, Firebase, JWT config
├── firebase-service-account.json # Firebase private key (NOT in git)
├── bootstrap/app.php             # Middleware, routing, exception handler
├── config/firebase.php           # Kreait Firebase v6 config (projects array)
├── config/cache.php              # Explicit redis cache (no database store)
├── routes/api.php                # All public auth routes
├── routes/internal.php           # Internal service routes
└── app/Http/Controllers/
    ├── HealthController.php
    ├── Internal/UserController.php
    └── V1/Auth/
        ├── FirebaseAuthController.php  ✅ IMPLEMENTED
        ├── RegisterController.php      ⬜ STUB
        ├── LoginController.php         ⬜ STUB
        └── ... (other stubs)

frontend/
├── .env.local                    # Firebase config + API URL
├── src/lib/firebase.ts           # Firebase SDK init
├── src/hooks/useFirebaseAuth.ts  # Google sign-in hook
└── src/components/auth/
    └── GoogleSignInButton.tsx    ✅ IMPLEMENTED
```

---

## Firebase Project Details
- Project ID: `aichathub-ca2c2`
- Auth Domain: `aichathub-ca2c2.firebaseapp.com`
- Service account JSON: placed at `services/auth-service/firebase-service-account.json`
- Google Sign-in: enabled in Firebase console

---

## How to Start a New Development Session

```bash
# 1. Start infrastructure
cd "C:\Users\IT News\Downloads\aichathub\aichathub"
docker-compose up -d

# 2. Check all services healthy
docker-compose ps

# 3. Verify Firebase fix
docker-compose restart auth-nginx
curl -X POST http://localhost:8001/api/v1/auth/firebase \
  -H "Content-Type: application/json" -d '{"id_token":"bad"}'

# 4. Start building RegisterController
# File: services/auth-service/app/Http/Controllers/V1/Auth/RegisterController.php

# 5. Watch auth service logs
docker-compose logs -f auth-service
```

---

## Common Commands Reference

```bash
# Run artisan inside container
docker exec aichathub-auth php artisan <command>

# Clear all caches
docker exec aichathub-auth php artisan config:clear
docker exec aichathub-auth php artisan route:clear

# Rebuild autoloader (after adding new PHP files)
docker exec aichathub-auth composer dump-autoload --optimize -v

# Check logs
docker exec aichathub-auth tail -f /var/www/storage/logs/laravel.log

# Restart a service
docker-compose restart auth-service auth-nginx

# Check DB tables
docker exec aichathub-postgres psql -U postgres -d ai_chathub_db -c "\dt auth_svc.*"
```
