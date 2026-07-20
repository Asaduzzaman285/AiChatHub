<?php
use App\Http\Controllers\Internal\ChatInternalController;
use Illuminate\Support\Facades\Route;

// Prefix: /api/internal (set in bootstrap/app.php)
// Auth:   X-Internal-Service-Key header
Route::post('/sessions/{sessionId}/messages', [ChatInternalController::class, 'appendMessage']);
Route::post('/attachments/resolve', [ChatInternalController::class, 'resolveAttachments']);
