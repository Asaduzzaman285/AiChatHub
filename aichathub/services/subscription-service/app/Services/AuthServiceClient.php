<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AuthServiceClient
{
    private string $baseUrl;
    private string $internalKey;

    public function __construct()
    {
        $this->baseUrl     = rtrim(config('services.auth_url', 'http://auth-nginx'), '/');
        $this->internalKey = config('services.internal_key', '');
    }

    /** @return array{email: string, name: string}|null */
    public function findUser(string $userId): ?array
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders(['X-Internal-Service-Key' => $this->internalKey])
                ->get("{$this->baseUrl}/api/internal/users/{$userId}");

            if (! $response->successful()) {
                return null;
            }

            return ['email' => $response->json('email'), 'name' => $response->json('name')];
        } catch (\Exception $e) {
            Log::error('AuthServiceClient::findUser failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
