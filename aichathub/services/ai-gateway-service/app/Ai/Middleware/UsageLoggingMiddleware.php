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

        DB::table('usage_logs')->insert([
            'id'                 => (string) Str::uuid(),
            'user_id'            => $this->userId,
            'session_id'         => $this->sessionId,
            'model_id'           => $model->id,
            'operation_type'     => 'chat',
            'status'             => $status,
            'prompt_tokens'      => $response?->usage?->promptTokens ?? 0,
            'completion_tokens'  => $response?->usage?->completionTokens ?? 0,
            'total_tokens'       => ($response?->usage?->promptTokens ?? 0) + ($response?->usage?->completionTokens ?? 0),
            'estimated_cost'     => 0,
            'actual_cost'        => 0,
            'currency'           => 'USD',
            'duration_ms'        => (int) ((microtime(true) - $startedAt) * 1000),
            'error_message'      => $error,
            'created_at'         => now(),
        ]);
    }
}
