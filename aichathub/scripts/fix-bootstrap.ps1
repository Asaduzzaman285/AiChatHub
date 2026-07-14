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
    $file = "c:\Users\IT News\Downloads\aichathub\aichathub\services\$svc\bootstrap\app.php"
    $content = Get-Content $file -Raw
    # Add apiPrefix: 'api/v1' after the api: line
    $content = $content -replace "(api: __DIR__\.'/../routes/api\.php',)", "`$1`n        apiPrefix: 'api/v1',"
    Set-Content $file -Value $content -NoNewline
    Write-Host "Fixed: $svc"
}
Write-Host "Done."
