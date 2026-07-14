$containers = @(
    "aichathub-auth",
    "aichathub-subscription",
    "aichathub-wallet",
    "aichathub-payment",
    "aichathub-ai-gateway",
    "aichathub-chat",
    "aichathub-billing",
    "aichathub-notification",
    "aichathub-api-gateway"
)

foreach ($c in $containers) {
    Write-Host "Clearing cache: $c"
    docker exec $c php artisan config:clear 2>&1 | Out-Null
    docker exec $c php artisan route:clear  2>&1 | Out-Null
    docker exec $c php artisan cache:clear  2>&1 | Out-Null
    Write-Host "  Done: $c"
}
Write-Host ""
Write-Host "All caches cleared."
