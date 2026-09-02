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

    public function appendMessage(string $sessionId, string $userId, string $role, string $content, array $usage = [], array $attachmentIds = []): void
    {
        try {
            Http::timeout(15)
                ->withHeaders(['X-Internal-Service-Key' => $this->internalKey, 'Accept-Encoding' => 'identity'])
                ->post("{$this->baseUrl}/api/internal/sessions/{$sessionId}/messages", array_merge([
                    'user_id' => $userId,
                    'role'    => $role,
                    'content' => $content,
                    ...(! empty($attachmentIds) ? ['attachment_ids' => $attachmentIds] : []),
                ], $usage));
        } catch (\Exception $e) {
            // Persistence failure shouldn't fail an already-completed chat response —
            // the user already has their answer. Just log it for later investigation.
            Log::error('ChatServiceClient::appendMessage failed', ['error' => $e->getMessage(), 'session_id' => $sessionId]);
        }
    }

    /**
     * @param string[] $ids
     * @return array<int, array{id: string, mime_type: string, original_name: string, base64?: string, extracted_text?: string}>
     */
    public function resolveAttachments(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        try {
            // Accept-Encoding: identity — same-host docker traffic gains nothing from
            // compression, and forcing it off sidesteps a real bug confirmed live: under
            // this service's Swoole coroutine hooks (config/octane.php's hook_flags,
            // needed for ChatController::compare()'s concurrent fan-out), Guzzle's
            // automatic Brotli decompression silently fails on a hooked connection —
            // chat-service's response comes back Content-Encoding: br (large enough
            // payloads like extracted_text cross its compression threshold), the client
            // reports success with the marker header renamed but never actually inflates
            // the body, and the caller sees an empty/garbled result with no exception and
            // no error logged. Confirmed via a raw `curl --compressed` against the same
            // endpoint: it decodes br cleanly, so this is specific to the hooked client,
            // not the response itself.
            $response = Http::timeout(20)
                ->withHeaders(['X-Internal-Service-Key' => $this->internalKey, 'Accept-Encoding' => 'identity'])
                ->post("{$this->baseUrl}/api/internal/attachments/resolve", ['ids' => $ids]);

            return $response->successful() ? ($response->json('attachments') ?? []) : [];
        } catch (\Exception $e) {
            Log::error('ChatServiceClient::resolveAttachments failed', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
