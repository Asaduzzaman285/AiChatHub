<?php

namespace App\Ai\Agents;

use App\Ai\Middleware\CostTrackingMiddleware;
use App\Ai\Middleware\UsageLoggingMiddleware;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;

// No #[Temperature(...)] here on purpose — confirmed live that Anthropic's newest
// Claude 5-generation models (claude-sonnet-5, likely claude-opus-5 too) reject the
// request outright with 400 "`temperature` is deprecated for this model" the instant
// this attribute is present, regardless of value. Every provider we use is fine with
// temperature simply being omitted (falls back to that provider's own default), so
// dropping it here is the safe fix for all 8 providers, not a per-model workaround.
#[MaxTokens(4096)]
class TextChatAgent implements Agent, Conversational, HasMiddleware
{
    use Promptable;

    public function __construct(
        private string $userId,
        private string $sessionId,
        private array  $history = [],
        private ?string $systemPrompt = null
    ) {}

    public function instructions(): string
    {
        return $this->systemPrompt ?? 'You are a helpful, accurate, and concise AI assistant.';
    }

    public function messages(): iterable
    {
        return array_map(
            fn ($msg) => new Message($msg['role'], $msg['content']),
            $this->history
        );
    }

    public function middleware(): array
    {
        return [
            new CostTrackingMiddleware($this->userId),
            new UsageLoggingMiddleware($this->userId, $this->sessionId),
        ];
    }
}
