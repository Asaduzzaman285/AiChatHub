<?php

namespace App\Ai\Middleware;

use App\Services\WalletClientService;
use Closure;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

class CostTrackingMiddleware
{
    private float $estimatedCost = 0.0;
    private bool  $reserved      = false;

    public function __construct(private string $userId) {}

    public function handle(AgentPrompt $prompt, Closure $next): AgentResponse
    {
        // Estimate cost from prompt token count
        $inputTokens         = (int) ceil(strlen($prompt->prompt) / 4);
        $estimatedOutput     = 1000;
        $this->estimatedCost = $this->calculateEstimate($inputTokens, $estimatedOutput);

        // Reserve balance — abort if insufficient
        $walletClient = app(WalletClientService::class);
        $this->reserved = $walletClient->reserve($this->userId, $this->estimatedCost);

        if (! $this->reserved) {
            throw new \RuntimeException('Insufficient wallet balance', 402);
        }

        // Execute the AI call
        return $next($prompt)->then(function (AgentResponse $response) use ($walletClient) {
            $actualCost = $this->calculateActual(
                $response->usage?->promptTokens ?? 0,
                $response->usage?->completionTokens ?? 0
            );

            $walletClient->deduct(
                $this->userId,
                $actualCost,
                $this->estimatedCost,
                'AI Chat Request'
            );

            return $response;
        });
    }

    private function calculateEstimate(int $inputTokens, int $outputTokens): float
    {
        // Use average GPT-4o rate as default estimate
        return ($inputTokens / 1_000_000 * 2.50) + ($outputTokens / 1_000_000 * 10.00);
    }

    private function calculateActual(int $promptTokens, int $completionTokens): float
    {
        return ($promptTokens / 1_000_000 * 2.50) + ($completionTokens / 1_000_000 * 10.00);
    }
}
