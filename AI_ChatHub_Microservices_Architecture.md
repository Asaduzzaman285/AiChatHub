# AI ChatHub — Microservices Architecture Document

**Version 2.0 | Laravel 12 + Laravel AI SDK**
**Last Updated:** July 7, 2026

---

## 1. Architecture Overview

### 1.1 System Topology

```
                          ┌─────────────────────────────────────┐
                          │         CLIENTS                      │
                          │  Next.js Web  │  Mobile (Phase 3)   │
                          └──────────┬──────────────────────────┘
                                     │ HTTPS / WSS
                          ┌──────────▼──────────────────────────┐
                          │         CLOUDFLARE CDN / WAF         │
                          │     DDoS Protection + Edge Cache     │
                          └──────────┬──────────────────────────┘
                                     │
                          ┌──────────▼──────────────────────────┐
                          │        API GATEWAY SERVICE           │
                          │   Laravel 12 + Nginx + Rate Limit   │
                          │   JWT Validation + Request Routing  │
                          │   Port: 8000                        │
                          └──────┬────────────────────┬─────────┘
                                 │ Internal HTTP       │
              ┌──────────────────┼─────────────────────┼──────────────────┐
              │                  │                     │                  │
    ┌─────────▼──────┐  ┌───────▼────────┐  ┌────────▼────────┐  ┌──────▼──────────┐
    │  Auth Service  │  │  Subscription  │  │ Wallet Service  │  │ Payment Gateway │
    │  :8001         │  │  Service :8002 │  │  :8003          │  │ Service :8004   │
    └────────────────┘  └────────────────┘  └─────────────────┘  └─────────────────┘
              │                  │                     │                  │
    ┌─────────▼──────┐  ┌───────▼────────┐  ┌────────▼────────┐  ┌──────▼──────────┐
    │ AI Gateway Svc │  │  Chat Service  │  │ Billing Service │  │  Notification   │
    │  :8005         │  │  :8006         │  │  :8007          │  │  Service :8008  │
    └────────────────┘  └────────────────┘  └─────────────────┘  └─────────────────┘
                                     │
                    ┌────────────────┼────────────────────┐
                    │                │                    │
         ┌──────────▼───┐  ┌────────▼──────┐  ┌─────────▼──────┐
         │  PostgreSQL  │  │     Redis      │  │  S3 Storage    │
         │  (Shared DB) │  │  Cache + Bus   │  │  (Files/Media) │
         └──────────────┘  └───────────────┘  └────────────────┘
```

### 1.2 Service Port Map

| Service | Internal Port | Responsibilities |
|---------|--------------|------------------|
| API Gateway | 8000 | Routing, JWT validation, rate limiting |
| Auth Service | 8001 | User auth, tokens, admin users |
| Subscription Service | 8002 | Packages, subscriptions, renewals |
| Wallet Service | 8003 | Balance, ledger, credit buffer |
| Payment Gateway Service | 8004 | Stripe, bKash, webhooks |
| AI Gateway Service | 8005 | Providers, streaming, usage logs |
| Chat Service | 8006 | Sessions, messages, file attachments |
| Billing Service | 8007 | Invoices, receipts, promo codes |
| Notification Service | 8008 | Email, SMS, push delivery |

---

## 2. Monorepo Folder Structure

```
aichathub/
│
├── services/                          ← All microservices (one Laravel app each)
│   ├── api-gateway/
│   ├── auth-service/
│   ├── subscription-service/
│   ├── wallet-service/
│   ├── payment-service/
│   ├── ai-gateway-service/
│   ├── chat-service/
│   ├── billing-service/
│   └── notification-service/
│
├── frontend/                          ← Next.js 14 web application
│
├── packages/                          ← Shared PHP packages (Composer local)
│   ├── shared-kernel/                 ← DTOs, enums, base classes
│   ├── event-contracts/               ← Shared event payloads (typed)
│   └── jwt-middleware/                ← Shared JWT validation middleware
│
├── infrastructure/
│   ├── docker/
│   │   ├── php/                       ← Base PHP-FPM Dockerfile
│   │   └── nginx/                     ← Nginx config templates
│   ├── docker-compose.yml             ← Local dev (all services)
│   ├── docker-compose.prod.yml        ← Production override
│   └── k8s/                           ← Kubernetes manifests (Phase 2)
│       ├── deployments/
│       ├── services/
│       ├── ingress/
│       ├── configmaps/
│       └── hpa/                       ← Horizontal Pod Autoscalers
│
├── docs/                              ← All documentation files live here
│   ├── AI_ChatHub_BRD.md
│   ├── AI_ChatHub_SRS.md
│   ├── AI_ChatHub_ERD.mermaid
│   ├── AI_ChatHub_schema.sql
│   ├── AI_ChatHub_DB_Design_Notes.md
│   └── AI_ChatHub_Microservices_Architecture.md
│
├── scripts/
│   ├── setup.sh                       ← First-time dev environment setup
│   ├── migrate-all.sh                 ← Run migrations on all services
│   └── seed-all.sh                    ← Seed all services
│
└── .github/
    └── workflows/
        ├── ci.yml                     ← Test + lint on PR
        └── deploy.yml                 ← Deploy on merge to main
```

---

## 3. Per-Service Laravel Structure

Every service follows the same internal structure. This is the canonical layout — replicate it for each service.

```
services/wallet-service/               ← Example: Wallet Service
│
├── app/
│   ├── Console/
│   │   └── Commands/                  ← Artisan commands (scheduled jobs)
│   │       └── ReconcileWalletsCommand.php
│   │
│   ├── Events/                        ← Internal Laravel events
│   │   └── WalletCredited.php
│   │
│   ├── Exceptions/
│   │   ├── InsufficientBalanceException.php
│   │   └── Handler.php
│   │
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Internal/              ← Called by other services (no auth middleware)
│   │   │   │   ├── ReserveBalanceController.php
│   │   │   │   ├── DeductBalanceController.php
│   │   │   │   └── RefundBalanceController.php
│   │   │   └── V1/                    ← Public API (JWT protected)
│   │   │       ├── WalletController.php
│   │   │       └── LedgerController.php
│   │   │
│   │   ├── Middleware/
│   │   │   ├── ValidateJwt.php        ← From shared jwt-middleware package
│   │   │   └── ValidateInternalKey.php ← For internal service-to-service calls
│   │   │
│   │   └── Requests/
│   │       ├── ReserveBalanceRequest.php
│   │       └── DeductBalanceRequest.php
│   │
│   ├── Listeners/                     ← Event bus listeners
│   │   ├── CreditWalletOnSubscription.php
│   │   ├── CreditWalletOnTopup.php
│   │   └── HandleLowBalanceAlert.php
│   │
│   ├── Models/
│   │   ├── Wallet.php
│   │   ├── WalletLedgerEntry.php
│   │   └── CreditLedger.php
│   │
│   ├── Services/
│   │   └── WalletService.php          ← Core business logic
│   │
│   └── Providers/
│       ├── AppServiceProvider.php
│       └── EventServiceProvider.php   ← Register listeners here
│
├── bootstrap/
├── config/
│   ├── app.php
│   ├── database.php
│   └── services.php                   ← Internal service URLs
│
├── database/
│   ├── migrations/                    ← Only wallet_svc.* tables
│   └── seeders/
│
├── routes/
│   ├── api.php                        ← Public routes (JWT middleware)
│   └── internal.php                   ← Internal routes (service key middleware)
│
├── tests/
│   ├── Feature/
│   └── Unit/
│
├── .env.example
├── composer.json
└── Dockerfile
```

---

## 4. Detailed Service Implementations

### 4.1 Auth Service

```
services/auth-service/app/
├── Http/Controllers/V1/
│   ├── RegisterController.php
│   ├── LoginController.php
│   ├── LogoutController.php
│   ├── EmailVerificationController.php
│   ├── PasswordResetController.php
│   └── TokenRefreshController.php
├── Http/Controllers/Internal/
│   └── UserController.php             ← GET /internal/users/{id} for other services
├── Http/Controllers/Admin/
│   ├── UserManagementController.php
│   ├── AdminUserController.php
│   ├── AuditLogController.php
│   └── SystemConfigController.php
├── Services/
│   ├── AuthService.php
│   ├── JwtService.php                 ← Issue + validate JWT
│   ├── RateLimitService.php           ← Login attempt tracking
│   └── AuditLogService.php            ← Called from all admin actions
└── Models/
    ├── User.php
    ├── AdminUser.php
    ├── RefreshToken.php
    └── AuditLog.php
```

**JWT Token Structure:**
```json
{
  "sub":      "user-uuid",
  "email":    "user@example.com",
  "status":   "active",
  "iat":      1751875200,
  "exp":      1751961600,
  "iss":      "aichathub-auth"
}
```

### 4.2 Subscription Service

```
services/subscription-service/app/
├── Http/Controllers/V1/
│   ├── PackageController.php          ← GET /packages
│   ├── SubscriptionController.php     ← POST /subscribe, GET /my-subscription
│   ├── UpgradeController.php          ← POST /upgrade
│   ├── DowngradeController.php        ← POST /downgrade
│   └── CancelController.php           ← POST /cancel
├── Http/Controllers/Internal/
│   └── SubscriptionCheckController.php ← GET /internal/subscriptions/{user_id}/current
├── Http/Controllers/Admin/
│   ├── AdminPackageController.php
│   └── AdminSubscriptionController.php
├── Console/Commands/
│   └── ProcessRenewalsCommand.php     ← Runs hourly via scheduler
├── Jobs/
│   ├── ProcessRenewalJob.php          ← Processes single subscription renewal
│   └── RetryRenewalJob.php            ← Handles delayed retry attempts
├── Services/
│   ├── SubscriptionService.php        ← Core lifecycle logic
│   ├── RenewalService.php             ← Renewal + retry logic
│   └── PackageTierService.php         ← Model access resolution
└── Models/
    ├── Package.php
    ├── UserSubscription.php
    ├── SubscriptionHistory.php
    └── RenewalAttempt.php
```

**Renewal Command (app/Console/Commands/ProcessRenewalsCommand.php):**
```php
// Registered in app/Console/Kernel.php:
// $schedule->command('renewals:process')->hourly();

public function handle(RenewalService $service): void
{
    $due = UserSubscription::query()
        ->where('status', 'active')
        ->where('auto_renew', true)
        ->where('renews_at', '<=', now())
        ->get();

    foreach ($due as $subscription) {
        ProcessRenewalJob::dispatch($subscription);
    }

    $this->info("Dispatched {$due->count()} renewal jobs.");
}
```

### 4.3 Wallet Service

```
services/wallet-service/app/
├── Http/Controllers/V1/
│   ├── WalletController.php           ← GET /wallet (balance)
│   └── LedgerController.php           ← GET /wallet/ledger (history)
├── Http/Controllers/Internal/
│   ├── ReserveBalanceController.php   ← POST /internal/wallet/reserve
│   ├── DeductBalanceController.php    ← POST /internal/wallet/deduct
│   └── RefundBalanceController.php    ← POST /internal/wallet/refund
├── Console/Commands/
│   └── ReconcileWalletsCommand.php    ← Daily integrity check
├── Listeners/
│   ├── CreditWalletOnPurchase.php     ← Listens: subscription.purchased
│   ├── CreditWalletOnRenewal.php      ← Listens: subscription.renewed
│   ├── CreditWalletOnTopup.php        ← Listens: payment.succeeded (topup)
│   └── CreditWalletOnRefund.php       ← Listens: payment.refunded
└── Services/
    └── WalletService.php
```

**WalletService.php — Core Reserve Method:**
```php
public function reserve(string $userId, float $amount): bool
{
    return DB::transaction(function () use ($userId, $amount) {
        $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

        $available = $wallet->balance
            + ($wallet->credit_limit - abs($wallet->credit_balance));

        if ($available < $amount) {
            return false; // Insufficient funds
        }

        $wallet->reserved_balance += $amount;
        $wallet->save();

        return true;
    });
}

public function deduct(string $userId, float $actual, float $reserved, string $usageLogId): void
{
    DB::transaction(function () use ($userId, $actual, $reserved, $usageLogId) {
        $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

        $balanceBefore = $wallet->balance;
        $wallet->reserved_balance -= $reserved;

        if ($wallet->balance >= $actual) {
            $wallet->balance -= $actual;
        } else {
            $shortage = $actual - $wallet->balance;
            $wallet->balance = 0;
            $wallet->credit_balance -= $shortage;

            CreditLedger::create([
                'wallet_id'             => $wallet->id,
                'user_id'               => $userId,
                'type'                  => 'credit_used',
                'amount'                => $shortage,
                'credit_balance_before' => $wallet->credit_balance + $shortage,
                'credit_balance_after'  => $wallet->credit_balance,
                'description'           => 'Credit buffer used for AI request',
                'reference_id'          => $usageLogId,
            ]);
        }

        $wallet->save();

        WalletLedgerEntry::create([
            'wallet_id'      => $wallet->id,
            'user_id'        => $userId,
            'type'           => 'debit',
            'amount'         => $actual,
            'balance_before' => $balanceBefore,
            'balance_after'  => $wallet->balance,
            'description'    => 'AI Request Cost',
            'reference_type' => 'usage_log',
            'reference_id'   => $usageLogId,
        ]);

        if ($wallet->balance < 5.00) {
            event(new WalletBalanceLow($userId, $wallet->balance));
        }
    });
}
```

### 4.4 AI Gateway Service

```
services/ai-gateway-service/app/
├── Ai/
│   ├── Agents/
│   │   ├── TextChatAgent.php          ← General text chat (all providers)
│   │   ├── DocumentAnalysisAgent.php  ← With file attachment support
│   │   └── VisionAgent.php            ← Image analysis
│   ├── Middleware/
│   │   ├── TokenCountingMiddleware.php ← Counts tokens, estimates cost
│   │   ├── UsageLoggingMiddleware.php  ← Writes usage_logs
│   │   └── CostDeductionMiddleware.php ← Calls wallet service post-stream
│   └── Tools/
│       └── WebSearchTool.php          ← Wraps Laravel AI SDK WebSearch provider tool
│
├── Http/Controllers/V1/
│   ├── ChatController.php             ← POST /chat/stream
│   ├── ImageController.php            ← POST /generate/image
│   ├── AudioController.php            ← POST /generate/audio
│   ├── TranscriptionController.php    ← POST /transcribe
│   └── ComparisonController.php       ← POST /chat/compare (multi-model)
│
├── Services/
│   ├── ModelAccessService.php         ← Calls Subscription Service to verify access
│   ├── CostEstimationService.php      ← Pre-flight cost estimation
│   ├── ProviderRouterService.php      ← Failover + circuit breaker logic
│   └── CircuitBreakerService.php      ← Manages circuit_breaker_state table
│
└── Models/
    ├── AiModel.php
    ├── ModelPricing.php
    ├── UsageLog.php
    ├── ProviderFallbackRule.php
    └── CircuitBreakerState.php
```


---

## 5. Laravel AI SDK Integration (AI Gateway Service)

### 5.1 Installation

```bash
cd services/ai-gateway-service
composer require laravel/ai
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"
php artisan migrate
```

### 5.2 Provider Configuration (`config/ai.php`)

```php
return [
    'default' => env('AI_DEFAULT_PROVIDER', 'openai'),

    'providers' => [
        'openai' => [
            'driver' => 'openai',
            'key'    => env('OPENAI_API_KEY'),
        ],
        'anthropic' => [
            'driver' => 'anthropic',
            'key'    => env('ANTHROPIC_API_KEY'),
        ],
        'gemini' => [
            'driver' => 'gemini',
            'key'    => env('GEMINI_API_KEY'),
        ],
        'xai' => [
            'driver' => 'xai',
            'key'    => env('XAI_API_KEY'),
        ],
        'elevenlabs' => [
            'driver' => 'elevenlabs',
            'key'    => env('ELEVENLABS_API_KEY'),
        ],
    ],

    // Embedding cache for RAG / document search (Phase 2)
    'caching' => [
        'embeddings' => [
            'cache' => env('AI_CACHE_EMBEDDINGS', false),
            'store' => env('CACHE_STORE', 'redis'),
        ],
    ],
];
```

### 5.3 Agent Implementation (app/Ai/Agents/TextChatAgent.php)

```php
<?php

namespace App\Ai\Agents;

use App\Ai\Middleware\TokenCountingMiddleware;
use App\Ai\Middleware\UsageLoggingMiddleware;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;

#[Provider(Lab::OpenAI)]
#[MaxTokens(4096)]
#[Temperature(0.7)]
class TextChatAgent implements Agent, Conversational, HasMiddleware
{
    use Promptable;

    public function __construct(
        private User $user,
        private string $model,
        private array $conversationHistory = []
    ) {}

    public function instructions(): string
    {
        return 'You are a helpful AI assistant. Be concise, accurate, and friendly.';
    }

    public function messages(): iterable
    {
        return array_map(
            fn($msg) => new Message($msg['role'], $msg['content']),
            $this->conversationHistory
        );
    }

    public function middleware(): array
    {
        return [
            new TokenCountingMiddleware($this->user),
            new UsageLoggingMiddleware($this->user, $this->model),
        ];
    }
}
```

### 5.4 Streaming Chat Controller (`app/Http/Controllers/V1/ChatController.php`)

```php
<?php

namespace App\Http\Controllers\V1;

use App\Ai\Agents\TextChatAgent;
use App\Http\Controllers\Controller;
use App\Services\ModelAccessService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Laravel\Ai\Enums\Lab;

class ChatController extends Controller
{
    public function __construct(
        private ModelAccessService $modelAccess,
        private WalletService $wallet
    ) {}

    public function stream(Request $request)
    {
        $validated = $request->validate([
            'message'     => 'required|string|max:10000',
            'model_id'    => 'required|uuid',
            'session_id'  => 'nullable|uuid',
            'attachments' => 'nullable|array',
        ]);

        $user = $request->user();

        // 1. Check subscription allows this model
        if (!$this->modelAccess->canAccess($user->id, $validated['model_id'])) {
            return response()->json([
                'error' => 'Model not available in your subscription package'
            ], 403);
        }

        // 2. Estimate cost
        $estimatedCost = $this->estimateCost($validated['message'], $validated['model_id']);

        // 3. Reserve balance
        if (!$this->wallet->reserve($user->id, $estimatedCost)) {
            return response()->json([
                'error' => 'Insufficient wallet balance. Please top up.'
            ], 402);
        }

        // 4. Create agent + stream
        $agent = TextChatAgent::make(
            user: $user,
            model: $validated['model_id'],
            conversationHistory: $this->loadHistory($validated['session_id'] ?? null)
        );

        try {
            return $agent->stream($validated['message'])
                ->then(function ($response) use ($user, $estimatedCost) {
                    // Deduct actual cost after stream completes
                    $actualCost = $response->usage->totalCost();
                    $this->wallet->deduct($user->id, $actualCost, $estimatedCost);
                });
        } catch (\Exception $e) {
            // Refund on failure
            $this->wallet->refund($user->id, $estimatedCost);
            throw $e;
        }
    }

    private function estimateCost(string $message, string $modelId): float
    {
        // Token estimation logic here
        return 0.05; // Placeholder
    }

    private function loadHistory(?string $sessionId): array
    {
        // Load from chat_messages table
        return [];
    }
}
```

### 5.5 Failover Configuration

```php
// Use Laravel AI SDK's built-in failover
$response = (new TextChatAgent)->prompt(
    $message,
    provider: [Lab::OpenAI, Lab::Anthropic, Lab::Gemini] // Try in order
);
```

Or configure fallback rules in `provider_fallback_rules` table and apply manually via `ProviderRouterService`.

---

## 6. Event Bus Architecture

### 6.1 Technology: Redis Pub/Sub (Phase 1)

Laravel Broadcasting + Redis provides a simple event bus for Phase 1.

**Why Redis Pub/Sub:**
- Already using Redis for cache
- Simple to set up
- Low latency (sub-millisecond)
- Built-in Laravel support

**Limitations:**
- Fire-and-forget (at-most-once delivery)
- No persistence — if subscriber is down, events are lost
- No replay capability

**Phase 2 Migration:** RabbitMQ for persistent queues + dead letter exchange.

### 6.2 Configuration (`config/broadcasting.php`)

```php
'connections' => [
    'redis' => [
        'driver'     => 'redis',
        'connection' => 'default',
    ],
],
```

### 6.3 Event Publishing

**Example Event: `app/Events/SubscriptionPurchased.php` (Subscription Service)**

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionPurchased implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $userId,
        public string $subscriptionId,
        public string $packageId,
        public float $amount,
        public string $currency,
        public string $transactionId
    ) {}

    public function broadcastOn(): array
    {
        return ['subscription-events'];
    }

    public function broadcastAs(): string
    {
        return 'subscription.purchased';
    }
}
```

**Publishing:**
```php
event(new SubscriptionPurchased(
    userId: $user->id,
    subscriptionId: $subscription->id,
    packageId: $package->id,
    amount: $amount,
    currency: $currency,
    transactionId: $transaction->id
));
```

### 6.4 Event Listening

**Example Listener: `app/Listeners/CreditWalletOnPurchase.php` (Wallet Service)**

```php
<?php

namespace App\Listeners;

use App\Services\WalletService;

class CreditWalletOnPurchase
{
    public function __construct(private WalletService $wallet) {}

    public function handle(object $event): void
    {
        // $event is deserialized from Redis broadcast
        $this->wallet->credit(
            userId: $event->userId,
            amount: $event->amount,
            description: "Subscription Purchase: {$event->packageId}",
            referenceType: 'transaction',
            referenceId: $event->transactionId
        );
    }
}
```

**Register in `app/Providers/EventServiceProvider.php`:**

```php
protected $listen = [
    'subscription.purchased' => [
        CreditWalletOnPurchase::class,
    ],
    'subscription.renewed' => [
        CreditWalletOnRenewal::class,
    ],
    'payment.succeeded' => [
        CreditWalletOnTopup::class,
    ],
    'wallet.balance_low' => [
        SendLowBalanceNotification::class,
    ],
];
```

**Start the event listener worker:**

```bash
php artisan queue:work redis --queue=subscription-events
```

---

## 7. Docker Setup

### 7.1 Base PHP Dockerfile (`infrastructure/docker/php/Dockerfile`)

```dockerfile
FROM php:8.3-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl

# Install PHP extensions
RUN docker-php-ext-install pdo_pgsql pgsql zip opcache

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy PHP config
COPY php.ini /usr/local/etc/php/conf.d/custom.ini

# Copy entrypoint
COPY entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
```

### 7.2 Nginx Config (`infrastructure/docker/nginx/default.conf`)

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/public;
    index index.php;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass php-fpm:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### 7.3 Docker Compose (`docker-compose.yml`)

```yaml
version: '3.8'

services:
  # PostgreSQL
  postgres:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: ai_chathub_db
      POSTGRES_USER: postgres
      POSTGRES_PASSWORD: secret
    ports:
      - "5432:5432"
    volumes:
      - postgres-data:/var/lib/postgresql/data

  # Redis
  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"
    volumes:
      - redis-data:/data

  # Auth Service
  auth-service:
    build:
      context: ./infrastructure/docker/php
      dockerfile: Dockerfile
    volumes:
      - ./services/auth-service:/var/www
    environment:
      DB_HOST: postgres
      DB_DATABASE: ai_chathub_db
      DB_USERNAME: postgres
      DB_PASSWORD: secret
      DB_SCHEMA: auth_svc
      REDIS_HOST: redis
    depends_on:
      - postgres
      - redis

  auth-nginx:
    image: nginx:alpine
    ports:
      - "8001:80"
    volumes:
      - ./services/auth-service:/var/www
      - ./infrastructure/docker/nginx/default.conf:/etc/nginx/conf.d/default.conf
    depends_on:
      - auth-service

  # Subscription Service
  subscription-service:
    build:
      context: ./infrastructure/docker/php
      dockerfile: Dockerfile
    volumes:
      - ./services/subscription-service:/var/www
    environment:
      DB_HOST: postgres
      DB_SCHEMA: subscription_svc
      REDIS_HOST: redis
    depends_on:
      - postgres
      - redis

  subscription-nginx:
    image: nginx:alpine
    ports:
      - "8002:80"
    volumes:
      - ./services/subscription-service:/var/www
      - ./infrastructure/docker/nginx/default.conf:/etc/nginx/conf.d/default.conf
    depends_on:
      - subscription-service

  # Repeat pattern for all services...
  # wallet-service (8003), payment-service (8004), etc.

  # Frontend (Next.js)
  frontend:
    build:
      context: ./frontend
      dockerfile: Dockerfile
    ports:
      - "3000:3000"
    environment:
      NEXT_PUBLIC_API_URL: http://localhost:8000
    depends_on:
      - api-gateway

volumes:
  postgres-data:
  redis-data:
```

### 7.4 First-Time Setup Script (`scripts/setup.sh`)

```bash
#!/bin/bash
set -e

echo "🚀 AI ChatHub Setup"

# Build all images
docker-compose build

# Start services
docker-compose up -d postgres redis

# Wait for postgres
echo "⏳ Waiting for PostgreSQL..."
sleep 5

# Run migrations for each service
for service in auth subscription wallet payment billing ai-gateway chat notification; do
    echo "📦 Migrating ${service}-service..."
    docker-compose exec ${service}-service php artisan migrate --force
done

# Seed data
docker-compose exec subscription-service php artisan db:seed
docker-compose exec ai-gateway-service php artisan db:seed

echo "✅ Setup complete!"
echo "🌐 API Gateway: http://localhost:8000"
echo "🖥️  Frontend: http://localhost:3000"
```

---

## 8. Inter-Service Communication

### 8.1 Internal REST API Pattern

**Calling Service (AI Gateway → Wallet Service):**

```php
// app/Services/WalletService.php (AI Gateway Service)
use Illuminate\Support\Facades\Http;

public function reserveBalance(string $userId, float $amount): bool
{
    $response = Http::timeout(5)
        ->withHeaders(['X-Internal-Key' => config('services.internal_key')])
        ->post(config('services.wallet_url') . '/internal/wallet/reserve', [
            'user_id' => $userId,
            'amount'  => $amount,
        ]);

    return $response->successful() && $response->json('success');
}
```

**Receiving Service (Wallet Service):**

```php
// routes/internal.php
Route::middleware('internal.key')->group(function () {
    Route::post('/wallet/reserve', [ReserveBalanceController::class, 'reserve']);
    Route::post('/wallet/deduct', [DeductBalanceController::class, 'deduct']);
    Route::post('/wallet/refund', [RefundBalanceController::class, 'refund']);
});

// app/Http/Middleware/ValidateInternalKey.php
public function handle(Request $request, Closure $next)
{
    if ($request->header('X-Internal-Key') !== config('services.internal_key')) {
        abort(401, 'Unauthorized internal request');
    }

    return $next($request);
}
```

**Service URLs Configuration (`config/services.php`):**

```php
return [
    'internal_key' => env('INTERNAL_SERVICE_KEY', 'change-me-in-production'),

    'auth_url'         => env('AUTH_SERVICE_URL', 'http://auth-nginx'),
    'subscription_url' => env('SUBSCRIPTION_SERVICE_URL', 'http://subscription-nginx'),
    'wallet_url'       => env('WALLET_SERVICE_URL', 'http://wallet-nginx'),
    'payment_url'      => env('PAYMENT_SERVICE_URL', 'http://payment-nginx'),
    'billing_url'      => env('BILLING_SERVICE_URL', 'http://billing-nginx'),
    'notification_url' => env('NOTIFICATION_SERVICE_URL', 'http://notification-nginx'),
];
```

### 8.2 Circuit Breaker Pattern

```php
// Wrap external calls with retry + timeout
$response = Http::retry(3, 100) // 3 retries, 100ms delay
    ->timeout(5)                 // 5 second timeout
    ->post($url, $data);

if ($response->failed()) {
    // Log failure, increment circuit breaker counter
    CircuitBreakerService::recordFailure($serviceName);

    if (CircuitBreakerService::isOpen($serviceName)) {
        throw new ServiceUnavailableException("$serviceName is down");
    }
}
```

---

## 9. Shared Packages

### 9.1 Event Contracts Package (`packages/event-contracts/`)

```php
// packages/event-contracts/src/SubscriptionPurchased.php
namespace AiChatHub\EventContracts;

readonly class SubscriptionPurchased
{
    public function __construct(
        public string $userId,
        public string $subscriptionId,
        public string $packageId,
        public float $amount,
        public string $currency,
        public string $transactionId
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            userId: $data['user_id'],
            subscriptionId: $data['subscription_id'],
            packageId: $data['package_id'],
            amount: $data['amount'],
            currency: $data['currency'],
            transactionId: $data['transaction_id']
        );
    }

    public function toArray(): array
    {
        return [
            'user_id'         => $this->userId,
            'subscription_id' => $this->subscriptionId,
            'package_id'      => $this->packageId,
            'amount'          => $this->amount,
            'currency'        => $this->currency,
            'transaction_id'  => $this->transactionId,
        ];
    }
}
```

**composer.json in each service:**
```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../../packages/event-contracts"
        }
    ],
    "require": {
        "aichathub/event-contracts": "@dev"
    }
}
```

---

## 10. Testing Strategy

### 10.1 Test Types per Service

| Test Type | Coverage Target | Tools |
|-----------|----------------|-------|
| Unit Tests | 80%+ | PHPUnit |
| Feature Tests (API) | All endpoints | PHPUnit + Pest |
| Integration Tests | Event flows | PHPUnit |
| Contract Tests | Inter-service APIs | Pact (Phase 2) |
| E2E Tests | Critical user flows | Playwright |

### 10.2 Example Feature Test (`tests/Feature/SubscriptionPurchaseTest.php`)

```php
<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionPurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_purchase_subscription(): void
    {
        $user = User::factory()->create();
        $package = Package::factory()->create(['monthly_price_usd' => 20.00]);

        // Mock payment service response
        Http::fake([
            'payment-service/*/charge' => Http::response(['transaction_id' => 'txn_123'], 200),
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/subscribe', [
            'package_id'        => $package->id,
            'payment_method_id' => 'pm_test',
        ]);

        $response->assertStatus(201);
        $response->assertJson(['message' => 'Subscription activated']);

        $this->assertDatabaseHas('user_subscriptions', [
            'user_id'    => $user->id,
            'package_id' => $package->id,
            'status'     => 'active',
        ]);
    }
}
```

---

## 11. CI/CD Pipeline

### 11.1 GitHub Actions Workflow (`.github/workflows/ci.yml`)

```yaml
name: CI

on:
  pull_request:
    branches: [main, develop]

jobs:
  test:
    runs-on: ubuntu-latest

    services:
      postgres:
        image: postgres:16
        env:
          POSTGRES_DB: test_db
          POSTGRES_USER: test
          POSTGRES_PASSWORD: test
        ports:
          - 5432:5432

      redis:
        image: redis:7-alpine
        ports:
          - 6379:6379

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.3
          extensions: pdo_pgsql, redis

      - name: Install Dependencies
        run: |
          cd services/auth-service && composer install
          cd ../subscription-service && composer install
          # Repeat for all services

      - name: Run Migrations
        run: |
          cd services/auth-service && php artisan migrate --env=testing
          # Repeat for all services

      - name: Run Tests
        run: |
          cd services/auth-service && php artisan test
          cd ../subscription-service && php artisan test
          # Repeat for all services

      - name: PHPStan Analysis
        run: |
          cd services/auth-service && vendor/bin/phpstan analyze --level=8
          # Repeat for all services
```

---

## 12. Deployment (Kubernetes - Phase 2)

### 12.1 Deployment Manifest (`infrastructure/k8s/deployments/wallet-service.yaml`)

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: wallet-service
spec:
  replicas: 3
  selector:
    matchLabels:
      app: wallet-service
  template:
    metadata:
      labels:
        app: wallet-service
    spec:
      containers:
      - name: wallet-service
        image: aichathub/wallet-service:latest
        ports:
        - containerPort: 9000
        env:
        - name: DB_HOST
          valueFrom:
            configMapKeyRef:
              name: db-config
              key: host
        - name: DB_SCHEMA
          value: "wallet_svc"
        resources:
          requests:
            cpu: "100m"
            memory: "256Mi"
          limits:
            cpu: "500m"
            memory: "512Mi"
---
apiVersion: v1
kind: Service
metadata:
  name: wallet-service
spec:
  selector:
    app: wallet-service
  ports:
  - port: 80
    targetPort: 9000
  type: ClusterIP
```

---

## 13. Monitoring & Observability

### 13.1 Health Check Endpoints

Every service exposes:

```php
// routes/api.php
Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

Route::get('/ready', function () {
    try {
        DB::connection()->getPdo();
        Redis::ping();
        return response()->json(['status' => 'ready']);
    } catch (\Exception $e) {
        return response()->json(['status' => 'not_ready'], 503);
    }
});
```

### 13.2 Logging Strategy

**Structured JSON Logs:**

```php
// config/logging.php
'stack' => [
    'driver' => 'stack',
    'channels' => ['daily', 'stdout'],
    'ignore_exceptions' => false,
],

'stdout' => [
    'driver' => 'monolog',
    'handler' => StreamHandler::class,
    'with' => ['stream' => 'php://stdout'],
    'formatter' => \Monolog\Formatter\JsonFormatter::class,
],
```

**Example Log Entry:**

```json
{
  "timestamp": "2026-07-08T12:34:56Z",
  "level": "info",
  "service": "wallet-service",
  "message": "Balance reserved",
  "context": {
    "user_id": "uuid",
    "amount": 0.05,
    "request_id": "uuid"
  }
}
```

### 13.3 Metrics (Prometheus + Grafana)

```php
// Use Laravel Prometheus package
composer require spatie/laravel-prometheus

// Expose /metrics endpoint
Route::get('/metrics', '\Spatie\Prometheus\PrometheusController');
```

**Key Metrics:**
- `wallet_reserve_duration_seconds` (histogram)
- `subscription_renewals_total` (counter)
- `ai_requests_total` (counter by model)
- `wallet_balance_low_alerts_total` (counter)
- `payment_failures_total` (counter by gateway)

---

## 14. Security Hardening

### 14.1 Service-to-Service Authentication

All internal APIs protected by shared secret in `X-Internal-Key` header.

**Production:** Rotate key monthly, store in Vault/AWS Secrets Manager.

### 14.2 Rate Limiting

```php
// config/app.php — per service
'rate_limits' => [
    'api'      => '100/minute',
    'internal' => '1000/minute',
],
```

### 14.3 Secrets Management

Never commit `.env` files. Use:

- **Local:** `.env.example` template + `.env` generated per developer
- **Production:** AWS Secrets Manager / HashiCorp Vault / Kubernetes Secrets

---

## 15. Phase Rollout Plan

### Phase 1 (Weeks 1-10) — MVP

- All services running in Docker Compose on single VPS
- Shared PostgreSQL, single Redis instance
- Manual deployment via `docker-compose up --build -d`
- Basic monitoring (logs + manual checks)

### Phase 2 (Weeks 11-22) — Production Ready

- Kubernetes deployment (3 replicas per service)
- Managed PostgreSQL (AWS RDS Multi-AZ)
- Redis Cluster (3 nodes)
- CI/CD with GitHub Actions
- Prometheus + Grafana monitoring
- PgBouncer connection pooling

### Phase 3 (Weeks 23+) — Scale

- Separate database per service
- Kafka event bus (replace Redis Pub/Sub)
- ClickHouse for analytics (usage_logs, chat_messages)
- Horizontal pod autoscaling (HPA)
- Service mesh (Istio) for traffic management

---

**END OF MICROSERVICES ARCHITECTURE DOCUMENT**

**Prepared By:** AI ChatHub Development Team  
**Status:** Ready for Implementation  
**Next Steps:** Clone repo structure → Docker setup → First service (Auth) → Iterate
