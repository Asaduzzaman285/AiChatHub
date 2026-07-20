<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationClient
{
    private string $baseUrl;
    private string $internalKey;

    public function __construct()
    {
        $this->baseUrl     = rtrim(config('services.notification_url', 'http://notification-nginx'), '/');
        $this->internalKey = config('services.internal_key', '');
    }

    public function send(string $type, string $userId, string $email, array $data, ?string $idempotencyKey = null): void
    {
        try {
            Http::timeout(15)
                ->withHeaders(['X-Internal-Service-Key' => $this->internalKey])
                ->post("{$this->baseUrl}/api/internal/notifications/send", [
                    'type'            => $type,
                    'user_id'         => $userId,
                    'email'           => $email,
                    'data'            => $data,
                    'idempotency_key' => $idempotencyKey,
                ]);
        } catch (\Exception $e) {
            // A failed notification shouldn't fail the underlying financial operation —
            // this is a best-effort side effect.
            Log::error('NotificationClient::send failed', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }
}
