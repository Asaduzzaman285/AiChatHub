$services = @(
    "subscription-service","wallet-service","payment-service",
    "ai-gateway-service","chat-service","billing-service",
    "notification-service","api-gateway"
)

$srcJwt      = "c:\Users\IT News\Downloads\aichathub\aichathub\services\auth-service\app\Http\Middleware\JwtAuthMiddleware.php"
$srcInternal = "c:\Users\IT News\Downloads\aichathub\aichathub\services\auth-service\app\Http\Middleware\InternalServiceMiddleware.php"

foreach ($svc in $services) {
    $dir = "c:\Users\IT News\Downloads\aichathub\aichathub\services\$svc\app\Http\Middleware"
    if (!(Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    Copy-Item $srcJwt      "$dir\JwtAuthMiddleware.php"
    Copy-Item $srcInternal "$dir\InternalServiceMiddleware.php"
    Write-Host "Copied middleware to: $svc"
}
Write-Host "Done."
