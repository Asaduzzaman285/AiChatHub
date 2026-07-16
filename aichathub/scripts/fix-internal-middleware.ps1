$services = @(
    "subscription-service","payment-service","ai-gateway-service",
    "chat-service","billing-service","notification-service","api-gateway"
)

$old = '        $key = $request->header(''X-Internal-Service-Key'');

        if ($key !== config(''app.internal_service_key'')) {'

$new = '        $key      = $request->header(''X-Internal-Service-Key'');
        $expected = env(''INTERNAL_SERVICE_KEY'', config(''app.internal_service_key''));

        if (! $expected || $key !== $expected) {'

foreach ($svc in $services) {
    $file = "c:\Users\IT News\Downloads\aichathub\aichathub\services\$svc\app\Http\Middleware\InternalServiceMiddleware.php"
    if (Test-Path $file) {
        $content = Get-Content $file -Raw
        $content = $content.Replace($old, $new)
        Set-Content $file -Value $content -NoNewline
        Write-Host "Fixed: $svc"
    }
}
Write-Host "Done."
