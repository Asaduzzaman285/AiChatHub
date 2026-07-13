$Root = "C:\Users\IT News\Downloads\aichathub\aichathub"

$services = @(
    "auth-service",
    "subscription-service",
    "wallet-service",
    "payment-service",
    "ai-gateway-service",
    "chat-service",
    "billing-service",
    "notification-service",
    "api-gateway"
)

$commonDirs = @(
    "app\Http\Controllers\V1",
    "app\Http\Controllers\Internal",
    "app\Http\Controllers\Admin",
    "app\Http\Middleware",
    "app\Http\Requests",
    "app\Models",
    "app\Services",
    "app\Events",
    "app\Listeners",
    "app\Providers",
    "app\Exceptions",
    "app\Console\Commands",
    "app\Jobs",
    "database\migrations",
    "database\seeders",
    "routes",
    "config",
    "tests\Feature",
    "tests\Unit",
    "bootstrap\cache",
    "storage\logs",
    "storage\framework\cache",
    "storage\framework\sessions",
    "storage\framework\views",
    "public"
)

foreach ($svc in $services) {
    $base = "$Root\services\$svc"
    foreach ($d in $commonDirs) {
        New-Item -ItemType Directory -Force -Path "$base\$d" | Out-Null
    }
    Write-Host "  created: $svc"
}

# Extra dirs per service
New-Item -ItemType Directory -Force -Path "$Root\services\auth-service\app\Http\Controllers\V1\Auth" | Out-Null
New-Item -ItemType Directory -Force -Path "$Root\services\auth-service\tests\Feature\Auth" | Out-Null
New-Item -ItemType Directory -Force -Path "$Root\services\payment-service\app\Http\Controllers\V1\Webhooks" | Out-Null
New-Item -ItemType Directory -Force -Path "$Root\services\ai-gateway-service\app\Ai\Agents" | Out-Null
New-Item -ItemType Directory -Force -Path "$Root\services\ai-gateway-service\app\Ai\Middleware" | Out-Null
New-Item -ItemType Directory -Force -Path "$Root\services\ai-gateway-service\app\Ai\Tools" | Out-Null
New-Item -ItemType Directory -Force -Path "$Root\services\notification-service\app\Mail" | Out-Null
New-Item -ItemType Directory -Force -Path "$Root\services\notification-service\app\Notifications" | Out-Null
New-Item -ItemType Directory -Force -Path "$Root\services\api-gateway\app\Http\Controllers\Proxy" | Out-Null

# Frontend dirs
$fe = "$Root\frontend"
$feDirs = @(
    "src\app\(auth)\login",
    "src\app\(auth)\register",
    "src\app\(auth)\forgot-password",
    "src\app\(dashboard)\chat\[sessionId]",
    "src\app\(dashboard)\wallet",
    "src\app\(dashboard)\billing",
    "src\app\(dashboard)\settings",
    "src\app\(admin)\users",
    "src\app\(admin)\models",
    "src\app\(admin)\transactions",
    "src\components\ui",
    "src\components\chat",
    "src\components\wallet",
    "src\components\auth",
    "src\components\admin",
    "src\hooks",
    "src\lib",
    "src\stores",
    "src\types",
    "public"
)
foreach ($d in $feDirs) {
    New-Item -ItemType Directory -Force -Path "$fe\$d" | Out-Null
}
Write-Host "  created: frontend"

# Shared packages
New-Item -ItemType Directory -Force -Path "$Root\packages\shared-kernel\src\DTOs" | Out-Null
New-Item -ItemType Directory -Force -Path "$Root\packages\shared-kernel\src\Enums" | Out-Null
New-Item -ItemType Directory -Force -Path "$Root\packages\event-contracts\src" | Out-Null
New-Item -ItemType Directory -Force -Path "$Root\packages\jwt-middleware\src" | Out-Null
Write-Host "  created: packages"

Write-Host "Done."
