# Creates a stub controller that returns 501 Not Implemented
function Make-Stub($namespace, $class, $path) {
    if (Test-Path $path) { return }
    $dir = Split-Path $path -Parent
    if (!(Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $content = @"
<?php

namespace $namespace;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class $class extends Controller
{
    public function __call(string `$method, array `$args): JsonResponse
    {
        return response()->json(['error' => 'Not implemented', 'method' => `$method], 501);
    }
}
"@
    Set-Content -Path $path -Value $content
    Write-Host "Stubbed: $namespace\$class"
}

$base = "c:\Users\IT News\Downloads\aichathub\aichathub\services"

# ── AUTH SERVICE ──────────────────────────────────────────────────────────────
$authV1 = "$base\auth-service\app\Http\Controllers\V1\Auth"
Make-Stub "App\Http\Controllers\V1\Auth" "LogoutController"           "$authV1\LogoutController.php"
Make-Stub "App\Http\Controllers\V1\Auth" "EmailVerificationController" "$authV1\EmailVerificationController.php"
Make-Stub "App\Http\Controllers\V1\Auth" "PasswordResetController"     "$authV1\PasswordResetController.php"
Make-Stub "App\Http\Controllers\V1\Auth" "TokenRefreshController"      "$authV1\TokenRefreshController.php"
Make-Stub "App\Http\Controllers\V1\Auth" "SocialAccountController"     "$authV1\SocialAccountController.php"

# ── SUBSCRIPTION SERVICE ──────────────────────────────────────────────────────
$subV1 = "$base\subscription-service\app\Http\Controllers\V1"
Make-Stub "App\Http\Controllers\V1" "PackageController"      "$subV1\PackageController.php"
Make-Stub "App\Http\Controllers\V1" "SubscriptionController" "$subV1\SubscriptionController.php"

# ── WALLET SERVICE ────────────────────────────────────────────────────────────
$walV1 = "$base\wallet-service\app\Http\Controllers\V1"
Make-Stub "App\Http\Controllers\V1" "WalletController" "$walV1\WalletController.php"
Make-Stub "App\Http\Controllers\V1" "LedgerController" "$walV1\LedgerController.php"

# ── PAYMENT SERVICE ───────────────────────────────────────────────────────────
$payV1 = "$base\payment-service\app\Http\Controllers\V1"
$payWH = "$payV1\Webhooks"
Make-Stub "App\Http\Controllers\V1" "PaymentMethodController" "$payV1\PaymentMethodController.php"
Make-Stub "App\Http\Controllers\V1" "TopupController"         "$payV1\TopupController.php"
Make-Stub "App\Http\Controllers\V1" "TransactionController"   "$payV1\TransactionController.php"
Make-Stub "App\Http\Controllers\V1\Webhooks" "BkashWebhookController" "$payWH\BkashWebhookController.php"

# ── AI GATEWAY SERVICE ────────────────────────────────────────────────────────
$aiV1 = "$base\ai-gateway-service\app\Http\Controllers\V1"
Make-Stub "App\Http\Controllers\V1" "ModelController"        "$aiV1\ModelController.php"
Make-Stub "App\Http\Controllers\V1" "ImageController"        "$aiV1\ImageController.php"
Make-Stub "App\Http\Controllers\V1" "AudioController"        "$aiV1\AudioController.php"
Make-Stub "App\Http\Controllers\V1" "TranscriptionController" "$aiV1\TranscriptionController.php"

# ── CHAT SERVICE ──────────────────────────────────────────────────────────────
$chatV1 = "$base\chat-service\app\Http\Controllers\V1"
Make-Stub "App\Http\Controllers\V1" "SessionController"        "$chatV1\SessionController.php"
Make-Stub "App\Http\Controllers\V1" "MessageController"        "$chatV1\MessageController.php"
Make-Stub "App\Http\Controllers\V1" "FileAttachmentController" "$chatV1\FileAttachmentController.php"

# ── BILLING SERVICE ───────────────────────────────────────────────────────────
$bilV1 = "$base\billing-service\app\Http\Controllers\V1"
Make-Stub "App\Http\Controllers\V1" "InvoiceController" "$bilV1\InvoiceController.php"
Make-Stub "App\Http\Controllers\V1" "ReceiptController" "$bilV1\ReceiptController.php"

Write-Host ""
Write-Host "All stubs created successfully."
