<?php

namespace App\Ai\Middleware;

use App\Models\AiModel;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;

class UsageLoggingMiddleware
{
    public function __construct(private string $userId, private ?string $sessionId = null) {}

    public function handle(AgentPrompt $prompt, Closure $next): AgentResponse|StreamableAgentResponse
    {
        $model     = AiModel::where('model_id', $prompt->model)->first();
        $startedAt = microtime(true);

        try {
            return $next($prompt)->then(function (AgentResponse $response) use ($model, $startedAt) {
                $this->log($model, $startedAt, 'completed', $response, null);
                return $response;
            });
        } catch (\Throwable $e) {
            $this->log($model, $startedAt, 'failed', null, $e->getMessage());
            throw $e;
        }
    }

    private function log(?AiModel $model, float $startedAt, string $status, ?AgentResponse $response, ?string $error): void
    {
        if (! $model) {
            return;
        }

        $promptTokens     = $response?->usage?->promptTokens ?? 0;
        $completionTokens = $response?->usage?->completionTokens ?? 0;
        $cost             = $this->calculateCost($model, $promptTokens, $completionTokens);

        DB::table('usage_logs')->insert([
            'id'                 => (string) Str::uuid(),
            'user_id'            => $this->userId,
            'session_id'         => $this->sessionId,
            'model_id'           => $model->id,
            'operation_type'     => 'chat',
            'status'             => $status,
            'prompt_tokens'      => $promptTokens,
            'completion_tokens'  => $completionTokens,
            'total_tokens'       => $promptTokens + $completionTokens,
            'estimated_cost'     => $cost,
            'actual_cost'        => $cost,
            'currency'           => 'USD',
            'duration_ms'        => (int) ((microtime(true) - $startedAt) * 1000),
            'error_message'      => $error,
            'created_at'         => now(),
        ]);
    }

    // Mirrors CostTrackingMiddleware::calculateCost() — this is the same request's
    // usage being logged for reporting, so it must resolve to the same figure the
    // wallet was actually debited, not an independent estimate.
    private function calculateCost(AiModel $model, int $promptTokens, int $completionTokens): float
    {
        $pricing = $model->activePricing();

        if (! $pricing || $pricing->pricing_type !== 'token_based') {
            return 0.0;
        }

        return ($promptTokens / 1_000_000 * (float) $pricing->input_rate_per_million)
             + ($completionTokens / 1_000_000 * (float) $pricing->output_rate_per_million);
    }
}
