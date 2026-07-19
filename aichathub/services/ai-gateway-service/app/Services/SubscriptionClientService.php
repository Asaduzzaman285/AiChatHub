<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SubscriptionClientService
{
    private string $baseUrl;
    private string $internalKey;

    public function __construct()
    {
        $this->baseUrl     = rtrim(config('services.subscription_url', 'http://subscription-nginx'), '/');
        $this->internalKey = config('services.internal_key', '');
    }

    /**
     * @return array{allowed: bool, model_access: string[]}|null null when the user has no active subscription
     */
    public function currentPackageAccess(string $userId): ?array
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders(['X-Internal-Service-Key' => $this->internalKey])
                ->get("{$this->baseUrl}/api/internal/subscriptions/{$userId}/current");

            // SubscriptionCheckController::current() returns {"subscription": null} when
            // there's no active subscription, but puts package/status at the TOP level
            // (not nested under "subscription") when one exists — match that quirk here.
            if (! $response->successful() || $response->json('package') === null) {
                return null;
            }

            return [
                'package_slug' => $response->json('package.slug'),
                'model_access' => $response->json('package.model_access') ?? [],
            ];
        } catch (\Exception $e) {
            Log::error('SubscriptionClientService::currentPackageAccess failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * @return array{allowed: bool, reason: ?string, package: ?string}
     */
    public function canAccess(string $userId, string $modelId): array
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders(['X-Internal-Service-Key' => $this->internalKey])
                ->get("{$this->baseUrl}/api/internal/subscriptions/{$userId}/can-access/{$modelId}");

            if (! $response->successful()) {
                return ['allowed' => false, 'reason' => 'subscription_check_failed', 'package' => null];
            }

            return [
                'allowed' => (bool) $response->json('allowed'),
                'reason'  => $response->json('reason'),
                'package' => $response->json('package'),
            ];
        } catch (\Exception $e) {
            Log::error('SubscriptionClientService::canAccess failed', ['error' => $e->getMessage()]);
            return ['allowed' => false, 'reason' => 'subscription_check_failed', 'package' => null];
        }
    }
}
