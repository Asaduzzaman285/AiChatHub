<?php

namespace App\Ai\Middleware;

use App\Models\AiModel;
use App\Services\WalletClientService;
use Closure;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;

class CostTrackingMiddleware
{
    private float $estimatedCost = 0.0;
    private bool  $reserved      = false;

    // Fallback only for a model with no model_pricing row yet — should not
    // normally be hit once pricing is seeded for every active model.
    private const FALLBACK_INPUT_RATE  = 2.50;
    private const FALLBACK_OUTPUT_RATE = 10.00;

    public function __construct(private string $userId) {}

    public function handle(AgentPrompt $prompt, Closure $next): AgentResponse|StreamableAgentResponse
    {
        [$inputRate, $outputRate] = $this->ratesFor($prompt->model);

        // Estimate cost from prompt token count
        $inputTokens         = (int) ceil(strlen($prompt->prompt) / 4);
        $estimatedOutput     = 1000;
        $this->estimatedCost = $this->calculateCost($inputTokens, $estimatedOutput, $inputRate, $outputRate);

        // Reserve balance — abort if insufficient
        $walletClient = app(WalletClientService::class);
        $this->reserved = $walletClient->reserve($this->userId, $this->estimatedCost);

        if (! $this->reserved) {
            throw new \RuntimeException('Insufficient wallet balance', 402);
        }

        // Execute the AI call
        return $next($prompt)->then(function (AgentResponse $response) use ($walletClient, $inputRate, $outputRate) {
            $actualCost = $this->calculateCost(
                $response->usage?->promptTokens ?? 0,
                $response->usage?->completionTokens ?? 0,
                $inputRate,
                $outputRate,
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

    /** @return array{0: float, 1: float} [inputRatePerMillion, outputRatePerMillion] */
    private function ratesFor(string $modelId): array
    {
        $pricing = AiModel::where('model_id', $modelId)->first()?->activePricing();

        if (! $pricing || $pricing->pricing_type !== 'token_based') {
            return [self::FALLBACK_INPUT_RATE, self::FALLBACK_OUTPUT_RATE];
        }

        return [(float) $pricing->input_rate_per_million, (float) $pricing->output_rate_per_million];
    }

    private function calculateCost(int $inputTokens, int $outputTokens, float $inputRate, float $outputRate): float
    {
        return ($inputTokens / 1_000_000 * $inputRate) + ($outputTokens / 1_000_000 * $outputRate);
    }
}
