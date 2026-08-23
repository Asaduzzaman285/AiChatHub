<?php
use App\Http\Controllers\V1\ChatController;
use Illuminate\Support\Facades\Route;

// Called by chat-service after a session's first assistant reply lands, to generate
// a real title instead of leaving it on the "New Chat" default.
Route::post('/generate-title', [ChatController::class, 'generateTitle']);
