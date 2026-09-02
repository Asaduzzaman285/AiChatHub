<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Posts a record of a sensitive admin action to auth-service's audit_logs table. Best-effort — never blocks the actual admin action if logging fails. */
class AuditLogClient
{
    private string $baseUrl;
    private string $internalKey;

    public function __construct()
    {
        $this->baseUrl     = rtrim(config('services.auth_url', 'http://auth-nginx'), '/');
        $this->internalKey = config('services.internal_key', '');
    }

    public function log(?string $adminUserId, string $action, string $resourceType, ?string $resourceId = null, ?array $oldValues = null, ?array $newValues = null, ?string $ip = null, ?string $userAgent = null): void
    {
        try {
            Http::timeout(10)
                // Accept-Encoding: identity — see ChatServiceClient::resolveAttachments for why
                // (Brotli auto-decode silently fails on this service's hooked coroutine client).
                ->withHeaders(['X-Internal-Service-Key' => $this->internalKey, 'Accept-Encoding' => 'identity'])
                ->post("{$this->baseUrl}/api/internal/audit-logs", [
                    'admin_user_id' => $adminUserId,
                    'action'        => $action,
                    'resource_type' => $resourceType,
                    'resource_id'   => $resourceId,
                    'old_values'    => $oldValues,
                    'new_values'    => $newValues,
                    'ip_address'    => $ip,
                    'user_agent'    => $userAgent,
                ]);
        } catch (\Exception $e) {
            Log::error('AuditLogClient::log failed', ['error' => $e->getMessage(), 'action' => $action]);
        }
    }
}
