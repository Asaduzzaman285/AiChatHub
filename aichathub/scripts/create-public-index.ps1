$indexContent = Get-Content "c:\Users\IT News\Downloads\aichathub\aichathub\services\auth-service\public\index.php" -Raw

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
    $path = "c:\Users\IT News\Downloads\aichathub\aichathub\services\$svc\public"
    if (!(Test-Path $path)) {
        New-Item -ItemType Directory -Path $path | Out-Null
    }
    Set-Content -Path "$path\index.php" -Value $indexContent
    Write-Host "Created: $svc/public/index.php"
}

Write-Host ""
Write-Host "Done! public/index.php created for all services."
