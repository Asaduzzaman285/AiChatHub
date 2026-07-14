$content = @'
<?php

namespace App\Http\Controllers;

abstract class Controller
{
    //
}
'@

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
    $path = "c:\Users\IT News\Downloads\aichathub\aichathub\services\$svc\app\Http\Controllers\Controller.php"
    if (!(Test-Path $path)) {
        Set-Content -Path $path -Value $content
        Write-Host "Created base Controller: $svc"
    } else {
        Write-Host "Already exists: $svc"
    }
}
Write-Host "Done."
