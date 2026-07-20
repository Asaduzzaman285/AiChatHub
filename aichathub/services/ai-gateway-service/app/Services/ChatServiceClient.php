<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatServiceClient
{
    private string $baseUrl;
    private string $internalKey;

    public function __construct()
    {
        $this->baseUrl     = rtrim(config('services.chat_url', 'http://chat-nginx'), '/');
        $this->internalKey = config('services.internal_key', '');
    }

    public function appendMessage(string $sessionId, string $userId, string $role, string $content, array $usage = []): void
    {
        try {
            Http::timeout(15)
                ->withHeaders(['X-Internal-Service-Key' => $this->internalKey])
                ->post("{$this->baseUrl}/api/internal/sessions/{$sessionId}/messages", array_merge([
                    'user_id' => $userId,
                    'role'    => $role,
                    'content' => $content,
                ], $usage));
        } catch (\Exception $e) {
            // Persistence failure shouldn't fail an already-completed chat response —
            // the user already has their answer. Just log it for later investigation.
            Log::error('ChatServiceClient::appendMessage failed', ['error' => $e->getMessage(), 'session_id' => $sessionId]);
        }
    }

    /**
     * @param string[] $ids
     * @return array<int, array{id: string, base64: string, mime_type: string, original_name: string}>
     */
    public function resolveAttachments(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        try {
            $response = Http::timeout(20)
                ->withHeaders(['X-Internal-Service-Key' => $this->internalKey])
                ->post("{$this->baseUrl}/api/internal/attachments/resolve", ['ids' => $ids]);

            return $response->successful() ? ($response->json('attachments') ?? []) : [];
        } catch (\Exception $e) {
            Log::error('ChatServiceClient::resolveAttachments failed', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
