$src = "c:\Users\IT News\Downloads\aichathub\aichathub\services\auth-service\app\Http\Controllers\HealthController.php"
$services = @(
    "subscription-service",
    "wallet-service",
    "payment-service",
    "ai-gateway-service",
    "chat-service",
    "billing-service",
    "notification-service",
    "api-gateway"
)
foreach ($svc in $services) {
    $dest = "c:\Users\IT News\Downloads\aichathub\aichathub\services\$svc\app\Http\Controllers\HealthController.php"
    Copy-Item $src $dest
    Write-Host "Copied to $svc"
}
Write-Host "Done."
