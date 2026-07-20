<?php
use App\Http\Controllers\Internal\NotificationInternalController;
use Illuminate\Support\Facades\Route;

// Prefix: /api/internal (set in bootstrap/app.php)
// Auth:   X-Internal-Service-Key header
Route::post('/notifications/send', [NotificationInternalController::class, 'send']);
